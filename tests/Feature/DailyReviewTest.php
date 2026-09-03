<?php

namespace Tests\Feature;

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DailyReviewTest extends TestCase
{
    use RefreshDatabase;

    private function makeDueWord(User $learner): VocabularyWord
    {
        return VocabularyWord::create([
            'learner_id' => $learner->id,
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'next_review_at' => now()->subMinute(),
        ]);
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

    public function test_a_word_requires_revealing_the_meaning_before_grading(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->assertSee('commute')
            ->assertDontSee('Did you remember it?')
            ->call('gradeSelf', 5);

        $this->assertSame(0, $word->fresh()->repetitions);
    }

    public function test_revealing_a_word_then_grading_advances_its_schedule(): void
    {
        $learner = User::factory()->create();
        $word = $this->makeDueWord($learner);
        $this->actingAs($learner);

        Livewire::test('review.index')
            ->call('reveal')
            ->assertSee('to travel to work')
            ->call('gradeSelf', 5);

        $this->assertSame(1, $word->fresh()->repetitions);
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
}
