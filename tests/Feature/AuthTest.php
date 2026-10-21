<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_log_in_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'correct-password')
            ->call('login')
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'correct-password']);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'wrong-password')
            ->call('login')
            ->assertHasErrors(['email']);

        $this->assertGuest();
    }

    public function test_a_visitor_can_register_and_is_logged_in(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->call('register')
            // No mission seeded here, so home; see the test below for M01.
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    }

    public function test_a_new_learner_goes_straight_to_the_first_step_of_m01(): void
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [['phase' => 'foundation', 'steps' => [['key' => 'mission_brief'], ['key' => 'vocabulary_builder_1']]]],
        ]);

        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->call('register')
            ->assertRedirect(route('missions.show', [$mission, 'mission_brief']));
    }

    public function test_registration_saves_the_learners_self_reported_level_and_target_band(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->set('cefr_level', 'A2')
            ->set('target_band', '6.5')
            ->call('register')
            ->assertRedirect(route('home'));

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'cefr_level' => 'A2',
            'target_band' => '6.5',
        ]);
    }

    public function test_registration_defaults_to_pre_intermediate_and_no_target_band_if_left_untouched(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->call('register');

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'cefr_level' => 'A2+',
            'target_band' => null,
        ]);
    }

    public function test_registration_defaults_to_unspecified_gender_and_the_plain_initial_avatar(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->call('register');

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'gender' => 'unspecified',
            'avatar_style' => 'initial',
        ]);
    }

    public function test_registering_with_a_gender_picks_a_matching_default_avatar_style(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'super-secret')
            ->set('gender', 'female')
            ->call('register');

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'gender' => 'female',
            'avatar_style' => 'long',
        ]);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->set('password_confirmation', 'does-not-match')
            ->call('register')
            ->assertHasErrors(['password']);

        $this->assertGuest();
    }

    public function test_an_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
