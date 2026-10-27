<?php

namespace Tests\Feature;

use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_the_missions_overview_shows_a_nudge_when_prompts_are_due(): void
    {
        $learner = User::factory()->create();
        $this->makeDuePrompt($learner);
        $this->actingAs($learner);

        Livewire::test('missions.overview')
            ->assertSee('Daily Review · 1 item');
    }

    public function test_the_old_speaking_page_sends_to_daily_review(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/speaking')->assertRedirect(route('review.index'));
    }
}
