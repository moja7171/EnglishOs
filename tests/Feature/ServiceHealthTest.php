<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Minishlink\WebPush\MessageSentReport;
use Mockery\MockInterface;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class ServiceHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.diagnostics.token' => 'diag-token',
            'webpush.vapid.public_key' => 'public-key-value',
            'webpush.vapid.private_key' => 'private-key-value',
            'webpush.vapid.subject' => 'mailto:admin@example.test',
        ]);

        Http::fake(['*' => Http::response('', 404)]);
    }

    public function test_a_missing_or_wrong_token_is_a_404(): void
    {
        $this->get('/_diag/health')->assertNotFound();
        $this->get('/_diag/health?token=nope')->assertNotFound();
    }

    public function test_it_is_healthy_when_cron_keys_and_push_services_are_all_fine(): void
    {
        Cache::forever('scheduler:last-run', now()->subSeconds(20)->getTimestamp());

        $response = $this->get('/_diag/health?token=diag-token');

        $response->assertOk();
        $response->assertSee('RESULT: everything checked looks healthy.', false);
        $response->assertSee('firing every minute', false);
        $response->assertDontSee('private-key-value', false);
        $response->assertDontSee('public-key-value', false);
    }

    public function test_a_cron_that_never_ran_is_reported_as_a_problem(): void
    {
        $this->get('/_diag/health?token=diag-token')
            ->assertSee('cron is not firing', false)
            ->assertSee('[FAIL] Cron heartbeat', false);
    }

    public function test_a_stale_heartbeat_is_reported_as_a_problem(): void
    {
        Cache::forever('scheduler:last-run', now()->subMinutes(10)->getTimestamp());

        $this->get('/_diag/health?token=diag-token')->assertSee('stale', false);
    }

    public function test_missing_vapid_keys_are_reported(): void
    {
        Cache::forever('scheduler:last-run', now()->getTimestamp());
        config(['webpush.vapid.private_key' => '']);

        $this->get('/_diag/health?token=diag-token')->assertSee('VAPID keys are not set', false);
    }

    public function test_an_unreachable_push_service_is_reported(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

        $this->get('/_diag/health?token=diag-token')
            ->assertSee('UNREACHABLE', false)
            ->assertSee('no phone alert of any kind', false);
    }

    public function test_the_test_alert_goes_through_the_push_channel_and_shows_the_result(): void
    {
        $user = User::factory()->create();
        $user->updatePushSubscription('https://fcm.example.test/send/1', 'key', 'token', 'aes128gcm');

        $refusal = new MessageSentReport(new Request('POST', 'https://fcm.example.test/send/1'), null, false, 'unreachable');
        $this->mock(WebPushChannel::class, function (MockInterface $channel) use ($refusal): void {
            $channel->shouldReceive('send')->once()->andReturn([$refusal]);
        });

        $this->get("/_diag/health?token=diag-token&send_test={$user->id}")
            ->assertSee('fcm.example.test', false)
            ->assertSee('refused the test alert', false);
    }

    public function test_the_test_alert_says_so_when_the_learner_has_no_device(): void
    {
        $user = User::factory()->create();

        $this->get("/_diag/health?token=diag-token&send_test={$user->email}")
            ->assertSee('no subscribed device', false);
    }
}
