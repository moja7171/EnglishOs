<?php

namespace Tests\Feature;

use App\Models\ListeningLog;
use App\Models\User;
use App\Notifications\StreakWarning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class StreakWarningTest extends TestCase
{
    use RefreshDatabase;

    /** 20:05 in Tehran (UTC+3:30, no DST) on 2026-10-03. */
    private const TEHRAN_EVENING = '2026-10-03 16:35:00';

    /**
     * @param  list<string>  $activeDays  local dates the learner was active
     */
    private function learner(array $activeDays, bool $withPhone = true): User
    {
        $user = User::factory()->create(['timezone' => 'Asia/Tehran']);

        if ($withPhone) {
            $user->updatePushSubscription('https://push.example.test/send/'.$user->id, 'key', 'token', 'aes128gcm');
        }

        foreach ($activeDays as $day) {
            ListeningLog::factory()->create(['learner_id' => $user->id, 'listened_on' => $day]);
        }

        return $user;
    }

    private function expectWarnings(int $times): MockInterface
    {
        $channel = $this->mock(WebPushChannel::class);
        $channel->shouldReceive('send')->times($times)->andReturn([]);

        return $channel;
    }

    private function runCommand(): void
    {
        $this->artisan('streak:send-warnings')->assertSuccessful();
    }

    public function test_a_streak_that_breaks_unless_they_act_today_is_warned_about(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $user = $this->learner(['2026-10-01', '2026-09-30', '2026-09-29']);
        $this->expectWarnings(1);

        $this->runCommand();

        $this->assertSame('2026-10-03', $user->fresh()->last_streak_warning_on->toDateString());
    }

    public function test_the_warning_is_sent_at_most_once_a_day(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['2026-10-01']);
        $this->expectWarnings(1);

        $this->runCommand();
        Carbon::setTestNow(now()->addMinutes(15));
        $this->runCommand();
    }

    public function test_no_warning_when_they_were_active_yesterday_or_today(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['2026-10-02']);
        $this->learner(['2026-10-03']);
        $this->expectWarnings(0);

        $this->runCommand();
    }

    public function test_no_warning_when_there_is_no_streak_left_to_save(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['2026-09-30']);
        $this->learner([]);
        $this->expectWarnings(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_before_20_00_their_time(): void
    {
        Carbon::setTestNow('2026-10-03 16:25:00');
        $this->learner(['2026-10-01']);
        $this->expectWarnings(0);

        $this->runCommand();
    }

    public function test_nothing_is_sent_without_a_phone_subscription(): void
    {
        Carbon::setTestNow(self::TEHRAN_EVENING);
        $this->learner(['2026-10-01'], withPhone: false);
        $this->expectWarnings(0);

        $this->runCommand();
    }

    public function test_the_warning_is_in_persian_with_the_streak_length_and_opens_the_home_page(): void
    {
        $user = User::factory()->create();
        $notification = new StreakWarning(12);

        $payload = $notification->toWebPush($user, $notification)->toArray();

        $this->assertStringContainsString('۱۲ روزه', $payload['body']);
        $this->assertSame(['url' => route('home')], $payload['data']);
        $this->assertSame('streak-warning', $payload['tag']);
    }
}
