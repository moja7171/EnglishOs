<?php

namespace Tests\Feature;

use App\Models\AiPractice;
use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\NotebookEntry;
use App\Models\PairGame;
use App\Models\User;
use App\Services\AiPartner;
use App\Services\AiPractices;
use App\Services\TeddyLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What a practice with Teddy keeps along the way, how fairly it judges a
 * spoken word and his level, and what it gives back at the end: his slips,
 * the learner's own mistakes in his grammar turns, and the week's count.
 */
class TeddyPracticeBetterTest extends TestCase
{
    use RefreshDatabase;

    private function learnerWithAFinishedDay(): User
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk.',
            'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [
                ['key' => 'vocabulary_builder_1', 'words' => collect(['breakfast', 'lunch', 'dinner', 'kitchen', 'bread', 'butter', 'jam', 'honey'])->map(fn (string $word) => ['phrase' => $word, 'meaning_fa' => 'معنی '.$word])->all()],
            ]]],
        ]);
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder_1', 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);

        return $learner;
    }

    /**
     * A grammar card of Teddy's own, on which he slips.
     */
    private function grammarTurnOfHis(): AiPractice
    {
        $practice = AiPractice::factory()->create(['stage' => AiPractice::STAGE_GRAMMAR, 'position' => 1]);
        $practice->update(['deck' => [
            ...$practice->deck,
            AiPractice::STAGE_GRAMMAR => [
                ['prompt' => 'He are tall.', 'answer' => 'He is tall.', 'source' => 'M01', 'key' => 'g:0'],
                ['prompt' => 'She go to work.', 'answer' => 'She goes to work.', 'source' => 'M01', 'key' => 'g:1'],
            ],
            'slips' => [AiPractice::STAGE_GRAMMAR => [1]],
        ]]);
        $this->actingAs($practice->learner);

        return $practice->refresh();
    }

    public function test_a_word_is_found_in_its_other_forms(): void
    {
        $this->assertSame(['make'], AiPractice::wordsIn('I am making dinner', ['make']));
        $this->assertSame(['run'], AiPractice::wordsIn('He was running home', ['run']));
        $this->assertSame(['study'], AiPractice::wordsIn('She studied a lot', ['study']));
        $this->assertSame(['stop'], AiPractice::wordsIn('We stopped there', ['stop']));
        $this->assertSame([], AiPractice::wordsIn('She likes bread', ['make']));
        $this->assertSame([], AiPractice::wordsIn('A runner', ['run']));
    }

    public function test_a_hint_blanks_out_every_form_of_the_word(): void
    {
        $this->assertSame('She is _____ dinner.', AiPractice::blankedOut('She is making dinner.', 'make'));
    }

    public function test_a_spoken_word_is_not_taken_for_another_one_that_only_looks_like_it(): void
    {
        $this->assertFalse(AiPractice::namesWord('house', 'horse'));
        $this->assertFalse(AiPractice::namesWord('team', 'tea'));
        $this->assertTrue(AiPractice::namesWord('it is a house', 'house'));
        $this->assertTrue(AiPractice::namesWord('cook', 'cooking', heardAloud: false) === false);
        $this->assertTrue(AiPractice::namesWord('cooking', 'cook', heardAloud: false));
    }

    public function test_a_slip_is_only_one_when_it_is_the_same_sentence_with_a_small_change(): void
    {
        $this->assertTrue(AiPartner::isSmallSlip('She go to work every day.', 'She goes to work every day.'));
        $this->assertFalse(AiPartner::isSmallSlip('She goes to work every day.', 'She goes to work every day.'));
        $this->assertFalse(AiPartner::isSmallSlip('I like pizza very much.', 'She goes to work every day.'));
        $this->assertFalse(AiPartner::isSmallSlip('  ', 'She goes to work.'));
    }

    public function test_a_word_the_learner_could_not_say_is_kept_at_once_and_three_answers_count_for_the_day(): void
    {
        $practice = AiPractice::factory()->create();
        $learner = $practice->learner;

        $practice->recordAnswer('lunch', false);

        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $learner->id, 'word' => 'breakfast']);

        $practice->recordAnswer('x', true);
        $practice->creditTheDay();
        $this->assertDatabaseMissing('day_activities', ['learner_id' => $learner->id, 'activity' => 'ai-practice']);

        $practice->recordAnswer('y', true);
        $practice->creditTheDay();
        $practice->creditTheDay();

        $this->assertSame(1, DayActivity::where('learner_id', $learner->id)->where('activity', 'ai-practice')->count());
        $this->assertSame(['right' => 2, 'total' => 3], $practice->refresh()->deck['tally']['vocabulary']);
    }

    public function test_leaving_early_does_not_look_like_a_lost_game(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, 2) as $ignored) {
            $practice = AiPractice::factory()->create(['learner_id' => $learner->id, 'answered' => 4, 'score' => 3]);
            $practice->update(['position' => 1, 'current' => ['heard' => 'x', 'correct' => true, 'feedback' => null]]);
            $practice->finish();
        }

        $this->assertSame(2, $practice->refresh()->deck['cards_reached']);
        $this->assertSame(TeddyLevel::HARD, app(TeddyLevel::class)->levelFor($learner, AiPractice::KIND_PRACTICE));
    }

    public function test_just_a_minute_points_for_words_cannot_pass_the_most_a_card_could_give(): void
    {
        $practice = AiPractice::factory()->create([
            'kind' => PairGame::GAME_MINUTE,
            'stage' => AiPractice::STAGE_GAME,
            'deck' => ['game' => ['game' => PairGame::GAME_MINUTE, 'cards' => [
                ['word' => 'food', 'words' => ['a', 'b', 'c', 'd']],
                ['word' => 'food', 'words' => ['a', 'b', 'c', 'd']],
            ]]],
            'score' => 4,
            'finished_at' => now(),
        ]);

        $this->assertSame(10, $practice->pointsPossible(2));
        $this->assertEqualsWithDelta(0.4, $practice->scoreShare(), 0.001);
    }

    public function test_picking_other_days_finishes_the_open_practice_and_starts_a_new_one(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $open = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

        $this->assertTrue($open->is(app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE)));
        $this->assertTrue($open->is(app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE, $open->days)));

        $other = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE, ['M01-9']);

        if ($other !== null) {
            $this->assertFalse($open->is($other));
        }

        $this->assertNotNull($open->refresh()->finished_at);
    }

    public function test_his_own_grammar_turns_can_be_the_learners_notebook_mistakes(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $entry = NotebookEntry::factory()->create(['learner_id' => $learner->id, 'original' => 'She go to work every day.', 'better' => 'She goes to work every day.', 'times_seen' => 3]);
        NotebookEntry::factory()->learned()->create(['learner_id' => $learner->id, 'original' => 'He have two cats at home.', 'better' => 'He has two cats at home.']);

        $found = false;

        for ($round = 0; $round < 5 && ! $found; $round++) {
            $practice = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);
            $cards = collect($practice->deck[AiPractice::STAGE_GRAMMAR] ?? []);

            foreach ($cards->filter(fn (array $card) => isset($card['notebook_id'])) as $position => $card) {
                $found = true;
                $this->assertSame($entry->id, $card['notebook_id']);
                $this->assertSame('She go to work every day.', $card['prompt']);
                $this->assertSame(1, $position % 2);
            }

            $this->assertNull($cards->firstWhere('prompt', 'He have two cats at home.'));
            $practice->finish();
        }

        $this->assertTrue($found || ($cards->count() < 2), 'the notebook mistake never reached a turn of his');
    }

    public function test_a_slip_the_learner_misses_is_kept_in_the_notebook_and_one_they_catch_is_not(): void
    {
        $practice = $this->grammarTurnOfHis();

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('judgeTeddy', 'right');

        $this->assertSame(['total' => 1, 'caught' => 0], $practice->refresh()->slipCount());
        $this->assertDatabaseHas('notebook_entries', ['learner_id' => $practice->learner_id, 'original' => 'She go to work.', 'better' => 'She goes to work.']);

        $caught = $this->grammarTurnOfHis();

        Livewire::test('ai-practice.session', ['practice' => $caught])->call('judgeTeddy', 'mistake');

        $this->assertSame(['total' => 1, 'caught' => 1], $caught->refresh()->slipCount());
        $this->assertDatabaseMissing('notebook_entries', ['learner_id' => $caught->learner_id]);
    }

    public function test_the_end_of_a_practice_shows_the_parts_the_slips_and_the_ones_missed(): void
    {
        $practice = AiPractice::factory()->create(['stage' => AiPractice::STAGE_GRAMMAR]);
        $practice->update(['deck' => [
            ...$practice->deck,
            'tally' => ['vocabulary' => ['right' => 4, 'total' => 6]],
            'slip_log' => [
                ['said' => 'She go to work.', 'fix' => 'She goes to work.', 'caught' => false, 'kind' => 'sentence'],
                ['said' => 'He are tall.', 'fix' => 'He is tall.', 'caught' => true, 'kind' => 'sentence'],
            ],
        ]]);
        $this->actingAs($practice->learner);
        $practice->refresh()->finish();

        Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])
            ->assertSee('Words 4/6')
            ->assertSee('slips you caught 1/2')
            ->assertSee('Teddy’s slips you missed')
            ->assertSee('She goes to work.')
            ->assertDontSee('He is tall.');
    }

    public function test_a_slip_missed_in_who_am_i_is_counted_when_the_secret_is_left(): void
    {
        $practice = AiPractice::factory()->create([
            'kind' => PairGame::GAME_WHO,
            'stage' => AiPractice::STAGE_GAME,
            'deck' => ['game' => ['game' => PairGame::GAME_WHO, 'cards' => [['word' => 'dog', 'category' => 'an animal'], ['word' => 'cat', 'category' => 'an animal']]]],
            'current' => ['qa' => [['q' => 'Are you big?', 'a' => 'no', 'flip' => true, 'real' => 'yes'], ['q' => 'Can you bark?', 'a' => 'yes']], 'won' => true],
        ]);
        $this->actingAs($practice->learner);

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('next');

        $this->assertSame(['total' => 1, 'caught' => 0], $practice->refresh()->slipCount());
        $this->assertDatabaseCount('notebook_entries', 0);
    }

    public function test_the_page_shows_the_weeks_count_and_how_he_plays(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        AiPractice::factory()->create(['learner_id' => $learner->id, 'deck' => ['slip_log' => [
            ['said' => 'a', 'fix' => 'b', 'caught' => true, 'kind' => 'sentence'],
            ['said' => 'c', 'fix' => 'd', 'caught' => false, 'kind' => 'sentence'],
        ]]]);
        AiPractice::factory()->create(['learner_id' => $learner->id, 'created_at' => now()->subDays(10), 'deck' => ['slip_log' => [['said' => 'e', 'fix' => 'f', 'caught' => true, 'kind' => 'sentence']]]]);

        Livewire::test('friends.teddy')->assertSee('This week you caught 1 of 2 slips');
    }
}
