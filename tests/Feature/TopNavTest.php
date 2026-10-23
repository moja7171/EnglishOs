<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_review_icon_replaces_the_three_separate_dropdown_links(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        $response = $this->get(route('home'));

        $response->assertSee('Review');
        $response->assertSee(route('review.index'), false);
        $response->assertDontSee('My words');
        $response->assertDontSee('Daily Review');
        $response->assertDontSee('Speaking Recall');
    }

    public function test_the_review_icon_shows_a_combined_due_count_badge(): void
    {
        $learner = User::factory()->create();
        $learner->vocabularyWords()->create([
            'word' => 'commute', 'meaning' => 'to travel to work', 'next_review_at' => now()->subHour(),
        ]);
        $learner->vocabularyWords()->create([
            'word' => 'errand', 'meaning' => 'a short trip to do a task', 'next_review_at' => now()->subHour(),
        ]);
        $learner->speakingPrompts()->create([
            'prompt' => 'What do you usually do on weekends?', 'next_review_at' => now()->subHour(),
        ]);

        $this->actingAs($learner);

        $this->get(route('home'))->assertSee('>3<', false);
    }

    public function test_the_bottom_nav_has_four_tabs_with_missions_and_notebook(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('review.index'))
            ->assertSee('aria-label="Primary"', false)
            ->assertSee('grid-cols-4', false)
            ->assertSeeInOrder([
                'href="'.route('home').'"',
                'href="'.route('missions.index').'"',
                'href="'.route('review.index').'"',
                'href="'.route('notebook.index').'"',
            ], false);
    }

    public function test_the_nav_pill_sits_under_the_current_tab_and_hides_on_other_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('missions.index'))->assertSee('style="translate: 100% 0"', false);
        $this->get(route('listening.show'))->assertSee('style="translate: 100% 0"', false);
        $this->get(route('pi.practice'))->assertSee('style="translate: 100% 0"', false);
        $this->get(route('review.index'))->assertSee('style="translate: 200% 0"', false);
        $this->get(route('notebook.index'))->assertSee('style="translate: 300% 0"', false);

        $this->get(route('friends.index'))->assertSee('motion-reduce:transition-none opacity-0', false);
    }

    public function test_the_header_shows_only_the_logo_mark_for_a_signed_in_learner(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertDontSee('English <span', false);
    }

    public function test_the_signed_out_header_keeps_the_wordmark_and_has_no_bottom_nav(): void
    {
        $this->get(route('login'))
            ->assertSee('English <span', false)
            ->assertDontSee('aria-label="Primary"', false);
    }

    public function test_the_account_menu_holds_only_progress_friends_and_profile(): void
    {
        $this->actingAs(User::factory()->create());

        $menu = (string) $this->blade('<x-account-menu />');

        $this->assertSame(3, substr_count($menu, '<a '));
        $this->assertStringContainsString('href="'.route('progress.index').'"', $menu);
        $this->assertStringContainsString('Day 1 of 96', $menu);
        $this->assertStringContainsString('href="'.route('friends.index').'"', $menu);
        $this->assertStringContainsString('href="'.route('profile').'"', $menu);
        $this->assertStringNotContainsString('Sign out', $menu);
        $this->assertStringNotContainsString('dark mode', $menu);
    }

    public function test_the_account_menu_shows_admin_to_an_admin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->blade('<x-account-menu />')->assertSee('href="'.route('admin.index').'"', false);
    }

    public function test_profile_and_settings_holds_the_guide_the_switches_and_sign_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('href="'.route('learner.guide').'"', false)
            ->assertSee('aria-label="Dark mode"', false)
            ->assertSee('aria-label="Sound effects"', false)
            ->assertSee("localStorage.setItem('eosSoundEnabled', soundEnabled)", false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('Sign out');
    }

    public function test_the_avatar_shows_a_dot_when_friends_has_something_new(): void
    {
        $learner = User::factory()->create();
        $friend = User::factory()->create();
        $learner->follow($friend);
        $friend->acceptFollowRequest($learner);
        DirectMessage::create([
            'sender_id' => $friend->id,
            'recipient_id' => $learner->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => 'Hi!',
        ]);

        $this->actingAs($learner);

        $this->get(route('home'))->assertSee('new in Friends');
    }

    public function test_the_avatar_dot_ignores_messages_from_someone_you_can_no_longer_message(): void
    {
        $learner = User::factory()->create();
        $friend = User::factory()->create();
        DirectMessage::create([
            'sender_id' => $friend->id,
            'recipient_id' => $learner->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => 'Hi!',
        ]);

        $this->actingAs($learner);

        $this->get(route('home'))->assertDontSee('new in Friends');
    }

    public function test_the_avatar_has_no_dot_when_friends_is_quiet(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertDontSee('new in Friends');
    }

    public function test_the_bottom_nav_is_hidden_inside_a_mission(): void
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'mission_brief']]]],
        ]);

        $this->actingAs(User::factory()->create());

        $this->get(route('missions.show', $mission))
            ->assertOk()
            ->assertDontSee('aria-label="Primary"', false);
    }

    public function test_the_bottom_nav_stays_out_of_the_placement_test_and_chat_threads(): void
    {
        $learner = User::factory()->create();
        $friend = User::factory()->create();
        $this->actingAs($learner);

        $this->get(route('placement'))->assertOk()->assertDontSee('aria-label="Primary"', false);
        $this->get(route('friends.conversation', $friend))->assertDontSee('aria-label="Primary"', false);
    }

    public function test_the_bottom_nav_hides_itself_while_typing_or_recording(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))
            ->assertSee('x-show="! typing && ! recording"', false)
            ->assertSee('x-on:eos-recording.window', false);
    }

    public function test_the_progress_page_loads(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        $this->get(route('progress.index'))
            ->assertOk()
            ->assertSee('My Progress');
    }
}
