<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class FriendsBoardTest extends TestCase
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
                ['phase' => 'foundation', 'label' => 'Foundation', 'steps' => [['key' => 'mission_brief'], ['key' => 'listening']]],
                ['phase' => 'build', 'label' => 'Build', 'steps' => [['key' => 'activation']]],
            ],
        ]);
    }

    private function makeMutualFriend(User $me): User
    {
        $friend = User::factory()->create();
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    public function test_the_empty_state_shows_an_illustration_when_there_are_no_friends(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.board')
            ->assertSee('Once you and a friend follow each other back')
            ->assertSeeHtml('<svg viewBox="0 0 160 90"');
    }

    public function test_only_mutual_friends_appear_on_the_board(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        $mutual = $this->makeMutualFriend($me);

        $oneWay = User::factory()->create(['name' => 'One Way Wendy']);
        $me->follow($oneWay); // never accepted back — one-directional only

        Livewire::test('friends.board')
            ->assertSee($mutual->name)
            ->assertDontSee('One Way Wendy');
    }

    public function test_a_friends_page_shows_their_mission_day_and_overall_progress_never_an_answer(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($friend, $mission);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'a very private real answer',
        ]);

        $this->get(route('friends.profile', $friend))
            ->assertOk()
            ->assertSee('M01 My Daily Life · Day 1 of 2')
            ->assertSee('1%')
            ->assertSeeHtml('aria-label="Mission days"')
            ->assertSee('Message')
            ->assertDontSee('a very private real answer');

        Livewire::test('friends.board')->assertSee('M01 · D1')->assertSeeHtml(route('friends.profile', $friend));
    }

    public function test_only_mutual_friends_and_i_can_open_a_friends_page(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $stranger = User::factory()->create();
        $me->follow($stranger);

        $this->get(route('friends.profile', $stranger))->assertForbidden();
        $this->get(route('friends.profile', $me))->assertOk()->assertDontSee('Message');
    }

    public function test_a_friend_who_moved_on_with_a_note_still_counts_the_mission_as_finished(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($friend, $mission);
        $run->update(['status' => MissionRun::STATUS_NEEDS_REVIEW, 'completed_at' => now()]);

        $this->assertSame(1, $friend->missionsFinishedCount());
        $this->assertSame(0, $friend->missionsCompletedCount());

        // 1 of 24 missions.
        $this->get(route('friends.profile', $friend))->assertSee('4%')->assertSee('Not in a mission right now');
    }

    public function test_a_friend_with_no_active_mission_run_renders_without_error(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        Livewire::test('friends.board')->assertOk();
        $this->get(route('friends.profile', $friend))->assertOk();
    }

    public function test_the_board_ranks_everyone_by_practice_days_this_week_with_me_in_the_list(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);
        $friend->update(['name' => 'Zed Friend']);

        // Sat 31 Oct and Mon 2 Nov for the friend, only today for me; a day of last week doesn't count.
        foreach (['2026-10-31', '2026-11-02', '2026-10-29'] as $date) {
            DayActivity::create(['learner_id' => $friend->id, 'on_date' => $date, 'activity' => 'listening']);
        }
        DayActivity::create(['learner_id' => $me->id, 'on_date' => '2026-11-04', 'activity' => 'pi']);

        $ranked = Livewire::test('friends.board')
            ->assertSee('Sat 31 Oct – Fri 6 Nov')
            ->assertSee('New week in 3 days')
            ->assertSee('You')
            ->assertSee('Zed Friend')
            ->get('ranked');

        $this->assertSame([$friend->id, $me->id], collect($ranked)->pluck('user.id')->all());
        $this->assertSame([2, 1], collect($ranked)->pluck('days')->all());

        $lastWeek = Livewire::test('friends.board')->call('showWeek', true)->assertSee('Sat 24 Oct – Fri 30 Oct')->get('ranked');
        $this->assertSame(1, collect($lastWeek)->firstWhere('user.id', $friend->id)['days']);
    }

    public function test_a_day_shows_what_was_done_with_mission_day_tags_and_games_with_me(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Asia/Tehran'));
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);
        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($friend, $mission);

        foreach (['mission_brief', 'listening'] as $step) {
            DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-02', 'activity' => 'step:'.$run->id.':'.$step]);
        }
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-02', 'activity' => 'listening']);
        DayActivity::create(['learner_id' => $friend->id, 'on_date' => '2026-11-03', 'activity' => 'pi']);
        PairGame::factory()->create([
            'user_a_id' => min($me->id, $friend->id), 'user_b_id' => max($me->id, $friend->id), 'started_by_id' => $me->id,
            'stage' => PairGame::STAGE_DONE, 'score' => 14, 'finished_at' => Carbon::parse('2026-11-02 18:00', 'Asia/Tehran'),
        ]);

        $page = Livewire::test('friends.profile', ['user' => $friend])->assertSee('D1')->assertSee('2 of 7 days');

        $page->call('pickDay', '2026-11-02')
            ->assertSee('M01 · Day 1 finished')
            ->assertSee('Listening')
            ->assertSee('Game with you')
            ->assertSee('Taboo · team 7')
            ->assertDontSee('Daily talk');

        $page->call('pickDay', '2026-11-03')->assertSee('Daily talk')->assertDontSee('M01 · Day 1');
    }

    public function test_a_finished_game_counts_as_a_slice_of_both_players_day(): void
    {
        $pairGame = PairGame::factory()->create(['stage' => PairGame::STAGE_PLAYING]);
        $pairGame->finish();

        $this->assertSame(2, DayActivity::where('activity', 'game')->count());
    }

    public function test_a_friends_page_holds_what_we_played_together_and_their_badges(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);
        PairGame::factory()->create([
            'user_a_id' => min($me->id, $friend->id), 'user_b_id' => max($me->id, $friend->id), 'started_by_id' => $me->id,
            'stage' => PairGame::STAGE_DONE, 'score' => 16, 'finished_at' => now(),
        ]);

        $this->get(route('friends.profile', $friend))
            ->assertSee('You and '.$friend->name)
            ->assertSee('best Taboo')
            ->assertSee('First game');
    }

    public function test_a_pinned_highlight_can_be_saved_and_is_shown_on_my_own_card(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.board')
            ->set('pinnedHighlight', 'Loving the daily routine unit!')
            ->call('savePinnedHighlight')
            ->assertSet('pinnedHighlightSaved', true);

        $this->assertSame('Loving the daily routine unit!', $me->fresh()->pinned_highlight);
    }

    public function test_a_friends_pinned_highlight_is_shown_on_their_page(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);
        $friend->update(['pinned_highlight' => 'Almost at a 30-day streak!']);

        $this->get(route('friends.profile', $friend))->assertSee('Almost at a 30-day streak!');
    }

    public function test_no_highlight_shown_when_a_friend_never_pinned_one(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $this->makeMutualFriend($me);

        Livewire::test('friends.board')->assertOk();
    }

    public function test_clearing_the_pinned_highlight_removes_it(): void
    {
        $me = User::factory()->create(['pinned_highlight' => 'Old highlight']);
        $this->actingAs($me);

        Livewire::test('friends.board')
            ->set('pinnedHighlight', '')
            ->call('savePinnedHighlight');

        $this->assertNull($me->fresh()->pinned_highlight);
    }

    public function test_no_evidence_content_ever_appears_on_the_board_even_when_expanded(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        $run = MissionRun::findOrStart($friend, $mission);
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => 'SUPER SECRET SENTENCE NOBODY SHOULD SEE',
        ]);

        Livewire::test('friends.board')
            ->assertDontSee('SUPER SECRET SENTENCE NOBODY SHOULD SEE');
    }

    public function test_the_board_link_is_reachable_from_the_friends_page(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.index')->assertSeeHtml(route('friends.board'));
    }

    public function test_a_friends_photo_opens_their_story_which_leads_to_their_page(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        Livewire::test('friends.index')
            ->assertSeeHtml(route('friends.conversation', $friend))
            ->assertDontSeeHtml(route('friends.profile', $friend))
            ->call('openStory', $friend->id)
            ->assertSeeHtml(route('friends.profile', $friend));
    }
}
