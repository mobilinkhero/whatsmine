<?php

namespace App\Modules\Integrations\Services;

use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Registers all Meta webhooks (WhatsApp, Messenger, Instagram) automatically
 * when the admin saves the Meta App integration config.
 *
 * Called from IntegrationConfigController::update() so webhooks are always
 * in sync with the configured credentials — no manual CLI step needed.
 */
class MetaWebhookRegistrarService
{
    private const BASE = 'https://graph.facebook.com/v20.0';

    private const WA_FIELDS = 'messages,message_template_status_update,phone_number_name_update,phone_number_quality_update,account_update';

    private const MESSENGER_FIELDS = 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads,message_echoes';

    private const INSTAGRAM_FIELDS = 'messages,messaging_postbacks,messaging_optins,message_deliveries,message_reads,message_echoes';

    /**
     * Register all webhooks for the given verify token and app credentials.
     *
     * @return array{ok: bool, results: array<int, array{key: string, ok: bool, detail: string}>}
     */
    public function registerAll(): array
    {
        $meta = CredentialResolver::system()->meta();

        if (! $meta || ! $meta->appId() || ! $meta->appSecret()) {
            return [
                'ok' => false,
                'results' => [
                    ['key' => 'credentials', 'ok' => false, 'detail' => 'Meta App ID or App Secret is not configured.'],
                ],
            ];
        }

        $appId = $meta->appId();
        $appSecret = $meta->appSecret();
        $verifyToken = $meta->verifyToken();
        $appToken = $appId . '|' . $appSecret;

        if (! $verifyToken) {
            return [
                'ok' => false,
                'results' => [
                    ['key' => 'verify_token', 'ok' => false, 'detail' => 'Webhook Verify Token is not set. Save the config again to auto-generate it.'],
                ],
            ];
        }

        $results = [];

        // 1. WhatsApp webhook
        $waCallback = route('webhooks.whatsapp.global.receive');
        $results[] = $this->registerSubscription($appId, $appToken, $waCallback, $verifyToken, 'whatsapp_business_account', self::WA_FIELDS, 'whatsapp');

        // 2. Messenger webhook — only if config_id_social is set
        if ($meta->configIdSocial()) {
            $messengerCallback = route('webhooks.meta.receive', ['token' => $verifyToken]);
            $results[] = $this->registerSubscription($appId, $appToken, $messengerCallback, $verifyToken, 'page', self::MESSENGER_FIELDS, 'messenger');
        }

        // 3. Instagram webhook — only if config_id_social is set
        if ($meta->configIdSocial()) {
            $instagramCallback = route('webhooks.meta.receive', ['token' => $verifyToken]);
            $results[] = $this->registerSubscription($appId, $appToken, $instagramCallback, $verifyToken, 'instagram', self::INSTAGRAM_FIELDS, 'instagram');
        }

        $allOk = collect($results)->every(fn ($r) => $r['ok']);

        return ['ok' => $allOk, 'results' => $results];
    }

    /**
     * @return array{key: string, ok: bool, detail: string}
     */
    private function registerSubscription(
        string $appId,
        string $appToken,
        string $callbackUrl,
        string $verifyToken,
        string $object,
        string $fields,
        string $label,
    ): array {
        try {
            $res = Http::timeout(15)->post(self::BASE . "/{$appId}/subscriptions", [
                'access_token' => $appToken,
                'object' => $object,
                'callback_url' => $callbackUrl,
                'verify_token' => $verifyToken,
                'fields' => $fields,
            ]);

            if ($res->successful()) {
                Log::info("Meta webhook auto-registered", ['object' => $object, 'callback' => $callbackUrl]);

                return [
                    'key' => $label,
                    'ok' => true,
                    'detail' => "✓ {$label} webhook registered ({$callbackUrl})",
                ];
            }

            $error = $res->json('error.message') ?? 'Meta rejected the request';

            // If callback URL mismatch, try to update it
            if (str_contains($error, 'callback_url') || str_contains($error, 'already exists')) {
                // Delete existing and re-register
                Http::timeout(10)->delete(self::BASE . "/{$appId}/subscriptions", [
                    'access_token' => $appToken,
                    'object' => $object,
                ]);

                $retry = Http::timeout(15)->post(self::BASE . "/{$appId}/subscriptions", [
                    'access_token' => $appToken,
                    'object' => $object,
                    'callback_url' => $callbackUrl,
                    'verify_token' => $verifyToken,
                    'fields' => $fields,
                ]);

                if ($retry->successful()) {
                    Log::info("Meta webhook re-registered after delete", ['object' => $object]);

                    return [
                        'key' => $label,
                        'ok' => true,
                        'detail' => "✓ {$label} webhook re-registered ({$callbackUrl})",
                    ];
                }

                $error = $retry->json('error.message') ?? 'Retry also failed';
            }

            Log::warning("Meta webhook auto-registration failed", [
                'object' => $object,
                'error' => $error,
                'callback' => $callbackUrl,
            ]);

            return [
                'key' => $label,
                'ok' => false,
                'detail' => "✗ {$label} webhook failed: {$error}",
            ];
        } catch (\Throwable $e) {
            Log::warning("Meta webhook auto-registration exception", [
                'object' => $object,
                'error' => $e->getMessage(),
            ]);

            return [
                'key' => $label,
                'ok' => false,
                'detail' => "✗ {$label} webhook error: " . $e->getMessage(),
            ];
        }
    }
}
