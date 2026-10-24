<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\DirectMessage;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\AiFailure;
use App\Services\AiLimitReached;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every AI request made for a learner is logged for the admin page, and
 * the admin's daily cap on the app's key is enforced (see AiUsage).
 */
class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI_URL = 'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'app-key',
            'services.gemini.chat_models' => ['model-a'],
            'services.gemini.judge_models' => ['model-a'],
            'services.groq.key' => 'app-groq-key',
            'services.groq.whisper_models' => ['whisper-a'],
        ]);
    }

    private function geminiReply(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function askGemini(): string
    {
        return app(GeminiClient::class)->chat([['role' => 'user', 'text' => 'Hi']]);
    }

    public function test_a_request_on_the_app_key_is_logged_for_the_learner(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply('Hello.'))]);
        $learner = User::factory()->create();
        $this->actingAs($learner);

        $this->askGemini();

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $learner->id,
            'provider' => 'gemini',
            'profile' => 'chat',
            'key_source' => 'app',
            'outcome' => 'ok',
        ]);
    }

    public function test_a_failing_own_key_is_logged_as_failed_and_the_app_key_fallback_as_ok(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => fn (Request $request) => $request->header('x-goog-api-key') === ['my-key']
            ? Http::response(['error' => ['code' => 429, 'message' => 'No.']], 429)
            : Http::response($this->geminiReply('From the app key.'))]);
        $learner = User::factory()->create();
        $learner->forceFill(['gemini_api_key' => 'my-key'])->save();
        $this->actingAs($learner);

        $this->askGemini();

        $this->assertSame(
            [['own', 'failed'], ['app', 'ok']],
            $learner->aiUsageLogs()->orderBy('id')->get()->map(fn (AiUsageLog $log) => [$log->key_source, $log->outcome])->all(),
        );
    }

    public function test_a_groq_transcription_is_logged_as_whisper(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/openai/v1/audio/transcriptions' => Http::response(['text' => 'Hello.'])]);
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $audio = tempnam(sys_get_temp_dir(), 'usage-audio');
        file_put_contents($audio, 'fake-audio-bytes');

        app(GroqClient::class)->transcribe($audio);

        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $learner->id, 'provider' => 'groq', 'profile' => 'whisper', 'outcome' => 'ok']);
    }

    public function test_the_daily_limit_refuses_the_app_key_once_it_is_used_up(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply('Hello.'))]);
        $learner = User::factory()->create();
        $learner->forceFill(['ai_daily_limit' => 1])->save();
        $this->actingAs($learner);
        $this->askGemini();

        try {
            $this->askGemini();
            $this->fail('The second request should have been refused.');
        } catch (AiLimitReached) {
            // expected
        }

        Http::assertSentCount(1);
        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $learner->id, 'outcome' => 'blocked']);
    }

    public function test_yesterdays_requests_do_not_count_toward_todays_limit(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply('Hello.'))]);
        $learner = User::factory()->create();
        $learner->forceFill(['ai_daily_limit' => 1])->save();
        AiUsageLog::factory()->for($learner)->create(['created_at' => now()->subDay()]);
        $this->actingAs($learner);

        $this->assertSame('Hello.', $this->askGemini());
    }

    public function test_ai_turned_off_still_lets_the_learners_own_key_answer(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply('From my key.'))]);
        $learner = User::factory()->create();
        $learner->forceFill(['ai_daily_limit' => 0, 'gemini_api_key' => 'my-key'])->save();
        $this->actingAs($learner);

        $this->assertSame('From my key.', $this->askGemini());
    }

    public function test_the_learner_is_told_to_come_back_tomorrow_when_the_limit_is_reached(): void
    {
        $message = AiFailure::message(new AiLimitReached('Limit reached.'), "Couldn't check this one");

        $this->assertSame("Couldn't check this one — you've used today's AI checks. Try again tomorrow.", $message);
    }

    public function test_a_request_from_a_mission_step_is_logged_with_its_mission_and_step(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply(json_encode(['severity' => 'none', 'hint' => ''])))]);
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [[
                    'key' => 'vocabulary_builder_1',
                    'story' => 'I **wake up** early.',
                    'words' => [['phrase' => 'wake up', 'meaning' => 'to stop sleeping']],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.vocabulary-builder', ['run' => $run, 'stepKey' => 'vocabulary_builder_1'])
            ->set('examples.0', 'I wake up at seven every day.')
            ->call('checkOne', 0);

        $this->assertDatabaseHas('ai_usage_logs', ['user_id' => $learner->id, 'mission_code' => 'M01', 'step_key' => 'vocabulary_builder_1']);
    }

    public function test_a_feedback_check_past_the_hourly_cap_is_logged_as_limited(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);
        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Hello!']);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn(json_encode(['sentences' => [], 'techniques' => []])));
        $this->actingAs($me);
        $chat = Livewire::test('friends.conversation', ['other' => $bob]);

        foreach (range(1, 11) as $attempt) {
            $chat->call('generateFeedback');
        }

        $this->assertSame(1, AiUsageLog::query()->where('user_id', $me->id)->where('outcome', 'limited')->count());
    }

    /**
     * A friends chat with one line of mine in it, ready for generateFeedback.
     */
    private function chatWithAFriend(User $me): Testable
    {
        $bob = User::factory()->create();
        $me->follow($bob);
        $bob->acceptFollowRequest($me);
        DirectMessage::create(['sender_id' => $me->id, 'recipient_id' => $bob->id, 'type' => DirectMessage::TYPE_MESSAGE, 'body' => 'Hello!']);
        $this->actingAs($me);

        return Livewire::test('friends.conversation', ['other' => $bob]);
    }

    public function test_feedback_answered_by_the_learners_own_key_is_not_held_to_the_hourly_cap(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => Http::response($this->geminiReply($this->learnerFeedbackReply()))]);
        $me = User::factory()->create();
        $me->forceFill(['gemini_api_key' => 'my-key'])->save();
        $chat = $this->chatWithAFriend($me);

        foreach (range(1, 11) as $attempt) {
            $chat->call('generateFeedback')->assertSet('feedbackError', null);
        }

        Http::assertSent(fn (Request $request) => $request->header('x-goog-api-key') === ['my-key']);
        Http::assertNotSent(fn (Request $request) => $request->header('x-goog-api-key') === ['app-key']);
        $this->assertSame(0, AiUsageLog::query()->where('user_id', $me->id)->where('outcome', 'limited')->count());
    }

    public function test_past_the_hourly_cap_the_app_key_no_longer_answers_for_a_failing_own_key(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GEMINI_URL => fn (Request $request) => $request->header('x-goog-api-key') === ['my-key']
            ? Http::response(['error' => ['code' => 429, 'message' => 'No.']], 429)
            : Http::response($this->geminiReply($this->learnerFeedbackReply()))]);
        $me = User::factory()->create();
        $me->forceFill(['gemini_api_key' => 'my-key'])->save();
        $chat = $this->chatWithAFriend($me);

        foreach (range(1, 10) as $attempt) {
            $chat->call('generateFeedback')->assertSet('feedbackError', null);
        }

        $chat->call('generateFeedback')
            ->assertSet('feedback', null)
            ->assertSet('feedbackError', "Your AI key didn't answer — try again later.");

        $this->assertSame(10, $me->aiUsageLogs()->where('key_source', 'app')->where('outcome', 'ok')->count());
        $this->assertSame(1, $me->aiUsageLogs()->where('key_source', 'app')->where('outcome', 'blocked')->count());
    }
}
