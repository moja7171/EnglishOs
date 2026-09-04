<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_friends_current_mission_progress_percentage_is_shown(): void
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

        // 1 of 3 total steps recorded = 33%.
        Livewire::test('friends.board')
            ->assertSee('33%')
            ->assertDontSee('a very private real answer');
    }

    public function test_a_friend_with_no_active_mission_run_renders_without_error(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $this->makeMutualFriend($me);

        Livewire::test('friends.board')->assertOk();
    }

    public function test_missions_completed_is_shown_for_a_friend(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        MissionRun::create([
            'learner_id' => $friend->id,
            'mission_id' => $mission->id,
            'status' => MissionRun::STATUS_COMPLETE,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        Livewire::test('friends.board')->assertSee('1 mission');
    }

    public function test_expanding_a_friends_card_reveals_the_heatmap_and_journey_map(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        MissionRun::findOrStart($friend, $mission);

        Livewire::test('friends.board')
            ->assertDontSee('Last 12 weeks')
            ->call('toggleExpanded', $friend->id)
            ->assertSee('Last 12 weeks')
            ->assertSee('Day 1 · Foundation');
    }

    public function test_collapsed_by_default_a_friends_card_hides_the_journey_map(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);

        $mission = $this->makeMission();
        MissionRun::findOrStart($friend, $mission);

        Livewire::test('friends.board')->assertDontSee('Day 1 · Foundation');
    }

    public function test_a_pinned_highlight_can_be_saved_and_is_shown_on_my_own_card(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.board')
            ->set('pinnedHighlight', 'Loving the daily routine unit!')
            ->call('savePinnedHighlight')
            ->assertSet('pinnedHighlightSaved', true)
            ->assertSee('Loving the daily routine unit!');

        $this->assertSame('Loving the daily routine unit!', $me->fresh()->pinned_highlight);
    }

    public function test_a_friends_pinned_highlight_is_shown_only_if_they_set_one(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);
        $friend = $this->makeMutualFriend($me);
        $friend->update(['pinned_highlight' => 'Almost at a 30-day streak!']);

        Livewire::test('friends.board')->assertSee('Almost at a 30-day streak!');
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
            ->call('toggleExpanded', $friend->id)
            ->assertDontSee('SUPER SECRET SENTENCE NOBODY SHOULD SEE');
    }

    public function test_the_board_link_is_reachable_from_the_friends_page(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('friends.index')->assertSeeHtml(route('friends.board'));
    }
}
