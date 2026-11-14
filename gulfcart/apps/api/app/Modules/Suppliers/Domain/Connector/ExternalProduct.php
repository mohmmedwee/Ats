<?php

namespace App\Modules\Suppliers\Domain\Connector;

final class ExternalProduct
{
    /** @param list<ExternalVariant> $variants */
    public function __construct(
        public readonly string $externalProductId,
        public readonly ?string $title,
        public readonly array $variants,
    ) {}

    public function variant(string $externalVariantId): ?ExternalVariant
    {
        foreach ($this->variants as $v) {
            if ($v->externalVariantId === $externalVariantId) {
                return $v;
            }
        }

        return null;
    }
}
