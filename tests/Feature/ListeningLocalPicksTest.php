<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ListeningPicks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The listening picks that play inside the app (document/{code}/picks/,
 * see listening:build-picks). The files are fixtures in a temporary
 * folder the service is pointed at — the real ones are not in git.
 */
class ListeningLocalPicksTest extends TestCase
{
    use RefreshDatabase;

    private string $documents;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documents = sys_get_temp_dir().'/eos-picks-'.bin2hex(random_bytes(4));
        $folder = "{$this->documents}/M01/picks";
        File::ensureDirectoryExists($folder);

        File::put("{$folder}/index.json", json_encode([
            [['slug' => 'lee-fixture'], ['slug' => 'voa-fixture'], ['slug' => 'sme-fixture']],
            [], [], [],
        ]));
        File::put("{$folder}/lee-fixture.mp3", str_repeat('0123456789', 100));
        File::put("{$folder}/lee-fixture.turns.json", json_encode(['duration' => 4.0, 'segments' => [
            ['speaker' => 'Alex', 'text' => 'First fixture line.', 'start' => 0.0, 'end' => 2.0],
            ['speaker' => 'Sam', 'text' => 'Second fixture line.', 'start' => 2.0, 'end' => 4.0],
        ]]));
        File::put("{$folder}/voa-fixture.turns.json", json_encode(['duration' => 4.0, 'segments' => []]));

        $this->app->instance(ListeningPicks::class, new ListeningPicks($this->documents));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->documents);

        parent::tearDown();
    }

    public function test_only_a_listed_pick_whose_audio_file_exists_is_played_here(): void
    {
        $trio = app(ListeningPicks::class)->forDay('M01', 1);

        $this->assertSame('lee-fixture', $trio[0]['local']['slug']);
        $this->assertSame(route('listening.audio', ['M01', 'lee-fixture']), $trio[0]['local']['audioUrl']);
        $this->assertNull($trio[1]['local'], 'listed, but its audio file is missing');
        $this->assertNull($trio[2]['local'], 'not listed at all');
        $this->assertNull(app(ListeningPicks::class)->forDay('M01', 2)[0]['local']);
    }

    public function test_the_synced_text_keeps_its_speakers_and_only_listed_slugs_are_read(): void
    {
        $picks = app(ListeningPicks::class);

        $this->assertSame(['Alex', 'Sam'], array_column($picks->segmentsFor('M01', 'lee-fixture'), 'speaker'));
        $this->assertSame([], $picks->segmentsFor('M01', '../../M01/picks/lee-fixture'));
        $this->assertNull($picks->audioPath('M01', '../picks/lee-fixture'));
        $this->assertNull($picks->audioPath('../M01', 'lee-fixture'));
    }

    public function test_a_logged_in_learner_can_stream_and_seek_the_audio(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.audio', ['M01', 'lee-fixture']))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->withHeaders(['Range' => 'bytes=10-19'])
            ->get(route('listening.audio', ['M01', 'lee-fixture']))
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 10-19/1000');
    }

    public function test_audio_that_is_not_a_listed_pick_is_not_found_and_visitors_must_log_in(): void
    {
        $this->get(route('listening.audio', ['M01', 'lee-fixture']))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());

        $this->get(route('listening.audio', ['M01', 'sme-fixture']))->assertNotFound();
        $this->get(route('listening.audio', ['M01', 'voa-fixture']))->assertNotFound();
        $this->get('/listening/audio/M01/lee-fixture.mp3')->assertNotFound();
    }

    public function test_the_page_offers_to_listen_here_with_the_text_and_keeps_the_original_link(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('listening.show'))
            ->assertSee('Listen here')
            ->assertSee('Plays here')
            ->assertSeeHtml('src="'.route('listening.audio', ['M01', 'lee-fixture']).'"')
            ->assertSeeInOrder(['Alex', 'First fixture line.', 'Sam', 'Second fixture line.'])
            ->assertSee('Original page')
            ->assertSeeHtml('window.eosListenTracker(audio, () => { $wire.markListened() }, 0.9);')
            ->assertSeeHtml('href="https://www.bbc.co.uk/learningenglish/english/features/real-easy-english/240607"');
    }

    public function test_a_pick_that_does_not_play_here_still_just_links_out(): void
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('listening.show'))->getContent();

        $this->assertSame(1, substr_count($html, 'Listen here</span>'), 'only the one fixture pick has a player');
        $this->assertStringContainsString('href="https://learningenglish.voanews.com/a/describing-your-day-mornings/6241274.html"', $html);
    }
}
