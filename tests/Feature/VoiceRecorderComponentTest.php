<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class VoiceRecorderComponentTest extends TestCase
{
    public function test_the_default_style_shows_labeled_record_and_stop_buttons(): void
    {
        $html = Blade::render('<x-voice-recorder field="audioFile" />');

        $this->assertStringContainsString('Record</button>', $html);
        $this->assertStringContainsString('Stop</button>', $html);
    }

    public function test_the_compact_style_shows_icon_only_buttons_with_no_labels(): void
    {
        $html = Blade::render('<x-voice-recorder field="audioFile" :compact="true" />');

        $this->assertStringNotContainsString('Record</button>', $html);
        $this->assertStringNotContainsString('Recording saved', $html);
        $this->assertStringNotContainsString('Listen back', $html);
        $this->assertStringContainsString('Record a voice message', $html);
    }

    public function test_the_big_style_is_a_72px_mic_with_a_soft_ring_not_a_3d_shadow(): void
    {
        $html = Blade::render('<x-voice-recorder field="audioFile" big />');

        $this->assertStringContainsString('aria-label="Record"', $html);
        $this->assertStringContainsString('mic-ring inline-flex h-18 w-18', $html);
        $this->assertStringContainsString("state === 'recording' ? 'animate-mic-breathe' : 'mic-ring'", $html);
        $this->assertStringNotContainsString('h-24 w-24', $html);
        $this->assertStringNotContainsString('shadow-[0_5px_0', $html);
    }

    public function test_both_styles_offer_a_way_to_cancel_a_recording_in_progress(): void
    {
        $default = Blade::render('<x-voice-recorder field="audioFile" />');
        $compact = Blade::render('<x-voice-recorder field="audioFile" :compact="true" />');

        $this->assertStringContainsString('cancelRecording', $default);
        $this->assertStringContainsString('cancelRecording', $compact);
    }

    public function test_both_styles_can_pause_a_take_and_review_it_before_it_is_uploaded(): void
    {
        foreach (['<x-voice-recorder field="audioFile" />', '<x-voice-recorder field="audioFile" :compact="true" />'] as $template) {
            $html = Blade::render($template);

            $this->assertStringContainsString('pauseRecording', $html);
            $this->assertStringContainsString('resumeRecording', $html);
            $this->assertStringContainsString('x-on:click="sendRecording"', $html);
            $this->assertStringContainsString('MAX_SECONDS: 180', $html);
        }
    }
}
