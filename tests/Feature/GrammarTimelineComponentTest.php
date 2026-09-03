<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class GrammarTimelineComponentTest extends TestCase
{
    public function test_it_renders_a_span_with_its_label_and_position(): void
    {
        $spans = [['label' => 'Last week', 'start' => 10, 'end' => 40]];

        $html = Blade::render('<x-grammar-timeline :spans="$spans" />', ['spans' => $spans]);

        $this->assertStringContainsString('Last week', $html);
        $this->assertStringContainsString('left: 10%', $html);
        $this->assertStringContainsString('width: 30%', $html);
    }

    public function test_it_renders_a_marker_with_its_label_and_position(): void
    {
        $markers = [['label' => 'Yesterday', 'position' => 70]];

        $html = Blade::render('<x-grammar-timeline :markers="$markers" />', ['markers' => $markers]);

        $this->assertStringContainsString('Yesterday', $html);
        $this->assertStringContainsString('left: 70%', $html);
    }

    public function test_it_always_shows_a_now_label_at_the_right_edge(): void
    {
        $html = Blade::render('<x-grammar-timeline />');

        $this->assertStringContainsString('Now', $html);
    }

    public function test_the_now_label_is_customizable(): void
    {
        $html = Blade::render('<x-grammar-timeline now-label="Right now" />');

        $this->assertStringContainsString('Right now', $html);
    }

    public function test_a_span_can_override_its_default_accent_color(): void
    {
        $spans = [['label' => 'Since Monday', 'start' => 20, 'end' => 100, 'color' => 'bg-success dark:bg-success-dark']];

        $html = Blade::render('<x-grammar-timeline :spans="$spans" />', ['spans' => $spans]);

        $this->assertStringContainsString('bg-success', $html);
    }

    public function test_renders_with_no_content_without_error(): void
    {
        $html = Blade::render('<x-grammar-timeline :spans="[]" :markers="[]" />');

        $this->assertStringContainsString('Now', $html);
    }
}
