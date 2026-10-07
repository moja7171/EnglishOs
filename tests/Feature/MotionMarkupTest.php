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
}
