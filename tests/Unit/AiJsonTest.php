<?php

namespace Tests\Unit;

use App\Services\AiJson;
use PHPUnit\Framework\TestCase;

class AiJsonTest extends TestCase
{
    public function test_it_reads_valid_json_as_is(): void
    {
        $this->assertSame(
            ['severity' => 'minor', 'hint' => 'Use "days".'],
            AiJson::decode('{"severity": "minor", "hint": "Use \"days\"."}'),
        );
    }

    public function test_it_strips_a_code_fence(): void
    {
        $this->assertSame(
            ['severity' => 'none', 'hint' => 'Clear.'],
            AiJson::decode("```json\n{\"severity\": \"none\", \"hint\": \"Clear.\"}\n```"),
        );
    }

    public function test_it_reads_a_value_wrapped_in_single_quotes_around_a_quoted_word(): void
    {
        $this->assertSame(
            ['severity' => 'minor', 'hint' => '"day" should be plural.'],
            AiJson::decode('{"severity": "minor", "hint": \'"day" should be plural.\'}'),
        );
    }

    public function test_it_reads_a_value_with_an_unescaped_quote_inside(): void
    {
        $this->assertSame(
            ['severity' => 'minor', 'hint' => 'Write "work" here.'],
            AiJson::decode('{"severity": "minor", "hint": "Write "work" here."}'),
        );
    }

    public function test_it_reads_every_field_of_a_longer_object_with_nulls(): void
    {
        $data = AiJson::decode('{"status": "fix", "original": "I wakes up", "corrected": null, "why": "کلمه «wake» درست است، "wakes" نه."}');

        $this->assertSame('fix', $data['status']);
        $this->assertSame('I wakes up', $data['original']);
        $this->assertNull($data['corrected']);
        $this->assertSame('کلمه «wake» درست است، "wakes" نه.', $data['why']);
    }

    public function test_it_gives_up_on_text_with_no_keys(): void
    {
        $this->assertNull(AiJson::decode('Sorry, I cannot do that.'));
    }
}
