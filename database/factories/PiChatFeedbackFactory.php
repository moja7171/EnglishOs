<?php

namespace Database\Factories;

use App\Models\PiChatFeedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PiChatFeedback>
 */
class PiChatFeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'learner_id' => User::factory(),
            'mission_code' => 'M01',
            'day_number' => 1,
            'chat' => "My words:\nRound 1 — Q: What did you do? / Me: I go to the park yesterday.",
            'feedback' => [
                'summary' => 'Clear answers.',
                'severity' => 'minor',
                'sentences' => [
                    ['original' => 'I go to the park yesterday.', 'status' => 'fix', 'corrected' => 'I went to the park yesterday.', 'why' => 'Past time needs the past form.', 'pattern' => 'past simple'],
                ],
                'positives' => [],
                'negatives' => [],
                'techniques' => [],
                'better_version' => null,
            ],
        ];
    }
}
