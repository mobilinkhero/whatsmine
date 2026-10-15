<?php

namespace App\Listeners;

use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\LeadQualified;
use App\Events\LeadStageChanged;
use App\Events\MessageReceived;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Services\AutomationEngine;
use Illuminate\Support\Facades\Cache;

class AutomationTriggerListener
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;

        // Only a contact's own message can drive a flow; never an outbound echo.
        if (($message->direction ?? 'in') !== 'in') {
            return;
        }

        $contactId = $message->conversation?->contact_id;
        $workspaceId = $message->conversation?->workspace_id;
        if (! $contactId || ! $workspaceId) {
            return;
        }

        // Evaluate each inbound message exactly once, however many times the event
        // reaches us (a retried webhook, parallel workers, a double registration).
        if ($message->id && ! Cache::add("automation_trigger_lock:{$message->id}", 1, 60)) {
            return;
        }

        $messageBody = $message->body ?? '';

        // A reply to an "Ask question" node resumes the parked run. That same message
        // must not also restart the automation from its trigger, otherwise every
        // answer re-asks the question and runs pile up in "waiting".
        $resumed = $this->engine->resumeAwaitingReplies($workspaceId, $contactId, $messageBody);

        $this->fireWithConfig('message.received', $workspaceId, $contactId, [
            'message_id' => $message->id,
            'message_channel' => $message->channel,
            'message_body' => $messageBody,
        ], $messageBody, $resumed);
    }

    public function handleContactCreated(ContactCreated $event): void
    {
        $this->fire('contact.created', $event->contact->workspace_id, $event->contact->id);
    }

    public function handleCampaignCompleted(CampaignCompleted $event): void
    {
        // No per-contact trigger for campaign completion; skip.
    }

    public function handleCommerceEvent(CommerceEventReceived $event): void
    {
        // eventType is one of order.placed / order.fulfilled / order.cancelled /
        // cart.abandoned / customer.created — matched directly against trigger_type.
        $this->fire($event->eventType, $event->workspaceId, $event->contactId, $event->context);
    }

    public function handleLeadStageChanged(LeadStageChanged $event): void
    {
        // A lead only has a contact once it has been pushed to contacts. Without
        // one there is nobody for the automation's send/tag actions to act on.
        if (! $event->contactId) {
            return;
        }

        $this->fire('lead.stage_changed', $event->workspaceId, $event->contactId, $event->context());

        // Reaching a terminal stage is its own trigger, so a tenant can wire
        // "won" or "lost" follow-up without a condition node on the stage name.
        if ($event->isWon) {
            $this->fire('lead.won', $event->workspaceId, $event->contactId, $event->context());
        }

        if ($event->isLost) {
            $this->fire('lead.lost', $event->workspaceId, $event->contactId, $event->context());
        }
    }

    public function handleLeadQualified(LeadQualified $event): void
    {
        if (! $event->contactId) {
            return;
        }

        $this->fire('lead.qualified', $event->workspaceId, $event->contactId, $event->context());
    }

    public function handleAutomationWebhookReceived(AutomationWebhookReceived $event): void
    {
        $automation = Automation::where('id', $event->automationId)
            ->where('status', 'active')
            ->where('trigger_type', 'webhook')
            ->first();

        if (! $automation) {
            return;
        }

        $context = ['payload' => $event->payload];

        if ($event->contactId) {
            $this->engine->triggerForContact($automation, $event->contactId, $context);
        } else {
            // Contactless: trigger a run without a contact (contact_id = null)
            $this->triggerWithoutContact($automation, $context);
        }
    }

    private function triggerWithoutContact(Automation $automation, array $context = []): void
    {
        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => null,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        dispatch(new ExecuteAutomationRunJob($run->id))->onQueue('automation');
    }

    private function fire(string $triggerType, int $workspaceId, int $contactId, array $context = []): void
    {
        Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->each(fn ($automation) => $this->engine->triggerForContact($automation, $contactId, $context));
    }

    /**
     * Like fire(), but respects trigger_config.keywords for message.received automations.
     * If keywords are set, the message body must contain at least one keyword (case-insensitive).
     */
    private function fireWithConfig(string $triggerType, int $workspaceId, int $contactId, array $context, string $messageBody = '', array $excludeAutomationIds = []): void
    {
        $automations = Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->when($excludeAutomationIds !== [], fn ($q) => $q->whereNotIn('id', $excludeAutomationIds))
            ->get();

        $bodyLower = mb_strtolower($messageBody);

        foreach ($automations as $automation) {
            $keywords = $automation->trigger_config['keywords'] ?? [];

            if (! empty($keywords)) {
                $matches = false;
                foreach ($keywords as $kw) {
                    if (str_contains($bodyLower, mb_strtolower((string) $kw))) {
                        $matches = true;
                        break;
                    }
                }
                if (! $matches) {
                    continue;
                }
            }

            $this->engine->triggerForContact($automation, $contactId, $context);
        }
    }
}
