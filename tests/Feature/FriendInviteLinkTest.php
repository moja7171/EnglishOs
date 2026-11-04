<?php

namespace Tests\Feature;

use App\Models\Follow;
use App\Models\FriendBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FriendInviteLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unknown_code_is_not_found(): void
    {
        $this->get(route('invite', 'nosuchcode'))->assertNotFound();
    }

    public function test_a_visitor_who_signs_up_from_a_link_sends_the_inviter_a_request(): void
    {
        $inviter = User::factory()->create();

        $this->get($inviter->inviteUrl())->assertRedirect(route('register'));

        Livewire::test('auth.register')
            ->set('name', 'New Learner')
            ->set('email', 'new@example.com')
            ->set('password', 'password123')
            ->call('register');

        $newcomer = User::where('email', 'new@example.com')->sole();

        $this->assertSame(Follow::STATUS_PENDING, Follow::where('follower_id', $newcomer->id)->where('followed_id', $inviter->id)->sole()->status);
    }

    public function test_someone_with_an_account_who_logs_in_lands_on_the_inviter(): void
    {
        $inviter = User::factory()->create();
        $learner = User::factory()->create(['password' => 'password123']);

        $this->get($inviter->inviteUrl());

        Livewire::test('auth.login')
            ->set('email', $learner->email)
            ->set('password', 'password123')
            ->call('login')
            ->assertRedirect(route('friends.index', ['invite' => $inviter->invite_code]));
    }

    public function test_a_signed_in_learner_sees_the_inviter_ready_to_follow_even_when_hidden_from_search(): void
    {
        $inviter = User::factory()->create(['name' => 'Hidden Friend', 'discoverable' => false]);
        $this->actingAs(User::factory()->create());

        $this->get($inviter->inviteUrl())->assertRedirect(route('friends.index', ['invite' => $inviter->invite_code]));

        Livewire::withQueryParams(['invite' => $inviter->invite_code])
            ->test('friends.index')
            ->assertSee('Hidden Friend')
            ->assertSee($inviter->handle())
            ->call('follow', $inviter->id)
            ->assertSee('Requested');
    }

    public function test_a_link_shows_nobody_when_it_is_my_own_or_we_are_blocked(): void
    {
        $me = User::factory()->create();
        $blocked = User::factory()->create(['name' => 'Blocked Person']);
        FriendBlock::create(['blocker_id' => $blocked->id, 'blocked_id' => $me->id]);
        $this->actingAs($me);

        Livewire::withQueryParams(['invite' => $blocked->invite_code])->test('friends.index')->assertDontSee('Blocked Person');
        $this->assertNull(Livewire::withQueryParams(['invite' => $me->invite_code])->test('friends.index')->instance()->invitedPerson);
    }
}
