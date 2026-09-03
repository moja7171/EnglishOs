<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StreakFlameComponentTest extends TestCase
{
    public function test_nothing_renders_for_a_zero_streak(): void
    {
        $html = Blade::render('<x-streak-flame :streak="0" />');

        $this->assertSame('', trim($html));
    }

    public function test_an_outline_icon_renders_below_the_first_tier(): void
    {
        $html = Blade::render('<x-streak-flame :streak="3" />');

        $this->assertStringContainsString('svg', $html);
    }

    public function test_reaching_100_days_uses_the_gold_tier_color(): void
    {
        $html = Blade::render('<x-streak-flame :streak="100" />');

        $this->assertStringContainsString('text-amber-500', $html);
    }

    public function test_reaching_30_days_uses_the_orange_tier_color(): void
    {
        $html = Blade::render('<x-streak-flame :streak="45" />');

        $this->assertStringContainsString('text-orange-500', $html);
    }

    public function test_reaching_7_days_uses_the_accent_tier_color(): void
    {
        $html = Blade::render('<x-streak-flame :streak="10" />');

        $this->assertStringContainsString('text-accent-ink', $html);
    }
}
