<?php

namespace Database\Factories;

use App\Models\VocabText;
use App\Models\VocabUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabText>
 */
class VocabTextFactory extends Factory
{
    /**
     * Section A of unit 16 shortened to two sentences, said by an mp3.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vocab_unit_id' => VocabUnit::factory(),
            'letter' => 'A',
            'title' => 'Sleep',
            'kind' => 'numbered',
            'body' => 'I usually wake up about 7.30 am. I get up a few minutes later.',
            'sentences' => [
                ['text' => 'I usually wake up about 7.30 am.', 'start' => 0, 'end' => 32, 'audio_start' => 1.0, 'audio_end' => 4.0],
                ['text' => 'I get up a few minutes later.', 'start' => 33, 'end' => 62, 'audio_start' => 4.5, 'audio_end' => 7.0],
            ],
            'rows' => null,
            'notes' => null,
            'audio_file' => 'U_016.A.mp3',
        ];
    }
}
