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
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AiConversation2StepTest extends TestCase
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
                [
                    'phase' => 'mission',
                    'steps' => [
                        [
                            'key' => 'ai_conversation_2',
                            'rounds' => ['Describe your typical weekday.', 'Compare weekday and weekend.'],
                            'final_prompt' => 'Speak for 3 minutes about your daily life.',
                            'requirements' => ['Present Simple', '5+ vocabulary expressions'],
                        ],
                        ['key' => 'active_recall'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_full_flow_records_two_rounds_then_the_final_challenge_and_advances_the_run(): void
    {
        Storage::fake('local');
        $run = $this->makeRun();

        $this->mock(GroqClient::class, function ($mock) {
            $mock->shouldReceive('transcribe')
                ->times(3)
                ->andReturn('I get up and go to work.', 'Weekends are more relaxed.', 'I usually wake up at seven and I often cook dinner.');
        });
        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->times(3)
                ->andReturn(
                    'What time do you leave for work?',
                    'What do you enjoy most about weekends?',
                    json_encode([
                        'requirements' => ['Present Simple' => true, '5+ vocabulary expressions' => false],
                        'note' => 'Good use of Present Simple, try more vocabulary next time.',
                    ])
                );
        });

        $component = Livewire::test('missions.steps.ai-conversation2', ['run' => $run]);

        $component->set('audioFile', UploadedFile::fake()->create('r1.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 1);

        $component->set('audioFile', UploadedFile::fake()->create('r2.webm', 100, 'audio/webm'))
            ->call('submitRoundAnswer')
            ->assertSet('roundIndex', 2)
            ->assertSet('inFinalStage', true);

        $component->set('audioFile', UploadedFile::fake()->create('final.webm', 100, 'audio/webm'))
            ->call('submitFinalChallenge')
            ->assertSet('checklist', ['Present Simple' => true, '5+ vocabulary expressions' => false]);

        $component->call('finishConversation')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'ai_conversation_2')->first();
        $this->assertNotNull($evidence);
        $content = json_decode($evidence->content_ref, true);
        $this->assertCount(2, $content['rounds']);
        $this->assertTrue($content['requirements']['Present Simple']);

        $this->assertSame('active_recall', $run->fresh()->currentStepKey());
    }

    public function test_shows_its_hook_alongside_the_alpine_recorder_widget(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'mission',
                    'steps' => [[
                        'key' => 'ai_conversation_2',
                        'hook' => "This one's harder on purpose.",
                        'rounds' => ['Describe your typical weekday.'],
                        'final_prompt' => 'Speak for 3 minutes.',
                        'requirements' => ['Present Simple'],
                    ]],
                ],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.ai-conversation2', ['run' => $run])
            ->assertSee("This one's harder on purpose.");
    }
}
