<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ProgressRingComponentTest extends TestCase
{
    public function test_it_renders_the_percentage_label(): void
    {
        $html = Blade::render('<x-progress-ring :percent="65" />');

        $this->assertStringContainsString('65%', $html);
    }

    public function test_it_clamps_a_percentage_above_100(): void
    {
        $html = Blade::render('<x-progress-ring :percent="150" />');

        $this->assertStringContainsString('100%', $html);
        $this->assertStringNotContainsString('150%', $html);
    }

    public function test_it_clamps_a_negative_percentage_to_zero(): void
    {
        $html = Blade::render('<x-progress-ring :percent="-10" />');

        $this->assertStringContainsString('0%', $html);
    }

    public function test_it_defaults_to_zero_percent(): void
    {
        $html = Blade::render('<x-progress-ring />');

        $this->assertStringContainsString('0%', $html);
    }
}
