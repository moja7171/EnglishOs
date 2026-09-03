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
        $this->assertStringContainsString('Stop (', $html);
    }

    public function test_the_compact_style_shows_icon_only_buttons_with_no_labels(): void
    {
        $html = Blade::render('<x-voice-recorder field="audioFile" :compact="true" />');

        $this->assertStringNotContainsString('Record</button>', $html);
        $this->assertStringNotContainsString('Recording saved', $html);
        $this->assertStringNotContainsString('Listen back', $html);
        $this->assertStringContainsString('Record a voice message', $html);
    }
}
