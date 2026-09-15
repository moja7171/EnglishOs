<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ContinueButtonComponentTest extends TestCase
{
    public function test_it_renders_an_ordinary_in_page_continue_button(): void
    {
        $html = Blade::render('<x-continue-button on-click="$wire.save()" wire-target="save" />');

        $this->assertStringContainsString('Continue', $html);
        $this->assertStringContainsString('$wire.save()', $html);
        $this->assertStringNotContainsString('sticky', $html);
    }

    public function test_it_is_disabled_until_ready_and_says_what_is_missing(): void
    {
        $html = Blade::render('<x-continue-button on-click="$wire.save()" wire-target="save" ready-when="filledCount >= 3" hint="Write 3 sentences to continue" />');

        $this->assertStringContainsString('x-bind:disabled="! (filledCount &gt;= 3)"', $html);
        $this->assertStringContainsString('Write 3 sentences to continue', $html);
    }

    public function test_a_custom_loading_label_is_used(): void
    {
        $html = Blade::render('<x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Checking…" />');

        $this->assertStringContainsString('Checking…', $html);
    }
}
