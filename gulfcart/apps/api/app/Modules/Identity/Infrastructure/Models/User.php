<?php

namespace App\Modules\Identity\Infrastructure\Models;

use App\Modules\Identity\Domain\UserType;
use Database\Factories\UserFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property UserType $type
 * @property string $status
 * @property string|null $preferred_locale
 * @property string|null $mfa_secret
 * @property list<string>|null $mfa_recovery_codes
 * @property int|null $mfa_last_timestamp
 * @property Carbon|null $mfa_confirmed_at
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<UserFactory> */
    use CanResetPassword, HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable;

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'phone', 'password', 'type', 'status', 'preferred_locale'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_last_timestamp' => 'integer',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null;
    }

    /** Password reset notifications go to email only. */
    public function getEmailForPasswordReset(): string
    {
        return (string) $this->email;
    }

    public function preferredLocale(): ?string
    {
        return $this->preferred_locale;
    }
}
