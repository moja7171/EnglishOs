<?php

namespace Tests\Feature;

use App\Models\ErrorLogItem;
use App\Models\ErrorPatternReview;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ErrorLogStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRunWithEvidence(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'mission', 'steps' => [['key' => 'error_log'], ['key' => 'mission_result']]],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'ai_conversation_1',
            'type' => Evidence::TYPE_TRANSCRIPT,
            'content_ref' => json_encode([['question' => 'Q', 'answer' => 'She go to work.', 'followup' => 'F']]),
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'I usually goes to work by bus.',
        ]);

        return $run;
    }

    public function test_mount_does_not_call_gemini(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->assertSet('generated', false)
            ->assertSet('mistakes', [])
            ->assertSee('Finding your top mistakes…')
            ->assertSeeHtml('x-init="$wire.generate()"');
    }

    public function test_clicking_generate_calls_gemini_and_fills_mistakes(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.', 'why' => 'فعل نیاز به s سوم شخص دارد.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSet('generated', true)
            ->assertSet('mistakes', [[
                'error' => 'She go to work.',
                'correction' => 'She goes to work.',
                'why' => 'فعل نیاز به s سوم شخص دارد.',
                'category' => null,
            ]]);
    }

    public function test_the_learner_can_skip_the_step_when_the_review_cannot_be_generated(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andThrow(new ConnectionException('every model down'));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSet('generated', false)
            ->assertSee('Skip this step for now')
            ->call('skipForNow');

        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'error_log', 'content_ref' => '[]']);
        $this->assertDatabaseCount('error_log_items', 0);
    }

    public function test_the_why_explanation_is_shown_on_the_mistakes_screen(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                [
                    'error' => 'She go to work.',
                    'correction' => 'She goes to work.',
                    'why' => 'فعل نیاز به s سوم شخص دارد.',
                    'category' => 'third-person-s',
                ],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSee('Fix 1 of 1')
            ->assertSee('فعل نیاز به s سوم شخص دارد.');
    }

    public function test_the_closing_line_counts_the_mistakes(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
                ['error' => 'I usually goes.', 'correction' => 'I usually go.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSee('2 الگو رو اصلاح کردی؛ توی مرور دوباره میان.');
    }

    public function test_the_ai_assigned_category_is_persisted_for_recurrence_detection(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.', 'category' => 'third-person-s'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day by bus.')
            ->call('save');

        $this->assertSame('third-person-s', ErrorLogItem::first()->category);
    }

    public function test_the_why_field_is_persisted_on_the_error_log_item(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.', 'why' => 'فعل نیاز به s سوم شخص دارد.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day by bus.')
            ->call('save');

        $this->assertSame('فعل نیاز به s سوم شخص دارد.', ErrorLogItem::first()->why);
    }

    public function test_new_example_required_for_every_error_before_saving(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
                ['error' => 'I usually goes.', 'correction' => 'I usually go.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day.')
            ->call('save')
            ->assertHasErrors(['newExamples']);

        $this->assertDatabaseCount('error_log_items', 0);
    }

    public function test_each_mistakes_continue_waits_for_its_own_sentence(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
                ['error' => 'I usually goes.', 'correction' => 'I usually go.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSeeHtml('x-bind:disabled="! (filled[0] || false)"')
            ->set('newExamples.0', 'She goes to work every day.')
            ->assertSeeHtml('x-bind:disabled="! (filled[0] || true)"')
            ->assertSeeHtml('x-bind:disabled="! (filled[1] || false)"');
    }

    public function test_saving_persists_error_log_items_and_advances_the_run(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day by bus.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $item = ErrorLogItem::first();
        $this->assertSame('She go to work.', $item->error);
        $this->assertSame('She goes to work every day by bus.', $item->new_example);

        $this->assertSame('mission_result', $run->fresh()->currentStepKey());
    }

    public function test_no_mistakes_found_lets_the_learner_continue_immediately(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn('[]');
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->assertSet('mistakes', [])
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $this->assertDatabaseCount('error_log_items', 0);
        $this->assertSame('mission_result', $run->fresh()->currentStepKey());
    }

    public function test_read_only_mode_loads_from_error_log_items_without_calling_gemini(): void
    {
        $run = $this->makeRunWithEvidence();

        ErrorLogItem::create([
            'mission_run_id' => $run->id,
            'error' => 'She go to work.',
            'correction' => 'She goes to work.',
            'why' => 'فعل نیاز به s سوم شخص دارد.',
            'new_example' => 'She goes to work every day.',
        ]);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([['error' => 'She go to work.', 'correction' => 'She goes to work.']]),
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.error-log', ['run' => $run, 'readOnly' => true])
            ->assertSet('generated', true)
            ->assertSet('mistakes', [[
                'error' => 'She go to work.',
                'correction' => 'She goes to work.',
                'why' => 'فعل نیاز به s سوم شخص دارد.',
                'category' => null,
            ]])
            ->assertSet('newExamples', ['She goes to work every day.'])
            ->assertDontSee('Continue');
    }

    public function test_a_newly_recurring_category_starts_a_speaking_recall_style_error_review(): void
    {
        $learner = User::factory()->create();

        // The minimum for User::recurringErrorCategories() to flag it —
        // one prior mission already logged this exact category.
        $earlierMission = Mission::create([
            'code' => 'M-EARLIER',
            'title' => 'Earlier Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [],
        ]);
        $earlierRun = MissionRun::findOrStart($learner, $earlierMission);
        ErrorLogItem::create([
            'mission_run_id' => $earlierRun->id,
            'error' => 'He walk fast.',
            'correction' => 'He walks fast.',
            'category' => 'third-person-s',
        ]);

        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'mission', 'steps' => [['key' => 'error_log'], ['key' => 'mission_result']]],
            ],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'She go to work.',
        ]);

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.', 'category' => 'third-person-s'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day.')
            ->call('save');

        $review = ErrorPatternReview::where('learner_id', $learner->id)->firstOrFail();
        $this->assertSame('third-person-s', $review->category);
        $this->assertSame('She go to work.', $review->last_error);
        $this->assertTrue($review->isDue());
    }

    public function test_a_one_off_category_never_starts_an_error_review(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->once()->andReturn(json_encode([
                ['error' => 'She go to work.', 'correction' => 'She goes to work.', 'category' => 'third-person-s'],
            ]));
        });

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'She goes to work every day by bus.')
            ->call('save');

        $this->assertDatabaseCount('error_pattern_reviews', 0);
    }

    public function test_a_generated_review_comes_back_after_leaving_without_another_ai_call(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
        ])));

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('newExamples.0', 'My sister goes to school.');

        $returned = Livewire::test('missions.steps.error-log', ['run' => $run])
            ->assertSet('generated', true)
            ->assertSet('newExamples', ['My sister goes to school.']);

        $this->assertSame('She goes to work.', $returned->get('mistakes')[0]['correction']);
    }

    public function test_saying_the_new_sentence_fills_that_mistakes_box(): void
    {
        $run = $this->makeRunWithEvidence();

        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            ['error' => 'She go to work.', 'correction' => 'She goes to work.'],
        ])));
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('He goes to the gym on Mondays.'));

        Livewire::test('missions.steps.error-log', ['run' => $run])
            ->call('generate')
            ->set('dictation', UploadedFile::fake()->create('answer.webm', 100, 'audio/webm'))
            ->call('dictateExample', 0)
            ->assertSet('newExamples.0', 'He goes to the gym on Mondays.');
    }
}
