<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ConfidenceTranscriptComponentTest extends TestCase
{
    /**
     * Learners read the old amber/red "Whisper wasn't sure" colors as
     * mistakes, so every segment is now plain text, with no caption.
     */
    public function test_unsure_segments_are_joined_as_plain_text_with_no_colors_or_caption(): void
    {
        $segments = [
            ['text' => 'Clear bit.', 'confidence' => 'high'],
            ['text' => 'Mumbled bit.', 'confidence' => 'medium'],
            ['text' => 'Unclear bit.', 'confidence' => 'low'],
        ];

        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => $segments]);

        $this->assertStringContainsString('Clear bit. Mumbled bit. Unclear bit.', $html);
        $this->assertStringNotContainsString('text-warning-ink', $html);
        $this->assertStringNotContainsString('text-danger-ink', $html);
        $this->assertStringNotContainsString('might be worth saying again', $html);
    }

    public function test_falls_back_to_the_plain_uncolored_transcript_when_segments_are_empty(): void
    {
        $html = Blade::render(
            '<x-confidence-transcript :segments="$segments" :fallback="$fallback" />',
            ['segments' => [], 'fallback' => 'The plain saved transcript.']
        );

        $this->assertStringContainsString('The plain saved transcript.', $html);
        $this->assertStringNotContainsString('text-warning-ink', $html);
        $this->assertStringNotContainsString('text-danger-ink', $html);
    }

    public function test_renders_nothing_when_both_segments_and_fallback_are_empty(): void
    {
        $html = Blade::render('<x-confidence-transcript :segments="$segments" />', ['segments' => []]);

        $this->assertStringNotContainsString('<p', $html);
    }
}
