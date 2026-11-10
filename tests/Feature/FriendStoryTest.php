<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\FriendStory;
use App\Services\ListeningPicks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FriendStoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief'], ['key' => 'vocabulary_builder_1']]],
                ['phase' => 'build', 'label' => 'Day 2', 'steps' => [['key' => 'grammar_in_context'], ['key' => 'reading_comprehension']]],
            ],
        ]);
    }

    private function friendOf(User $me): User
    {
        $friend = User::factory()->create(['name' => 'Sara Friend']);
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    public function test_a_friends_photo_opens_their_story_and_closing_it_removes_it(): void
    {
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertDontSeeHtml('data-testid="story-today"')
            ->call('openStory', $friend->id)
            ->assertSet('storyFriendId', $friend->id)
            ->assertSeeHtml('data-testid="story-today"')
            ->assertSeeHtml('data-testid="story-week"')
            ->assertSeeHtml('data-testid="story-overall"')
            ->call('closeStory')
            ->assertDontSeeHtml('data-testid="story-today"');
    }

    public function test_only_a_mutual_friend_has_a_story_to_open(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $oneWay = User::factory()->create();
        $me->follow($oneWay);
        $this->actingAs($me);

        Livewire::test('friends.index')->call('openStory', $stranger->id)->assertForbidden();
        Livewire::test('friends.index')->call('openStory', $oneWay->id)->assertForbidden();
    }

    public function test_the_story_says_in_persian_exactly_where_the_friend_is_and_what_they_did_today(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        $run = MissionRun::findOrStart($friend, $this->makeMission());

        foreach (['mission_brief', 'vocabulary_builder_1'] as $step) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $step, 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        }
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-04', 'activity' => 'listening']);

        $story = app(FriendStory::class)->for($me, $friend);

        $this->assertSame(3, $story['ring']);
        $this->assertSame('M01 · روز 2 از 2 · الان در «گرامر»', $story['where']);
        $this->assertSame(2, $story['today']['count']);
        $this->assertSame('مأموریت M01 · روز 1 تمام شد ✓', $story['today']['items'][0]['title']);
        $this->assertSame(['آماده‌سازی', 'واژه‌های جدید'], array_column($story['today']['items'][0]['details'], 'name'));
        $this->assertSame('گوش دادن', $story['today']['items'][1]['title']);
        $this->assertSame('حلقهٔ امروز کامل شد ✓', $story['today']['ringNote']);
    }

    public function test_a_friend_who_did_nothing_today_shows_the_last_day_they_practised(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-03', 'activity' => 'review']);

        $story = app(FriendStory::class)->for($me, $friend);

        $this->assertSame(0, $story['ring']);
        $this->assertSame(0, $story['today']['count']);
        $this->assertNull($story['where']);
        $this->assertSame('آخرین تمرین: دیروز', $story['today']['last']);

        Livewire::actingAs($me)->test('friends.index')
            ->call('openStory', $friend->id)
            ->assertSee('کار تا حالا', false)
            ->assertSee('آخرین تمرین: دیروز', false)
            ->assertSee('مأموریتی باز ندارد', false);
    }

    public function test_the_week_page_counts_practised_days_against_mine(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $friend = $this->friendOf($me);

        foreach (['2026-10-31', '2026-11-02', '2026-11-04'] as $date) {
            DayActivity::create(['learner_id' => $friend->id, 'on_date' => $date, 'activity' => 'review']);
        }
        DayActivity::create(['learner_id' => $me->id, 'on_date' => '2026-11-03', 'activity' => 'review']);

        $week = app(FriendStory::class)->for($me, $friend)['week'];

        $this->assertSame(3, $week['practised']);
        $this->assertSame(1, $week['viewerPractised']);
        $this->assertSame(['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'], array_column($week['days'], 'letter'));
        $this->assertSame(['practised', 'none', 'practised', 'none', 'practised', 'future', 'future'], array_column($week['days'], 'state'));
    }

    public function test_the_row_ring_follows_how_much_of_the_day_is_done(): void
    {
        $me = User::factory()->create();
        $none = $this->friendOf($me);
        $half = $this->friendOf($me);
        DayActivity::factory()->create(['learner_id' => $half->id, 'on_date' => now()->toDateString()]);
        $this->actingAs($me);

        $rows = collect(Livewire::test('friends.index')->get('rows'))->keyBy(fn (array $row) => $row['friend']->id);

        $this->assertSame(0, $rows[$none->id]['slices']);
        $this->assertSame(1, $rows[$half->id]['slices']);
    }

    public function test_the_page_says_once_that_a_photo_opens_the_story_only_when_there_are_friends(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.index')->assertDontSee('Tap a photo to see their day');

        $this->friendOf($me);

        Livewire::test('friends.index')->assertSee('Tap a photo to see their day');
    }

    public function test_a_learner_can_open_their_own_story_without_a_comparison_or_chat_buttons(): void
    {
        $me = User::factory()->create(['name' => 'Mina Me']);
        DayActivity::create(['learner_id' => $me->id, 'on_date' => now()->toDateString(), 'activity' => 'review']);
        $this->actingAs($me);

        Livewire::test('friends.index')
            ->assertSee('Your day')
            ->call('openStory', $me->id)
            ->assertSet('storyFriendId', $me->id)
            ->assertSee('کار انجام دادی', false)
            ->assertSee('الان کجایی', false)
            ->assertSee('صفحهٔ من', false)
            ->assertDontSeeHtml('data-testid="story-compare"')
            ->assertDontSeeHtml(route('friends.conversation', $me));

        $this->assertTrue(app(FriendStory::class)->for($me, $me)['self']);
    }

    public function test_the_story_names_which_listenings_were_heard_today_and_how_many_times(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        AudioListen::factory()->pick(1, 0)->count(3)->create(['learner_id' => $friend->id, 'created_at' => now()]);
        AudioListen::factory()->pick(1, 2)->create(['learner_id' => $friend->id, 'created_at' => now()]);
        AudioListen::factory()->pick(1, 1)->create(['learner_id' => $friend->id, 'created_at' => now()->subDays(2)]);
        $trio = app(ListeningPicks::class)->forDay('M01', 1);

        $item = app(FriendStory::class)->for($me, $friend)['today']['items'][0];

        $this->assertSame('گوش دادن · 4 بار', $item['title']);
        $this->assertSame([$trio[0]['title'], $trio[2]['title']], array_column($item['details'], 'name'));
        $this->assertSame([3, 1], array_column($item['details'], 'times'));
        $this->assertSame('M01 · روز 1 · سبک‌تر', $item['details'][0]['note']);
    }

    public function test_the_week_lists_what_each_day_held_and_the_overall_page_adds_the_mission_days(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $friend = $this->friendOf($me);
        $run = MissionRun::findOrStart($friend, $this->makeMission());
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-02', 'activity' => 'review']);
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-02', 'activity' => 'pi']);

        $story = app(FriendStory::class)->for($me, $friend);

        $this->assertSame(['مرور', 'گفتگوی روزانه'], collect($story['week']['days'][2]['kinds'])->sort()->values()->all());
        $this->assertSame(['مأموریت'], $story['week']['days'][4]['kinds']);
        $this->assertSame(['current', 'todo'], $story['overall']['missionDays']);
        $this->assertSame(2, $story['overall']['practiceDays']);
    }
}
