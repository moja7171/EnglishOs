<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_get_the_theme_toggle_on_the_login_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Switch to dark mode');
    }

    public function test_signed_in_learners_get_the_theme_toggle_in_the_header(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Switch to dark mode');
    }

    public function test_the_saved_theme_is_applied_before_first_paint_and_restored_after_navigation(): void
    {
        $this->get(route('login'))
            ->assertSee("localStorage.getItem('eosTheme')", false)
            ->assertSee('new MutationObserver(apply)', false);
    }
}
