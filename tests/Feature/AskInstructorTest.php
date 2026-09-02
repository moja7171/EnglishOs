<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Livewire;
use Tests\TestCase;

class AskInstructorTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'steps' => [['key' => 'grammar_in_context', 'label' => 'Grammar in Context']]],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_an_empty_question_does_nothing(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->call('ask')
            ->assertSet('messages', []);
    }

    public function test_asking_a_question_grounds_the_ai_in_the_learner_and_current_step(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($systemPrompt, 'Grammar in Context')
                    && str_contains($systemPrompt, 'never solve their current exercise')
                    && $messages[0]['text'] === 'What does "present simple" mean?')
                ->andReturn('It describes habits and routines, like "I go to work every day."');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'What does "present simple" mean?')
            ->call('ask')
            ->assertSet('messages.0.role', 'learner')
            ->assertSet('messages.0.text', 'What does "present simple" mean?')
            ->assertSet('messages.1.role', 'instructor')
            ->assertSee('habits and routines');
    }

    public function test_asking_a_question_never_creates_evidence_or_touches_progress(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('Some answer.');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'Can you explain articles?')
            ->call('ask');

        $this->assertDatabaseCount('evidences', 0);
        $this->assertSame('grammar_in_context', $run->fresh()->currentStepKey());
    }

    public function test_a_connection_failure_shows_a_friendly_retry_message(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(
                new ConnectionException('cURL error 7: Failed to connect() to host')
            );
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'Help?')
            ->call('ask')
            ->assertSee("Couldn't reach the AI Instructor")
            ->assertDontSee('cURL error');
    }

    public function test_the_question_field_clears_after_asking(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Answer.'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'A question')
            ->call('ask')
            ->assertSet('question', '');
    }
}
