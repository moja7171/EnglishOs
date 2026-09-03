<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SequentialPictureStoryComponentTest extends TestCase
{
    public function test_it_renders_each_image_with_a_1_based_step_number(): void
    {
        $images = [
            ['url' => 'https://example.test/1.jpg'],
            ['url' => 'https://example.test/2.jpg'],
        ];

        $html = Blade::render('<x-sequential-picture-story :images="$images" />', ['images' => $images]);

        $this->assertStringContainsString('https://example.test/1.jpg', $html);
        $this->assertStringContainsString('https://example.test/2.jpg', $html);
        $this->assertMatchesRegularExpression('/<span[^>]*>\s*1\s*<\/span>/', $html);
        $this->assertMatchesRegularExpression('/<span[^>]*>\s*2\s*<\/span>/', $html);
    }

    public function test_it_renders_a_captions_when_given(): void
    {
        $images = [['url' => 'https://example.test/1.jpg', 'caption' => 'She wakes up.']];

        $html = Blade::render('<x-sequential-picture-story :images="$images" />', ['images' => $images]);

        $this->assertStringContainsString('She wakes up.', $html);
    }

    public function test_a_missing_caption_falls_back_to_a_generic_step_label_for_the_alt_text(): void
    {
        $images = [['url' => 'https://example.test/1.jpg']];

        $html = Blade::render('<x-sequential-picture-story :images="$images" />', ['images' => $images]);

        $this->assertStringContainsString('alt="Step 1"', $html);
    }

    public function test_renders_with_no_images_without_error(): void
    {
        $html = Blade::render('<x-sequential-picture-story :images="[]" />');

        $this->assertStringNotContainsString('<img', $html);
    }
}
