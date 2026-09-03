<?php

namespace Tests\Feature;

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

    public function test_my_progress_is_reachable_from_the_dropdown_and_links_to_the_progress_page(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        $response = $this->get(route('home'));

        $response->assertSee('My Progress');
        $response->assertSee(route('progress.index'), false);
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
