<?php

namespace Tests\Feature;

use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SpeakingRecallTest extends TestCase
{
    use RefreshDatabase;

    private function makeDuePrompt(User $learner, array $attributes = []): SpeakingPrompt
    {
        return SpeakingPrompt::create(array_merge([
            'learner_id' => $learner->id,
            'prompt' => 'What time do you usually wake up?',
            'mission_code' => 'M01',
            'next_review_at' => now()->subMinute(),
        ], $attributes));
    }

    /**
     * Stands in for the Whisper + judge calls a recording triggers, so no
     * test ever reaches a real AI. Null feedback means the transcription
     * itself fails.
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

    public function test_a_learner_with_no_tracked_prompts_sees_an_empty_state(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertSee('Mission Result');
    }

    public function test_a_learner_with_nothing_due_sees_the_caught_up_state(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner, ['next_review_at' => now()->addWeek()]);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertSee('all caught up');
    }

    public function test_a_due_prompt_is_shown_and_grading_is_blocked_until_recorded(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertSee('What time do you usually wake up?')
            ->assertDontSee('How did that feel?')
            ->call('gradeSelf', 5);

        $this->assertSame(0, SpeakingPrompt::first()->repetitions);
    }

    public function test_recording_stores_the_file_and_unlocks_grading(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi();
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSet('recordedThisTurn', true)
            ->assertSee('How did that feel?');

        $prompt = SpeakingPrompt::firstOrFail();
        $this->assertNotNull($prompt->last_recording_url);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('speaking-recall/'.$learner->id));
    }

    public function test_grading_after_recording_reviews_the_prompt_and_advances(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi();
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner, ['prompt' => 'What time do you usually wake up?', 'next_review_at' => now()->subMinutes(5)]);
        $this->makeDuePrompt($learner, ['prompt' => 'What do you do after work?', 'next_review_at' => now()->subMinute()]);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->call('gradeSelf', 5)
            ->assertSee('What do you do after work?');

        $this->assertSame(1, $prompt->fresh()->repetitions);
    }

    public function test_a_recording_gets_one_line_of_ai_feedback_that_goes_away_with_the_prompt(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi('Say "I wake up at seven" instead of "I wakes up at seven".');
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertDontSee('I wake up at seven', false)
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSee('I wake up at seven', false)
            ->call('gradeSelf', 4)
            ->assertSet('feedback', null);
    }

    public function test_a_dead_ai_only_means_no_feedback_line_and_never_blocks_grading(): void
    {
        Storage::fake('public');
        $this->fakeSpeakingAi();
        $learner = User::factory()->create();
        $prompt = $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->set('recording', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('recorded')
            ->assertSet('feedback', null)
            ->assertSee('How did that feel?')
            ->call('gradeSelf', 4);

        $this->assertSame(1, $prompt->fresh()->repetitions);
    }

    public function test_only_the_learners_own_prompts_are_shown(): void
    {
        $learner = User::factory()->create();
        $other = User::factory()->create();
        $this->makeDuePrompt($other, ['prompt' => 'Someone else\'s question']);

        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertDontSee("Someone else's question");
    }

    public function test_the_missions_overview_shows_a_nudge_when_prompts_are_due(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Daily Review · 1 item');
    }

    public function test_the_browsable_list_shows_every_tracked_prompt(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner, ['prompt' => 'First question']);
        $this->makeDuePrompt($learner, ['prompt' => 'Second question', 'next_review_at' => now()->addDays(3)]);

        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertSeeHtml('All my questions (2)')
            ->assertSee('Due now')
            ->assertSeeHtml('Second question');
    }

    public function test_the_due_prompt_offers_practicing_it_with_a_mutual_friend(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $friend = User::factory()->create(['name' => 'Priya']);
        $learner->follow($friend);
        $friend->acceptFollowRequest($learner);

        $this->actingAs($learner);

        Livewire::test('speaking.index')
            ->assertSee('Practice this with a friend')
            ->assertSee('Priya');
    }
}
