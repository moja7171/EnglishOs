<?php

namespace Tests\Unit;

use App\Services\SpeakerTurnAligner;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are shaped like the real M01 data (BBC "Real Easy English:
 * Mornings"): Whisper's segments don't follow the speakers' turns — one
 * segment can hold the end of one person's line and the start of the
 * next's — and its spelling differs from the written transcript here
 * and there ("OK" / "Okay", a dropped contraction).
 */
class SpeakerTurnAlignerTest extends TestCase
{
    private const TRANSCRIPT = [
        ['speaker' => 'Neil', 'text' => 'So, Georgie, how are you today?'],
        ['speaker' => 'Georgie', 'text' => "I'm very well, thank you. How are you?"],
        ['speaker' => 'Neil', 'text' => "I'm well, thank you very much."],
        ['speaker' => 'Georgie', 'text' => 'Good.'],
        ['speaker' => 'Neil', 'text' => "OK, let's get started."],
    ];

    public function test_each_chunk_gets_the_speaker_of_the_words_in_it(): void
    {
        $segments = [
            ['text' => 'So, Georgie, how are you today?', 'start' => 23.5, 'end' => 25.3],
            ['text' => "I'm very well, thank you. How are you?", 'start' => 25.9, 'end' => 28.1],
            ['text' => "I'm well, thank you very much.", 'start' => 28.1, 'end' => 29.7],
        ];

        $result = (new SpeakerTurnAligner)->align($segments, self::TRANSCRIPT);

        $this->assertSame(['Neil', 'Georgie', 'Neil'], array_column($result['turns'], 'speaker'));
        $this->assertSame("I'm very well, thank you. How are you?", $result['turns'][1]['text']);
        $this->assertSame(25.9, $result['turns'][1]['start']);
        $this->assertSame(28.1, $result['turns'][1]['end']);
    }

    public function test_a_segment_holding_two_speakers_is_split_where_the_words_change_hands(): void
    {
        $segments = [
            ['text' => "So, Georgie, how are you today? I'm very well, thank you. How are you?", 'start' => 23.5, 'end' => 28.1],
            ['text' => "I'm well, thank you very much. Good. Okay, let's get started.", 'start' => 28.1, 'end' => 41.8],
        ];

        $result = (new SpeakerTurnAligner)->align($segments, self::TRANSCRIPT);

        $this->assertSame(['Neil', 'Georgie', 'Neil', 'Georgie', 'Neil'], array_column($result['turns'], 'speaker'));
        $this->assertSame(
            ['So, Georgie, how are you today?', "I'm very well, thank you. How are you?", "I'm well, thank you very much.", 'Good.', "Okay, let's get started."],
            array_column($result['turns'], 'text'),
        );
        $this->assertSame(array_column($result['turns'], 'start'), collect(array_column($result['turns'], 'start'))->sort()->values()->all());
        $this->assertSame(23.5, $result['turns'][0]['start']);
        $this->assertSame(41.8, $result['turns'][4]['end']);
    }

    public function test_audio_the_transcript_never_mentions_takes_the_speaker_of_its_neighbours(): void
    {
        $segments = [
            ['text' => 'Welcome back to the show everybody.', 'start' => 0.0, 'end' => 3.0],
            ['text' => 'So, Georgie, how are you today?', 'start' => 3.0, 'end' => 5.0],
            ['text' => "I'm very well, thank you. How are you?", 'start' => 5.5, 'end' => 8.0],
        ];

        $result = (new SpeakerTurnAligner)->align($segments, self::TRANSCRIPT);

        $this->assertSame(['Neil', 'Neil', 'Georgie'], array_column($result['turns'], 'speaker'));
        $this->assertLessThan(1.0, $result['spokenMatchRate']);
        $this->assertLessThan(1.0, $result['transcriptMatchRate']);
    }

    public function test_a_clean_match_reports_full_match_rates(): void
    {
        $segments = [
            ['text' => 'So, Georgie, how are you today?', 'start' => 0.0, 'end' => 2.0],
        ];

        $result = (new SpeakerTurnAligner)->align($segments, [self::TRANSCRIPT[0]]);

        $this->assertSame(1.0, $result['spokenMatchRate']);
        $this->assertSame(1.0, $result['transcriptMatchRate']);
    }

    public function test_an_empty_transcript_or_audio_yields_no_turns_instead_of_failing(): void
    {
        $aligner = new SpeakerTurnAligner;

        $this->assertSame([], $aligner->align([], self::TRANSCRIPT)['turns']);
        $this->assertSame(0.0, $aligner->align([], self::TRANSCRIPT)['spokenMatchRate']);
        $this->assertSame(0.0, $aligner->align([['text' => 'Hello there.', 'start' => 0.0, 'end' => 1.0]], [])['transcriptMatchRate']);
    }
}
