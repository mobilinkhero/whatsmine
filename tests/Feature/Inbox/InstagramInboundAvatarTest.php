<?php

namespace Tests\Feature\Inbox;

use App\Events\ContactCreated;
use App\Events\MessageReceived;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Instagram profile pictures arrive from Meta's CDN as long signed URLs (often
 * 600-900+ chars). Storing one on contacts.avatar used to throw
 * "Data too long for column 'avatar'" inside the webhook handler, which aborted
 * the event before the inbound message was written. These tests drive the real
 * webhook endpoint end-to-end (controller -> job -> InstagramDriver) and assert
 * the contact, conversation and inbound message all land.
 */
class InstagramInboundAvatarTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = 'test_meta_app_id';

    private const APP_SECRET = 'test_meta_app_secret';

    private const VERIFY_TOKEN = 'meta-verify-token-xyz';

    private const IG_ACCOUNT_ID = '17841400000000001';

    private const IGSID = '1234567890123456';

    private array $ctx;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ctx = $this->createWorkspaceContext();

        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
                'verify_token' => self::VERIFY_TOKEN,
            ],
        ]);

        $this->account = ChannelAccount::create([
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'instagram',
            'provider' => 'meta',
            'display_name' => 'Test Instagram',
            'credentials' => [
                'access_token' => 'ig-page-token-1',
                'instagram_account_id' => self::IG_ACCOUNT_ID,
            ],
            'meta_json' => [
                'instagram_account_id' => self::IG_ACCOUNT_ID,
                'instagram_page_id' => self::IG_ACCOUNT_ID,
            ],
            'status' => 'active',
        ]);
    }

    /**
     * A realistic signed Instagram CDN profile picture URL. The `_nc_oc` and `oh`
     * signature parameters are what push real-world URLs far past 512 characters.
     */
    private function longCdnAvatarUrl(): string
    {
        return 'https://scontent-fra3-2.xx.fbcdn.net/v/t51.2885-15/123456789_987654321098765_1234567890123456789_n.jpg'
            .'?stp=dst-jpg_s720x720_tt6&_nc_cat=105&ccb=1-7&_nc_sid=7d201b'
            .'&_nc_ohc='.str_repeat('A', 64)
            .'&_nc_oc='.str_repeat('B', 220)
            .'&_nc_ad=z-m&_nc_cid=0&_nc_zt=23&_nc_ht=scontent-fra3-2.xx.fbcdn.net&edm=AP4hL3IEAAAA'
            .'&_nc_gid='.str_repeat('C', 48)
            .'&oh='.str_repeat('D', 96)
            .'&oe=68C8F5A1';
    }

    private function fakeInstagramProfile(string $profilePic): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v20.0/'.self::IGSID.'*' => Http::response([
                'id' => self::IGSID,
                'name' => 'Jane Doe',
                'username' => 'jane.doe',
                'profile_pic' => $profilePic,
            ]),
        ]);
    }

    private function postInboundMessage(string $mid, string $text): void
    {
        $body = [
            'object' => 'instagram',
            'entry' => [[
                'id' => self::IG_ACCOUNT_ID,
                'time' => now()->getTimestampMs(),
                'messaging' => [[
                    'sender' => ['id' => self::IGSID],
                    'recipient' => ['id' => self::IG_ACCOUNT_ID],
                    'timestamp' => now()->getTimestampMs(),
                    'message' => ['mid' => $mid, 'text' => $text],
                ]],
            ]],
        ];

        $signature = 'sha256='.hash_hmac('sha256', json_encode($body), self::APP_SECRET);

        $this->withHeaders(['X-Hub-Signature-256' => $signature])
            ->postJson('/webhooks/meta/'.self::VERIFY_TOKEN, $body)
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    #[Test]
    public function contacts_avatar_column_is_nullable_text(): void
    {
        $this->assertSame('text', Schema::getColumnType('contacts', 'avatar'));

        $avatar = collect(Schema::getColumns('contacts'))->firstWhere('name', 'avatar');
        $this->assertNotNull($avatar);
        $this->assertTrue($avatar['nullable']);
    }

    #[Test]
    public function instagram_inbound_message_is_stored_when_profile_picture_url_exceeds_512_chars(): void
    {
        Event::fake([MessageReceived::class, ContactCreated::class]);

        $avatar = $this->longCdnAvatarUrl();
        $this->assertGreaterThan(512, strlen($avatar));
        $this->fakeInstagramProfile($avatar);

        $this->postInboundMessage('m_ig_long_avatar_1', 'Hello from Instagram');

        $workspaceId = $this->ctx['workspace']->id;

        $contact = Contact::where('workspace_id', $workspaceId)
            ->whereJsonContains('custom_fields->instagram_psid', self::IGSID)
            ->first();
        $this->assertNotNull($contact, 'Instagram contact was not created');
        $this->assertSame($avatar, $contact->avatar);
        $this->assertSame($avatar, $contact->avatar_url);
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('instagram', $contact->source);
        $this->assertSame('jane.doe', $contact->custom_fields['instagram_username'] ?? null);

        $conversation = Conversation::where('workspace_id', $workspaceId)
            ->where('contact_id', $contact->id)
            ->where('channel_account_id', $this->account->id)
            ->first();
        $this->assertNotNull($conversation, 'Instagram conversation was not created');
        $this->assertSame(self::IGSID, $conversation->external_thread_id);
        $this->assertSame('open', $conversation->status);
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertNotNull($conversation->last_message_at);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'instagram',
            'type' => 'text',
            'body' => 'Hello from Instagram',
            'status' => 'delivered',
            'provider_message_id' => 'm_ig_long_avatar_1',
        ]);

        Event::assertDispatched(ContactCreated::class);
        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $e) => $e->message->provider_message_id === 'm_ig_long_avatar_1');
    }

    #[Test]
    public function repeat_instagram_messages_reuse_the_same_contact_and_conversation(): void
    {
        Event::fake([MessageReceived::class, ContactCreated::class]);

        $avatar = $this->longCdnAvatarUrl();
        $this->fakeInstagramProfile($avatar);

        $this->postInboundMessage('m_ig_repeat_1', 'First');
        $this->postInboundMessage('m_ig_repeat_2', 'Second');

        $workspaceId = $this->ctx['workspace']->id;

        $this->assertSame(1, Contact::where('workspace_id', $workspaceId)->count());
        $this->assertSame(1, Conversation::where('workspace_id', $workspaceId)->count());

        $conversation = Conversation::where('workspace_id', $workspaceId)->first();
        $this->assertSame(2, Message::where('conversation_id', $conversation->id)->where('direction', 'in')->count());
        $this->assertSame(2, (int) $conversation->unread_count);
        $this->assertSame($avatar, $conversation->contact->avatar);

        Event::assertDispatchedTimes(ContactCreated::class, 1);
        Event::assertDispatchedTimes(MessageReceived::class, 2);
    }
}
