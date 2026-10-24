<?php

namespace Tests\Feature;

use App\Models\NotebookEntry;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\FeedbackNotebook;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\LearnerFeedback;
use App\Services\NotebookDrill;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The learner's two notebooks (mistakes + tips): filled from feedback,
 * one line per repeated mistake with a counter, listed on /notebook and
 * reviewed in Daily Review until marked learned.
 */
class NotebookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $sentences
     * @param  list<array<string, mixed>>  $techniques
     */
    private function record(User $learner, array $sentences, array $techniques = []): array
    {
        return app(FeedbackNotebook::class)->record(
            $learner,
            LearnerFeedback::normalize(['summary' => '', 'sentences' => $sentences, 'positives' => [], 'negatives' => [], 'techniques' => $techniques]),
            'writing',
        );
    }

    private function fix(string $original, string $corrected, ?string $pattern = 'present_perfect'): array
    {
        return ['original' => $original, 'status' => 'fix', 'corrected' => $corrected, 'why' => 'برای مدت از «for» استفاده کن.', 'pattern' => $pattern];
    }

    public function test_the_same_mistake_in_a_new_sentence_bumps_one_line_instead_of_adding_another(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();

        $this->record($learner, [$this->fix('I am here since 3 years.', "I've been here for 3 years.")]);
        $this->record($learner, [$this->fix('I work there since 2 years.', "I've worked there for 2 years.")]);

        $entry = $learner->notebookEntries()->sole();
        $this->assertSame(2, $entry->times_seen);
        $this->assertSame('I work there since 2 years.', $entry->original);
        $this->assertTrue($entry->next_review_at->equalTo(now()->addDay()));
    }

    public function test_asking_again_about_the_same_text_does_not_count_twice(): void
    {
        $learner = User::factory()->create();
        $sentence = $this->fix('I am here since 3 years.', "I've been here for 3 years.");

        $this->record($learner, [$sentence]);
        $this->record($learner, [$sentence]);

        $this->assertSame(1, $learner->notebookEntries()->sole()->times_seen);
    }

    public function test_a_learned_mistake_made_again_comes_back_and_is_due_now(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $this->record($learner, [$this->fix('I am here since 3 years.', "I've been here for 3 years.")]);
        $learner->notebookEntries()->update(['learned_at' => now()]);

        $this->record($learner, [$this->fix('She lives here since May.', 'She has lived here since May.')]);

        $entry = $learner->notebookEntries()->sole();
        $this->assertNull($entry->learned_at);
        $this->assertTrue($entry->next_review_at->equalTo(now()));
    }

    public function test_better_phrasings_and_techniques_become_tips_and_a_repeated_technique_stays_one_line(): void
    {
        $learner = User::factory()->create();
        $better = ['original' => 'My job is very hard.', 'status' => 'better', 'corrected' => 'My job is really tough.', 'why' => 'طبیعی‌تر است.', 'pattern' => null];
        $technique = ['key' => 'linking', 'tip' => 'وصل کن.', 'example' => 'It is hard, so I rest.'];

        $saved = $this->record($learner, [$better, ['original' => 'I like it.', 'status' => 'ok']], [$technique]);
        $this->record($learner, [], [['key' => 'linking', 'tip' => 'وصل کن.', 'example' => 'I was tired, so I slept.']]);

        $this->assertSame(['mistakes' => 0, 'tips' => 2], $saved);
        $this->assertSame(2, $learner->notebookEntries()->where('kind', NotebookEntry::KIND_TIP)->count());
        $this->assertSame('I was tired, so I slept.', $learner->notebookEntries()->where('label', 'linking')->value('better'));
    }

    public function test_the_notebook_page_lists_mistakes_with_their_counter_and_tips_with_the_technique_name(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->for($learner, 'learner')->create(['better' => 'I went home.', 'times_seen' => 3]);
        NotebookEntry::factory()->tip()->for($learner, 'learner')->create(['label' => 'buying_time', 'dedupe_key' => 't:buying_time', 'original' => null, 'better' => 'Well, let me think…']);
        $this->actingAs($learner);

        $this->get(route('notebook.index'))
            ->assertSee('I went home.')
            ->assertSee('3×')
            ->assertSee('Buy time')
            ->assertSee('Well, let me think…');
    }

    public function test_marking_a_line_learned_takes_it_off_the_list_and_out_of_daily_review(): void
    {
        $learner = User::factory()->create();
        $entry = NotebookEntry::factory()->for($learner, 'learner')->create(['next_review_at' => now()->subMinute()]);
        $this->actingAs($learner);

        Livewire::test('notebook.index')->call('markLearned', $entry->id);

        $this->assertNotNull($entry->fresh()->learned_at);
        $this->assertSame(0, $learner->dailyReviewCount());
    }

    public function test_bringing_a_learned_line_back_makes_it_due_again(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $entry = NotebookEntry::factory()->learned()->for($learner, 'learner')->create(['next_review_at' => now()->addMonth()]);
        $this->actingAs($learner);

        Livewire::test('notebook.index')->call('bringBack', $entry->id);

        $this->assertNull($entry->fresh()->learned_at);
        $this->assertTrue($entry->fresh()->next_review_at->equalTo(now()));
    }

    public function test_a_learner_cannot_mark_someone_elses_line(): void
    {
        $entry = NotebookEntry::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);

        Livewire::test('notebook.index')->call('markLearned', $entry->id);
    }

    private function dueMistake(User $learner, int $level, string $original = 'She go home.', string $better = 'She goes home.'): NotebookEntry
    {
        return NotebookEntry::factory()->for($learner, 'learner')->create([
            'original' => $original,
            'better' => $better,
            'practice_level' => $level,
            'next_review_at' => now()->subMinute(),
        ]);
    }

    /**
     * @param  list<string>  $options
     */
    private function optionIndex(array $options, string $text): int
    {
        return array_search($text, $options, true);
    }

    public function test_a_new_mistake_is_reviewed_by_tapping_the_wrong_word_and_climbs_a_rung(): void
    {
        $learner = User::factory()->create();
        $entry = $this->dueMistake($learner, NotebookEntry::LEVEL_SPOT);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Your mistake')
            ->assertSee('Tap the wrong word')
            ->call('drillTap', 1)
            ->assertSee('Spot on!')
            ->call('drillNext');

        $this->assertSame(1, $entry->fresh()->repetitions);
        $this->assertSame(NotebookEntry::LEVEL_PICK, $entry->fresh()->practice_level);
    }

    public function test_the_second_rung_fills_the_gap_from_the_fix_and_the_learners_own_word(): void
    {
        $learner = User::factory()->create();
        $entry = $this->dueMistake($learner, NotebookEntry::LEVEL_PICK);
        $this->actingAs($learner);

        $card = NotebookDrill::card($entry);
        $this->assertSame(['mode' => 'pick', 'before' => 'She', 'after' => 'home.'], array_intersect_key($card, array_flip(['mode', 'before', 'after'])));
        $this->assertEqualsCanonicalizing(['goes', 'go'], $card['options']);

        Livewire::test('review.index')
            ->assertSee('Fill the gap')
            ->call('drillTap', $this->optionIndex($card['options'], 'goes'))
            ->assertSee('Spot on!')
            ->call('drillNext');

        $this->assertSame(NotebookEntry::LEVEL_SAY, $entry->fresh()->practice_level);
    }

    public function test_a_wrong_answer_drops_a_rung_and_comes_back_once_this_session_without_a_second_grade(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $entry = $this->dueMistake($learner, NotebookEntry::LEVEL_PICK);
        $wrongChip = $this->optionIndex(NotebookDrill::card($entry)['options'], 'go');
        $this->actingAs($learner);

        $review = Livewire::test('review.index')
            ->call('drillTap', $wrongChip)
            ->assertSee('Not quite')
            ->call('drillNext');

        $this->assertSame(NotebookEntry::LEVEL_SPOT, $entry->fresh()->practice_level);
        $this->assertTrue($entry->fresh()->next_review_at->equalTo(now()->addDay()));

        $review->assertSee('Tap the wrong word')
            ->call('drillTap', 1)
            ->call('drillNext')
            ->assertSee('Session done!')
            ->assertSee('1 item reviewed');

        $this->assertSame(0, $entry->fresh()->repetitions);
        $this->assertSame(NotebookEntry::LEVEL_PICK, $entry->fresh()->practice_level);
    }

    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function cardsByRungAndFix(): array
    {
        return [
            'a missing word cannot be tapped, so rung 1 is a choice' => [NotebookEntry::LEVEL_SPOT, 'I went to cinema.', 'I went to the cinema.', 'choose'],
            'a missing word is a gap on rung 2' => [NotebookEntry::LEVEL_PICK, 'I went to cinema.', 'I went to the cinema.', 'pick'],
            'a rewritten sentence is a choice' => [NotebookEntry::LEVEL_PICK, 'Yesterday me and my friend we go to park.', 'My friend and I went to the park yesterday.', 'choose'],
            'rung 3 is always said' => [NotebookEntry::LEVEL_SAY, 'She go home.', 'She goes home.', 'say'],
        ];
    }

    #[DataProvider('cardsByRungAndFix')]
    public function test_the_card_follows_the_rung_and_the_size_of_the_fix(int $level, string $original, string $better, string $mode): void
    {
        $entry = $this->dueMistake(User::factory()->create(), $level, $original, $better);

        $this->assertSame($mode, NotebookDrill::card($entry)['mode']);
    }

    public function test_a_missing_word_is_picked_from_the_word_and_no_word(): void
    {
        $entry = $this->dueMistake(User::factory()->create(), NotebookEntry::LEVEL_PICK, 'I went to cinema.', 'I went to the cinema.');

        $this->assertEqualsCanonicalizing(['the', NotebookDrill::NO_WORD], NotebookDrill::card($entry)['options']);
    }

    public function test_the_last_rung_is_said_and_checked_from_the_transcript(): void
    {
        $learner = User::factory()->create();
        $entry = $this->dueMistake($learner, NotebookEntry::LEVEL_SAY);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn(' She goes home. '));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Say it right')
            ->set('drillRecording', UploadedFile::fake()->create('notebook-say.webm', 8, 'audio/webm'))
            ->call('drillHeardRecording')
            ->assertSee('Spot on!')
            ->call('drillNext');

        $this->assertSame(1, $entry->fresh()->repetitions);
    }

    public function test_an_empty_transcript_asks_to_say_it_again_instead_of_marking_it_wrong(): void
    {
        $learner = User::factory()->create();
        $this->dueMistake($learner, NotebookEntry::LEVEL_SAY);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn(''));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('drillRecording', UploadedFile::fake()->create('notebook-say.webm', 8, 'audio/webm'))
            ->call('drillHeardRecording')
            ->assertSee("Couldn't hear that", false)
            ->assertSet('drillCorrect', null);
    }

    public function test_a_due_technique_is_practised_by_choosing_the_sentence_that_uses_it(): void
    {
        $learner = User::factory()->create();
        $entry = NotebookEntry::factory()->tip()->for($learner, 'learner')->create([
            'label' => 'ask_back',
            'dedupe_key' => 't:ask_back',
            'original' => null,
            'better' => 'What about you?',
            'next_review_at' => now()->subMinute(),
        ]);
        $options = NotebookDrill::card($entry)['options'];
        $this->actingAs($learner);

        $this->assertEqualsCanonicalizing(['What about you?', 'I like football.'], $options);

        Livewire::test('review.index')
            ->assertSee('Ask back')
            ->assertSee('Which one uses it?')
            ->call('drillTap', $this->optionIndex($options, 'What about you?'))
            ->assertSee('Spot on!')
            ->assertSee('Listen, then say it out loud.');
    }

    public function test_a_starred_phrase_swap_asks_which_sounds_more_natural(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->tip()->for($learner, 'learner')->create([
            'original' => 'My job is very hard.',
            'better' => 'My job is really tough.',
            'starred_at' => now(),
            'next_review_at' => now()->subMinute(),
        ]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Which sounds more natural?')
            ->assertSee('My job is very hard.')
            ->assertSee('My job is really tough.');
    }

    public function test_a_mistake_without_a_known_category_is_kept_by_its_fixed_sentence(): void
    {
        $learner = User::factory()->create();

        $this->record($learner, [
            $this->fix('I am here since 3 years.', "I've been here for 3 years.", 'present perfect with for'),
            $this->fix('She go home.', 'She goes home.', 'third person s'),
        ]);

        $this->assertSame(
            ["m:i've been here for 3 years", 'm:she goes home'],
            $learner->notebookEntries()->orderBy('id')->pluck('dedupe_key')->all(),
        );
        $this->assertSame(0, $learner->notebookEntries()->whereNotNull('label')->count());
    }

    public function test_daily_review_takes_at_most_three_notebook_lines_the_most_repeated_first(): void
    {
        $learner = User::factory()->create();
        $notes = collect([1, 5, 2, 4])->map(fn (int $timesSeen) => NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => $timesSeen, 'next_review_at' => now()->subDay()]));
        $word = VocabularyWord::create(['learner_id' => $learner->id, 'word' => 'commute', 'meaning' => 'to travel to work', 'next_review_at' => now()->subHour()]);

        $items = $learner->dailyReviewItems();

        $this->assertEqualsCanonicalizing(
            [['type' => 'note', 'id' => $notes[1]->id], ['type' => 'note', 'id' => $notes[3]->id], ['type' => 'note', 'id' => $notes[2]->id], ['type' => 'word', 'id' => $word->id]],
            $items->all(),
        );
        $this->assertSame(4, $learner->dailyReviewCount());
    }

    public function test_notebook_lines_reviewed_today_use_up_the_three_slots(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->count(2)->for($learner, 'learner')->create(['last_reviewed_at' => now(), 'next_review_at' => now()->addDay()]);
        NotebookEntry::factory()->count(3)->for($learner, 'learner')->create(['next_review_at' => now()->subDay()]);

        $this->assertSame(1, $learner->dailyReviewItems()->where('type', 'note')->count());
        $this->assertSame(1, $learner->dailyReviewCount());
    }

    public function test_a_phrase_swap_goes_to_daily_review_only_once_starred(): void
    {
        $learner = User::factory()->create();
        $swap = NotebookEntry::factory()->tip()->for($learner, 'learner')->create(['next_review_at' => now()->subDay()]);
        $technique = NotebookEntry::factory()->tip()->for($learner, 'learner')->create(['label' => 'linking', 'dedupe_key' => 't:linking', 'next_review_at' => now()->subDay()]);
        $this->actingAs($learner);

        $this->assertSame([$technique->id], $learner->dailyReviewItems()->pluck('id')->all());

        Livewire::test('notebook.index')->call('toggleStar', $swap->id);

        $this->assertNotNull($swap->fresh()->starred_at);
        $this->assertEqualsCanonicalizing([$swap->id, $technique->id], $learner->dailyReviewItems()->pluck('id')->all());
    }

    public function test_only_a_phrase_swap_can_be_starred(): void
    {
        $learner = User::factory()->create();
        $mistake = NotebookEntry::factory()->for($learner, 'learner')->create();
        $this->actingAs($learner);

        Livewire::test('notebook.index')->call('toggleStar', $mistake->id);

        $this->assertNull($mistake->fresh()->starred_at);
    }

    public function test_a_line_remembered_three_times_in_a_row_with_a_long_gap_becomes_learned(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $almost = NotebookEntry::factory()->for($learner, 'learner')->create(['repetitions' => 3, 'interval_days' => 15, 'ease_factor' => 2.5]);
        $young = NotebookEntry::factory()->for($learner, 'learner')->create(['repetitions' => 2, 'interval_days' => 6, 'ease_factor' => 2.5]);

        $almost->review(4);
        $young->review(4);

        $this->assertTrue($almost->fresh()->learned_at->equalTo(now()));
        $this->assertSame(15, $young->fresh()->interval_days);
        $this->assertNull($young->fresh()->learned_at);
    }

    public function test_the_notebook_page_shows_three_lines_in_full_and_folds_the_rest(): void
    {
        $learner = User::factory()->create();
        foreach ([5, 4, 3, 2] as $timesSeen) {
            NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => $timesSeen, 'label' => 'articles', 'note' => "note {$timesSeen}", 'next_review_at' => now()->subDay()]);
        }
        $this->actingAs($learner);

        Livewire::test('notebook.index')
            ->assertSeeInOrder(['note 5', 'note 4', 'note 3', 'a / an / the', 'note 2'])
            ->assertSee('4 waiting for review')
            ->assertSee('Practice 4 now');
    }

    public function test_practice_moves_the_rung_not_the_schedule_and_a_wrong_line_comes_back_once(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $first = NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => 3, 'original' => 'She go home.', 'better' => 'She goes home.', 'next_review_at' => now()->addWeek()]);
        $second = NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => 1, 'original' => 'I am agree.', 'better' => 'I agree.']);
        $this->actingAs($learner);

        Livewire::test('notebook.index')
            ->call('startPractice', NotebookEntry::KIND_MISTAKE)
            ->assertSet('practiceIds', [$first->id, $second->id])
            ->assertSeeInOrder(['Tap the wrong word', 'She', 'go', 'home.'])
            ->call('drillTap', 1)
            ->assertSee('Spot on!')
            ->call('drillNext')
            ->assertSeeInOrder(['I', 'am', 'agree.'])
            ->call('drillTap', 0)
            ->assertSee('Not quite')
            ->call('drillNext')
            ->assertSet('practiceIds', [$first->id, $second->id, $second->id])
            ->call('drillTap', 1)
            ->call('drillNext')
            ->assertSee('1 / 2 right');

        $this->assertSame(NotebookEntry::LEVEL_PICK, $first->fresh()->practice_level);
        $this->assertSame(NotebookEntry::LEVEL_PICK, $second->fresh()->practice_level);
        $this->assertTrue($first->fresh()->next_review_at->equalTo(now()->addWeek()));
        $this->assertSame(0, $first->fresh()->repetitions);
    }

    public function test_tips_are_practised_from_their_own_tab_and_five_more_brings_new_ones(): void
    {
        $learner = User::factory()->create();
        $tips = NotebookEntry::factory()->tip()->count(6)->for($learner, 'learner')->sequence(fn ($sequence) => ['times_seen' => 10 - $sequence->index])->create();
        NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => 20]);
        $this->actingAs($learner);

        $page = Livewire::test('notebook.index')
            ->call('startPractice', NotebookEntry::KIND_TIP)
            ->assertSet('practiceIds', $tips->take(5)->pluck('id')->all())
            ->assertSee('Which sounds more natural?');

        foreach (range(1, 10) as $card) {
            $page->call('drillShowFix')->call('drillNext');
        }

        $page->assertSee('1 more')
            ->call('startPractice', NotebookEntry::KIND_TIP)
            ->assertSet('practiceIds', [$tips->last()->id]);
    }

    public function test_a_different_right_fix_counts_when_the_ai_accepts_it(): void
    {
        $learner = User::factory()->create();
        $this->dueMistake($learner, NotebookEntry::LEVEL_SAY, 'I am here since 3 years.', "I've been here for 3 years.");
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"correct": true}'));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('drillTyping', true)
            ->set('drillTyped', 'I have lived here for three years.')
            ->call('drillCheckTyped')
            ->assertSee('Spot on!');
    }

    public function test_when_the_ai_fails_only_the_saved_fix_counts(): void
    {
        $learner = User::factory()->create();
        $this->dueMistake($learner, NotebookEntry::LEVEL_SAY, 'I am here since 3 years.', "I've been here for 3 years.");
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('drillTyping', true)
            ->set('drillTyped', 'I have lived here for three years.')
            ->call('drillCheckTyped')
            ->assertDontSee('Spot on!')
            ->assertSee('You wrote: I have lived here for three years.');
    }
}
