<?php

namespace Tests\Feature\Automation;

use App\Events\AutomationFailed;
use App\Events\MessageReceived;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Support\ApiAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Regression guards for the production-readiness fixes to the automation
 * pipeline: single listener registration, builder-format trigger nodes,
 * one run per inbound message, reply-to-question resume, error reporting,
 * the API trigger queue, and stale "waiting" run pruning.
 */
class AutomationProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    private $workspace;

    private $user;

    private Contact $contact;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspace = $ctx['workspace'];
        $this->user = $ctx['user'];

        $this->contact = Contact::factory()->create(['workspace_id' => $this->workspace->id]);
        $channel = ChannelAccount::create([
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'WA',
            'status' => 'active',
        ]);
        $this->conversation = Conversation::create([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $this->contact->id,
            'channel_account_id' => $channel->id,
            'status' => 'open',
        ]);
    }

    private function inbound(string $body = 'Hello', string $direction = 'in'): Message
    {
        return Message::create([
            'conversation_id' => $this->conversation->id,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }

    /** Exactly what Builder.jsx serializeNodes() posts. */
    private function builderGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'triggerNode', 'position' => ['x' => 250, 'y' => 50], 'data' => ['triggerType' => 'message.received', 'label' => 'Trigger']],
                ['id' => 'add_tag-1', 'type' => 'add_tag', 'position' => ['x' => 300, 'y' => 200], 'data' => ['nodeType' => 'add_tag', 'tag' => 'from-builder', 'configured' => true]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'add_tag-1', 'sourceHandle' => null, 'targetHandle' => null],
            ],
        ];
    }

    private function messageReceivedAutomation(?array $graph = null): Automation
    {
        $graph ??= $this->builderGraph();

        return Automation::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Support flow',
            'status' => 'active',
            'trigger_type' => 'message.received',
            'trigger_config' => [],
            'nodes' => $graph['nodes'],
            'edges' => $graph['edges'],
        ]);
    }

    private function fakeSideEffects(): void
    {
        Queue::fake();
        Http::fake();
        Notification::fake();
    }

    // ─── Listener registration ────────────────────────────────────────────────

    public function test_every_app_event_listener_is_registered_exactly_once(): void
    {
        $raw = app('events')->getRawListeners();

        $eventClasses = collect(glob(app_path('Events/*.php')))
            ->map(fn ($f) => 'App\\Events\\'.basename($f, '.php'))
            ->filter(fn ($c) => isset($raw[$c]));

        $this->assertNotEmpty($eventClasses, 'No app events have listeners registered.');

        foreach ($eventClasses as $event) {
            $names = collect($raw[$event])->map(function ($listener) {
                if (is_array($listener)) {
                    return implode('@', $listener);
                }

                return is_string($listener) ? $listener : 'closure#'.spl_object_id($listener);
            })->all();

            $this->assertSame(
                array_values(array_unique($names)),
                array_values($names),
                "{$event} has a listener registered more than once: ".implode(', ', $names)
            );
        }

        $this->assertCount(4, $raw[MessageReceived::class]);
    }

    // ─── Builder-format graphs execute ────────────────────────────────────────

    public function test_builder_saved_trigger_node_executes_instead_of_failing(): void
    {
        $automation = $this->messageReceivedAutomation();
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'context' => [],
            'started_at' => now(),
        ]);

        app(AutomationEngine::class)->executeRun($run);

        $run->refresh();
        $this->assertSame('completed', $run->status, 'Run failed: '.$run->error);
        $this->assertTrue($this->contact->fresh()->tags()->where('name', 'from-builder')->exists());
        $this->assertSame('add_tag', $run->logs()->first()->node_type);
    }

    public function test_saving_from_builder_normalises_node_types(): void
    {
        $automation = $this->messageReceivedAutomation();
        $graph = $this->builderGraph();
        // Raw React Flow shape, in case the client ever posts it unserialised.
        $graph['nodes'][1]['type'] = 'automationNode';

        $this->actingAs($this->user)
            ->put(route('client.automations.update', $automation->uuid), $graph)
            ->assertSessionHasNoErrors();

        $nodes = $automation->fresh()->nodes;
        $this->assertSame('trigger', $nodes[0]['type']);
        $this->assertSame('add_tag', $nodes[1]['type']);
        $this->assertSame('add_tag', $nodes[1]['data']['nodeType']);
    }

    // ─── One run per inbound message ──────────────────────────────────────────

    public function test_one_inbound_message_creates_exactly_one_run(): void
    {
        $this->fakeSideEffects();
        $automation = $this->messageReceivedAutomation();

        MessageReceived::dispatch($this->inbound());

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
        Queue::assertPushedOn('automation', ExecuteAutomationRunJob::class);
    }

    public function test_duplicate_delivery_of_the_same_message_does_not_create_a_second_run(): void
    {
        $this->fakeSideEffects();
        $automation = $this->messageReceivedAutomation();
        $message = $this->inbound();

        MessageReceived::dispatch($message);
        MessageReceived::dispatch($message);

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_outbound_message_never_triggers_a_run(): void
    {
        $this->fakeSideEffects();
        $automation = $this->messageReceivedAutomation();

        MessageReceived::dispatch($this->inbound('echo', 'out'));

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_contact_cannot_have_two_overlapping_runs_of_the_same_automation(): void
    {
        Queue::fake();
        $automation = $this->messageReceivedAutomation();
        $engine = app(AutomationEngine::class);

        $first = $engine->triggerForContact($automation, $this->contact->id);
        $second = $engine->triggerForContact($automation, $this->contact->id);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A second run started while the first was still pending.');
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());

        $first->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotNull($engine->triggerForContact($automation, $this->contact->id));
    }

    // ─── Ask question: reply resumes, never restarts ──────────────────────────

    public function test_reply_to_ask_question_resumes_the_parked_run_and_does_not_restart_the_flow(): void
    {
        $this->fakeSideEffects();
        $automation = $this->messageReceivedAutomation();

        // Parked on an "Ask question" node, exactly as executeAskQuestion() leaves it.
        $parked = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'waiting',
            'context' => ['_awaiting_reply' => true, '_reply_var' => 'email'],
            'current_node_id' => 'q1',
            'resume_node_id' => 'add_tag-1',
            'started_at' => now(),
        ]);

        MessageReceived::dispatch($this->inbound('me@x.com'));

        $parked->refresh();
        $this->assertSame('pending', $parked->status);
        $this->assertSame('me@x.com', $parked->context['email']);
        $this->assertArrayNotHasKey('_awaiting_reply', $parked->context);

        // The reply resumed the parked run; it did NOT start the automation over.
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);
        Queue::assertPushed(ExecuteAutomationRunJob::class, fn ($job) => $job->runId === $parked->id);
    }

    public function test_resume_is_claimed_atomically_so_a_second_delivery_cannot_resume_twice(): void
    {
        Queue::fake();
        $automation = $this->messageReceivedAutomation();
        $parked = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'waiting',
            'context' => ['_awaiting_reply' => true, '_reply_var' => 'answer'],
            'resume_node_id' => 'add_tag-1',
            'started_at' => now(),
        ]);
        $engine = app(AutomationEngine::class);

        $first = $engine->resumeAwaitingReplies($this->workspace->id, $this->contact->id, 'yes');
        $second = $engine->resumeAwaitingReplies($this->workspace->id, $this->contact->id, 'yes again');

        $this->assertSame([$automation->id], $first);
        $this->assertSame([], $second);
        $this->assertSame('yes', $parked->fresh()->context['answer']);
        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);
    }

    // ─── Failures are visible ─────────────────────────────────────────────────

    public function test_a_crashed_run_records_its_error_on_the_run(): void
    {
        Event::fake([AutomationFailed::class]);
        $automation = $this->messageReceivedAutomation();
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'context' => [],
            'started_at' => now(),
        ]);

        $engine = Mockery::mock(AutomationEngine::class);
        $engine->shouldReceive('executeRun')->once()->andThrow(new \RuntimeException('boom: driver exploded'));

        $rethrown = null;
        try {
            (new ExecuteAutomationRunJob($run->id))->handle($engine);
        } catch (\RuntimeException $e) {
            $rethrown = $e;
        }
        $this->assertNotNull($rethrown, 'Job must rethrow so the queue records the failure.');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('boom: driver exploded', $run->error, 'Crash reason must land in automation_runs.error.');
        $this->assertNotNull($run->completed_at);
        Event::assertDispatched(AutomationFailed::class, fn ($e) => $e->run->id === $run->id);
    }

    public function test_a_run_whose_automation_was_deleted_fails_with_a_readable_reason(): void
    {
        $run = AutomationRun::create([
            'automation_id' => 999999,
            'contact_id' => $this->contact->id,
            'status' => 'pending',
            'context' => [],
            'started_at' => now(),
        ]);

        app(AutomationEngine::class)->executeRun($run);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('Automation no longer exists.', $run->fresh()->error);
    }

    public function test_a_finished_run_is_never_executed_again_by_a_stale_job(): void
    {
        $automation = $this->messageReceivedAutomation();
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'completed',
            'context' => [],
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        (new ExecuteAutomationRunJob($run->id))->handle(app(AutomationEngine::class));

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(0, $run->logs()->count());
    }

    // ─── API trigger ──────────────────────────────────────────────────────────

    public function test_api_trigger_dispatches_on_the_automation_queue(): void
    {
        Queue::fake();
        $automation = $this->messageReceivedAutomation();
        $token = $this->user->createToken('t', [ApiAbilities::AUTOMATIONS_WRITE])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/automations/{$automation->id}/trigger", ['contact_id' => $this->contact->id])
            ->assertStatus(201)
            ->assertJsonPath('status', 'pending');

        Queue::assertPushedOn('automation', ExecuteAutomationRunJob::class);

        // Same contact again while the first run is pending → refused, not duplicated.
        $this->withToken($token)
            ->postJson("/api/v1/automations/{$automation->id}/trigger", ['contact_id' => $this->contact->id])
            ->assertStatus(422);
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    public function test_api_trigger_refuses_an_inactive_automation(): void
    {
        Queue::fake();
        $automation = $this->messageReceivedAutomation();
        $automation->update(['status' => 'paused']);
        $token = $this->user->createToken('t', [ApiAbilities::AUTOMATIONS_WRITE])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/automations/{$automation->id}/trigger", ['contact_id' => $this->contact->id])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    // ─── Stale waiting runs ───────────────────────────────────────────────────

    public function test_prune_command_cancels_only_reply_waits_older_than_the_cutoff(): void
    {
        $automation = $this->messageReceivedAutomation();
        $make = fn (array $context) => AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $this->contact->id,
            'status' => 'waiting',
            'context' => $context,
            'resume_node_id' => 'add_tag-1',
            'started_at' => now(),
        ]);

        $staleReply = $make(['_awaiting_reply' => true, '_reply_var' => 'answer']);
        $freshReply = $make(['_awaiting_reply' => true, '_reply_var' => 'answer']);
        $timedWait = $make([]);

        AutomationRun::whereKey([$staleReply->id, $timedWait->id])->update(['updated_at' => now()->subDays(40)]);

        $this->artisan('automation:prune-stale-runs', ['--days' => 30])
            ->expectsOutputToContain('Cancelled 1 stale')
            ->assertSuccessful();

        $this->assertSame('cancelled', $staleReply->fresh()->status);
        $this->assertStringContainsString('30 days', $staleReply->fresh()->error);
        $this->assertSame('waiting', $freshReply->fresh()->status);
        $this->assertSame('waiting', $timedWait->fresh()->status);
    }
}
