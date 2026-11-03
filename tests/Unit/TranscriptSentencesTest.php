<?php

namespace Tests\Unit;

use App\Services\WebVttParser;
use PHPUnit\Framework\TestCase;

class TranscriptSentencesTest extends TestCase
{
    public function test_chunks_cut_mid_sentence_stay_together_until_the_sentence_ends(): void
    {
        $chunks = [
            'Hello and welcome to Real Easy English, the podcast where we have real conversations in',
            'easy English to help you learn. I\'m Neil.',
            'And I\'m Georgie.',
            'Today we are talking about',
            'our morning routines',
        ];

        $groups = WebVttParser::sentences($chunks, fn (string $chunk) => $chunk);

        $this->assertSame([
            [$chunks[0], $chunks[1]],
            [$chunks[2]],
            [$chunks[3], $chunks[4]],
        ], $groups);
    }
}
