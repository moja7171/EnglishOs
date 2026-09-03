<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StickyBarComponentTest extends TestCase
{
    public function test_it_wraps_its_slot_content_with_sticky_positioning(): void
    {
        $html = Blade::render('<x-sticky-bar>Hello</x-sticky-bar>');

        $this->assertStringContainsString('sticky bottom-0', $html);
        $this->assertStringContainsString('Hello', $html);
    }
}
