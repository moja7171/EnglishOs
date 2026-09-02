<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
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
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Explicit, not left to the DB column default — Eloquent
            // doesn't re-fetch a Postgres row's own defaults after
            // insert(), so a freshly created instance would otherwise
            // read these as null in the very same request/test instead
            // of their real value until the next fresh query.
            'cefr_level' => 'B1',
            'avatar_color' => 'accent',
            'avatar_style' => 'initial',
            'gender' => 'unspecified',
            'discoverable' => true,
            'celebrated_streak_milestone' => 0,
        ];
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
