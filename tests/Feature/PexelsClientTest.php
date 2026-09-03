<?php

namespace Tests\Feature;

use App\Services\PexelsClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PexelsClientTest extends TestCase
{
    public function test_it_downloads_and_caches_the_image_for_a_word(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.pexels.com/*' => Http::response([
                'photos' => [['src' => ['medium' => 'https://images.pexels.com/photos/1/shower.jpg']]],
            ]),
            'images.pexels.com/*' => Http::response('fake-image-bytes'),
        ]);

        $url = (new PexelsClient('test-key'))->imageUrlFor('have a shower', 'shower');

        $this->assertNotNull($url);
        Storage::disk('public')->assertExists('vocabulary-images/have-a-shower.jpg');
    }

    public function test_a_second_call_for_the_same_word_never_hits_the_api_again(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('vocabulary-images/have-a-shower.jpg', 'already-cached-bytes');
        Http::fake(); // any real HTTP call would fail this test via assertNothingSent below

        $url = (new PexelsClient('test-key'))->imageUrlFor('have a shower', 'shower');

        $this->assertNotNull($url);
        Http::assertNothingSent();
    }

    public function test_it_returns_null_without_an_api_key(): void
    {
        Storage::fake('public');

        $url = (new PexelsClient(''))->imageUrlFor('cereal', 'bowl of cereal');

        $this->assertNull($url);
    }

    public function test_it_returns_null_when_pexels_has_no_results(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.pexels.com/*' => Http::response(['photos' => []]),
        ]);

        $url = (new PexelsClient('test-key'))->imageUrlFor('fortunately', 'fortunately');

        $this->assertNull($url);
    }

    public function test_it_returns_null_when_the_pexels_request_fails(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.pexels.com/*' => Http::response(null, 500),
        ]);

        $url = (new PexelsClient('test-key'))->imageUrlFor('cereal', 'bowl of cereal');

        $this->assertNull($url);
    }

    public function test_it_downloads_and_caches_a_video_preferring_sd_quality(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.pexels.com/videos/*' => Http::response([
                'videos' => [[
                    'video_files' => [
                        ['quality' => 'hd', 'link' => 'https://videos.pexels.com/video/hd.mp4'],
                        ['quality' => 'sd', 'link' => 'https://videos.pexels.com/video/sd.mp4'],
                    ],
                ]],
            ]),
            'videos.pexels.com/*' => Http::response('fake-video-bytes'),
        ]);

        $url = (new PexelsClient('test-key'))->videoUrlFor('m01-morning', 'quiet morning routine');

        $this->assertNotNull($url);
        Storage::disk('public')->assertExists('ambient-videos/m01-morning.mp4');
        Http::assertSent(fn ($request) => $request->url() === 'https://videos.pexels.com/video/sd.mp4');
    }

    public function test_a_second_call_for_the_same_video_never_hits_the_api_again(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('ambient-videos/m01-morning.mp4', 'already-cached-bytes');
        Http::fake();

        $url = (new PexelsClient('test-key'))->videoUrlFor('m01-morning', 'quiet morning routine');

        $this->assertNotNull($url);
        Http::assertNothingSent();
    }

    public function test_video_returns_null_when_pexels_has_no_results(): void
    {
        Storage::fake('public');
        Http::fake([
            'api.pexels.com/videos/*' => Http::response(['videos' => []]),
        ]);

        $url = (new PexelsClient('test-key'))->videoUrlFor('m01-morning', 'quiet morning routine');

        $this->assertNull($url);
    }
}
