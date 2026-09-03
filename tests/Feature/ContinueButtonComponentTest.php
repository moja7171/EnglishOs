<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ContinueButtonComponentTest extends TestCase
{
    public function test_it_renders_inside_a_sticky_bar(): void
    {
        $html = Blade::render('<x-continue-button on-click="$wire.save()" wire-target="save" />');

        $this->assertStringContainsString('sticky bottom-0', $html);
        $this->assertStringContainsString('Continue', $html);
        $this->assertStringContainsString('$wire.save()', $html);
    }

    public function test_a_custom_loading_label_is_used(): void
    {
        $html = Blade::render('<x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Checking…" />');

        $this->assertStringContainsString('Checking…', $html);
    }
}
