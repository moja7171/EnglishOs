<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MissionJourneyMapComponentTest extends TestCase
{
    public function test_it_renders_each_days_label_and_status(): void
    {
        $dayProgress = [
            ['label' => 'Foundation', 'done' => true, 'current' => false, 'completedAt' => now()],
            ['label' => 'Build', 'done' => false, 'current' => true, 'completedAt' => null],
            ['label' => 'Practice', 'done' => false, 'current' => false, 'completedAt' => null],
        ];

        $html = Blade::render('<x-mission-journey-map :day-progress="$dayProgress" />', ['dayProgress' => $dayProgress]);

        $this->assertStringContainsString('Day 1 · Foundation', $html);
        $this->assertStringContainsString('Day 2 · Build', $html);
        $this->assertStringContainsString('Day 3 · Practice', $html);
        $this->assertStringContainsString('In progress', $html);
    }

    public function test_it_never_renders_a_link_to_the_actual_mission(): void
    {
        $dayProgress = [
            ['label' => 'Foundation', 'done' => true, 'current' => false, 'completedAt' => now()],
        ];

        $html = Blade::render('<x-mission-journey-map :day-progress="$dayProgress" />', ['dayProgress' => $dayProgress]);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('href', $html);
    }

    public function test_an_empty_day_progress_renders_nothing(): void
    {
        $html = Blade::render('<x-mission-journey-map :day-progress="[]" />');

        $this->assertSame('', trim($html));
    }
}
