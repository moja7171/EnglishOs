<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StreakFlameTest extends TestCase
{
    public function test_the_flame_only_lights_up_when_asked_to(): void
    {
        $this->assertStringNotContainsString(
            'animate-flame-pop',
            Blade::render('<x-streak-flame :streak="10" />')
        );

        $this->assertStringContainsString(
            'animate-flame-pop',
            Blade::render('<x-streak-flame :streak="10" animated />')
        );
    }

    public function test_no_flame_is_drawn_without_a_streak(): void
    {
        $this->assertSame('', trim(Blade::render('<x-streak-flame :streak="0" animated />')));
    }

    public function test_the_progress_ring_starts_empty_and_fills_on_arrival(): void
    {
        $html = Blade::render('<x-progress-ring :percent="50" />');

        $this->assertStringContainsString('x-data="{ filled: false }"', $html);
        $this->assertStringContainsString('x-bind:stroke-dashoffset="filled ?', $html);
        $this->assertStringContainsString('50%', $html);
    }
}
