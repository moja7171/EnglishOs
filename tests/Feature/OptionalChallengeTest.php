<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * S6 of [[project_growth_without_discouragement_stories]]: difficulty a
 * learner picks up, never one handed to them. See <x-optional-challenge>
 * for the shared component and Mission::SCAFFOLD_MINIMAL for "last
 * third of the roadmap", the same gate S4 uses.
 */
class OptionalChallengeTest extends TestCase
{
    use RefreshDatabase;

    private function makeActivationRun(string $missionCode): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => $missionCode,
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [['phase' => 'build', 'steps' => [
                ['key' => 'ai_conversation_1', 'warm_up_task' => 'Write 5 personal sentences, then record 2 minutes.', 'interview_questions' => ['Q1']],
            ]]],
        ]);

        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['commute', 'exhausted']]),
        ]);

        return $run;
    }

    private function makeFinalChallengeRun(string $missionCode): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => $missionCode,
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [['phase' => 'apply', 'steps' => [
                ['key' => 'ai_conversation_2', 'rounds' => ['What do you do on weekends?', 'Describe your last holiday.']],
            ]]],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_the_component_renders_the_offered_label_and_wires_the_named_model(): void
    {
        $html = Blade::render(
            '<x-optional-challenge model="noThingChallenge" label="Try it without the thing?" />'
        );

        $this->assertStringContainsString('Try it without the thing?', $html);
        $this->assertStringContainsString('noThingChallenge = true', $html);
        $this->assertStringContainsString('Try it', $html);
        $this->assertStringContainsString('No thanks', $html);
    }

    public function test_activation_offers_the_challenge_only_in_the_last_third(): void
    {
        Livewire::test('missions.steps.ai-conversation1', ['run' => $this->makeActivationRun('M08')])
            ->assertDontSee('without the word chips');

        Livewire::test('missions.steps.ai-conversation1', ['run' => $this->makeActivationRun('M17')])
            ->assertSee('without the word chips');
    }

    public function test_final_challenge_offers_the_challenge_only_in_the_last_third(): void
    {
        Livewire::test('missions.steps.ai-conversation2', ['run' => $this->makeFinalChallengeRun('M08')])
            ->assertDontSee('no time to prepare');

        Livewire::test('missions.steps.ai-conversation2', ['run' => $this->makeFinalChallengeRun('M17')])
            ->assertSee('no time to prepare');
    }

    public function test_activation_still_completes_normally_in_the_last_third(): void
    {
        Storage::fake('public');
        $run = $this->makeActivationRun('M20');

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->times(5)->andReturn(json_encode(['severity' => 'none', 'hint' => ''])));
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribeWithConfidence')->once()->andThrow(new \RuntimeException('irrelevant to this test')));

        // The optional "no word chips" challenge only ever removes a
        // scaffold, never the requirement itself — the warm-up round
        // still finishes normally (moving into the interview) with it on.
        Livewire::test('missions.steps.ai-conversation1', ['run' => $run])
            ->set('sentences.0', 'I usually wake up at 7.')
            ->set('sentences.1', 'I have breakfast at 8.')
            ->set('sentences.2', 'I go to work by bus.')
            ->set('sentences.3', 'I exercise in the evening.')
            ->set('sentences.4', 'I go to bed at 11.')
            ->set('warmUpAudioFile', UploadedFile::fake()->create('rec.webm', 100, 'audio/webm'))
            ->call('finishWarmUp')
            ->assertHasNoErrors()
            ->assertSet('warmUpDone', true);
    }

    public function test_final_challenge_round_still_submits_normally_in_the_last_third(): void
    {
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('I usually relax and see friends.'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn(
            json_encode(['severity' => 'none', 'hint' => '']),
            'Nice, what do you usually do?',
        ));

        $run = $this->makeFinalChallengeRun('M20');

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->set('audioFile', UploadedFile::fake()->create('rec.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 1);
    }

    public function test_neither_challenge_appears_before_the_last_third(): void
    {
        foreach (['M01', 'M09', 'M16'] as $code) {
            Livewire::test('missions.steps.ai-conversation1', ['run' => $this->makeActivationRun($code)])
                ->assertDontSee('without the word chips');
        }
    }
}
