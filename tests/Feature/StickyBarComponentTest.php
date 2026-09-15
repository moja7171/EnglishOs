<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StickyBarComponentTest extends TestCase
{
    public function test_it_renders_its_slot_as_an_ordinary_in_page_row(): void
    {
        $html = Blade::render('<x-sticky-bar>Hello</x-sticky-bar>');

        $this->assertStringContainsString('Hello', $html);
        // Deliberately NOT pinned to the viewport any more: the learner
        // found the strip tracking the scroll distracting. See the
        // component's own note.
        $this->assertStringNotContainsString('sticky', $html);
        $this->assertStringNotContainsString('backdrop-blur', $html);
    }

    public function test_the_hint_only_shows_while_the_action_is_not_ready(): void
    {
        $html = Blade::render('<x-sticky-bar ready-when="done" hint="Finish it first">Go</x-sticky-bar>');

        $this->assertStringContainsString('Finish it first', $html);
        $this->assertStringContainsString('x-show="! (done)"', $html);
    }
}
