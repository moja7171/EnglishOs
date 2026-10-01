<?php

namespace Tests\Feature;

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DailyReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeDueWord(User $learner, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => $learner->id,
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'next_review_at' => now()->subMinute(),
        ], $attributes));
    }

    private function makeDuePrompt(User $learner): SpeakingPrompt
    {
        return SpeakingPrompt::create([
            'learner_id' => $learner->id,
            'prompt' => 'What time do you usually wake up?',
            'next_review_at' => now()->subMinute(),
        ]);
    }

    private function makeDueError(User $learner): ErrorPatternReview
    {
        return ErrorPatternReview::create([
            'learner_id' => $learner->id,
            'category' => 'third-person-s',
            'last_error' => 'He walk fast.',
            'last_correction' => 'He walks fast.',
            'next_review_at' => now()->subMinute(),
        ]);
    }

    private function makeDueGrammarPoint(User $learner): GrammarPoint
    {
        return GrammarPoint::create([
            'learner_id' => $learner->id,
            'mission_code' => 'M01',
            'focus' => 'Present Simple + Adverbs of Frequency',
            'example_sentence' => 'I usually wake up at 7.',
            'rule_reminder' => 'The adverb goes before the main verb.',
            'next_review_at' => now()->subMinute(),
        ]);
    }

    public function test_a_learner_with_nothing_due_anywhere_sees_the_caught_up_state(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('all caught up');
    }

    public function test_the_queue_combines_every_due_source(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->makeDuePrompt($learner);
        $this->makeDueError($learner);
        $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        $queue = Livewire::test('review.index')->instance()->queue();

        $this->assertCount(4, $queue);
        $this->assertEqualsCanonicalizing(['word', 'speaking', 'error', 'grammar'], array_column($queue, 'type'));
    }

    public function test_an_experienced_word_requires_revealing_the_meaning_before_grading(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('commute')
            ->assertDontSee('Did you remember it?')
            ->call('gradeSelf', 5);

        $this->assertSame(2, $word->fresh()->repetitions);
    }

    public function test_revealing_an_experienced_word_then_grading_advances_its_schedule(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('reveal')
            ->assertSee('to travel to work')
            ->call('gradeSelf', 5);

        $this->assertSame(3, $word->fresh()->repetitions);
    }

    /**
     * Task 1 of the Daily Review UX audit: a brand-new word (or one just
     * knocked back to day 1 by a failed review) must get the exact same
     * AI-checked written-review flow My Words uses — see
     * VocabularyWord::needsWrittenReview() and ChecksVocabularyWordSentences.
     * Before this, Daily Review always showed the shallow reveal + tap-to-grade
     * flow regardless of needsWrittenReview(), letting a learner clear a
     * brand-new word's very first review with one tap on "Knew it instantly"
     * and never write a real sentence.
     */
    public function test_a_brand_new_due_word_shows_the_written_review_flow_not_the_quick_grade_buttons(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner); // repetitions 0 — the model default

        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('commute')
            ->assertSee('to travel to work')
            ->assertSee('Write a sentence using this word.')
            ->assertDontSee('Show meaning')
            ->assertDontSee('Knew it instantly');
    }

    /**
     * The actual bypass this whole task closes: gradeSelf() must refuse to
     * grade a word that still needsWrittenReview(), even if called
     * directly (e.g. a crafted wire:click), not merely hidden in the view.
     */
    public function test_grading_self_is_a_no_op_on_a_word_that_still_needs_a_written_review(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner); // repetitions 0

        $this->actingAs($learner);

        Livewire::test('review.index')->call('gradeSelf', 5);

        $this->assertSame(0, $word->fresh()->repetitions);
        $this->assertNull($word->fresh()->last_reviewed_at);
    }

    public function test_checking_a_good_sentence_in_daily_review_advances_the_word_and_shows_feedback(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));

        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('wordSentence', 'I commute to work by train every day.')
            ->call('checkWordSentence')
            ->assertSee('Looks good');

        $this->assertSame(1, $word->fresh()->repetitions);
    }

    public function test_a_major_issue_in_daily_review_sends_the_word_back_to_day_1(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->andReturn(json_encode(['severity' => 'major', 'hint' => 'The word is missing.'])));

        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('wordSentence', 'Not using the word at all.')
            ->call('checkWordSentence');

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    /**
     * "Forgot it" (gradeSelf(1), quality < 3) resets repetitions to 0 —
     * exactly needsWrittenReview()'s condition — so the same gate must
     * come right back the next time this word is due, in Daily Review same
     * as My Words.
     */
    public function test_forgetting_an_experienced_word_reactivates_the_written_review_gate(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('reveal')
            ->call('gradeSelf', 1);

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertTrue($fresh->needsWrittenReview());

        // A failed review schedules the next attempt a day out — pull it
        // back to "due now" to check what Daily Review would show THEN,
        // without waiting on the real calendar.
        $fresh->update(['next_review_at' => now()->subMinute()]);

        Livewire::test('review.index')
            ->assertSee('Write a sentence using this word.')
            ->assertDontSee('Show meaning');
    }

    public function test_an_error_pattern_requires_revealing_the_fix_before_grading(): void
    {
        $learner = User::factory()->create();
        $error = $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('He walk fast.')
            ->assertDontSee('He walks fast.')
            ->call('gradeSelf', 5);

        $this->assertSame(0, $error->fresh()->repetitions);
    }

    public function test_revealing_an_error_pattern_then_grading_advances_its_schedule(): void
    {
        $learner = User::factory()->create();
        $error = $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('reveal')
            ->assertSee('He walks fast.')
            ->call('gradeSelf', 5);

        $this->assertSame(1, $error->fresh()->repetitions);
    }

    public function test_a_grammar_point_requires_revealing_the_reminder_before_grading(): void
    {
        $learner = User::factory()->create();
        $point = $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Present Simple + Adverbs of Frequency')
            ->assertSee('I usually wake up at 7.')
            ->assertDontSee('The adverb goes before the main verb.')
            ->call('gradeSelf', 5);

        $this->assertSame(0, $point->fresh()->repetitions);
    }

    public function test_revealing_a_grammar_point_then_grading_advances_its_schedule(): void
    {
        $learner = User::factory()->create();
        $point = $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('reveal')
            ->assertSee('The adverb goes before the main verb.')
            ->call('gradeSelf', 5);

        $this->assertSame(1, $point->fresh()->repetitions);
    }

    public function test_a_speaking_prompt_requires_a_fresh_recording_before_grading(): void
    {
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('What time do you usually wake up?')
            ->assertDontSee('How did that feel?')
            ->call('gradeSelf', 5);

        $this->assertSame(0, $prompt->fresh()->repetitions);
    }

    public function test_recording_then_grading_a_speaking_prompt_advances_its_schedule(): void
    {
        Storage::fake('public');
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSee('How did that feel?')
            ->call('gradeSelf', 5);

        $this->assertSame(1, $prompt->fresh()->repetitions);
        $this->assertNotNull($prompt->fresh()->last_recording_url);
    }

    public function test_only_the_learners_own_items_are_shown(): void
    {
        $learner = User::factory()->create();
        $other = User::factory()->create();
        $this->makeDueWord($other);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('all caught up');
    }

    public function test_the_missions_overview_nudge_combines_every_source(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->makeDuePrompt($learner);
        $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('3 items ready for Daily Review');
    }

    /**
     * Task 3 of the Daily Review UX audit: a learner stuck on one item
     * (e.g. denied microphone access — see voice-recorder.blade.php) must
     * be able to move on to the rest of today's queue instead of every
     * later item becoming unreachable behind it. See skip() and
     * $skippedKeys.
     */
    public function test_skipping_the_current_item_moves_it_to_the_back_without_grading_it(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2]);
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        $test = Livewire::test('review.index');
        $before = $test->instance()->currentItem();
        // Identified by (type, id) rather than just ->id — a VocabularyWord
        // and a SpeakingPrompt have their own independent id sequences, so
        // comparing raw ids alone could coincidentally collide.
        $skippedType = $before['type'];
        $skippedModel = $before['model'];
        $skippedRepetitions = $skippedModel->repetitions;
        $skippedNextReviewAt = $skippedModel->next_review_at;

        $test->call('skip');

        $after = $test->instance()->currentItem();
        $this->assertNotSame([$skippedType, $skippedModel->id], [$after['type'], $after['model']->id]);

        // A skip must never behave like a review — SM-2's fields are
        // completely untouched.
        $fresh = $skippedModel->fresh();
        $this->assertSame($skippedRepetitions, $fresh->repetitions);
        $this->assertEqualsWithDelta($skippedNextReviewAt->timestamp, $fresh->next_review_at->timestamp, 2);
    }

    public function test_a_skipped_item_stays_in_the_queue_just_moved_to_the_end_not_removed(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2]);
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        $test = Livewire::test('review.index');
        $skippedType = $test->instance()->currentItem()['type'];

        $test->call('skip');

        $queueTypes = array_column($test->instance()->queue(), 'type');

        $this->assertCount(2, $queueTypes);
        $this->assertSame($skippedType, end($queueTypes));
    }

    /**
     * Skipping around the whole queue must not spin forever — once every
     * remaining item has been skipped this session, Daily Review shows a
     * distinct "come back later" message instead of silently looping back
     * to the first skipped item (see hasSkippedEverything()). Neither item
     * is graded in the process.
     */
    public function test_skipping_every_item_shows_a_come_back_later_message_without_looping(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2]);
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        $test = Livewire::test('review.index')
            ->call('skip')
            ->call('skip');

        $this->assertTrue($test->instance()->hasSkippedEverything());
        $test->assertSee('skipped everything'); // avoids the apostrophe in "You've" — assertSee HTML-escapes its needle by default, static Blade text does not

        $this->assertSame(2, $word->fresh()->repetitions);
        $this->assertNull($word->fresh()->last_reviewed_at);
        $this->assertSame(0, $prompt->fresh()->repetitions);
        $this->assertNull($prompt->fresh()->last_reviewed_at);
    }

    /**
     * Skip state is deliberately session-only (a plain Livewire component
     * property, never written to the database) — a brand-new page load
     * has no memory of an earlier skip, which is also how a skipped item
     * naturally "comes back" the next time the learner opens Daily Review.
     */
    public function test_skip_state_does_not_persist_to_a_fresh_page_load(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2]);
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')->call('skip')->call('skip');

        $fresh = Livewire::test('review.index');
        $this->assertFalse($fresh->instance()->hasSkippedEverything());
        $this->assertNotNull($fresh->instance()->currentItem());
    }
}
