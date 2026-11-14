<?php

namespace App\Modules\Suppliers\Application\Connectors\Cj;

use App\Modules\Suppliers\Domain\Connector\ConnectorException;

/** CJ answered and refused the request (validation, not found, account issue). Permanent. */
final class CjApiException extends ConnectorException
{
    /** CJ "not found" codes (product, variant, order). */
    private const NOT_FOUND_CODES = [1600101, 1602000, 1602001, 1602002, 1602003, 1603100];

    public function isNotFound(): bool
    {
        return in_array($this->getCode(), self::NOT_FOUND_CODES, true);
    }
}
