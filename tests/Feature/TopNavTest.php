<?php

namespace Tests\Feature;

use App\Models\DirectMessage;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_the_bottom_nav_has_five_tabs_ending_with_friends(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('review.index'))
            ->assertSee('aria-label="Primary"', false)
            ->assertSee('grid-cols-5', false)
            ->assertSeeInOrder([
                'href="'.route('home').'"',
                'href="'.route('missions.index').'"',
                'href="'.route('review.index').'"',
                'href="'.route('notebook.index').'"',
                'href="'.route('friends.index').'"',
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
        $this->get(route('friends.index'))->assertSee('style="translate: 400% 0"', false);
        $this->get(route('pair-practice.index'))->assertSee('style="translate: 400% 0"', false);

        $this->get(route('profile'))->assertSee('motion-reduce:transition-none opacity-0', false);
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

    public function test_the_avatar_opens_profile_directly_with_no_menu(): void
    {
        $this->actingAs(User::factory()->create());

        $avatar = (string) $this->blade('<x-account-menu />');

        $this->assertSame(1, substr_count($avatar, 'href='));
        $this->assertStringContainsString('href="'.route('profile').'"', $avatar);
        $this->assertStringNotContainsString('href="'.route('progress.index').'"', $avatar);
        $this->assertStringNotContainsString('Sign out', $avatar);
    }

    public function test_profile_leads_with_a_progress_card(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('profile')
            ->assertSee('href="'.route('progress.index').'"', false)
            ->assertSee('Day 1 of 96');
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

    public function test_the_friends_tab_counts_what_is_new_in_friends(): void
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

    public function test_the_friends_tab_ignores_messages_from_someone_you_can_no_longer_message(): void
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

    public function test_the_friends_tab_has_no_count_when_friends_is_quiet(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertDontSee('new in Friends');
    }

    public function test_a_mission_keeps_the_bottom_nav_but_holds_new_alerts_back(): void
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
            ->assertSee('aria-label="Primary"', false)
            ->assertSee('data-no-interrupt', false);
    }

    public function test_the_bottom_nav_shows_in_the_placement_test_but_not_in_a_chat_thread(): void
    {
        $learner = User::factory()->create();
        $friend = User::factory()->create();
        $this->actingAs($learner);

        $this->get(route('placement'))->assertOk()->assertSee('aria-label="Primary"', false);
        $this->get(route('friends.conversation', $friend))
            ->assertDontSee('aria-label="Primary"', false)
            ->assertDontSee('data-no-interrupt', false);
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
            ->assertSee('This week');
    }
}
