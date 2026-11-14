<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\NotebookEntry;
use App\Models\User;
use App\Services\GrammarPractice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Notebook's grammar practice: a round of seven cards from a lesson's
 * own content (which one is right, fix the sentence, build the sentence,
 * say it), with the missed sentences saved to Mistakes once at the end.
 */
class GrammarPracticeTest extends TestCase
{
    use RefreshDatabase;

    private function mission(bool $withWordOrder = true, int $pairsPerSection = 2): Mission
    {
        $pairs = fn (string $topic) => collect(range(1, $pairsPerSection))->map(fn (int $n) => [
            'type' => 'mistake_fix',
            'wrong' => "She go to {$topic} number {$n}.",
            'right' => "She goes to {$topic} number {$n}.",
            'explanation' => 'he / she / it needs an -s.',
        ])->all();

        $step = [
            'key' => 'grammar_in_context',
            'focus' => 'Present Simple',
            'lesson' => [
                'intro' => 'Two small things.',
                'sections' => [
                    ['heading' => 'A · The -s', 'blocks' => [['type' => 'rule', 'text' => 'Add -s.'], ...$pairs('work')]],
                    ['heading' => 'B · Questions', 'blocks' => [['type' => 'rule', 'text' => 'Use does.'], ...$pairs('school')]],
                ],
            ],
            'quick_check' => [
                ['wrong' => 'He work every day.', 'options' => ['He works every day.', 'He working every day.'], 'correct' => 0],
                ['wrong' => 'Do she like tea?', 'options' => ['Does she like tea?', 'Do she likes tea?'], 'correct' => 0],
                ['wrong' => 'They doesn\'t know.', 'options' => ['They don\'t know.', 'They not know.'], 'correct' => 0],
            ],
        ];

        if ($withWordOrder) {
            $step['word_order'] = [['words' => ['up', 'She', 'wakes', 'early'], 'answer' => 'She wakes up early.']];
        }

        return Mission::create(['code' => 'M20', 'title' => 'My Daily Life', 'module' => 'Me', 'outcome' => 'x', 'phases' => [['steps' => [$step]]]]);
    }

    public function test_a_round_is_three_pairs_two_fixes_one_build_and_one_say_in_that_order(): void
    {
        $cards = app(GrammarPractice::class)->round($this->mission());

        $this->assertSame(['pick', 'pick', 'pick', 'fix', 'fix', 'order', 'say'], array_column($cards, 'type'));
    }

    public function test_a_pick_card_has_the_right_sentence_at_the_correct_position(): void
    {
        $cards = app(GrammarPractice::class)->round($this->mission());
        $pick = $cards[0];

        $this->assertCount(2, $pick['options']);
        $this->assertSame($pick['better'], $pick['options'][$pick['correct']]);
        $this->assertContains($pick['original'], $pick['options']);
        $this->assertNotSame($pick['original'], $pick['better']);
    }

    public function test_the_say_card_is_one_of_the_right_sentences(): void
    {
        $cards = app(GrammarPractice::class)->round($this->mission());
        $rights = collect($cards)->whereIn('type', ['pick', 'fix'])->pluck('better')->all();

        $this->assertContains(collect($cards)->last()['sentence'], $rights);
    }

    public function test_a_lesson_without_a_word_order_list_has_no_build_card(): void
    {
        $cards = app(GrammarPractice::class)->round($this->mission(withWordOrder: false));

        $this->assertNotContains('order', array_column($cards, 'type'));
        $this->assertSame('say', collect($cards)->last()['type']);
    }

    public function test_a_lesson_short_of_pairs_gets_more_fix_cards_instead(): void
    {
        $mission = $this->mission(pairsPerSection: 1);
        $cards = app(GrammarPractice::class)->round($mission);
        $types = array_count_values(array_column($cards, 'type'));

        $this->assertSame(2, $types['pick']);
        $this->assertSame(3, $types['fix']);
    }

    public function test_a_mission_without_lesson_content_has_no_round(): void
    {
        $mission = Mission::create(['code' => 'M21', 'title' => 'Empty', 'module' => 'Me', 'outcome' => 'x', 'phases' => []]);

        $this->assertFalse(app(GrammarPractice::class)->isAvailable($mission));
        $this->assertSame([], app(GrammarPractice::class)->round($mission));
    }

    public function test_the_page_opens_for_a_mission_the_learner_started_and_not_for_others(): void
    {
        $learner = User::factory()->create();
        $stranger = User::factory()->create();
        $mission = $this->mission();
        MissionRun::findOrStart($learner, $mission);

        $this->actingAs($learner)->get(route('notebook.grammar', 'M20'))->assertOk()->assertSee('Which one is right?');
        $this->actingAs($stranger)->get(route('notebook.grammar', 'M20'))->assertNotFound();
    }

    public function test_finishing_saves_only_the_missed_sentences_to_mistakes_and_only_once(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mission = $this->mission();
        MissionRun::findOrStart($learner, $mission);

        $component = Livewire::test('notebook.grammar-practice', ['mission' => $mission]);
        $cards = $component->instance()->cards;

        $component->call('finish', [0, 99]);
        $component->call('finish', [1]);

        $entries = NotebookEntry::where('learner_id', $learner->id)->get();
        $this->assertCount(1, $entries);
        $this->assertSame($cards[0]['original'], $entries->first()->original);
        $this->assertSame($cards[0]['better'], $entries->first()->better);
        $this->assertSame('grammar_in_context', $entries->first()->source);
        $this->assertSame($mission->id, $entries->first()->mission_id);
    }

    public function test_a_perfect_round_saves_nothing(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mission = $this->mission();
        MissionRun::findOrStart($learner, $mission);

        Livewire::test('notebook.grammar-practice', ['mission' => $mission])->call('finish', []);

        $this->assertSame(0, NotebookEntry::where('learner_id', $learner->id)->count());
    }

    public function test_the_grammar_tab_offers_practice_on_an_open_grammar_point_only(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mission = $this->mission();

        Livewire::test('notebook.index')->assertDontSee(route('notebook.grammar', 'M20'));

        MissionRun::findOrStart($learner, $mission);

        Livewire::test('notebook.index')->assertSee(route('notebook.grammar', 'M20'));
    }

    public function test_a_wrong_sentence_two_sections_share_is_asked_once(): void
    {
        $mission = $this->mission();
        $phases = $mission->phases;
        $phases[0]['steps'][0]['lesson']['sections'][1]['blocks'][] = ['type' => 'mistake_fix', 'wrong' => 'She go to work number 1.', 'right' => 'She goes to work number 1.'];
        $mission->update(['phases' => $phases]);

        $originals = app(GrammarPractice::class)->pairs($mission->fresh())->pluck('wrong');

        $this->assertSame($originals->unique()->count(), $originals->count());
    }
}
