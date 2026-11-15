<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Creates (or promotes) the store owner and emails a link to set the password. */
final class CreateOwnerCommand extends Command
{
    protected $signature = 'users:create-owner {email} {name}';

    protected $description = 'Create the owner (super admin) account and email a password link';

    public function handle(): int
    {
        $email = mb_strtolower((string) $this->argument('email'));
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => (string) $this->argument('name'), 'password' => Str::password(32),
            'type' => UserType::Staff, 'status' => 'active', 'preferred_locale' => 'ar',
        ]);
        $user->forceFill(['type' => UserType::Staff, 'status' => 'active'])->save();
        $user->syncRoles(['super_admin']);
        Password::broker()->sendResetLink(['email' => $email]);
        $this->info("Owner {$email} is ready. A password link was emailed; set up two-step verification at first sign-in.");

        return self::SUCCESS;
    }
}
