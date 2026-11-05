<?php

namespace Database\Factories;

use App\Models\AiPractice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiPractice>
 */
class AiPracticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'kind' => AiPractice::KIND_PRACTICE,
            'days' => null,
            'deck' => [
                AiPractice::STAGE_VOCABULARY => [
                    ['prompt' => 'صبحانه', 'answer' => 'breakfast', 'meaning' => 'the first meal', 'example' => 'I eat breakfast at seven.', 'source' => 'M01 · Day 1', 'key' => 'v:breakfast'],
                    ['prompt' => 'ناهار', 'answer' => 'lunch', 'meaning' => 'the midday meal', 'example' => 'We have lunch at noon.', 'source' => 'M01 · Day 1', 'key' => 'v:lunch'],
                ],
                AiPractice::STAGE_GRAMMAR => [
                    ['prompt' => 'She go to work.', 'answer' => 'She goes to work.', 'source' => 'M01 · Day 2', 'key' => 'g:1'],
                ],
                AiPractice::STAGE_SPEAKING => [
                    ['question' => 'What time do you wake up?', 'answer' => 'I wake up at seven.', 'words' => [], 'source' => 'M01 · Day 3', 'key' => 's:1'],
                ],
            ],
            'stage' => AiPractice::STAGE_VOCABULARY,
        ];
    }
}
