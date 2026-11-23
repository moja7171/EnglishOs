<?php

namespace Tests\Feature;

use App\Models\ExtraEpisode;
use App\Models\User;
use App\Services\SrtToVtt;
use Database\Seeders\ExtraEnglishSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Extra English: a standalone course of 30 sitcom episodes. The grid, the
 * full-episode page with one English-captions button, and the seeded data.
 */
class ExtraEnglishTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_loads_all_thirty_episodes_and_can_run_again(): void
    {
        Storage::fake('public');

        $this->seed(ExtraEnglishSeeder::class);
        $this->seed(ExtraEnglishSeeder::class);

        $this->assertSame(30, ExtraEpisode::count());
        $this->assertSame("Hector's Arrival", ExtraEpisode::where('number', 1)->value('title'));
        $this->assertSame('Love Hurts', ExtraEpisode::where('number', 30)->value('title'));
        $this->assertSame([], ExtraEpisode::whereNull('video_file')->orWhere('duration_seconds', '<', 1200)->pluck('number')->all());
    }

    public function test_srt_becomes_webvtt(): void
    {
        $vtt = SrtToVtt::convert("\xEF\xBB\xBF1\r\n00:00:14,519 --> 00:00:17,519\r\n This is the story.\r\n");

        $this->assertStringStartsWith("WEBVTT\n\n1\n00:00:14.519 --> 00:00:17.519\n This is the story.", $vtt);
        $this->assertStringNotContainsString(',', $vtt);
    }

    public function test_the_grid_shows_every_episode_with_number_title_and_length(): void
    {
        ExtraEpisode::factory()->create(['number' => 1, 'title' => "Hector's Arrival", 'duration_seconds' => 1438]);
        ExtraEpisode::factory()->create(['number' => 2, 'title' => 'Hector Goes Shopping', 'duration_seconds' => 1427]);

        $this->actingAs(User::factory()->create())->get(route('courses.extra'))
            ->assertOk()
            ->assertSee('Hector Goes Shopping')
            ->assertSee('24 min')
            ->assertSeeHtml('images/extra/02.jpg')
            ->assertSeeHtml('href="'.route('courses.extra.episode', 2).'"');
    }

    public function test_the_episode_page_plays_the_full_video_from_the_download_host_with_a_cc_button(): void
    {
        config(['media.base_url' => 'https://dl.example.test/englishos']);
        Storage::fake('public');
        $episode = ExtraEpisode::factory()->create(['number' => 1, 'video_file' => '01 - Hectors_arrival [www.languagecentre.ir].mp4']);
        Storage::disk('public')->put($episode->captionsPath(), "WEBVTT\n");

        $this->actingAs(User::factory()->create())->get(route('courses.extra.episode', 1))
            ->assertOk()
            ->assertSee("Hector's Arrival")
            ->assertSeeHtml('https://dl.example.test/englishos/extra/01%20-%20Hectors_arrival%20%5Bwww.languagecentre.ir%5D.mp4')
            ->assertSeeHtml('extra/01.vtt')
            ->assertSee('CC');
    }

    public function test_an_episode_without_captions_has_no_cc_button_and_without_a_host_says_so(): void
    {
        config(['media.base_url' => null]);
        Storage::fake('public');
        ExtraEpisode::factory()->create(['number' => 3]);

        $this->actingAs(User::factory()->create())->get(route('courses.extra.episode', 3))
            ->assertOk()
            ->assertSeeHtml('data-testid="extra-no-video"');
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('courses.extra'))->assertRedirect();
    }
}
