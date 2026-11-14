<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\Infrastructure\Models\User;

final class EloquentUserDirectory implements UserDirectory
{
    public function contact(string $userId): ?array
    {
        $u = User::query()->find($userId);

        return $u === null ? null : [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'locale' => in_array($u->preferred_locale, ['ar', 'en'], true) ? $u->preferred_locale : 'en',
        ];
    }
}
