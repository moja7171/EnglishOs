<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlacementTest as PlacementTestContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PlacementTest extends TestCase
{
    use RefreshDatabase;

    private function content(): PlacementTestContent
    {
        return app(PlacementTestContent::class);
    }

    /**
     * Answers every item up to and including $band correctly, and every
     * item above it wrongly — a learner who genuinely tops out there.
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function answersUpTo(string $band): array
    {
        $order = ['A1' => 1, 'A2' => 2, 'B1' => 3];
        $out = [];

        foreach (['vocabulary', 'grammar'] as $part) {
            $answers = [];

            foreach ($this->content()->{$part}() as $index => $item) {
                $answers[$index] = $order[$item['band']] <= $order[$band]
                    ? $item['correct']
                    : ($item['correct'] + 1) % count($item['options']);
            }

            $out[] = $answers;
        }

        return $out;
    }

    public function test_recognition_stops_at_the_highest_band_actually_passed(): void
    {
        foreach (['A1', 'A2', 'B1'] as $band) {
            [$vocabulary, $grammar] = $this->answersUpTo($band);

            $this->assertSame(
                $band,
                $this->content()->score($vocabulary, $grammar, null)['recognitionLevel'],
                "a learner who only passes up to {$band} should place at {$band}"
            );
        }
    }

    public function test_a_lucky_higher_band_never_promotes_past_a_failed_lower_one(): void
    {
        $vocabulary = [];
        $grammar = [];

        // Every A2 item wrong, every A1 and B1 item right.
        foreach (['vocabulary', 'grammar'] as $i => $part) {
            $answers = [];
            foreach ($this->content()->{$part}() as $index => $item) {
                $answers[$index] = $item['band'] === 'A2'
                    ? ($item['correct'] + 1) % count($item['options'])
                    : $item['correct'];
            }
            $i === 0 ? $vocabulary = $answers : $grammar = $answers;
        }

        $this->assertSame('A1', $this->content()->score($vocabulary, $grammar, null)['recognitionLevel']);
    }

    public function test_the_spoken_answer_decides_when_it_is_lower_than_recognition(): void
    {
        [$vocabulary, $grammar] = $this->answersUpTo('B1');

        // Recognises B1, speaks A2 — the gap this app exists to close.
        $result = $this->content()->score($vocabulary, $grammar, 'A2');

        $this->assertSame('B1', $result['recognitionLevel']);
        $this->assertSame('A2', $result['spokenLevel']);
        $this->assertSame('A2', $result['level']);
        $this->assertFalse($result['provisional']);
    }

    public function test_a_generous_spoken_grade_can_only_pull_one_level_above_recognition(): void
    {
        [$vocabulary, $grammar] = $this->answersUpTo('A1');

        $this->assertSame('A2', $this->content()->score($vocabulary, $grammar, 'B1')['level']);
    }

    public function test_the_result_never_leaves_the_a1_b1_range(): void
    {
        [$low, $lowGrammar] = $this->answersUpTo('A1');
        $this->assertSame('A1', $this->content()->score($low, $lowGrammar, 'below A1')['level']);

        [$high, $highGrammar] = $this->answersUpTo('B1');
        $result = $this->content()->score($high, $highGrammar, 'above B1');
        $this->assertSame('B1', $result['level']);
        $this->assertTrue($result['aboveRange']);
    }

    public function test_a_missing_spoken_grade_still_produces_a_provisional_result(): void
    {
        [$vocabulary, $grammar] = $this->answersUpTo('A2');

        $result = $this->content()->score($vocabulary, $grammar, null);

        $this->assertSame('A2', $result['level']);
        $this->assertTrue($result['provisional']);
        $this->assertNull($result['spokenLevel']);
    }

    public function test_finishing_records_the_result_and_sets_the_real_level(): void
    {
        $learner = User::factory()->create(['cefr_level' => 'A2+']);
        $this->actingAs($learner);

        [$vocabulary, $grammar] = $this->answersUpTo('A2');

        $component = Livewire::test('placement');
        foreach ($vocabulary as $index => $option) {
            $component->call('answerVocabulary', $index, $option);
        }
        foreach ($grammar as $index => $option) {
            $component->call('answerGrammar', $index, $option);
        }

        // No recording here: the AI half is allowed to be absent (a relay
        // outage must not cost the learner their test).
        $component->call('finish')->assertSet('completed', true);

        $this->assertDatabaseHas('placement_tests', [
            'learner_id' => $learner->id,
            'level' => 'A2',
            'recognition_level' => 'A2',
            'spoken_level' => null,
        ]);
        $this->assertSame('A2', $learner->fresh()->cefr_level);
        $this->assertNotNull($learner->fresh()->latestPlacementTest());
    }

    public function test_the_home_page_offers_the_test_until_it_has_been_taken(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        // assertSeeHtml, not assertSee: the banner's apostrophe is literal
        // template text, so it never gets HTML-escaped the way assertSee
        // escapes what you hand it.
        Livewire::test('missions.overview')->assertSeeHtml("Find out where you're starting");

        $learner->placementTests()->create([
            'level' => 'A2', 'recognition_level' => 'A2', 'detail' => [],
        ]);

        Livewire::test('missions.overview')->assertDontSeeHtml("Find out where you're starting");
    }

    public function test_the_test_page_renders_all_three_parts(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('placement'))
            ->assertOk()
            ->assertSee('breakfast')                               // part 1
            ->assertSee('She ___ coffee every morning.')           // part 2
            ->assertSee('Tell me about a normal day in your life.'); // part 3
    }
}
