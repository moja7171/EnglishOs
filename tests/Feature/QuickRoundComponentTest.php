<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class QuickRoundComponentTest extends TestCase
{
    public function test_it_renders_every_cards_prompt_and_options_into_the_alpine_state(): void
    {
        $cards = [
            ['prompt' => 'oversleep', 'options' => ['to sleep too long by accident', 'to wake up early'], 'correct' => 0],
            ['prompt' => 'sleep in', 'options' => ['to stay in bed longer than usual', 'to go to bed early'], 'correct' => 0],
        ];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        $this->assertStringContainsString('oversleep', $html);
        $this->assertStringContainsString('sleep in', $html);
        $this->assertStringContainsString('to sleep too long by accident', $html);
        $this->assertStringContainsString('to stay in bed longer than usual', $html);
    }

    public function test_it_wires_the_skip_and_completion_events(): void
    {
        $cards = [['prompt' => 'word', 'options' => ['a', 'b'], 'correct' => 0]];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        $this->assertStringContainsString('Skip', $html);
        $this->assertStringContainsString('quick-round-skipped', $html);
        $this->assertStringContainsString('quick-round-completed', $html);
    }

    public function test_on_complete_and_on_skip_alpine_statements_are_embedded(): void
    {
        $cards = [['prompt' => 'word', 'options' => ['a', 'b'], 'correct' => 0]];

        $html = Blade::render(
            '<x-quick-round :cards="$cards" on-complete="\$wire.call(\'unlockWriting\')" on-skip="\$wire.call(\'markSkipped\')" />',
            ['cards' => $cards]
        );

        $this->assertStringContainsString('$wire.call(', $html);
        $this->assertStringContainsString('unlockWriting', $html);
        $this->assertStringContainsString('markSkipped', $html);
    }

    public function test_an_empty_card_list_renders_nothing(): void
    {
        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => []]);

        $this->assertSame('', trim($html));
    }

    public function test_extra_classes_are_merged_onto_the_root(): void
    {
        $cards = [['prompt' => 'word', 'options' => ['a', 'b'], 'correct' => 0]];

        $html = Blade::render('<x-quick-round :cards="$cards" class="mt-4" />', ['cards' => $cards]);

        $this->assertStringContainsString('mt-4', $html);
        $this->assertStringContainsString('rounded-2xl', $html);
    }
}
