<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ConfidenceTranscriptComponentTest extends TestCase
{
    public function test_high_confidence_segments_get_no_color_class(): void
    {
        $segments = [['text' => 'This part was clear.', 'confidence' => 'high']];

        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => $segments]);

        $this->assertStringContainsString('This part was clear.', $html);
        $this->assertStringNotContainsString('text-amber-600', $html);
        $this->assertStringNotContainsString('text-red-600', $html);
    }

    public function test_medium_and_low_confidence_segments_get_their_own_color(): void
    {
        $segments = [
            ['text' => 'Mumbled bit.', 'confidence' => 'medium'],
            ['text' => 'Unclear bit.', 'confidence' => 'low'],
        ];

        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => $segments]);

        $this->assertStringContainsString('text-amber-600', $html);
        $this->assertStringContainsString('text-red-600', $html);
    }

    public function test_a_fully_high_confidence_transcript_has_no_explanatory_caption(): void
    {
        $segments = [['text' => 'Everything was clear.', 'confidence' => 'high']];

        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => $segments]);

        $this->assertStringNotContainsString('might be worth saying again', $html);
    }

    public function test_falls_back_to_the_plain_uncolored_transcript_when_segments_are_empty(): void
    {
        $html = Blade::render(
            '<x-confidence-transcript :segments="$segments" :fallback="$fallback" />',
            ['segments' => [], 'fallback' => 'The plain saved transcript.']
        );

        $this->assertStringContainsString('The plain saved transcript.', $html);
        $this->assertStringNotContainsString('text-amber-600', $html);
        $this->assertStringNotContainsString('text-red-600', $html);
    }

    public function test_renders_nothing_when_both_segments_and_fallback_are_empty(): void
    {
        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => []]);

        $this->assertStringNotContainsString('<p', $html);
    }
}
