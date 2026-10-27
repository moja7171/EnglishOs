<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BottomSheetComponentTest extends TestCase
{
    public function test_it_opens_and_closes_through_the_named_alpine_flag(): void
    {
        $html = Blade::render('<x-bottom-sheet show="ideas" label="Ideas">Ask about her weekend</x-bottom-sheet>');

        $this->assertStringContainsString('x-show="ideas"', $html);
        $this->assertStringContainsString('x-on:click="ideas = false"', $html);
        $this->assertStringContainsString('x-swipe-close="ideas = false"', $html);
        $this->assertStringContainsString('x-back-close="{ open: ideas, close: () => ideas = false }"', $html);
        $this->assertStringContainsString('aria-label="Ideas"', $html);
        $this->assertStringContainsString('Ask about her weekend', $html);
    }

    public function test_the_answer_sheet_shows_its_verdict_and_continue(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-answer-sheet when="checked" tone="right ? 'good' : 'almost'" title="right ? 'Right!' : 'Almost!'">
                go → went
                <x-slot:actions><button>Continue</button></x-slot:actions>
            </x-answer-sheet>
            BLADE);

        $this->assertStringContainsString('x-show="checked"', $html);
        $this->assertStringContainsString('x-bind:data-tone="'.e("right ? 'good' : 'almost'").'"', $html);
        $this->assertStringContainsString('x-text="'.e("right ? 'Right!' : 'Almost!'").'"', $html);
        $this->assertStringContainsString('go → went', $html);
        $this->assertStringContainsString('<button>Continue</button>', $html);
    }
}
