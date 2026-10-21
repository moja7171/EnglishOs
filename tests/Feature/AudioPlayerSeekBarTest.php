<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AudioPlayerSeekBarTest extends TestCase
{
    /**
     * A seek bar snapped to whole seconds can never reach a 7.4s
     * recording's end — the thumb stopped at 7s, visibly short.
     */
    public function test_both_players_seek_bars_can_reach_a_fractional_end(): void
    {
        $templates = [
            '<x-audio-player url="/voice.webm" />',
            '<x-audio-player-compact url="/voice.webm" />',
        ];

        foreach ($templates as $template) {
            $html = Blade::render($template);

            $this->assertStringContainsString('step="any"', $html);
            $this->assertStringNotContainsString('step="1"', $html);
        }
    }
}
