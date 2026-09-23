<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
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

    public function test_final_challenge_offers_the_challenge_only_in_the_last_third(): void
    {
        Livewire::test('missions.steps.ai-conversation2', ['run' => $this->makeFinalChallengeRun('M08')])
            ->assertDontSee('no time to prepare');

        Livewire::test('missions.steps.ai-conversation2', ['run' => $this->makeFinalChallengeRun('M17')])
            ->assertSee('no time to prepare');
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

}
