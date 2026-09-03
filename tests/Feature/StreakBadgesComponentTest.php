<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StreakBadgesComponentTest extends TestCase
{
    public function test_no_badges_render_below_the_first_tier(): void
    {
        $html = Blade::render('<x-streak-badges :longest-streak="6" />');

        $this->assertStringNotContainsString('7-day streak', $html);
    }

    public function test_only_earned_tiers_render(): void
    {
        $html = Blade::render('<x-streak-badges :longest-streak="10" />');

        $this->assertStringContainsString('7-day streak', $html);
        $this->assertStringNotContainsString('30-day streak', $html);
        $this->assertStringNotContainsString('100-day streak', $html);
    }

    public function test_all_three_tiers_render_once_100_is_reached(): void
    {
        $html = Blade::render('<x-streak-badges :longest-streak="120" />');

        $this->assertStringContainsString('7-day streak', $html);
        $this->assertStringContainsString('30-day streak', $html);
        $this->assertStringContainsString('100-day streak', $html);
    }
}
