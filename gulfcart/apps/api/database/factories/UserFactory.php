<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\UserType;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'password' => static::$password ??= Hash::make('Password-123'),
            'type' => UserType::Customer,
            'status' => 'active',
            'email_verified_at' => now(),
        ];
    }

    public function staff(): static
    {
        return $this->state(fn () => ['type' => UserType::Staff]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
