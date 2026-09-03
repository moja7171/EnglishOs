<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AmbientVideoComponentTest extends TestCase
{
    public function test_it_renders_a_muted_looping_autoplaying_video_when_a_url_is_given(): void
    {
        $html = Blade::render('<x-ambient-video url="https://example.test/clip.mp4" />');

        $this->assertStringContainsString('src="https://example.test/clip.mp4"', $html);
        $this->assertStringContainsString('muted', $html);
        $this->assertStringContainsString('loop', $html);
        $this->assertStringContainsString('autoplay', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_it_renders_nothing_when_the_url_is_null(): void
    {
        $html = Blade::render('<x-ambient-video :url="null" />');

        $this->assertStringNotContainsString('<video', $html);
    }

    public function test_it_renders_nothing_by_default_with_no_url_prop(): void
    {
        $html = Blade::render('<x-ambient-video />');

        $this->assertStringNotContainsString('<video', $html);
    }
}
