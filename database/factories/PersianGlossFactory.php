<?php

namespace Database\Factories;

use App\Models\PersianGloss;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersianGloss>
 */
class PersianGlossFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'word' => fake()->unique()->word(),
            'pos' => 'noun',
            'words' => ['کلمه'],
        ];
    }
}
