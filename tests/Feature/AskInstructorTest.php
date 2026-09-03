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

    /**
     * A Livewire full-page navigation (Next/Previous between steps) tears
     * this component down and remounts it fresh regardless of scoping —
     * so if history were still filtered to $stepKey, an in-progress
     * conversation would visibly change or shrink the moment the learner
     * navigated mid-chat, even though nothing about the actual
     * conversation changed. It must now show the whole run's thread.
     */
    public function test_the_full_conversation_persists_across_a_step_change_mid_chat(): void
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
        // Asked from a different step, mid the same run — must still show.
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'listening',
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'Can you give another example?',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->assertSee('What is a preposition?')
            ->assertSee('It shows a relationship')
            ->assertSee('Can you give another example?');
    }

    public function test_a_new_question_is_answered_with_the_prior_conversation_as_context(): void
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

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(function ($messages) {
                    return count($messages) === 3
                        && $messages[0] === ['role' => 'user', 'text' => 'What is a preposition?']
                        && $messages[1] === ['role' => 'model', 'text' => 'It shows a relationship, like "in" or "on".']
                        && $messages[2] === ['role' => 'user', 'text' => 'Give me another example.'];
                })
                ->andReturn('Sure — "under the table" is another one!');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('question', 'Give me another example.')
            ->call('ask')
            ->assertSee('under the table');
    }

    public function test_recording_a_voice_question_only_fills_the_text_box_and_sends_nothing(): void
    {
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('What does "articles" mean?'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('transcribeVoiceQuestion')
            ->assertSet('question', 'What does "articles" mean?')
            ->assertSet('messages', []);

        $this->assertDatabaseCount('instructor_messages', 0);
    }

    /**
     * The recording is never persisted or lost either way — transcribing
     * it is only ever a shortcut for filling the text box, never a "send"
     * of its own, so a failure just means "try again" with nothing saved.
     */
    public function test_a_failed_voice_transcription_leaves_the_question_box_untouched(): void
    {
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('down')));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('transcribeVoiceQuestion')
            ->assertSee("Couldn't hear that clearly")
            ->assertSet('question', '');

        $this->assertDatabaseCount('instructor_messages', 0);
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

        Storage::disk('local')->put('instructor-messages/'.$run->learner_id.'/question.webm', 'fake audio');

        $message = InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'A question.',
            'type' => InstructorMessage::TYPE_VOICE,
            'attachment_path' => 'instructor-messages/'.$run->learner_id.'/question.webm',
            'attachment_name' => 'question.webm',
            'attachment_mime' => 'audio/webm',
        ]);

        $this->actingAs($stranger);
        $this->get(route('instructor.attachment', $message))->assertForbidden();

        $this->actingAs($run->learner);
        $this->get(route('instructor.attachment', $message))->assertOk();
    }
}
