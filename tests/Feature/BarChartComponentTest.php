<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BarChartComponentTest extends TestCase
{
    public function test_it_renders_every_points_label(): void
    {
        $data = [
            ['label' => 'Aug 3', 'count' => 2],
            ['label' => 'Aug 10', 'count' => 5],
        ];

        $html = Blade::render('<x-bar-chart :data="$data" />', ['data' => $data]);

        $this->assertStringContainsString('Aug 3', $html);
        $this->assertStringContainsString('Aug 10', $html);
        $this->assertStringContainsString('Aug 10: 5', $html);
    }

    public function test_an_empty_series_does_not_error(): void
    {
        $html = Blade::render('<x-bar-chart :data="[]" />');

        $this->assertStringContainsString('flex items-end', $html);
    }
}
