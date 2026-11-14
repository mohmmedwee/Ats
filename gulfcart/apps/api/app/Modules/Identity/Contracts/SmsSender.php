<?php

namespace App\Modules\Identity\Contracts;

/** SMS delivery. Bound to LogSmsSender until an SMS provider is contracted. */
interface SmsSender
{
    public function send(string $e164Phone, string $message): void;
}
