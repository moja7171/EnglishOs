<?php

namespace Tests\Unit;

use App\Services\ShadowLineTimestampMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Fixture segments below are real Whisper output (whisper-large-v3-turbo,
 * via GroqClient::transcribeSegmentsWithTimestamps) for M01's actual
 * "BBC Learning English - Real Easy English Talking about mornings.mp3"
 * — not invented data. Segment 6 in particular is the real case that
 * broke a naive implementation: Whisper merged 5 separate sentences
 * (including the target line) into one 16-second span.
 */
class ShadowLineTimestampMatcherTest extends TestCase
{
    private const SEGMENTS = [
        ['text', 0.88, 7.44, "Hello and welcome to Real Easy English, the podcast where we have real conversations in"],
        ['text', 25.92, 28.08, "I'm very well, thank you. How are you?"],
        ['text', 87.5, 89.1, 'Yes, I think it does.'],
        ['text', 89.22, 90.4, "I'm a morning person."],
        ['text', 90.4, 95.24, 'That means someone that has a lot of energy at the start of the day.'],
        ['text', 128.39, 129.99, "Otherwise, I'm very grumpy."],
        [
            'text', 292.38, 308.73,
            'So I check the forecast and I choose my clothes so that I wearing the right thing for '
                .'the type of weather That very sensible especially in the United Kingdom because the '
                .'weather can be different every day That very true Make sure you got your umbrella',
        ],
    ];

    private function segments(): array
    {
        return collect(self::SEGMENTS)
            ->map(fn ($row) => ['text' => $row[3], 'start' => $row[1], 'end' => $row[2]])
            ->values()
            ->all();
    }

    public function test_a_line_matching_one_segment_exactly_returns_that_segments_own_timing(): void
    {
        $result = (new ShadowLineTimestampMatcher())->match($this->segments(), "**Otherwise** I'm **very grumpy**.");

        $this->assertNotNull($result);
        $this->assertEqualsWithDelta(128.24, $result['start'], 0.05);
        $this->assertEqualsWithDelta(130.14, $result['end'], 0.05);
    }

    public function test_a_line_spanning_several_consecutive_segments_covers_all_of_them(): void
    {
        $result = (new ShadowLineTimestampMatcher())->match(
            $this->segments(),
            "Yes, I **think** it **does**. I'm a **morning person**. That **means** someone that has "
                .'**a lot of energy** at the **start** of the **day**.',
        );

        $this->assertNotNull($result);
        // Real span is 87.5 to 95.24 (segments 2-4) — never the whole
        // episode, never a single wrong segment.
        $this->assertEqualsWithDelta(87.35, $result['start'], 0.05);
        $this->assertEqualsWithDelta(95.39, $result['end'], 0.05);
    }

    public function test_a_line_buried_mid_way_through_a_merged_segment_lands_near_its_own_words_not_the_segments_start(): void
    {
        $result = (new ShadowLineTimestampMatcher())->match(
            $this->segments(),
            '**Especially** in the **United Kingdom** because the **weather** can be **different** **every day**.',
        );

        $this->assertNotNull($result);
        // The containing segment runs 292.38-308.73 (16.35s) — a wrong,
        // segment-level-only implementation would return exactly
        // 292.38, the START of an unrelated earlier sentence ("So I
        // check the forecast..."). The real words land roughly halfway
        // through the segment instead.
        $this->assertGreaterThan(296.0, $result['start']);
        $this->assertLessThan(306.0, $result['end']);
    }

    public function test_a_line_with_a_whisper_mis_transcribed_word_still_matches_via_fuzzy_fallback(): void
    {
        // Real seeded line says "you've got"; Whisper actually heard
        // "you got" — the exact substring search alone would fail here.
        $result = (new ShadowLineTimestampMatcher())->match(
            $this->segments(),
            "**Make sure** you've **got** your **umbrella**.",
        );

        $this->assertNotNull($result);
        $this->assertGreaterThan(304.0, $result['start']);
        $this->assertLessThan(309.5, $result['end']);
    }

    public function test_a_line_that_genuinely_is_not_in_the_audio_returns_null(): void
    {
        $result = (new ShadowLineTimestampMatcher())->match(
            $this->segments(),
            'This sentence was never said anywhere in this recording at all.',
        );

        $this->assertNull($result);
    }

    public function test_an_empty_line_returns_null(): void
    {
        $result = (new ShadowLineTimestampMatcher())->match($this->segments(), '');

        $this->assertNull($result);
    }
}
