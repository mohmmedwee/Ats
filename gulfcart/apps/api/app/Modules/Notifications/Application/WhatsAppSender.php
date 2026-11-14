<?php

namespace App\Modules\Notifications\Application;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp Business messages through Meta's WhatsApp Cloud API. Messages a business starts must use
 * templates approved in WhatsApp Manager; config gulfcart.whatsapp.templates maps our notification
 * templates to those names and lists the parameters in order (docs/NOTIFICATIONS.md).
 */
final class WhatsAppSender
{
    public function enabled(): bool
    {
        return config('gulfcart.whatsapp.provider') === 'meta'
            && (string) config('gulfcart.whatsapp.phone_number_id') !== ''
            && (string) config('gulfcart.whatsapp.token') !== '';
    }

    /** @return array{name: string, params: list<string>}|null */
    public function templateFor(string $template): ?array
    {
        $map = config("gulfcart.whatsapp.templates.{$template}");

        return is_array($map) && isset($map['name']) ? ['name' => (string) $map['name'], 'params' => array_values((array) ($map['params'] ?? []))] : null;
    }

    /** @param list<string> $bodyParams */
    public function send(string $e164Phone, string $templateName, string $locale, array $bodyParams, ?string $buttonUrlSuffix = null): void
    {
        $components = [];
        if ($bodyParams !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => mb_substr($v, 0, 1000)], $bodyParams)];
        }
        if ($buttonUrlSuffix !== null) {
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $buttonUrlSuffix]]];
        }

        $response = Http::withToken((string) config('gulfcart.whatsapp.token'))->timeout(10)
            ->post(sprintf('https://graph.facebook.com/%s/%s/messages', config('gulfcart.whatsapp.api_version', 'v21.0'), config('gulfcart.whatsapp.phone_number_id')), [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($e164Phone, '+'),
                'type' => 'template',
                'template' => ['name' => $templateName, 'language' => ['code' => $locale === 'ar' ? 'ar' : 'en'], 'components' => $components],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp send failed: HTTP '.$response->status().' '.mb_substr((string) $response->json('error.message'), 0, 300));
        }
    }
}
