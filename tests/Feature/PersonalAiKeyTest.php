<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiModelChain;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A learner's own Gemini/Groq key (Profile → AI keys): tried first for
 * every AI call made for them, with the app's key as the silent fallback.
 */
class PersonalAiKeyTest extends TestCase
{
    use RefreshDatabase;

    private function geminiReply(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    private function askGemini(): string
    {
        config(['services.gemini.key' => 'app-key', 'services.gemini.chat_models' => ['model-a']]);

        return app(GeminiClient::class)->chat([['role' => 'user', 'text' => 'Hi']]);
    }

    private function sentWithGeminiKey(string $key): int
    {
        return Http::recorded(fn (Request $request) => $request->header('x-goog-api-key') === [$key])->count();
    }

    public function test_the_learners_own_gemini_key_answers_when_it_works(): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response($this->geminiReply('From my key.'))]);
        $this->actingAs(User::factory()->create());
        auth()->user()->forceFill(['gemini_api_key' => 'my-key'])->save();

        $reply = $this->askGemini();

        $this->assertSame('From my key.', $reply);
        $this->assertSame(1, $this->sentWithGeminiKey('my-key'));
        $this->assertSame(0, $this->sentWithGeminiKey('app-key'));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingOwnKeyStatuses(): array
    {
        return [
            'quota used up' => [429],
            'key rejected' => [400],
        ];
    }

    #[DataProvider('failingOwnKeyStatuses')]
    public function test_a_failing_own_gemini_key_falls_back_to_the_app_key_without_taking_the_model_out_of_rotation(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => fn (Request $request) => $request->header('x-goog-api-key') === ['my-key']
            ? Http::response(['error' => ['code' => $status, 'message' => 'No.']], $status)
            : Http::response($this->geminiReply('From the app key.'))]);
        $this->actingAs(User::factory()->create());
        auth()->user()->forceFill(['gemini_api_key' => 'my-key'])->save();

        $reply = $this->askGemini();

        $this->assertSame('From the app key.', $reply);
        $this->assertSame(1, $this->sentWithGeminiKey('app-key'));
        $this->assertNull((new AiModelChain)->unavailableState('gemini', 'model-a'));
    }

    public function test_a_learner_without_an_own_key_uses_only_the_app_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response($this->geminiReply('From the app key.'))]);
        $this->actingAs(User::factory()->create());

        $this->askGemini();

        $this->assertSame(1, $this->sentWithGeminiKey('app-key'));
        Http::assertSentCount(1);
    }

    public function test_a_failing_own_groq_key_falls_back_to_the_app_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/openai/v1/audio/transcriptions' => fn (Request $request) => $request->header('Authorization') === ['Bearer my-groq-key']
            ? Http::response(['error' => ['message' => 'Invalid API Key']], 401)
            : Http::response(['text' => 'Hello from the app key.'])]);
        config(['services.groq.key' => 'app-groq-key', 'services.groq.whisper_models' => ['whisper-a']]);
        $this->actingAs(User::factory()->create());
        auth()->user()->forceFill(['groq_api_key' => 'my-groq-key'])->save();
        $audio = tempnam(sys_get_temp_dir(), 'own-key-audio');
        file_put_contents($audio, 'fake-audio-bytes');

        $text = app(GroqClient::class)->transcribe($audio);

        $this->assertSame('Hello from the app key.', $text);
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer app-groq-key']);
    }

    public function test_saving_a_working_key_stores_it_encrypted_and_shows_only_its_ending(): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/v1beta/models?pageSize=1' => Http::response(['models' => []])]);
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('profile')
            ->set('aiKeyInput.gemini', 'AIzaSecretKey1234')
            ->call('saveAiKey', 'gemini')
            ->assertHasNoErrors()
            ->assertSet('aiKeyInput.gemini', '')
            ->assertSet('aiKeyStatus.gemini', 'ok')
            ->assertSee('•••• 1234')
            ->assertDontSee('AIzaSecretKey1234');

        $this->assertSame('AIzaSecretKey1234', $me->fresh()->gemini_api_key);
        $this->assertNotSame('AIzaSecretKey1234', DB::table('users')->where('id', $me->id)->value('gemini_api_key'));
    }

    public function test_a_key_the_provider_rejects_is_not_saved(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/openai/v1/models' => Http::response(['error' => ['message' => 'Invalid API Key']], 401)]);
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('profile')
            ->set('aiKeyInput.groq', 'gsk_wrong')
            ->call('saveAiKey', 'groq')
            ->assertHasErrors('aiKeyInput.groq')
            ->assertSet('aiKeyStatus.groq', 'rejected');

        $this->assertNull($me->fresh()->groq_api_key);
    }

    public function test_a_key_that_cannot_be_checked_right_now_is_still_saved(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.groq.com/openai/v1/models' => Http::response('Bad gateway', 502)]);
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('profile')
            ->set('aiKeyInput.groq', 'gsk_maybe')
            ->call('saveAiKey', 'groq')
            ->assertHasNoErrors()
            ->assertSet('aiKeyStatus.groq', 'unknown');

        $this->assertSame('gsk_maybe', $me->fresh()->groq_api_key);
    }

    public function test_removing_a_key_clears_it(): void
    {
        $me = User::factory()->create();
        $me->forceFill(['gemini_api_key' => 'AIzaOld9999'])->save();
        $this->actingAs($me);

        Livewire::test('profile')
            ->call('removeAiKey', 'gemini')
            ->assertDontSee('•••• 9999');

        $this->assertNull($me->fresh()->gemini_api_key);
    }
}
