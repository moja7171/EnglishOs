<?php

namespace Tests\Feature;

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DailyReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A word from a mission's New Words (a source run) — a word card. A
     * word with no source run is one the learner added themselves, and
     * may come as a dictation card instead.
     */
    private function makeDueWord(User $learner, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => $learner->id,
            'source_mission_run_id' => $this->missionRunFor($learner)->id,
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'next_review_at' => now()->subMinute(),
        ], $attributes));
    }

    /**
     * A word the learner has already remembered once (a six-day gap) —
     * strong enough to come as a dictation card.
     */
    private function makeStrongWord(User $learner, array $attributes = []): VocabularyWord
    {
        return $this->makeDueWord($learner, array_merge([
            'repetitions' => 2,
            'interval_days' => 6,
            'last_reviewed_at' => now()->subDays(6),
        ], $attributes));
    }

    private function missionRunFor(User $learner): MissionRun
    {
        $mission = Mission::firstOrCreate(['code' => 'M01'], ['title' => 'My Daily Life', 'module' => 'Me', 'outcome' => 'x', 'phases' => []]);

        return MissionRun::firstOrCreate(
            ['learner_id' => $learner->id, 'mission_id' => $mission->id],
            ['status' => MissionRun::STATUS_IN_PROGRESS, 'started_at' => now()],
        );
    }

    private function makeDuePrompt(User $learner): SpeakingPrompt
    {
        return SpeakingPrompt::create([
            'learner_id' => $learner->id,
            'prompt' => 'What time do you usually wake up?',
            'next_review_at' => now()->subMinute(),
        ]);
    }

    /**
     * Stands in for the Whisper + judge calls a speaking recording
     * triggers, so no test ever reaches a real AI. Null feedback means
     * the transcription itself fails.
     */
    private function fakeSpeakingAi(?string $feedback = null): void
    {
        $this->mock(GroqClient::class, function ($mock) use ($feedback) {
            $expectation = $mock->shouldReceive('transcribe');
            $feedback === null
                ? $expectation->andThrow(new \RuntimeException('Whisper is down.'))
                : $expectation->andReturn('I wakes up at seven.');
        });

        if ($feedback !== null) {
            $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn($feedback));
        }
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
            ->assertSee('✕ Forget')
            ->assertSee('✓ I know')
            ->assertSee('1/1')
            ->assertSee('Skip');
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

        $component->assertSee('1/3')
            ->call('gradeWord', 5, true)
            ->assertSee('2/3')
            ->assertSet('sessionGraded', 1)
            ->assertSet('sessionRemembered', 1);

        $this->assertSame(3, $currentWord->fresh()->repetitions);
    }

    public function test_a_word_card_has_its_meaning_and_example_a_tap_away(): void
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
            ->assertSeeHtml($bold.'commute</strong> by train.');
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
            ->assertSee('✓ I know')
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

        // gradeSelf is for the grammar-reminder card; a word must go through gradeWord.
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

    public function test_after_todays_six_words_the_review_says_how_many_more_are_waiting(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, 9) as $number) {
            $this->makeDueWord($learner, ['word' => 'word'.$number, 'next_review_at' => now()->subMinutes($number)]);
        }

        $this->actingAs($learner);

        $page = Livewire::test('review.index', ['track' => 'words']);

        foreach (range(1, 6) as $ignored) {
            $page->call('gradeWord', 4, true);
        }

        $page->assertSee('Session done!')
            ->assertSee('3 more waiting · about 1 day');
    }

    public function test_arriving_on_an_already_finished_day_still_just_says_all_caught_up(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('all caught up')
            ->assertDontSee('Session done!');
    }

    public function test_a_grammar_reminder_uses_the_lesson_frame_and_two_buttons_after_the_reveal(): void
    {
        $learner = User::factory()->create();
        $this->makeDueGrammarPoint($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('1/1')
            ->assertDontSee('✓ I know')
            ->call('reveal')
            ->assertSee('✕ Forget')
            ->assertSee('✓ I know');
    }

    public function test_an_error_pattern_requires_revealing_the_fix_before_grading(): void
    {
        $learner = User::factory()->create();
        $error = $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('He walk fast.')
            ->assertDontSee('He walks fast.')
            ->call('continueError');

        $this->assertSame(0, $error->fresh()->repetitions);
    }

    public function test_an_error_pattern_has_no_self_grading_buttons_and_a_right_fix_grades_good(): void
    {
        $learner = User::factory()->create();
        $error = $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', 'He walks fast.')
            ->call('checkAttempt')
            ->assertSee('Spot on!')
            ->assertDontSee('✓ I know')
            ->assertDontSee('✕ Forget')
            ->call('continueError');

        $this->assertSame(1, $error->fresh()->repetitions);
    }

    public function test_a_wrong_fix_or_just_showing_it_grades_an_error_pattern_as_forgotten(): void
    {
        $learner = User::factory()->create();
        $wrong = $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', 'He walking fast.')
            ->call('checkAttempt')
            ->assertSee('Not quite')
            ->call('continueError');

        $this->assertSame(0, $wrong->fresh()->repetitions);

        $learner2 = User::factory()->create();
        $shown = $this->makeDueError($learner2);
        $this->actingAs($learner2);

        Livewire::test('review.index')
            ->call('reveal')
            ->call('continueError');

        $this->assertSame(0, $shown->fresh()->repetitions);
    }

    public function test_writing_the_correct_fix_for_an_error_pattern_says_spot_on_ignoring_case_and_the_full_stop(): void
    {
        $learner = User::factory()->create();
        $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', '  he WALKS fast ')
            ->call('checkAttempt')
            ->assertSet('revealed', true)
            ->assertSee('Spot on!')
            ->assertSee('He walks fast.');
    }

    public function test_a_wrong_attempt_at_an_error_pattern_shows_what_they_wrote_next_to_the_fix(): void
    {
        $learner = User::factory()->create();
        $this->makeDueError($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('attempt', 'He walking fast.')
            ->call('checkAttempt')
            ->assertDontSee('Spot on!')
            ->assertSee('You wrote: He walking fast.')
            ->assertSee('He walks fast.');
    }

    public function test_an_empty_attempt_does_not_reveal_the_fix_and_the_attempt_is_cleared_for_the_next_card(): void
    {
        $learner = User::factory()->create();
        $error = $this->makeDueError($learner);
        $this->actingAs($learner);

        $test = Livewire::test('review.index')
            ->set('attempt', '   ')
            ->call('checkAttempt')
            ->assertSet('revealed', false)
            ->assertDontSee('He walks fast.');

        $test->set('attempt', 'He walks fast.')->call('checkAttempt')->call('continueError');

        $test->assertSet('attempt', '');
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

    public function test_a_speaking_prompt_requires_a_fresh_recording_before_continuing(): void
    {
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('What time do you usually wake up?')
            ->assertDontSee('How did that feel?')
            ->call('continueSpeaking');

        $this->assertSame(0, $prompt->fresh()->repetitions);
    }

    public function test_recording_a_speaking_prompt_then_continuing_grades_it_good_without_self_grading_buttons(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi();
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSee('Continue')
            ->assertDontSee('✓ I know')
            ->assertDontSee('✕ Forget')
            ->call('continueSpeaking');

        $this->assertSame(1, $prompt->fresh()->repetitions);
        $this->assertNotNull($prompt->fresh()->last_recording_url);
    }

    public function test_a_speaking_recording_gets_one_line_of_ai_feedback_that_goes_away_with_the_card(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi('Say "I wake up at seven" instead of "I wakes up at seven".');
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertDontSee('instead of')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSee('I wake up at seven', false)
            ->call('continueSpeaking')
            ->assertSet('feedback', null);
    }

    public function test_a_dead_ai_only_means_no_feedback_line_and_never_blocks_grading(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi();
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSet('feedback', null)
            ->assertSee('Continue')
            ->call('continueSpeaking');

        $this->assertSame(1, $prompt->fresh()->repetitions);
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
            ->assertSeeInOrder(['Daily Review', '3 items']);
    }

    public function test_todays_words_are_capped_at_the_daily_limit_with_the_most_overdue_first(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 8) as $minutesOverdue) {
            $this->makeDueWord($learner, ['word' => "word{$minutesOverdue}", 'next_review_at' => now()->subMinutes($minutesOverdue)]);
        }

        $items = $learner->dailyReviewItems();

        $this->assertCount(User::DAILY_WORD_LIMIT, $items);
        $this->assertSame(6, $learner->dailyReviewCount());
        $this->assertNotContains(
            VocabularyWord::where('word', 'word1')->value('id'),
            $items->pluck('id')->all(),
        );

        Livewire::test('missions.overview')->assertSeeInOrder(['Daily Review', '6 items']);
    }

    public function test_reviewing_a_word_uses_up_one_of_todays_six_instead_of_pulling_in_a_seventh(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 8) as $minutesOverdue) {
            $this->makeDueWord($learner, ['word' => "word{$minutesOverdue}", 'next_review_at' => now()->subMinutes($minutesOverdue), 'repetitions' => 1]);
        }

        Livewire::test('review.index', ['track' => 'words'])->call('gradeWord', 4, true);

        $this->assertSame(1, $learner->reviewedTodayCount());
        $this->assertCount(5, $learner->dailyReviewItems());
        $this->assertSame(5, $learner->dailyReviewCount());
    }

    public function test_finishing_todays_batch_shows_reviewed_today_even_with_more_still_due(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        foreach (range(1, 7) as $i) {
            $this->makeDueWord($learner, ['word' => "word{$i}", 'last_reviewed_at' => $i <= 6 ? now() : null, 'next_review_at' => $i <= 6 ? now()->addDay() : now()->subMinute()]);
        }

        $this->assertSame(0, $learner->dailyReviewCount());

        Livewire::test('missions.overview')
            ->assertSee('Daily Review')
            ->assertDontSee('Daily Review ·');

        Livewire::test('review.index')
            ->assertSee('review done');
    }

    public function test_the_todays_box_row_is_hidden_on_a_day_with_nothing_to_review(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertDontSee('Daily Review');
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
            ->assertSeeHtml('<strong>he / she / it</strong>')
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

    public function test_a_strong_word_comes_as_dictation_whether_or_not_a_mission_taught_it(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeStrongWord($learner, ['meaning_fa' => 'رفت‌وآمد']);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])
            ->assertSee('Dictation')
            ->assertSee('🐢 Slower')
            ->assertDontSee('✓ I know')
            ->set('spelling', 'comut')
            ->call('checkSpelling')
            ->assertSet('spellingCorrect', false)
            ->assertSee('Almost!')
            ->assertSeeHtml('<span class="text-success dark:text-success-dark">m</span>')
            ->call('continueDictation')
            ->assertSet('sessionGraded', 1)
            ->assertSet('sessionRemembered', 0);

        $this->assertSame(0, $word->fresh()->repetitions);
    }

    public function test_a_right_spelling_moves_the_word_up_a_box(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeStrongWord($learner);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])
            ->set('spelling', 'Commute.')
            ->call('checkSpelling')
            ->assertSet('spellingCorrect', true)
            ->call('continueDictation')
            ->assertSet('sessionRemembered', 1);

        $this->assertSame(3, $word->fresh()->repetitions);
    }

    public function test_every_strong_word_that_can_be_spelled_is_a_dictation_with_no_daily_cap(): void
    {
        $learner = User::factory()->create();
        foreach (['commute', 'oversleep', 'errand', 'hang out', 'chore'] as $text) {
            $this->makeStrongWord($learner, ['word' => $text]);
        }
        $this->actingAs($learner);

        $queue = Livewire::test('review.index', ['track' => 'words'])->instance()->queue();

        $this->assertSame(5, collect($queue)->where('type', 'dictation')->count());
    }

    public function test_a_word_still_being_met_for_the_first_time_gets_the_plain_card(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner, ['source_mission_run_id' => null]);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])
            ->assertDontSee('Dictation')
            ->assertSee('✓ I know');
    }

    public function test_a_word_that_cannot_be_spelled_from_hearing_it_keeps_the_plain_card(): void
    {
        $learner = User::factory()->create();
        $this->makeStrongWord($learner, ['word' => 'tell (someone) off']);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])
            ->assertDontSee('Dictation')
            ->assertSee('✓ I know');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function dictatableWords(): array
    {
        return [
            'one word' => ['commute', true],
            'a phrase of three' => ['a bit of', true],
            'a hyphen' => ['work-life balance', true],
            'an apostrophe' => ["don't mind", true],
            'four words' => ['get on well with', false],
            'a gap for a person' => ['get (someone) into (something)', false],
            'a gap with a possessive' => ["open (someone's) eyes", false],
        ];
    }

    #[DataProvider('dictatableWords')]
    public function test_which_words_can_be_dictated(string $word, bool $expected): void
    {
        $this->assertSame($expected, (new VocabularyWord(['word' => $word]))->canBeDictated());
    }

    public function test_a_curly_apostrophe_and_a_space_for_a_hyphen_are_still_right(): void
    {
        $learner = User::factory()->create();
        $this->makeStrongWord($learner, ['word' => "don't mind"]);
        $this->makeStrongWord($learner, ['word' => 'ex-partner']);
        $this->actingAs($learner);
        $page = Livewire::test('review.index', ['track' => 'words']);

        foreach (['Don’t mind', 'ex partner'] as $typed) {
            $page->set('spelling', $typed)->call('checkSpelling')->assertSet('spellingCorrect', true)->call('continueDictation');
        }
    }

    public function test_not_being_able_to_listen_turns_the_card_into_a_plain_word_without_grading_it(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeStrongWord($learner);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])
            ->assertSee('Dictation')
            ->call('cantListen')
            ->assertDontSee('Dictation')
            ->assertSee('✓ I know')
            ->assertSet('sessionGraded', 0);

        $this->assertSame(2, $word->fresh()->repetitions);
        $this->assertSame(6, $word->fresh()->interval_days);
    }

    public function test_each_path_shows_only_its_own_items(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        $words = Livewire::test('review.index', ['track' => 'words'])->instance()->queue();
        $talk = Livewire::test('review.index', ['track' => 'talk'])->instance()->queue();

        $this->assertSame(['word'], collect($words)->pluck('type')->all());
        $this->assertSame(['speaking'], collect($talk)->pluck('type')->all());
    }

    public function test_the_two_paths_have_their_own_daily_limits(): void
    {
        $learner = User::factory()->create();
        foreach (range(1, 8) as $number) {
            $this->makeDueWord($learner, ['word' => "word{$number}"]);
        }
        foreach (range(1, 8) as $ignored) {
            $this->makeDuePrompt($learner);
        }

        $this->assertCount(6, $learner->dailyReviewItems('words'));
        $this->assertCount(6, $learner->dailyReviewItems('talk'));
        $this->assertSame(12, $learner->dailyReviewCount());
    }

    public function test_six_more_words_brings_the_next_due_ones_and_then_the_ones_coming_up_soonest(): void
    {
        $learner = User::factory()->create();
        foreach (range(1, 8) as $number) {
            $this->makeDueWord($learner, ['word' => "due{$number}", 'next_review_at' => now()->subMinutes($number)]);
        }
        foreach (range(1, 8) as $number) {
            $this->makeDueWord($learner, ['word' => "soon{$number}", 'next_review_at' => now()->addDays($number)]);
        }
        $this->actingAs($learner);

        $page = Livewire::test('review.index', ['track' => 'words']);
        foreach (range(1, 6) as $ignored) {
            $page->call('gradeWord', 4, true);
        }

        $page->assertSee('Session done!')->assertSee('6 more words')->call('loadMore')->assertSet('more', 1);

        $words = collect($page->instance()->queue())->map(fn (array $entry) => VocabularyWord::find($entry['id'])->word)->sort()->values()->all();

        $this->assertSame(['due1', 'due2', 'soon1', 'soon2', 'soon3', 'soon4'], $words);
    }

    public function test_six_more_on_the_speaking_and_grammar_path_brings_the_next_due_ones_only(): void
    {
        $learner = User::factory()->create();
        foreach (range(1, 8) as $ignored) {
            $this->makeDuePrompt($learner);
        }
        $this->actingAs($learner);

        $page = Livewire::test('review.index', ['track' => 'talk']);

        $this->assertCount(6, $page->instance()->queue());
        $this->assertTrue($page->instance()->moreAvailable());

        $page->call('loadMore')->assertSet('more', 1);

        $this->assertCount(8, $page->instance()->queue());
    }

    public function test_six_more_is_not_offered_when_nothing_more_is_due_on_the_speaking_and_grammar_path(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'talk'])
            ->call('loadMore')
            ->assertSet('more', 0);
    }

    public function test_finishing_one_path_fills_the_streak_ring_even_with_the_other_still_waiting(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->makeDueWord($learner, ['repetitions' => 1]);
        $this->actingAs($learner);

        Livewire::test('review.index', ['track' => 'words'])->call('gradeWord', 4, true);

        $this->assertDatabaseHas('day_activities', ['learner_id' => $learner->id, 'activity' => 'review']);
    }

    public function test_the_review_page_offers_both_paths_with_what_is_left_in_each(): void
    {
        $learner = User::factory()->create();
        $this->makeDueWord($learner);
        $this->makeDuePrompt($learner);

        $this->actingAs($learner)->get(route('review.index'))
            ->assertOk()
            ->assertSee('Words')
            ->assertSee('Speaking &amp; grammar', false)
            ->assertSee('1 left today')
            ->assertSee(route('review.track', 'words'), false)
            ->assertSee(route('review.track', 'talk'), false);
    }

    public function test_only_the_two_paths_exist(): void
    {
        $this->actingAs(User::factory()->create())->get('/review/everything')->assertNotFound();
    }
}
