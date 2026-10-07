<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotionMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_pages_stagger_their_sections_in(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['home', 'friends.index', 'review.index', 'vocabulary.index'] as $route) {
            $this->get(route($route))->assertSee('stagger-children', false);
        }
    }

    public function test_the_account_menu_and_bell_are_swipe_to_close_sheets_on_phones(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('home'))->getContent();

        $this->assertSame(2, substr_count($html, 'x-swipe-close="open = false"'));
        $this->assertStringContainsString('aria-label="Account menu"', $html);
        $this->assertStringContainsString('aria-label="Notifications"', $html);
    }
}
