<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Follow;
use App\Models\NotebookEntry;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Notifications\FollowRequestReceived;
use App\Services\AppShell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    private function friends(User $me, User $friend): void
    {
        Follow::create(['follower_id' => $me->id, 'followed_id' => $friend->id, 'status' => Follow::STATUS_ACCEPTED]);
        Follow::create(['follower_id' => $friend->id, 'followed_id' => $me->id, 'status' => Follow::STATUS_ACCEPTED]);
    }

    private function messageFrom(User $sender, User $recipient): DirectMessage
    {
        return DirectMessage::create([
            'sender_id' => $sender->id,
            'recipient_id' => $recipient->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => 'Hi!',
        ]);
    }

    private function requestFrom(User $requester, User $me): void
    {
        Follow::create(['follower_id' => $requester->id, 'followed_id' => $me->id, 'status' => Follow::STATUS_PENDING]);
        $me->notify(new FollowRequestReceived($requester));
    }

    public function test_the_state_holds_what_the_tabs_and_the_header_show(): void
    {
        $me = User::factory()->create(['name' => 'Zed', 'avatar_style' => 'initial', 'avatar_path' => null]);
        $friend = User::factory()->create();
        $this->friends($me, $friend);
        $this->messageFrom($friend, $me);
        VocabularyWord::create(['learner_id' => $me->id, 'word' => 'kettle', 'next_review_at' => now()->subHour()]);
        NotebookEntry::factory()->for($me, 'learner')->create(['last_practised_at' => null]);

        $state = app(AppShell::class)->state($me);

        $this->assertSame(1, $state['friends']);
        $this->assertGreaterThanOrEqual(1, $state['review']);
        $this->assertTrue($state['notebook']);
        $this->assertStringContainsString('Z', $state['avatar']);
    }

    public function test_every_page_seeds_the_store_and_hides_an_empty_badge(): void
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($me, $friend);
        $this->messageFrom($friend, $me);
        $this->actingAs($me);

        $response = $this->get(route('friends.index'))->assertOk();

        $response->assertSee('data-shell=', false);
        $response->assertSee('&quot;friends&quot;:1', false);
        $response->assertSee('x-show="$store.shell.review > 0" style="display: none;"', false);
        $response->assertDontSee('x-show="$store.shell.friends > 0" style="display: none;"', false);
    }

    public function test_only_screens_that_keep_themselves_current_stay_as_left_on_back(): void
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($me, $friend);
        $this->actingAs($me);

        $this->get(route('friends.conversation', $friend))->assertSee('data-stay', false)->assertDontSee('data-refresh-on-return', false);
        $this->get(route('friends.index'))->assertDontSee('data-stay', false)->assertSee('data-refresh-on-return', false);
    }

    public function test_changing_the_avatar_or_name_tells_the_open_page(): void
    {
        $this->actingAs(User::factory()->create(['avatar_color' => 'accent']));

        Livewire::test('profile')->call('selectColor', 'rose')->assertDispatched('shell-changed');
    }

    public function test_a_review_item_changing_tells_the_open_page_once(): void
    {
        $me = User::factory()->create();
        $entry = NotebookEntry::factory()->for($me, 'learner')->create();
        $this->actingAs($me);

        $component = Livewire::test('notebook.index')->call('markLearned', $entry->id);

        $dispatched = collect($component->effects['dispatches'] ?? [])->where('name', 'shell-changed');

        $this->assertCount(1, $dispatched);
    }

    public function test_saving_a_review_item_without_a_change_stays_quiet(): void
    {
        $entry = NotebookEntry::factory()->for(User::factory(), 'learner')->create();

        $saver = new class extends Component
        {
            public int $entryId = 0;

            public function saveUntouched(): void
            {
                NotebookEntry::findOrFail($this->entryId)->save();
            }

            public function render(): string
            {
                return '<div></div>';
            }
        };

        Livewire::test($saver, ['entryId' => $entry->id])->call('saveUntouched')->assertNotDispatched('shell-changed');
    }

    public function test_reading_a_chat_tells_the_open_page_and_a_read_one_does_not(): void
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($me, $friend);
        $this->messageFrom($friend, $me);
        $this->actingAs($me);

        Livewire::test('friends.conversation', ['other' => $friend])->assertDispatched('shell-changed');
        Livewire::test('friends.conversation', ['other' => $friend])->assertNotDispatched('shell-changed');
    }

    public function test_the_poll_carries_the_friends_count_and_a_change_carries_everything(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me);

        Livewire::test('notifications.bell')
            ->call('checkNew')
            ->assertDispatched('shell-state', fn (string $event, array $params) => array_key_exists('friends', $params) && ! array_key_exists('avatar', $params));

        Livewire::test('notifications.bell')
            ->dispatch('shell-changed')
            ->assertDispatched('shell-state', fn (string $event, array $params) => array_key_exists('friends', $params) && array_key_exists('avatar', $params) && array_key_exists('review', $params));
    }

    public function test_the_lists_fingerprint_moves_with_a_new_message_or_invite_and_not_otherwise(): void
    {
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->friends($me, $friend);
        $shell = app(AppShell::class);

        $quiet = $shell->pulse($me)['live'];
        $this->assertSame($quiet, $shell->pulse($me)['live']);

        $this->messageFrom($friend, $me)->update(['read_at' => now()]);
        $afterMessage = $shell->pulse($me)['live'];
        $this->assertNotSame($quiet, $afterMessage);

        PairPracticeSession::factory()->create(['user_a_id' => $friend->id, 'user_b_id' => $me->id, 'started_by_id' => $friend->id]);
        $this->assertNotSame($afterMessage, $shell->pulse($me)['live']);
    }

    public function test_answering_a_request_on_the_friends_page_clears_its_bell_row(): void
    {
        $me = User::factory()->create();
        $accepted = User::factory()->create();
        $rejected = User::factory()->create();
        $this->requestFrom($accepted, $me);
        $this->requestFrom($rejected, $me);
        $this->actingAs($me);

        Livewire::test('friends.index')
            ->call('acceptRequest', $accepted->id)
            ->assertDispatched('shell-changed')
            ->call('rejectRequest', $rejected->id);

        $this->assertSame(0, $me->notifications()->where('type', FollowRequestReceived::class)->count());
    }

    public function test_answering_a_request_leaves_someone_elses_bell_row_alone(): void
    {
        $me = User::factory()->create();
        $requester = User::factory()->create();
        $other = User::factory()->create();
        $this->requestFrom($requester, $me);
        $this->requestFrom($requester, $other);

        $me->acceptFollowRequest($requester);

        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $other->id)->count());
    }
}
