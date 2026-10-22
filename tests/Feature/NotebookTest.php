<?php

namespace Tests\Feature;

use App\Models\NotebookEntry;
use App\Models\User;
use App\Services\FeedbackNotebook;
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

    private function fix(string $original, string $corrected, string $pattern = 'present perfect with for'): array
    {
        return ['original' => $original, 'status' => 'fix', 'corrected' => $corrected, 'why' => 'برای مدت از «for» استفاده کن.', 'pattern' => $pattern];
    }

    public function test_the_same_mistake_in_a_new_sentence_bumps_one_line_instead_of_adding_another(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create();

        $this->record($learner, [$this->fix('I am here since 3 years.', "I've been here for 3 years.")]);
        $this->record($learner, [$this->fix('I work there since 2 years.', "I've worked there for 2 years.", 'Present perfect with for.')]);

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
}
