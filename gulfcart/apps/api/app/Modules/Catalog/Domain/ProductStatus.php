<?php

namespace App\Modules\Catalog\Domain;

use App\Shared\StateMachine\StateMachine;

final class ProductStatus extends StateMachine
{
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const ARCHIVED = 'archived';

    protected const TRANSITIONS = [
        self::DRAFT => [self::PENDING_REVIEW, self::ARCHIVED],
        self::PENDING_REVIEW => [self::PUBLISHED, self::DRAFT, self::ARCHIVED],
        self::PUBLISHED => [self::UNPUBLISHED, self::ARCHIVED],
        self::UNPUBLISHED => [self::PENDING_REVIEW, self::DRAFT, self::ARCHIVED],
        self::ARCHIVED => [],
    ];

    protected static function entity(): string
    {
        return 'product';
    }
}
