<?php

namespace Database\Factories;

use App\Models\VocabularyWord;
use App\Models\WordSentence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WordSentence>
 */
class WordSentenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vocabulary_word_id' => VocabularyWord::factory(),
            'sentence' => fake()->sentence(5),
            'source' => WordSentence::SOURCE_AI,
        ];
    }

    public function own(): static
    {
        return $this->state(['source' => WordSentence::SOURCE_OWN]);
    }
}
