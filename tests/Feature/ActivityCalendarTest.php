<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\ExtraEpisode;
use App\Models\User;
use App\Services\ActivityCalendar;
use App\Services\FriendActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merged 12-week calendar on Progress, and the two kinds of day that now
 * count in it, in the daily ring and in Friends: a Vocab in Use lesson and an
 * Extra English episode.
 */
class ActivityCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function slice(User $learner, string $activity, int $daysAgo = 0): void
    {
        DayActivity::create(['learner_id' => $learner->id, 'on_date' => $learner->localToday()->subDays($daysAgo)->toDateString(), 'activity' => $activity]);
    }

    public function test_a_day_is_coloured_by_how_many_kinds_of_thing_were_done_and_named_for_a_tap(): void
    {
        $learner = User::factory()->create();
        $this->slice($learner, 'review');
        $this->slice($learner, 'vocab');
        $this->slice($learner, 'step:1:vocabulary_builder');
        $this->slice($learner, 'extra', 1);

        $calendar = app(ActivityCalendar::class)->for($learner, null);
        $days = collect($calendar['weeks'])->flatMap(fn (array $week) => $week['days']);
        $today = $days->firstWhere('today', true);
        $yesterday = $days->firstWhere('date', $learner->localToday()->subDay()->toDateString());

        $this->assertCount(12, $calendar['weeks']);
        $this->assertCount(84, $days);
        $this->assertSame(3, $today['level']);
        $this->assertSame(['Mission', 'Review', 'Vocab in Use'], $today['kinds']);
        $this->assertSame(1, $yesterday['level']);
        $this->assertSame(['Extra English'], $yesterday['kinds']);
        $this->assertSame(2, $calendar['total']);
        $this->assertTrue($days->where('date', '>', $learner->localToday()->toDateString())->every(fn (array $day) => $day['future'] && $day['level'] === 0));
    }

    public function test_a_week_is_met_when_its_days_reach_the_goal(): void
    {
        $learner = User::factory()->create();
        $this->slice($learner, 'review', 0);
        $this->slice($learner, 'review', 1);

        $this->assertTrue(app(ActivityCalendar::class)->for($learner, 1)['weeks'][11]['met']);
        $this->assertFalse(app(ActivityCalendar::class)->for($learner, 7)['weeks'][11]['met']);
        $this->assertFalse(app(ActivityCalendar::class)->for($learner, null)['weeks'][11]['met']);
    }

    public function test_the_progress_page_has_one_calendar_and_a_courses_card_instead_of_this_week(): void
    {
        $learner = User::factory()->create(['weekly_goal_days' => 4]);
        $this->slice($learner, 'review');
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSeeHtml('data-testid="activity-calendar"')
            ->assertSee('1 day')
            ->assertSee('Goal 4/week')
            ->assertSeeHtml('data-testid="progress-courses"')
            ->assertSee('Extra English')
            ->assertDontSee('Last 12 weeks');
    }

    public function test_watching_an_extra_english_episode_earns_the_days_slice(): void
    {
        $learner = User::factory()->create();
        $episode = ExtraEpisode::factory()->create();

        Livewire::actingAs($learner)->test('courses.extra-episode', ['episodeId' => $episode->id])->call('markWatched');

        $this->assertTrue(DayActivity::where('learner_id', $learner->id)->where('activity', 'extra')->exists());
    }

    public function test_friends_see_the_lesson_and_the_episode_in_a_day(): void
    {
        $viewer = User::factory()->create();
        $friend = User::factory()->create();
        $this->slice($friend, 'vocab');
        $this->slice($friend, 'extra');

        $items = collect(app(FriendActivity::class)->dayItems($friend, $viewer, $friend->localToday()->toDateString()));

        $this->assertSame(['Vocab in Use', 'Extra English'], $items->pluck('title')->all());
    }
}
