<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SmsSender;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS through a hosted provider, chosen by SMS_PROVIDER:
 *  - twilio:   TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, SMS_SENDER (number or alphanumeric sender ID)
 *  - unifonic: UNIFONIC_APP_SID, SMS_SENDER (approved sender ID); common in Saudi Arabia
 * Gulf regulators require a registered sender ID; register it with the provider before launch.
 */
final class HttpSmsSender implements SmsSender
{
    public function __construct(private readonly string $provider) {}

    public function send(string $e164Phone, string $message): void
    {
        $cfg = (array) config('gulfcart.sms');
        try {
            $response = match ($this->provider) {
                'twilio' => Http::asForm()->timeout(10)
                    ->withBasicAuth((string) $cfg['twilio_sid'], (string) $cfg['twilio_token'])
                    ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['twilio_sid']}/Messages.json", [
                        'To' => $e164Phone, 'From' => $cfg['sender'], 'Body' => $message,
                    ]),
                'unifonic' => Http::asForm()->timeout(10)->post('https://el.cloud.unifonic.com/rest/SMS/messages', [
                    'AppSid' => $cfg['unifonic_app_sid'], 'SenderID' => $cfg['sender'],
                    'Recipient' => ltrim($e164Phone, '+'), 'Body' => $message,
                ]),
                default => throw new \InvalidArgumentException("Unknown SMS provider {$this->provider}."),
            };
        } catch (ConnectionException $e) {
            Log::warning('sms.unreachable', ['provider' => $this->provider, 'error' => $e->getMessage()]);
            throw new ApiException('SERVICE_UNAVAILABLE', 503);
        }

        if ($response->failed() || ($this->provider === 'unifonic' && $response->json('success') === false)) {
            Log::warning('sms.failed', ['provider' => $this->provider, 'status' => $response->status(), 'to' => substr($e164Phone, 0, 6).'****']);
            throw new ApiException('SERVICE_UNAVAILABLE', 503);
        }
    }
}
