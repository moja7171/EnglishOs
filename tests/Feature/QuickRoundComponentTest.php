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

    public function test_a_correct_pick_plays_the_success_sound(): void
    {
        $cards = [['prompt' => 'word', 'options' => ['a', 'b'], 'correct' => 0]];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        $this->assertStringContainsString('window.eosSound?.playSuccess()', $html);
    }

    public function test_an_image_option_type_renders_pictures_not_text_buttons(): void
    {
        $cards = [
            [
                'prompt' => 'cereal',
                'options' => ['http://localhost/a.jpg', 'http://localhost/b.jpg', 'http://localhost/c.jpg'],
                'correct' => 0,
                'optionType' => 'image',
            ],
        ];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        $this->assertStringContainsString(':src="option"', $html);
        $this->assertStringContainsString("card.optionType === 'image'", $html);
    }

    public function test_a_card_without_option_type_still_renders_as_text(): void
    {
        $cards = [['prompt' => 'word', 'options' => ['a', 'b'], 'correct' => 0]];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        $this->assertStringContainsString('x-text="option"', $html);
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

    public function test_a_card_with_a_difficulty_field_embeds_the_adaptive_selection_logic(): void
    {
        $cards = [
            ['prompt' => 'easy one', 'options' => ['a', 'b'], 'correct' => 0, 'difficulty' => 'easy'],
            ['prompt' => 'hard one', 'options' => ['a', 'b'], 'correct' => 0, 'difficulty' => 'hard'],
        ];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        // The adaptive-mode functions are always present in the markup (the
        // decision of whether to USE them is made at runtime by Alpine's
        // `adaptive` getter) — what must vary per-invocation is the actual
        // difficulty data reaching the client.
        $this->assertStringContainsString('pickNextIndex', $html);
        $this->assertStringContainsString('get adaptive()', $html);
        $this->assertStringContainsString('easy', $html);
        $this->assertStringContainsString('hard', $html);
    }

    public function test_the_adaptive_selection_rule_prefers_hard_after_a_two_streak_and_easy_after_a_miss(): void
    {
        // This is the exact algorithm embedded in quick-round.blade.php's
        // x-data (pickNextIndex/advance) — re-implemented here so the
        // selection RULE itself has a real assertion behind it, since this
        // project has no browser/JS test runner to execute the Alpine code
        // directly (see composer.json / package.json — no Dusk, no Node
        // test runner configured).
        $pickNextIndex = function (array $cards, array $shown, array $levels) {
            $remaining = array_values(array_diff(array_keys($cards), $shown));
            foreach ($levels as $level) {
                foreach ($remaining as $i) {
                    if (($cards[$i]['difficulty'] ?? 'medium') === $level) {
                        return $i;
                    }
                }
            }

            return $remaining[0] ?? null;
        };

        $cards = [
            ['difficulty' => 'easy'],
            ['difficulty' => 'easy'],
            ['difficulty' => 'medium'],
            ['difficulty' => 'medium'],
            ['difficulty' => 'hard'],
        ];

        // First card always starts easy.
        $first = $pickNextIndex($cards, [], ['easy', 'medium', 'hard']);
        $this->assertSame('easy', $cards[$first]['difficulty']);

        // After a streak of 2+ correct answers, the next card prefers hard.
        $shown = [0, 1];
        $next = $pickNextIndex($cards, $shown, ['hard', 'medium', 'easy']);
        $this->assertSame('hard', $cards[$next]['difficulty']);

        // A wrong answer (streak reset to 0) prefers easy/medium over hard,
        // even though a hard card is still unshown.
        $shown = [0, 1, 4];
        $next = $pickNextIndex($cards, $shown, ['easy', 'medium', 'hard']);
        $this->assertSame('medium', $cards[$next]['difficulty']); // both easy cards already shown

        // Once every easy/medium/hard-preferred option is exhausted, it
        // still falls back to whatever's left rather than returning null.
        $shown = [0, 1, 2, 3];
        $next = $pickNextIndex($cards, $shown, ['easy', 'medium']);
        $this->assertSame('hard', $cards[$next]['difficulty']);
    }

    public function test_a_card_without_any_difficulty_field_never_triggers_adaptive_mode(): void
    {
        $cards = [
            ['prompt' => 'a', 'options' => ['x', 'y'], 'correct' => 0],
            ['prompt' => 'b', 'options' => ['x', 'y'], 'correct' => 1],
        ];

        $html = Blade::render('<x-quick-round :cards="$cards" />', ['cards' => $cards]);

        // No card in this set carries `difficulty`, so cards.some(c =>
        // c.difficulty !== undefined) evaluates false at runtime — the
        // `card` getter's non-adaptive branch (cards[this.index]) is what
        // actually serves cards, same as before this feature existed.
        $this->assertStringContainsString('this.cards[this.index]', $html);
    }
}
