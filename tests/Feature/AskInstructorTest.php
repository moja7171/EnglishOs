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
     * conversation changed. It must still show the whole THREAD (now
     * topic-scoped, not step-scoped — see topicForStep()) across a step
     * change, as long as both steps share the same topic.
     */
    public function test_the_full_conversation_persists_across_a_step_change_mid_chat(): void
    {
        $run = $this->makeRun();

        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'listening',
            'topic' => InstructorMessage::TOPIC_GENERAL,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'What is a preposition?',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'listening',
            'topic' => InstructorMessage::TOPIC_GENERAL,
            'role' => InstructorMessage::ROLE_INSTRUCTOR,
            'body' => 'It shows a relationship, like "in" or "on".',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        // Asked from a different step, mid the same run — still 'general'
        // topic (only grammar_in_context/vocabulary_builder_* have their
        // own dedicated topic), so it must still show.
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'writing',
            'topic' => InstructorMessage::TOPIC_GENERAL,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'Can you give another example?',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'writing'])
            ->assertSee('What is a preposition?')
            ->assertSee('It shows a relationship')
            ->assertSee('Can you give another example?');
    }

    /**
     * Epic E: Sage keeps 3 separate persistent per-run threads instead of
     * one — a question asked from a grammar step must not leak into, or
     * pull context from, the vocabulary or general threads, and vice
     * versa, even within the very same mission run.
     */
    public function test_the_three_topic_threads_stay_isolated_from_each_other(): void
    {
        $run = $this->makeRun();

        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'topic' => InstructorMessage::TOPIC_GRAMMAR,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'A grammar-only question.',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'vocabulary_builder_1',
            'topic' => InstructorMessage::TOPIC_VOCABULARY,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'A vocabulary-only question.',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'listening',
            'topic' => InstructorMessage::TOPIC_GENERAL,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'A general question.',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->assertSee('A grammar-only question.')
            ->assertDontSee('A vocabulary-only question.')
            ->assertDontSee('A general question.')
            ->assertSee('Sage — Grammar chat');

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->assertSee('A vocabulary-only question.')
            ->assertDontSee('A grammar-only question.')
            ->assertDontSee('A general question.')
            ->assertSee('Sage — Vocabulary chat');

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'listening'])
            ->assertSee('A general question.')
            ->assertDontSee('A grammar-only question.')
            ->assertDontSee('A vocabulary-only question.');
    }

    public function test_a_new_question_is_answered_with_the_prior_conversation_as_context(): void
    {
        $run = $this->makeRun();

        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'topic' => InstructorMessage::TOPIC_GRAMMAR,
            'role' => InstructorMessage::ROLE_LEARNER,
            'body' => 'What is a preposition?',
            'type' => InstructorMessage::TYPE_TEXT,
        ]);
        InstructorMessage::create([
            'learner_id' => $run->learner_id,
            'mission_run_id' => $run->id,
            'step_key' => 'grammar_in_context',
            'topic' => InstructorMessage::TOPIC_GRAMMAR,
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

    /**
     * Voice used to only fill the text box for a manual send; it now
     * sends straight to Sage the moment it's recorded, same as every
     * other voice-recorder caller in the app — a real, playable message
     * (attachment + transcript) rather than a text-box shortcut.
     */
    public function test_recording_a_voice_question_sends_it_directly_as_a_real_message(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('What does "articles" mean?'));
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->withArgs(fn ($messages) => $messages[0]['text'] === 'What does "articles" mean?')
                ->andReturn('An article is a small word like "a", "an", or "the".');
        });

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('sendVoiceQuestion')
            ->assertSet('question', '')
            ->assertSet('voiceQuestion', null)
            ->assertSee('An article is a small word');

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();
        $this->assertSame('What does "articles" mean?', $message->body);
        Storage::disk('local')->assertExists($message->attachment_path);
    }

    /**
     * The recording is still kept as a real message even when
     * transcription fails — a silent "try again" would lose the
     * recording the learner just made; instead it's saved with a
     * fallback body so the audio itself is never lost.
     */
    public function test_a_failed_voice_transcription_still_sends_the_recording_with_a_fallback_body(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andThrow(new \RuntimeException('down')));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Could you type that instead?'));

        Livewire::test('missions.ask-instructor', ['run' => $run, 'stepKey' => 'grammar_in_context'])
            ->set('voiceQuestion', UploadedFile::fake()->create('question.webm', 100, 'audio/webm'))
            ->call('sendVoiceQuestion')
            ->assertSet('question', '')
            ->assertSet('voiceQuestion', null);

        $message = InstructorMessage::where('type', InstructorMessage::TYPE_VOICE)->firstOrFail();
        $this->assertSame("Couldn't transcribe this recording.", $message->body);
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
