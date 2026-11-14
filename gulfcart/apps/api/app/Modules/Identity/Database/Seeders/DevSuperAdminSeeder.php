<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development only: creates a super admin from GULFCART_DEV_ADMIN_EMAIL /
 * GULFCART_DEV_ADMIN_PASSWORD. MFA enrolment is still required on first login.
 */
final class DevSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('gulfcart.dev_admin.email');
        $password = config('gulfcart.dev_admin.password');

        if (! app()->environment('local') || ! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            return;
        }

        $user = User::query()->firstOrCreate(['email' => mb_strtolower($email)], [
            'name' => 'Super Admin',
            'password' => $password,
            'type' => UserType::Staff,
            'status' => 'active',
        ]);
        $user->syncRoles(['super_admin']);

        $this->command?->info("Dev super admin: {$user->email}");
    }
}
