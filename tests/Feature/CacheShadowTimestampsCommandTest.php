<?php

namespace Tests\Feature;

use App\Console\Commands\CacheShadowTimestamps;
use App\Models\Mission;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Word timings below are real Whisper output (whisper-large-v3-turbo, word
 * granularity) for M01's actual Bob the Canadian video — including the real
 * case of Whisper handing a 4-second pause to the word after it ("The").
 */
class CacheShadowTimestampsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'T99';

    private const WORDS = [
        ['learn', 38.82, 39.0], ['to', 39.0, 39.2], ['talk', 39.2, 39.46], ['about', 39.46, 39.7],
        ['your', 39.7, 39.98], ['daily', 39.98, 40.18], ['routine.', 39.76, 40.76],
        ['The', 40.76, 44.84], ['very', 44.84, 45.04], ['first', 45.04, 45.38], ['thing', 45.38, 45.62],
        ['I', 45.62, 45.8], ['do', 45.8, 46.0], ['every', 46.0, 46.26], ['day', 46.26, 46.58],
        ['is', 46.58, 47.26], ['I', 47.26, 47.56], ['wake', 47.56, 48.04], ['up', 48.04, 48.36],
        ['and', 48.36, 48.7], ['then', 48.7, 48.82], ['I', 48.82, 48.98], ['usually', 48.98, 49.32],
    ];

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('document/'.self::CODE));

        parent::tearDown();
    }

    /**
     * @return list<array{text: string, start: float, end: float}>
     */
    private function words(): array
    {
        return array_map(fn (array $word) => ['text' => $word[0], 'start' => $word[1], 'end' => $word[2]], self::WORDS);
    }

    /**
     * @param  array<string, mixed>  $cache
     */
    private function seedMission(array $cache): string
    {
        Mission::create([
            'code' => self::CODE,
            'title' => 'Test mission',
            'module' => 'Me',
            'outcome' => 'I can test.',
            'phases' => [[
                'phase' => 'build',
                'steps' => [
                    ['key' => 'listening', 'audio_url' => '/storage/missions/t99/missing.mp3'],
                    ['key' => 'daily_listen_2', 'shadow_lines' => ['The **very first** **thing** I **do** **every day** is I **wake up**.']],
                ],
            ]],
        ]);

        $path = base_path('document/'.self::CODE.'/shadow_timestamps.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($cache));

        return $path;
    }

    public function test_a_line_pauses_where_its_last_word_ends_not_inside_the_next_sentence(): void
    {
        $path = $this->seedMission(['listening_segments' => [], 'listening_words' => $this->words()]);
        $this->mock(GroqClient::class)->shouldNotReceive('transcribeWordsWithTimestamps', 'transcribeSegmentsWithTimestamps');

        $this->artisan('missions:cache-shadow-timestamps', ['code' => self::CODE])->assertSuccessful();

        $timestamps = json_decode(File::get($path), true)['daily_listen_2'][0];

        // "up" ends at 48.36; the next sentence ("and then I usually...")
        // starts right after — the pause must not run into it.
        $this->assertEqualsWithDelta(48.51, $timestamps['end'], 0.01);
        // "The" really starts ~44.5s; Whisper's 40.76 is the silence before
        // it, which would replay the end of the sentence before.
        $this->assertGreaterThan(43.9, $timestamps['start']);
        $this->assertLessThan(44.84, $timestamps['start']);
    }

    public function test_cached_whisper_output_is_reused_and_keys_it_does_not_own_are_kept(): void
    {
        $turns = [['text' => 'Hello.', 'start' => 0.0, 'end' => 1.0, 'speaker' => 'Pippa']];
        $path = $this->seedMission([
            'listening_segments' => [['text' => 'Hello.', 'start' => 0.0, 'end' => 1.0]],
            'listening_words' => $this->words(),
            'listening_turns' => $turns,
        ]);
        $this->mock(GroqClient::class)->shouldNotReceive('transcribeWordsWithTimestamps', 'transcribeSegmentsWithTimestamps');

        $this->artisan('missions:cache-shadow-timestamps', ['code' => self::CODE])->assertSuccessful();

        $cache = json_decode(File::get($path), true);
        $this->assertEquals($turns, $cache['listening_turns']);
        $this->assertEquals([['text' => 'Hello.', 'start' => 0.0, 'end' => 1.0]], $cache['listening_segments']);
    }

    public function test_a_normal_length_word_keeps_its_own_start(): void
    {
        $trimmed = CacheShadowTimestamps::trimSilenceFromWords($this->words());

        $this->assertSame(44.84, $trimmed[8]['start']);
        $this->assertSame(44.84, $trimmed[7]['end']);
    }
}
