<?php

namespace Tests\Feature;

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
            ->call('register')
            ->assertRedirect(route('onboarding'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    }

    public function test_registration_asks_only_for_name_email_and_password_and_starts_at_the_default_level(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'super-secret')
            ->call('register')
            ->assertRedirect(route('onboarding'));

        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'cefr_level' => 'A2+',
            'target_band' => null,
            'gender' => 'unspecified',
            'avatar_style' => 'initial',
        ]);
    }

    public function test_registration_needs_a_password_of_at_least_eight_characters(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'Ada Lovelace')
            ->set('email', 'ada@example.com')
            ->set('password', 'short')
            ->call('register')
            ->assertHasErrors(['password']);

        $this->assertGuest();
    }

    public function test_guests_land_on_the_welcome_screen_and_a_new_learner_gets_the_guide_cards_before_placement(): void
    {
        $this->get('/')->assertRedirect(route('welcome'));

        $this->get(route('welcome'))
            ->assertOk()
            ->assertSee('Speak English with confidence')
            ->assertSee('Get started')
            ->assertSee('I have an account');

        $this->actingAs(User::factory()->create())
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('اپ چطور کار می‌کنه')
            ->assertSee('href="'.route('placement').'"', false);
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
