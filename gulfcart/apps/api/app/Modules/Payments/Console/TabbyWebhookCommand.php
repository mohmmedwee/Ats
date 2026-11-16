<?php

namespace App\Modules\Payments\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Registers the Tabby webhook for each configured country, with the secret header GulfCart checks. */
final class TabbyWebhookCommand extends Command
{
    protected $signature = 'payments:tabby-webhook';

    protected $description = 'Register the Tabby webhook (run once after setting TABBY_* in the environment)';

    public function handle(): int
    {
        $secret = (string) config('gulfcart.payments.tabby.webhook_secret');
        $key = (string) config('gulfcart.payments.tabby.secret_key');
        if ($secret === '' || $key === '') {
            $this->error('Set TABBY_SECRET_KEY and TABBY_WEBHOOK_SECRET first.');

            return self::FAILURE;
        }
        $url = rtrim((string) (config('gulfcart.payments.tabby.webhook_base_url') ?: config('app.url')), '/').'/api/v1/webhooks/payments/tabby';
        $failed = false;
        foreach ((array) config('gulfcart.payments.tabby.merchant_codes') as $country => $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }
            $base = rtrim((string) (config("gulfcart.payments.tabby.base_urls.{$country}") ?? 'https://api.tabby.ai'), '/');
            $res = Http::withToken($key)->withHeaders(['X-Merchant-Code' => $code])->acceptJson()
                ->post($base.'/api/v1/webhooks', ['url' => $url, 'is_test' => config('gulfcart.payments.tabby.test_mode', true), 'header' => ['title' => (string) config('gulfcart.payments.tabby.webhook_header'), 'value' => $secret]]);
            $this->line(sprintf('%s (%s): %s', $country, $code, $res->successful() ? 'registered '.$url : 'failed '.$res->status().' '.$res->body()));
            $failed = $failed || $res->failed();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
