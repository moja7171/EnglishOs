<?php

namespace Tests\Feature;

use App\Models\InstructorMessage;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        $this->actingAs($learner);

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
            ->assertSee("Couldn't reach Sage")
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

    public function test_both_sides_of_the_exchange_are_persisted_for_the_learner(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Here you go.'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'A question')
            ->call('ask');

        $this->assertDatabaseHas('instructor_messages', [
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'A question',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        $this->assertDatabaseHas('instructor_messages', [
            'role' => InstructorMessage::ROLE_INSTRUCTOR,
            'body' => 'Here you go.',
        ]);
    }

    public function test_returning_to_the_same_step_reloads_its_prior_conversation(): void
    {
        $run = $this->makeRun();

        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'What is a preposition?',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'role' => InstructorMessage::ROLE_INSTRUCTOR,
            'body' => 'It shows a relationship, like "in" or "on".',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        // A different step's history must never bleed into this one.
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'listening',
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'Unrelated question',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->assertSee('What is a preposition?')
            ->assertSee('It shows a relationship')
            ->assertDontSee('Unrelated question');
    }

    public function test_asking_by_voice_transcribes_and_persists_the_recording(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('What does "articles" mean?'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages) => $messages[0]['text'] === 'What does "articles" mean?')
                ->andReturn('"A", "an", and "the" are articles.');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('askWithVoice')
            ->assertSee('What does "articles" mean?')
            ->assertSee('"A", "an", and "the" are articles.');

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_a_recorded_voice_message_is_playable_from_the_thread(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('A question.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('An answer.'));

        $html = Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('askWithVoice')
            ->html();

        $saved = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();
        $this->assertStringContainsString(route('instructor.attachment', $saved), $html);
    }

    /**
     * A transcription failure used to silently throw the whole recording
     * away — the file was already uploaded and stored, then orphaned with
     * nothing in the database pointing to it, and the learner had no way
     * to even hear their own attempt back. It must now be saved as a real,
     * playable message instead, with no AI call for text that doesn't exist.
     */
    public function test_a_failed_voice_transcription_still_saves_the_recording(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('down')));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('askWithVoice')
            ->assertSee("Couldn't hear that clearly");

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();
        $this->assertSame(InstructorMessage::ROLE_LEARNER, $message->role);
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_sending_a_file_attaches_it_and_tells_the_ai_it_cannot_see_it(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages, $systemPrompt) => str_contains($messages[0]['text'], 'homework.pdf')
                    && str_contains($systemPrompt, 'cannot see its contents'))
                ->andReturn('Feel free to describe what it says.');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('fileAttachment', UploadedFile::fake()->create('homework.pdf', 200, 'application/pdf'))
            ->call('sendFile')
            ->assertSee('homework.pdf')
            ->assertSee('Feel free to describe what it says.');

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_FILE)->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    public function test_an_oversized_or_disallowed_file_is_rejected(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('fileAttachment', UploadedFile::fake()->create('virus.exe', 200))
            ->call('sendFile')
            ->assertHasErrors(['fileAttachment']);

        $this->assertDatabaseCount('instructor_messages', 0);
    }

    public function test_only_the_owning_learner_can_download_an_instructor_attachment(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();
        $stranger = User::factory()->create();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('A question.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('An answer.'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('askWithVoice');

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();

        $this->actingAs($stranger);
        $this->get(route('instructor.attachment', $message))->assertForbidden();

        $this->actingAs($run->learner);
        $this->get(route('instructor.attachment', $message))->assertOk();
    }
}
