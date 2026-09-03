<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ActivityHeatmapComponentTest extends TestCase
{
    public function test_it_renders_the_default_caption_and_legend(): void
    {
        $calendar = [
            ['date' => '2026-09-01', 'label' => 'Sep 1, 2026', 'active' => true, 'future' => false],
            ['date' => '2026-09-02', 'label' => 'Sep 2, 2026', 'active' => false, 'future' => false],
        ];

        $html = Blade::render('<x-activity-heatmap :calendar="$calendar" />', ['calendar' => $calendar]);

        $this->assertStringContainsString('Last 12 weeks', $html);
        $this->assertStringContainsString('No activity', $html);
        $this->assertStringContainsString('Practiced', $html);
        $this->assertStringContainsString('Sep 1, 2026 — practiced', $html);
    }

    public function test_a_custom_caption_can_be_passed(): void
    {
        $html = Blade::render('<x-activity-heatmap :calendar="[]" caption="Friend activity" />');

        $this->assertStringContainsString('Friend activity', $html);
    }
}
