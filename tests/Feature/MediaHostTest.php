<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\MediaHost;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Serving the missions' audio and video from the download host
 * (config/media.php) instead of this app's own disk.
 */
class MediaHostTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://dl.example.test/englishos';

    public function test_only_audio_and_video_get_a_host_url_and_the_filename_is_encoded(): void
    {
        config(['media.base_url' => self::HOST.'/']);
        $host = app(MediaHost::class);

        $this->assertSame(self::HOST.'/m01/Bob%20the%20Canadian%20-%20Daily%20Routine.mp4', $host->missionFileUrl('M01', 'Bob the Canadian - Daily Routine.mp4'));
        $this->assertSame(self::HOST.'/m02/talk.mp3', $host->missionFileUrl('M02', 'talk.mp3'));
        $this->assertSame(self::HOST.'/m01/picks/lee-fixture.mp3', $host->pickAudioUrl('M01', 'lee-fixture'));
        $this->assertNull($host->missionFileUrl('M01', 'Daily Routine.en.vtt'), 'captions stay on this app');
    }

    public function test_nothing_has_a_host_url_while_no_host_is_configured(): void
    {
        config(['media.base_url' => null]);
        $host = app(MediaHost::class);

        $this->assertFalse($host->isConfigured());
        $this->assertNull($host->missionFileUrl('M01', 'talk.mp3'));
        $this->assertNull($host->pickAudioUrl('M01', 'lee-fixture'));
    }

    public function test_seeding_points_every_mission_audio_and_video_at_the_host_but_keeps_captions_here(): void
    {
        config(['media.base_url' => self::HOST]);

        $this->seed(MissionSeeder::class);

        $m01 = Mission::where('code', 'M01')->firstOrFail();

        $this->assertSame(self::HOST.'/m01/BBC%20Learning%20English%20-%20Real%20Easy%20English%20Talking%20about%20mornings.mp3', $m01->stepContent('listening')['audio_url']);
        $this->assertSame(self::HOST.'/m01/Bob%20the%20Canadian%20-%20Daily%20Routine.mp4', $m01->stepContent('video_shadowing')['video_url']);
        $this->assertStringContainsString('/storage/missions/m01/', $m01->stepContent('video_shadowing')['captions_url']);

        foreach (['M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08'] as $code) {
            $mission = Mission::where('code', $code)->firstOrFail();

            $this->assertStringStartsWith(self::HOST.'/'.strtolower($code).'/', $mission->stepContent('listening')['audio_url']);
            $this->assertStringStartsWith(self::HOST.'/'.strtolower($code).'/', $mission->stepContent('video_shadowing')['video_url']);
        }
    }

    public function test_a_pick_plays_from_the_host_when_its_text_is_here_even_though_its_audio_file_is_not(): void
    {
        config(['media.base_url' => self::HOST]);

        $documents = sys_get_temp_dir().'/eos-host-picks-'.bin2hex(random_bytes(4));
        $folder = "{$documents}/M01/picks";
        File::ensureDirectoryExists($folder);
        File::put("{$folder}/index.json", json_encode([
            [['slug' => 'lee-fixture'], ['slug' => 'voa-fixture'], ['slug' => 'sme-fixture']],
            [], [], [],
        ]));
        File::put("{$folder}/lee-fixture.turns.json", json_encode(['duration' => 2.0, 'segments' => []]));
        File::put("{$folder}/voa-fixture.mp3", 'audio, but no synced text');

        try {
            $trio = (new ListeningPicks($documents))->forDay('M01', 1);
        } finally {
            File::deleteDirectory($documents);
        }

        $this->assertSame(['slug' => 'lee-fixture', 'audioUrl' => self::HOST.'/m01/picks/lee-fixture.mp3'], $trio[0]['local']);
        $this->assertNull($trio[1]['local'], 'no synced text, so nothing to play along with');
        $this->assertNull($trio[2]['local'], 'not listed at all');
    }
}
