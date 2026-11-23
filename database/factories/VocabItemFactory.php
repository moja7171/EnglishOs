<?php

namespace Database\Factories;

use App\Models\VocabItem;
use App\Models\VocabText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VocabItem>
 */
class VocabItemFactory extends Factory
{
    /**
     * "wake up" in the first sentence of the VocabText factory's text.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vocab_text_id' => VocabText::factory(),
            'position' => 1,
            'phrase' => 'wake up',
            'start' => 10,
            'end' => 17,
            'sentence_index' => 0,
            'ipa' => 'weɪk ʌp',
            'gloss' => 'stop sleeping',
            'phrase_fa' => null,
            'audio_start' => 2.0,
            'audio_end' => 2.8,
        ];
    }
}
