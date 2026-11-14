<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class CancellationResult
{
    public function __construct(public readonly bool $cancelled, public readonly ?string $reason = null) {}
}
