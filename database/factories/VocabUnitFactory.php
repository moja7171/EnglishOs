<?php

namespace Database\Factories;

use App\Models\VocabUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabUnit>
 */
class VocabUnitFactory extends Factory
{
    /**
     * Unit 16 of the book, "Daily routines", in the "Daily life" section.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(5, 94),
            'title' => 'Daily routines',
            'book_section' => 3,
            'book_section_name' => 'Daily life',
            'page' => 39,
        ];
    }
}
