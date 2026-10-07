<?php

namespace Tests\Feature;

use App\Services\GeminiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the ordered model chain behind GeminiClient::chat(): the original
 * primary-then-fallback behavior (after the 2026-09-04 outage), plus
 * per-model "unavailable until" memory, failure classification, the depth
 * cap, profiles and per-model thinking levels.
 */
class GeminiClientTest extends TestCase
{
    private const PRIMARY_URL = 'generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent';

    private const FALLBACK_URL = 'generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    private const MODEL_A = 'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent';

    private const MODEL_B = 'generativelanguage.googleapis.com/v1beta/models/model-b:generateContent';

    private const MODEL_C = 'generativelanguage.googleapis.com/v1beta/models/model-c:generateContent';

    private const MODEL_D = 'generativelanguage.googleapis.com/v1beta/models/model-d:generateContent';

    private function textResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    /**
     * The shape of a real Google free-tier 429 (see
     * document/ai-model-chain-spec.md §3).
     */
    private function quotaBody(string $quotaId, string $retryDelay): array
    {
        return ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'You exceeded your current quota.',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay],
            ],
        ]];
    }

    /**
     * @param  list<string>  $models
     */
    private function chatChain(array $models): GeminiClient
    {
        config(['services.gemini.chat_models' => $models]);

        return new GeminiClient('test-key');
    }

    private function ask(GeminiClient $client, string $profile = GeminiClient::PROFILE_CHAT): string
    {
        return $client->chat([['role' => 'user', 'text' => 'Hi']], profile: $profile);
    }

    private function sentTo(string $model): int
    {
        return Http::recorded(fn ($request) => str_contains((string) $request->url(), "/models/{$model}:"))->count();
    }

    public function test_primary_model_succeeds_and_fallback_is_never_invoked(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response($this->textResponse('Hello from primary.')),
            self::FALLBACK_URL => Http::response($this->textResponse('Hello from fallback.')),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');
        $result = $client->chat([['role' => 'user', 'text' => 'Hi']]);

        $this->assertSame('Hello from primary.', $result);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'gemini-flash-latest'));
    }

    public function test_primary_model_failing_falls_back_to_the_second_model(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response(['error' => 'service unavailable'], 503),
            self::FALLBACK_URL => Http::response($this->textResponse('Recovered via fallback.')),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');
        $result = $client->chat([['role' => 'user', 'text' => 'Hi']]);

        $this->assertSame('Recovered via fallback.', $result);
        Http::assertSentCount(2);
    }

    public function test_both_primary_and_fallback_failing_still_throws(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response(['error' => 'service unavailable'], 503),
            self::FALLBACK_URL => Http::response(['error' => 'service unavailable'], 503),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');

        try {
            $client->chat([['role' => 'user', 'text' => 'Hi']]);
            $this->fail('Expected chat() to throw once both the primary and fallback models are exhausted.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(RuntimeException::class, $e, 'Should surface the HTTP failure, not swallow it silently.');
        }

        Http::assertSentCount(2);
    }

    public function test_exhausting_every_model_logs_each_cause_once_at_error_level_without_secrets(): void
    {
        Log::spy();

        Http::fake([
            self::PRIMARY_URL => Http::response(['error' => 'quota'], 429),
            self::FALLBACK_URL => Http::response(['error' => 'blocked'], 503),
        ]);

        try {
            (new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest'))
                ->chat([['role' => 'user', 'text' => 'Hi']]);
        } catch (\Throwable) {
            // expected
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context) => $context['profile'] === 'chat'
                && $context['attempts'][0]['model'] === 'gemini-3.5-flash-lite'
                && $context['attempts'][0]['kind'] === 'rate_limit'
                && $context['attempts'][1]['model'] === 'gemini-flash-latest'
                && $context['attempts'][1]['kind'] === 'transient'
                && ! str_contains(json_encode($context), 'test-key'));
    }

    public function test_max_output_tokens_is_sent_only_when_given(): void
    {
        Http::fake([
            self::PRIMARY_URL => Http::response($this->textResponse('Short reply.')),
        ]);

        $client = new GeminiClient('test-key', 'gemini-3.5-flash-lite', 'gemini-flash-latest');
        $client->chat([['role' => 'user', 'text' => 'Hi']], maxOutputTokens: 220);

        Http::assertSent(fn ($request) => ($request['generationConfig']['maxOutputTokens'] ?? null) === 220);

        $client->chat([['role' => 'user', 'text' => 'Hi']]);

        Http::assertSent(fn ($request) => ! array_key_exists('generationConfig', $request->data()));
    }

    public function test_missing_api_key_throws_without_any_http_call(): void
    {
        Http::fake();

        $client = new GeminiClient('', 'gemini-3.5-flash-lite', 'gemini-flash-latest');

        try {
            $client->chat([['role' => 'user', 'text' => 'Hi']]);
            $this->fail('Expected chat() to throw when no API key is configured.');
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_daily_quota_429_skips_the_model_on_later_requests_until_its_retry_delay_passes(): void
    {
        Http::fake([
            self::MODEL_A => Http::sequence()
                ->push($this->quotaBody('GenerateRequestsPerDayPerProjectPerModel-FreeTier', '40085s'), 429)
                ->push($this->textResponse('A is back.')),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);
        $client = $this->chatChain(['model-a', 'model-b']);

        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame(1, $this->sentTo('model-a'), 'Later requests must not pay a failed request on the exhausted model.');
        $this->assertSame(2, $this->sentTo('model-b'));

        $this->travel(40086)->seconds();

        $this->assertSame('A is back.', $this->ask($client));
        $this->assertSame(2, $this->sentTo('model-a'));
    }

    public function test_per_minute_429_is_skipped_only_for_about_its_retry_delay(): void
    {
        Http::fake([
            self::MODEL_A => Http::sequence()
                ->push($this->quotaBody('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '30s'), 429)
                ->push($this->textResponse('A is back.')),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);
        $client = $this->chatChain(['model-a', 'model-b']);

        $this->assertSame('Hello from B.', $this->ask($client));

        $this->travel(20)->seconds();
        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame(1, $this->sentTo('model-a'));

        $this->travel(15)->seconds();
        $this->assertSame('A is back.', $this->ask($client));
    }

    public function test_retired_model_404_is_skipped_for_a_long_time(): void
    {
        Http::fake([
            self::MODEL_A => Http::response(['error' => ['code' => 404, 'message' => 'no longer available to new users']], 404),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);
        $client = $this->chatChain(['model-a', 'model-b']);

        $this->assertSame('Hello from B.', $this->ask($client));

        $this->travel(2)->hours();
        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame(1, $this->sentTo('model-a'));
    }

    public function test_server_error_marks_the_model_down_for_a_few_minutes_only(): void
    {
        Http::fake([
            self::MODEL_A => Http::sequence()
                ->push(['error' => 'overloaded'], 503)
                ->push($this->textResponse('A is back.')),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);
        $client = $this->chatChain(['model-a', 'model-b']);

        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame('Hello from B.', $this->ask($client));
        $this->assertSame(1, $this->sentTo('model-a'));

        $this->travel(4)->minutes();

        $this->assertSame('A is back.', $this->ask($client));
    }

    public function test_connection_failure_moves_on_to_the_next_model(): void
    {
        Http::fake([
            self::MODEL_A => Http::failedConnection(),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);

        $this->assertSame('Hello from B.', $this->ask($this->chatChain(['model-a', 'model-b'])));
    }

    public function test_bad_request_400_throws_without_walking_the_chain(): void
    {
        Http::fake([
            self::MODEL_A => Http::response(['error' => ['code' => 400, 'message' => 'bad request']], 400),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);

        try {
            $this->ask($this->chatChain(['model-a', 'model-b']));
            $this->fail('Expected a 400 to be thrown, not retried on another model.');
        } catch (RequestException $e) {
            $this->assertSame(400, $e->response->status());
        }

        $this->assertSame(0, $this->sentTo('model-b'));
    }

    public function test_every_model_failing_throws_the_last_error_and_logs_once(): void
    {
        Log::spy();
        Http::fake([
            self::MODEL_A => Http::response(['error' => 'down'], 503),
            self::MODEL_B => Http::failedConnection(),
        ]);

        $this->expectException(ConnectionException::class);

        try {
            $this->ask($this->chatChain(['model-a', 'model-b']));
        } finally {
            Log::shouldHaveReceived('error')
                ->withArgs(fn (string $message) => str_contains($message, 'failed on every model'))
                ->once();
        }
    }

    public function test_a_request_tries_at_most_three_models(): void
    {
        Http::fake([
            self::MODEL_A => Http::response(['error' => 'down'], 503),
            self::MODEL_B => Http::response(['error' => 'down'], 503),
            self::MODEL_C => Http::response(['error' => 'down'], 503),
            self::MODEL_D => Http::response($this->textResponse('Hello from D.')),
        ]);

        try {
            $this->ask($this->chatChain(['model-a', 'model-b', 'model-c', 'model-d']));
            $this->fail('Expected the request to give up after three attempts.');
        } catch (RequestException) {
            // expected
        }

        $this->assertSame(0, $this->sentTo('model-d'));
    }

    public function test_when_every_model_is_marked_down_the_one_healing_soonest_is_probed(): void
    {
        Http::fake([
            self::MODEL_A => Http::sequence()
                ->push(['error' => 'down'], 503)
                ->push($this->textResponse('A recovered.')),
            self::MODEL_B => Http::response(['error' => 'down'], 503),
        ]);
        $client = $this->chatChain(['model-a', 'model-b']);

        try {
            $this->ask($client);
        } catch (RequestException) {
            // both marked down
        }

        $this->travel(1)->seconds();

        $this->assertSame('A recovered.', $this->ask($client), 'A request must still probe a model rather than fail with no attempt.');
        $this->assertSame(1, $this->sentTo('model-b'));
    }

    public function test_judge_profile_walks_its_own_list(): void
    {
        config([
            'services.gemini.chat_models' => ['model-a'],
            'services.gemini.judge_models' => ['model-b'],
        ]);
        Http::fake([
            self::MODEL_A => Http::response($this->textResponse('chat')),
            self::MODEL_B => Http::response($this->textResponse('judge')),
        ]);
        $client = new GeminiClient('test-key');

        $this->assertSame('judge', $this->ask($client, GeminiClient::PROFILE_JUDGE));
        $this->assertSame('chat', $this->ask($client));
    }

    public function test_without_chain_lists_the_legacy_model_and_fallback_are_used(): void
    {
        config([
            'services.gemini.chat_models' => [],
            'services.gemini.judge_models' => [],
            'services.gemini.model' => 'model-a',
            'services.gemini.fallback_model' => 'model-b',
        ]);
        Http::fake([
            self::MODEL_A => Http::response(['error' => 'down'], 503),
            self::MODEL_B => Http::response($this->textResponse('Hello from B.')),
        ]);

        $this->assertSame('Hello from B.', $this->ask(new GeminiClient('test-key'), GeminiClient::PROFILE_JUDGE));
    }

    public function test_thinking_level_is_sent_only_for_entries_that_carry_one(): void
    {
        Http::fake([
            self::MODEL_A => Http::response(['error' => 'down'], 503),
            self::MODEL_B => Http::response($this->textResponse('plain model')),
        ]);
        $client = $this->chatChain(['model-a:minimal', 'model-b']);

        $client->chat([['role' => 'user', 'text' => 'Hi']], maxOutputTokens: 220);

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'model-a')
            && $request['generationConfig'] === ['maxOutputTokens' => 220, 'thinkingConfig' => ['thinkingLevel' => 'minimal']]);
        Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'model-b')
            && $request['generationConfig'] === ['maxOutputTokens' => 220]);
    }
}
