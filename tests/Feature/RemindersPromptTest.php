<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RemindersPromptTest extends TestCase
{
    use RefreshDatabase;

    private function withPushKeys(): void
    {
        config(['webpush.vapid.public_key' => 'test-public-key']);
    }

    public function test_the_first_time_sheet_and_card_are_on_the_home_page(): void
    {
        $this->withPushKeys();

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSeeLivewire('notifications.reminders-prompt');
    }

    public function test_opening_the_app_remembers_the_browsers_timezone(): void
    {
        $this->withPushKeys();
        $user = User::factory()->create(['timezone' => null]);
        $this->actingAs($user);

        Livewire::withCookie('eos_tz', 'Europe/London')->test('notifications.reminders-prompt');

        $this->assertSame('Europe/London', $user->fresh()->timezone);
    }

    public function test_the_invitation_sheet_is_in_english_like_the_app_and_short(): void
    {
        $this->withPushKeys();
        $user = User::factory()->create();
        DayActivity::factory()->create(['learner_id' => $user->id]);
        $this->actingAs($user);

        Livewire::test('notifications.reminders-prompt', ['variant' => 'sheet'])
            ->assertSee("We'll remind you tomorrow", false)
            ->assertSee('Turn on')
            ->assertDontSee('زنجیره در خطر');
    }

    public function test_the_home_card_waits_a_day_after_the_sheet_and_later_quiets_it(): void
    {
        $this->withPushKeys();
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('home'))
            ->assertSee('Date.now() - sheetSeen > DAY', false)
            ->assertSee("write('eosAlertsCardDismissedUntil', Date.now() + 3 * DAY);", false);
    }

    public function test_the_install_banner_waits_only_for_the_ai_keys(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ai_keys_required' => true])->save();

        $this->actingAs($user)->get(route('ai-keys.setup'))->assertSee('x-show="false && deferredPrompt', false);

        $user->forceFill(array_fill_keys(array_values(User::AI_KEY_COLUMNS), 'saved-key'))->save();

        $this->actingAs($user->fresh())->get(route('onboarding'))->assertSee('x-show="true && deferredPrompt', false);
    }

    public function test_the_invitation_sheet_waits_for_the_first_lesson(): void
    {
        $this->withPushKeys();
        $this->actingAs(User::factory()->create());

        Livewire::test('notifications.reminders-prompt', ['variant' => 'sheet'])
            ->assertSet('hasStarted', false)
            ->assertDontSee("We'll remind you tomorrow", false);
    }

    public function test_nothing_is_shown_when_phone_alerts_are_not_configured(): void
    {
        config(['webpush.vapid.public_key' => null]);
        $this->actingAs(User::factory()->create());

        Livewire::test('notifications.reminders-prompt', ['variant' => 'sheet'])
            ->assertDontSee("We'll remind you tomorrow", false);
    }

    public function test_it_knows_whether_study_times_are_already_set(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test('notifications.reminders-prompt')->assertSet('hasStudyTimes', false);

        $this->actingAs(User::factory()->create(['study_reminder_time_2' => '21:30']));
        Livewire::test('notifications.reminders-prompt')->assertSet('hasStudyTimes', true);
    }

    public function test_the_one_tap_setup_turns_on_noon_and_eight_pm(): void
    {
        // 05:00 in Tehran: both times are still ahead today, so both fire today.
        Carbon::setTestNow('2026-10-03 01:30:00');
        $user = User::factory()->create(['timezone' => 'Asia/Tehran']);
        $this->actingAs($user);

        Livewire::test('notifications.reminders-prompt')
            ->call('useSuggestedTimes')
            ->assertSet('hasStudyTimes', true);

        $user->refresh();
        $this->assertSame('12:00', $user->study_reminder_time_1);
        $this->assertSame('20:00', $user->study_reminder_time_2);
        $this->assertNull($user->last_study_reminder_1_on);
        $this->assertNull($user->last_study_reminder_2_on);
    }

    public function test_the_one_tap_setup_starts_tomorrow_for_a_time_that_already_passed(): void
    {
        // 14:00 in Tehran: noon is gone, 20:00 is still ahead.
        Carbon::setTestNow('2026-10-03 10:30:00');
        $user = User::factory()->create(['timezone' => 'Asia/Tehran']);
        $this->actingAs($user);

        Livewire::test('notifications.reminders-prompt')->call('useSuggestedTimes');

        $user->refresh();
        $this->assertSame('2026-10-03', $user->last_study_reminder_1_on->toDateString());
        $this->assertNull($user->last_study_reminder_2_on);
    }

    public function test_the_one_tap_setup_never_overwrites_times_the_learner_chose(): void
    {
        $user = User::factory()->create(['study_reminder_time_1' => '07:15']);
        $this->actingAs($user);

        Livewire::test('notifications.reminders-prompt')->call('useSuggestedTimes');

        $user->refresh();
        $this->assertSame('07:15', $user->study_reminder_time_1);
        $this->assertNull($user->study_reminder_time_2);
    }
}
