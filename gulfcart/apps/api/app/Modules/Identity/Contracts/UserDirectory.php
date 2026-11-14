<?php

namespace App\Modules\Identity\Contracts;

interface UserDirectory
{
    /** @return array{id: string, name: string, email: string|null, phone: string|null, locale: string}|null */
    public function contact(string $userId): ?array;
}
