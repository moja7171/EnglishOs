<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AiChainDegraded;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admins are told when a model chain is down to its last model (and again
 * when it has none), once per outage rather than once per failing request.
 */
class AiChainAlertTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $models): GeminiClient
    {
        config(['services.gemini.chat_models' => $models]);

        return new GeminiClient('test-key');
    }

    private function ask(GeminiClient $client): void
    {
        try {
            $client->chat([['role' => 'user', 'text' => 'Hi']]);
        } catch (RequestException) {
            // the chain is expected to fail in these tests
        }
    }

    private function modelUrl(string $model): string
    {
        return "generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    public function test_admins_are_alerted_when_the_chain_is_down_to_its_last_model_and_again_when_none_is_left(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $learner = User::factory()->create();
        Http::fake([
            $this->modelUrl('model-a') => Http::response(['error' => 'down'], 503),
            $this->modelUrl('model-b') => Http::response(['error' => 'down'], 503),
        ]);

        $this->ask($this->client(['model-a', 'model-b']));

        Notification::assertSentTo($admin, AiChainDegraded::class, fn (AiChainDegraded $n) => str_contains($n->toArray($admin)['title'], 'down to its last model (model-b)'));
        Notification::assertSentTo($admin, AiChainDegraded::class, fn (AiChainDegraded $n) => str_contains($n->toArray($admin)['title'], 'every Gemini chat model is down'));
        Notification::assertNotSentTo($learner, AiChainDegraded::class);
    }

    public function test_a_chain_with_models_to_spare_does_not_alert(): void
    {
        Notification::fake();
        User::factory()->create(['is_admin' => true]);
        Http::fake([
            $this->modelUrl('model-a') => Http::response(['error' => 'down'], 503),
            $this->modelUrl('model-b') => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'ok']]]]]]),
        ]);

        $this->client(['model-a', 'model-b', 'model-c'])->chat([['role' => 'user', 'text' => 'Hi']]);

        Notification::assertNothingSent();
    }

    public function test_an_ongoing_outage_alerts_once_not_on_every_failing_request(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        Http::fake([$this->modelUrl('model-a') => Http::response(['error' => 'down'], 503)]);
        $client = $this->client(['model-a']);

        $this->ask($client);
        $this->ask($client);
        $this->ask($client);

        Notification::assertSentToTimes($admin, AiChainDegraded::class, 1);
    }
}
