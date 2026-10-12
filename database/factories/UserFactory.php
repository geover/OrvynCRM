<?php

namespace Database\Factories;

use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => null,                 // credentials live on the central identity: see withLogin()
            'remember_token' => Str::random(10),
            'role' => 'user',
            'status' => 'active',
            'timezone' => 'UTC',
        ];
    }

    /**
     * Give this profile a central identity (email + password) so the person can sign in. In single-database mode there
     * is no membership row; the multi-company tests create real companies instead.
     */
    public function withLogin(string $password = 'password'): static
    {
        return $this->afterCreating(function (User $user) use ($password) {
            $identity = AccountUser::create(['name' => $user->name, 'email' => $user->email, 'password' => $password]);
            $user->forceFill(['central_user_id' => $identity->id])->save();
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
