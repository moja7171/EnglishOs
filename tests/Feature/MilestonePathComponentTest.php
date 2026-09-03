<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MilestonePathComponentTest extends TestCase
{
    public function test_it_shows_days_remaining_to_the_next_milestone(): void
    {
        $html = Blade::render('<x-milestone-path :current-streak="5" />');

        $this->assertStringContainsString('2 days to your 7-day badge', $html);
    }

    public function test_it_celebrates_once_every_milestone_is_earned(): void
    {
        $html = Blade::render('<x-milestone-path :current-streak="150" />');

        $this->assertStringContainsString("You've earned every streak badge", $html);
    }

    public function test_reached_tiers_render_as_filled(): void
    {
        $html = Blade::render('<x-milestone-path :current-streak="10" />');

        $this->assertStringContainsString('border-accent bg-accent', $html);
        $this->assertStringContainsString('border-line bg-ground', $html);
    }
}
