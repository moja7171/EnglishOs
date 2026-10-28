<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ImageViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_zoomable_image_asks_the_viewer_to_open_its_own_picture(): void
    {
        $html = Blade::render('<x-zoomable-image url="/img/cat.jpg" alt="A cat" class="h-14 w-14" />');

        $this->assertStringContainsString('src="/img/cat.jpg"', $html);
        $this->assertStringContainsString('class="h-14 w-14"', $html);
        $this->assertStringContainsString('zoom-image', $html);
        $this->assertStringContainsString('/img/cat.jpg', $html);
        $this->assertStringContainsString('Enlarge picture', $html);
    }

    public function test_every_page_carries_the_one_shared_viewer(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('x-on:zoom-image.window', false)
            ->assertSee('x-swipe-close="open = false"', false);
    }
}
