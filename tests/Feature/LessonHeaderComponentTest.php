<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LessonHeaderComponentTest extends TestCase
{
    public function test_it_shows_close_a_progress_bar_and_the_counter(): void
    {
        $html = Blade::render('<x-lesson-header close="/home" :progress="0.375" counter="3/8" />');

        $this->assertStringContainsString('href="/home"', $html);
        $this->assertStringContainsString('aria-label="Close"', $html);
        $this->assertStringContainsString('width: 37.5%', $html);
        $this->assertStringContainsString('3/8', $html);
    }

    public function test_close_can_run_an_alpine_action_and_the_bar_can_follow_a_live_share(): void
    {
        $html = Blade::render('<x-lesson-header close-click="$wire.stop()" progress-expr="share" />');

        $this->assertStringContainsString('x-on:click="$wire.stop()"', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString("x-effect=\"if ((share) !== null) \$el.style.width = Math.round((share) * 1000) / 10 + '%'\"", html_entity_decode($html, ENT_QUOTES));
    }

    public function test_progress_outside_zero_to_one_is_clamped(): void
    {
        $this->assertStringContainsString('width: 100%', Blade::render('<x-lesson-header close="/" :progress="1.4" />'));
        $this->assertStringContainsString('width: 0%', Blade::render('<x-lesson-header close="/" :progress="-1" />'));
    }
}
