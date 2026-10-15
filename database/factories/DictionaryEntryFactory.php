<?php

namespace Database\Factories;

use App\Models\DictionaryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DictionaryEntry>
 */
class DictionaryEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'word' => fake()->unique()->word(),
            'phonetic' => '/wɜːd/',
            'audio_url' => null,
            'meanings' => [
                [
                    'pos' => 'noun',
                    'definitions' => [
                        ['definition' => 'a single unit of language', 'example' => 'Say that word again.'],
                    ],
                ],
            ],
            'translation_fa' => 'کلمه',
        ];
    }

    public function unknownToDictionary(): static
    {
        return $this->state(fn () => ['meanings' => [], 'phonetic' => null, 'audio_url' => null]);
    }

    public function withoutTranslation(): static
    {
        return $this->state(fn () => ['translation_fa' => null]);
    }
}
