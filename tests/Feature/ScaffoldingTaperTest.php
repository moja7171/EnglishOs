<?php

namespace Tests\Feature;

use App\Models\Mission;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4 of the "growth without discouragement" epic: the help thins out as
 * the roadmap goes on, and the learner is never told. Most of this file
 * is about what must NOT happen — the taper is only safe as long as it
 * can't reach the pass bar.
 */
class ScaffoldingTaperTest extends TestCase
{
    use RefreshDatabase;

    private function mission(string $code): Mission
    {
        return new Mission(['code' => $code, 'title' => 'T', 'module' => 'Me', 'outcome' => 'O', 'phases' => []]);
    }

    public function test_the_roadmap_is_split_into_three_even_stretches(): void
    {
        $levels = collect(range(1, Mission::TOTAL_ROADMAP_MISSIONS))
            ->mapWithKeys(fn (int $n) => [$n => $this->mission(sprintf('M%02d', $n))->scaffoldLevel()]);

        $this->assertSame(Mission::SCAFFOLD_FULL, $levels[1]);
        $this->assertSame(Mission::SCAFFOLD_FULL, $levels[8]);
        $this->assertSame(Mission::SCAFFOLD_REDUCED, $levels[9]);
        $this->assertSame(Mission::SCAFFOLD_REDUCED, $levels[16]);
        $this->assertSame(Mission::SCAFFOLD_MINIMAL, $levels[17]);
        $this->assertSame(Mission::SCAFFOLD_MINIMAL, $levels[24]);

        // Never goes back up — a learner should not meet more help at
        // M20 than they had at M10.
        $order = [Mission::SCAFFOLD_FULL => 0, Mission::SCAFFOLD_REDUCED => 1, Mission::SCAFFOLD_MINIMAL => 2];
        $ranks = $levels->map(fn (string $level) => $order[$level])->values()->all();
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks);
    }

    public function test_the_taper_removes_one_support_then_two(): void
    {
        $six = ['a', 'b', 'c', 'd', 'e', 'f'];

        $this->assertCount(6, $this->mission('M01')->taperScaffolding($six, 3));
        $this->assertCount(5, $this->mission('M09')->taperScaffolding($six, 3));
        $this->assertCount(4, $this->mission('M17')->taperScaffolding($six, 3));
    }

    public function test_the_taper_can_never_cut_into_what_a_step_requires(): void
    {
        // Three starters, three required: the last mission on the
        // roadmap must still be handed all three.
        $this->assertCount(3, $this->mission('M24')->taperScaffolding(['a', 'b', 'c'], 3));
        $this->assertCount(1, $this->mission('M24')->taperScaffolding(['a'], 1));
        $this->assertSame([], $this->mission('M24')->taperScaffolding([], 3));
    }

    public function test_the_taper_keeps_positions_so_saved_answers_stay_aligned(): void
    {
        $tapered = $this->mission('M17')->taperScaffolding(['a', 'b', 'c', 'd', 'e', 'f'], 3);

        $this->assertSame([0 => 'a', 1 => 'b', 2 => 'c', 3 => 'd'], $tapered);
    }

    public function test_every_seeded_mission_still_offers_enough_starters_to_finish(): void
    {
        $this->seed(MissionSeeder::class);

        // The guarantee that matters most, checked against real content
        // rather than a made-up array: whatever a mission authored, and
        // wherever it sits on the roadmap, the learner can always still
        // complete the step.
        foreach (Mission::all() as $mission) {
            $authored = $mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];

            if ($authored === []) {
                continue;
            }

            $offered = $mission->taperScaffolding($authored, 3);

            $this->assertGreaterThanOrEqual(
                3,
                count($offered),
                "{$mission->code} would offer only ".count($offered).' starters for a step that needs 3'
            );
            $this->assertLessThanOrEqual(count($authored), count($offered));
        }
    }

    public function test_the_word_chips_are_in_front_of_the_learner_early_and_folded_away_later(): void
    {
        $open = $this->blade('<x-vocabulary-chips :words="$words" field="sentences" :collapsed="false" />', ['words' => ['often', 'usually']]);
        $open->assertSee('often')->assertDontSee('My words');

        $folded = $this->blade('<x-vocabulary-chips :words="$words" field="sentences" :collapsed="true" />', ['words' => ['often', 'usually']]);
        // Still rendered and still one tap away — folded, not removed.
        $folded->assertSee('My words')->assertSee('often');
    }

    public function test_the_taper_is_never_named_on_screen(): void
    {
        // The whole design depends on the learner not noticing. If any of
        // these words reach a rendered page, the silent taper has become
        // "Level 2: harder mode" and stops working.
        $views = array_merge(
            glob(base_path('resources/views/components/missions/steps/*.blade.php')),
            glob(base_path('resources/views/components/*.blade.php')),
        );

        foreach ($views as $view) {
            $source = file_get_contents($view);

            // Strip Blade comments and PHP expressions — SCAFFOLD_FULL
            // legitimately appears in the conditions that drive this.
            $rendered = preg_replace(['/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/@php.*?@endphp/s', '/<\?php.*?\?>/s'], '', $source);

            foreach (['scaffold level', 'harder mode', 'difficulty level', 'less help'] as $phrase) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $phrase,
                    $rendered,
                    basename($view).' shows the learner that the scaffolding changed'
                );
            }
        }
    }
}
