<?php

namespace Tests\Feature;

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Models\VocabularyWord;
use Database\Seeders\MissionSeeder;
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

    public function test_a_word_shows_the_front_of_a_recall_card_with_session_progress(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('commute')
            ->assertSee('Do you remember it?')
            ->assertSee('I remember')
            ->assertSee('1 / 1')
            ->assertSee('Skip for now')
            ->assertSeeHtml('x-data="recallCard"');
    }

    public function test_grading_a_word_advances_its_schedule_and_the_session_progress(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'repetitions' => 2, 'interval_days' => 6, 'next_review_at' => now()->subMinutes(5)]);
        $this->makeDueWord($learner, ['word' => 'errand', 'repetitions' => 2, 'interval_days' => 6, 'next_review_at' => now()->subMinutes(4)]);
        $this->makeDueWord($learner, ['word' => 'chore', 'repetitions' => 2, 'interval_days' => 6, 'next_review_at' => now()->subMinutes(3)]);
        $this->actingAs($learner);

        $component = Livewire::test('review.index');
        $currentWord = $component->instance()->currentItem['model'];

        $component->assertSee('1 / 3')
            ->call('gradeWord', 5, true)
            ->assertSee('2 / 3')
            ->assertSet('sessionGraded', 1)
            ->assertSet('sessionRemembered', 1);

        $this->assertSame(3, $currentWord->fresh()->repetitions);
    }

    public function test_the_back_of_a_word_card_has_its_details_and_grade_gaps(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, [
            'repetitions' => 2,
            'interval_days' => 6,
            'pos' => 'verb',
            'example' => 'I commute by train.',
            'user_sentence' => 'He commutes to the office every day.',
        ]);
        $this->actingAs($learner);

        $bold = '<strong class="font-bold text-accent-ink dark:text-accent-ink-dark">';

        Livewire::test('review.index')
            ->assertSee('to travel to work')
            ->assertSee('You wrote')
            ->assertSeeHtml($bold.'commute</strong> by train.')
            ->assertSeeHtml($bold.'commutes</strong> to the office every day.')
            ->assertSeeInOrder(['Again', '1d', 'Good', '15d', 'Easy', '20d']);
    }

    /**
     * A brand-new word used to be forced through an AI-checked written
     * review here; now every word, new or not, gets the same quick recall
     * card — the sentence was already written and checked in the mission.
     */
    public function test_a_brand_new_word_gets_the_same_recall_card_as_any_other(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner); // repetitions 0 — the model default

        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('I remember')
            ->assertDontSee('Write a sentence using this word.')
            ->call('gradeWord', 4, true);

        $this->assertSame(1, $word->fresh()->repetitions);
    }

    public function test_a_word_the_learner_was_not_sure_about_can_only_be_graded_as_forgotten(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 3, 'interval_days' => 16, 'ease_factor' => 2.8]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('gradeWord', 5, false)
            ->assertSet('sessionGraded', 1)
            ->assertSet('sessionRemembered', 0);

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertSame(1, $fresh->interval_days);
    }

    public function test_the_word_and_non_word_grading_paths_do_not_cross_over(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        // gradeSelf is for the other card types; a word must go through gradeWord.
        Livewire::test('review.index')
            ->set('revealed', true)
            ->call('gradeSelf', 5);

        $this->assertSame(2, $word->fresh()->repetitions);

        // ...and gradeWord must not grade a non-word card.
        $other = User::factory()->create();
        $error = $this->makeDueError($other);
        $this->actingAs($other);

        Livewire::test('review.index')->call('gradeWord', 5, true);

        $this->assertSame(0, $error->fresh()->repetitions);
    }

    public function test_forgetting_an_experienced_word_brings_it_back_tomorrow(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner, ['repetitions' => 2]);
        $this->actingAs($learner);

        Livewire::test('review.index')->call('gradeWord', 1, true);

        $fresh = $word->fresh();
        $this->assertSame(0, $fresh->repetitions);
        $this->assertFalse($fresh->isDue());
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $fresh->next_review_at->timestamp, 5);
    }

    public function test_finishing_the_session_shows_a_summary_of_what_was_remembered(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['word' => 'commute', 'next_review_at' => now()->subMinutes(2)]);
        $this->makeDueWord($learner, ['word' => 'errand', 'next_review_at' => now()->subMinute()]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('gradeWord', 4, true)
            ->assertDontSee('Session done!')
            ->call('gradeWord', 1, true)
            ->assertSee('Session done!')
            ->assertSee('2 items reviewed')
            ->assertSee('1 remembered')
            ->assertSee('1 coming back tomorrow')
            ->assertDontSee("You're all caught up");
    }

    public function test_arriving_on_an_already_finished_day_still_just_says_all_caught_up(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('all caught up')
            ->assertDontSee('Session done!');
    }

    public function test_non_word_cards_use_the_shared_frame_and_show_grade_gaps(): void
    {
        $learner = User::factory()->create();
        $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Grammar pattern')
            ->assertSee('1 / 1')
            ->assertDontSee('Again')
            ->call('reveal')
            ->assertSeeInOrder(['Again', '1d', 'Good', 'Easy']);
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

    public function test_todays_review_is_capped_at_the_daily_limit_with_the_most_overdue_first(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 10) as $minutesOverdue) {
            $this->makeDueWord($learner, ['word' => "word{$minutesOverdue}", 'next_review_at' => now()->subMinutes($minutesOverdue)]);
        }

        $items = $learner->dailyReviewItems();

        $this->assertCount(User::DAILY_REVIEW_LIMIT, $items);
        $this->assertSame(8, $learner->dailyReviewCount());
        $this->assertNotContains(
            VocabularyWord::where('word', 'word1')->value('id'),
            $items->pluck('id')->all(),
        );

        Livewire::test('missions.overview')->assertSee('8 items ready for Daily Review');
    }

    public function test_reviewing_an_item_uses_up_one_of_todays_eight_instead_of_pulling_in_a_ninth(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 10) as $minutesOverdue) {
            $this->makeDueWord($learner, ['word' => "word{$minutesOverdue}", 'next_review_at' => now()->subMinutes($minutesOverdue), 'repetitions' => 1]);
        }

        Livewire::test('review.index')->call('gradeWord', 4, true);

        $this->assertSame(1, $learner->reviewedTodayCount());
        $this->assertCount(7, $learner->dailyReviewItems());
        $this->assertSame(7, $learner->dailyReviewCount());
    }

    public function test_finishing_todays_batch_shows_reviewed_today_even_with_more_still_due(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 9) as $i) {
            $this->makeDueWord($learner, ['word' => "word{$i}", 'last_reviewed_at' => $i <= 8 ? now() : null, 'next_review_at' => $i <= 8 ? now()->addDay() : now()->subMinute()]);
        }

        $this->assertSame(0, $learner->dailyReviewCount());

        Livewire::test('missions.overview')
            ->assertSee('Reviewed today')
            ->assertDontSee('ready for Daily Review');

        Livewire::test('review.index')
            ->assertSee('review done');
    }

    public function test_the_todays_box_row_is_hidden_on_a_day_with_nothing_to_review(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Daily Review')
            ->assertDontSee('Reviewed today');
    }

    public function test_the_todays_box_row_breaks_the_batch_down_by_kind(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->makeDuePrompt($learner);
        $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('1 word · 1 grammar · 1 speaking');
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

    public function test_a_grammar_point_with_lesson_content_is_reviewed_as_a_question(): void
    {
        $this->seed(MissionSeeder::class);
        $learner = User::factory()->create();
        $point = $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('Fix this sentence')
            ->assertSee('She go to work at eight.')
            ->assertSee('She goes to work at eight.')
            ->assertDontSee('Remember')
            ->assertDontSee('Show a quick reminder')
            ->call('continueGrammar');

        $this->assertSame(0, $point->fresh()->repetitions);
    }

    public function test_answering_a_grammar_question_correctly_shows_the_rules_and_grades_good(): void
    {
        $this->seed(MissionSeeder::class);
        $learner = User::factory()->create();
        $point = $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('pickOption', 1)
            ->assertSee('Yes — that')
            ->assertSee('Remember')
            ->assertSeeHtml('<strong>he / she / it</strong>')
            ->assertSee('I usually wake up at 7.')
            ->call('continueGrammar')
            ->assertSet('sessionGraded', 1)
            ->assertSet('sessionRemembered', 1);

        $this->assertSame(1, $point->fresh()->repetitions);
    }

    public function test_answering_a_grammar_question_wrongly_grades_again(): void
    {
        $this->seed(MissionSeeder::class);
        $learner = User::factory()->create();
        $point = $this->makeDueGrammarPoint($learner);
        $point->update(['repetitions' => 3]);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('pickOption', 0)
            ->call('pickOption', 1) // the first tap is final
            ->assertSee('Not quite')
            ->call('continueGrammar')
            ->assertSet('sessionRemembered', 0);

        $this->assertSame(0, $point->fresh()->repetitions);
    }
}
