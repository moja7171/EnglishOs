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

    /**
     * "Full screen when I press play" is remembered on the device, but only
     * the classic player has the checkbox and an Exit button. A podcast-style
     * lesson player must never jump into that full screen (it had no way out
     * and left its controls glued to the bottom edge).
     */
    public function test_a_podcast_player_never_switches_to_full_screen_on_play(): void
    {
        $segments = "[['text' => 'Hello.', 'start' => 0.0, 'end' => 1.0]]";

        $podcast = Blade::render('<x-audio-player podcast url="/a.mp3" :segments="'.$segments.'" />');
        $classic = Blade::render('<x-audio-player url="/a.mp3" :segments="'.$segments.'" />');

        $this->assertStringContainsString('this.segments.length && false) this.focus = true', $podcast);
        $this->assertStringContainsString('this.segments.length && true) this.focus = true', $classic);
    }
}
