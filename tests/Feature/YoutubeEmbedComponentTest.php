<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class YoutubeEmbedComponentTest extends TestCase
{
    public function test_it_renders_a_privacy_enhanced_embed_for_the_given_video_id(): void
    {
        $html = Blade::render('<x-youtube-embed video-id="KfVfjL8-R-0" title="My Morning Routine" />');

        $this->assertStringContainsString('src="https://www.youtube-nocookie.com/embed/KfVfjL8-R-0"', $html);
        $this->assertStringContainsString('title="My Morning Routine"', $html);
        $this->assertStringContainsString('allowfullscreen', $html);
    }

    public function test_it_defaults_to_a_generic_title_when_none_is_given(): void
    {
        $html = Blade::render('<x-youtube-embed video-id="KfVfjL8-R-0" />');

        $this->assertStringContainsString('title="Video"', $html);
    }
}
