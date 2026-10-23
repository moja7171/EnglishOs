<?php

namespace Tests\Feature;

use App\Models\NotebookEntry;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\FeedbackNotebook;
use App\Services\GeminiClient;
use App\Services\LearnerFeedback;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_a_due_mistake_is_reviewed_by_writing_the_fix(): void
    {
        $learner = User::factory()->create();
        $entry = NotebookEntry::factory()->for($learner, 'learner')->create([
            'original' => 'I am here since 3 years.',
            'better' => "I've been here for 3 years.",
            'next_review_at' => now()->subMinute(),
        ]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Your mistake')
            ->assertSee('I am here since 3 years.')
            ->set('attempt', "i've been here for 3 years")
            ->call('checkAttempt')
            ->assertSee('Spot on!')
            ->call('gradeSelf', 4);

        $this->assertSame(1, $entry->fresh()->repetitions);
    }

    public function test_a_due_technique_tip_is_recalled_then_revealed(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->tip()->for($learner, 'learner')->create([
            'label' => 'ask_back',
            'dedupe_key' => 't:ask_back',
            'original' => null,
            'better' => 'What about you?',
            'next_review_at' => now()->subMinute(),
        ]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Ask back')
            ->assertDontSee('What about you?')
            ->call('reveal')
            ->assertSee('What about you?');
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

    public function test_practice_goes_through_the_most_repeated_mistakes_without_touching_their_schedule(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();
        $first = NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => 3, 'original' => 'She go home.', 'better' => 'She goes home.', 'next_review_at' => now()->addWeek()]);
        $second = NotebookEntry::factory()->for($learner, 'learner')->create(['times_seen' => 1, 'original' => 'I am agree.', 'better' => 'I agree.']);
        $this->actingAs($learner);

        Livewire::test('notebook.index')
            ->call('startPractice')
            ->assertSet('practiceIds', [$first->id, $second->id])
            ->assertSee('She go home.')
            ->set('practiceAttempt', 'she goes home')
            ->call('checkPractice')
            ->assertSee('Spot on!')
            ->call('nextPractice')
            ->assertSee('I am agree.')
            ->call('showPracticeFix')
            ->assertSee('I agree.')
            ->call('nextPractice')
            ->assertSee('1 / 2 right');

        $this->assertTrue($first->fresh()->next_review_at->equalTo(now()->addWeek()));
        $this->assertSame(0, $first->fresh()->repetitions);
    }

    public function test_a_different_right_fix_counts_when_the_ai_accepts_it(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->for($learner, 'learner')->create(['original' => 'I am here since 3 years.', 'better' => "I've been here for 3 years.", 'next_review_at' => now()->subMinute()]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"correct": true}'));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', 'I have lived here for three years.')
            ->call('checkAttempt')
            ->assertSee('Spot on!');
    }

    public function test_when_the_ai_fails_only_the_saved_fix_counts(): void
    {
        $learner = User::factory()->create();
        NotebookEntry::factory()->for($learner, 'learner')->create(['original' => 'I am here since 3 years.', 'better' => "I've been here for 3 years.", 'next_review_at' => now()->subMinute()]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andThrow(new \RuntimeException('down')));
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', 'I have lived here for three years.')
            ->call('checkAttempt')
            ->assertDontSee('Spot on!')
            ->assertSee('You wrote: I have lived here for three years.');
    }
}
