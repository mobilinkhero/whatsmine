<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Modules\Integrations\Services\CredentialResolver;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Handles SMB (Small/Medium Business) webhook events from Meta when a
 * business customer onboards via the WhatsApp Business app (Coexistence).
 *
 * Three webhook fields are delivered here:
 *   - smb_app_state_sync  — contact sync from the WhatsApp Business app
 *   - history             — chat history sync (up to 180 days)
 *   - smb_message_echoes  — messages sent by the business via WhatsApp Business app
 */
class WhatsappSmbWebhookController extends Controller
{
    /**
     * Verify the SMB webhook (same challenge-response as regular webhooks).
     */
    public function verify(Request $request): \Illuminate\Http\Response
    {
        $meta = CredentialResolver::system()->meta();
        $expectedToken = $meta?->verifyToken()
            ?: hash('sha256', (string) ($meta?->appId() . '' . $meta?->appSecret()) . 'wh_global_verify');

        if (! $expectedToken) {
            abort(403, 'Meta credentials not configured');
        }

        if ($request->input('hub.mode') === 'subscribe'
            && hash_equals($expectedToken, (string) $request->input('hub.verify_token', ''))) {
            return response($request->input('hub_challenge', ''), 200);
        }

        abort(400);
    }

    /**
     * Receive SMB webhook events.
     */
    public function receive(Request $request): JsonResponse
    {
        $meta = CredentialResolver::system()->meta();
        $appSecret = $meta?->appSecret();

        // Verify HMAC signature
        if ($appSecret) {
            $this->verifySignature($request, $appSecret);
        }

        $payload = $request->all();

        foreach ($payload['entry'] ?? [] as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                switch ($field) {
                    case 'smb_app_state_sync':
                        $this->handleAppStateSync($value);
                        break;

                    case 'history':
                        $this->handleHistory($value);
                        break;

                    case 'smb_message_echoes':
                        $this->handleMessageEcho($value);
                        break;
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Handle contact sync from WhatsApp Business app.
     */
    private function handleAppStateSync(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            return;
        }

        $contacts = $value['smb_contacts'] ?? $value['contacts'] ?? [];

        Log::info('WhatsApp SMB: app_state_sync received', [
            'phone_number_id' => $phoneNumberId,
            'contact_count'   => count($contacts),
        ]);

        // Store contacts as channel account contacts if needed
        // For now, log the sync — contact storage depends on your CRM structure
        foreach ($contacts as $contact) {
            // Contacts from WhatsApp Business app are synced via Meta
            // They appear in the inbox when the user messages
            Log::info('WhatsApp SMB: contact synced', [
                'phone_number_id' => $phoneNumberId,
                'wa_id'           => $contact['wa_id'] ?? null,
                'name'            => $contact['name'] ?? null,
            ]);
        }
    }

    /**
     * Handle chat history sync (up to 180 days of messages).
     */
    private function handleHistory(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;
        $history = $value['history'] ?? [];

        if (! $phoneNumberId || empty($history)) {
            return;
        }

        $waba = WhatsappBusinessAccount::where('waba_id', $metadata['waba_id'] ?? '')->first()
            ?? $this->findWabaByPhoneNumber($phoneNumberId);

        Log::info('WhatsApp SMB: history sync received', [
            'phone_number_id' => $phoneNumberId,
            'phase'           => $metadata['phase'] ?? null,
            'chunk_order'    => $metadata['chunk_order'] ?? null,
            'progress'        => $metadata['progress'] ?? null,
            'thread_count'    => count($history),
        ]);

        foreach ($history as $thread) {
            $threadId = $thread['id'] ?? null;
            if (! $threadId) {
                continue;
            }

            $messages = $thread['messages'] ?? [];

            foreach ($messages as $message) {
                // Store historical messages for display in the inbox
                // This is a simplified version — you may want to store in the messages table
                Log::info('WhatsApp SMB: history message', [
                    'thread_id' => $threadId,
                    'message_id' => $message['id'] ?? null,
                    'type'       => $message['type'] ?? null,
                    'timestamp'  => $message['timestamp'] ?? null,
                ]);
            }
        }

        // If this is the final chunk (progress = 100), mark sync complete
        if (($metadata['progress'] ?? 0) == 100) {
            Log::info('WhatsApp SMB: history sync complete', [
                'phone_number_id' => $phoneNumberId,
            ]);
        }
    }

    /**
     * Handle messages sent by the business via WhatsApp Business app.
     */
    private function handleMessageEcho(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $phoneNumberId = $metadata['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            return;
        }

        $messages = $value['messages'] ?? $value['smb_messages'] ?? [];

        Log::info('WhatsApp SMB: message echo received', [
            'phone_number_id' => $phoneNumberId,
            'message_count'   => count($messages),
        ]);

        foreach ($messages as $message) {
            $messageId = $message['id'] ?? null;
            if (! $messageId) {
                continue;
            }

            Log::info('WhatsApp SMB: message echo', [
                'phone_number_id' => $phoneNumberId,
                'message_id'      => $messageId,
                'type'            => $message['type'] ?? null,
                'from'            => $message['from'] ?? null,
                'to'              => $message['to'] ?? null,
                'timestamp'       => $message['timestamp'] ?? null,
                'body'            => $message[$message['type'] ?? 'text']['body'] ?? null,
            ]);
        }
    }

    /**
     * Find WABA by phone number ID.
     */
    private function findWabaByPhoneNumber(string $phoneNumberId): ?WhatsappBusinessAccount
    {
        $phone = WhatsappPhoneNumber::where('phone_number_id', $phoneNumberId)->first();
        if ($phone) {
            return WhatsappBusinessAccount::find($phone->waba_id_fk);
        }
        return null;
    }

    /**
     * Verify HMAC signature from Meta.
     */
    private function verifySignature(Request $request, string $appSecret): void
    {
        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);
        $received = $request->header('X-Hub-Signature-256', '');

        if (! hash_equals($expected, $received)) {
            abort(401, 'Invalid signature');
        }
    }
}
