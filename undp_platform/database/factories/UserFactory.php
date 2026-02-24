<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $phone = fake()->unique()->numerify('9########');

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'country_code' => '+218',
            'phone' => $phone,
            'phone_e164' => '+218'.$phone,
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::REPORTER->value,
            'status' => UserStatus::ACTIVE->value,
            'preferred_locale' => fake()->randomElement(['ar', 'en']),
            'remember_token' => Str::random(10),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (Schema::hasTable('roles')) {
                $user->syncRoleSafely($user->role);
            }
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
