<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Initiates and manages WhatsApp Business app data synchronization
 * for businesses that onboarded via Coexistence (Embedded Signup
 * with existing WhatsApp Business app).
 *
 * Sync types:
 *   - smb_app_state_sync : contacts from the WhatsApp Business app
 *   - history            : chat history (up to 180 days)
 *
 * Must be called within 24 hours of onboarding or the customer
 * must complete the flow again.
 */
class SmbSyncService
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    /**
     * Initiate both contact and history sync for a phone number.
     *
     * @return array{ok: bool, contacts: array, history: array, error: ?string}
     */
    public function syncAll(string $phoneNumberId, string $accessToken): array
    {
        $results = [
            'ok'       => true,
            'contacts' => [],
            'history'  => [],
            'error'    => null,
        ];

        // Step 1: Initiate contact sync
        try {
            $results['contacts'] = $this->initiateContactSync($phoneNumberId, $accessToken);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp SMB: contact sync initiation failed', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
        }

        // Step 2: Initiate history sync
        try {
            $results['history'] = $this->initiateHistorySync($phoneNumberId, $accessToken);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp SMB: history sync initiation failed', [
                'phone_number_id' => $phoneNumberId,
                'error' => $e->getMessage(),
            ]);
            $results['error'] = $e->getMessage();
            $results['ok'] = false;
        }

        Log::info('WhatsApp SMB: sync initiated', [
            'phone_number_id' => $phoneNumberId,
            'contacts_ok' => !empty($results['contacts']),
            'history_ok'  => !empty($results['history']),
        ]);

        return $results;
    }

    /**
     * Initiate contact synchronization from WhatsApp Business app.
     */
    public function initiateContactSync(string $phoneNumberId, string $accessToken): array
    {
        $res = Http::timeout(30)
            ->withToken($accessToken)
            ->post(self::BASE . "/{$phoneNumberId}/smb_app_data", [
                'messaging_product' => 'whatsapp',
                'sync_type'         => 'smb_app_state_sync',
            ]);

        if (! $res->successful()) {
            Log::warning('WhatsApp SMB: smb_app_state_sync initiation failed', [
                'phone_number_id' => $phoneNumberId,
                'status' => $res->status(),
                'response' => $res->json(),
            ]);
            return ['ok' => false, 'error' => $res->json('error.message') ?? 'Unknown error'];
        }

        return [
            'ok'        => true,
            'request_id' => $res->json('request_id'),
            'messaging_product' => $res->json('messaging_product'),
        ];
    }

    /**
     * Initiate chat history synchronization (up to 180 days).
     */
    public function initiateHistorySync(string $phoneNumberId, string $accessToken): array
    {
        $res = Http::timeout(30)
            ->withToken($accessToken)
            ->post(self::BASE . "/{$phoneNumberId}/smb_app_data", [
                'messaging_product' => 'whatsapp',
                'sync_type'         => 'history',
            ]);

        if (! $res->successful()) {
            Log::warning('WhatsApp SMB: history sync initiation failed', [
                'phone_number_id' => $phoneNumberId,
                'status' => $res->status(),
                'response' => $res->json(),
            ]);
            return ['ok' => false, 'error' => $res->json('error.message') ?? 'Unknown error'];
        }

        return [
            'ok'        => true,
            'request_id' => $res->json('request_id'),
            'messaging_product' => $res->json('messaging_product'),
        ];
    }

    /**
     * Check onboarding status — is the number on both WhatsApp Business app
     * and Cloud API?
     */
    public function checkOnboardingStatus(string $phoneNumberId, string $accessToken): array
    {
        $res = Http::timeout(15)
            ->withToken($accessToken)
            ->get(self::BASE . "/{$phoneNumberId}", [
                'fields' => 'is_on_biz_app,platform_type',
            ]);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $res->json('error.message') ?? 'Unknown error'];
        }

        $isOnBizApp = ($res->json('is_on_biz_app') ?? false) === true;
        $platformType = $res->json('platform_type') ?? 'unknown';

        return [
            'ok'           => $isOnBizApp && $platformType === 'CLOUD_API',
            'is_on_biz_app' => $isOnBizApp,
            'platform_type' => $platformType,
        ];
    }
}
