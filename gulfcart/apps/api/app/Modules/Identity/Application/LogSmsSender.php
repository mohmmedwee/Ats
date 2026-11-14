<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Development driver: writes the message to the log. Refused in production so OTP codes are
 * never silently logged instead of delivered.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $e164Phone, string $message): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('No SMS provider configured.');
        }
        Log::info('sms.log_driver', ['to' => substr($e164Phone, 0, 6).'****', 'message' => $message]);
    }
}
