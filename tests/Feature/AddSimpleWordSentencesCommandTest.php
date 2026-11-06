<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabularyWord;
use App\Models\WordSentence;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AddSimpleWordSentencesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function word(string $word, ?string $example, ?User $learner = null): VocabularyWord
    {
        return VocabularyWord::factory()->create([
            'learner_id' => ($learner ?? User::factory()->create())->id,
            'word' => $word,
            'example' => $example,
        ]);
    }

    public function test_a_word_whose_only_sentence_is_long_gets_a_simple_one(): void
    {
        $word = $this->word('bank', 'We walked for hours until we finally sat down on the bank of the quiet river.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('I sat on the bank.'));

        $this->artisan('vocabulary:simple-sentences', ['--pause' => 0])->assertSuccessful();

        $this->assertSame(['I sat on the bank.'], $word->sentences()->pluck('sentence')->all());
        $this->assertSame('I sat on the bank.', $word->refresh()->displaySentence());
    }

    public function test_a_word_with_a_simple_sentence_is_left_alone(): void
    {
        $this->word('sleep', 'I sleep eight hours.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->artisan('vocabulary:simple-sentences', ['--pause' => 0])->assertSuccessful();
    }

    public function test_a_word_with_no_sentence_gets_one(): void
    {
        $word = $this->word('overwhelmed', null);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('I felt overwhelmed today.'));

        $this->artisan('vocabulary:simple-sentences', ['--pause' => 0])->assertSuccessful();

        $this->assertSame(1, $word->sentences()->count());
    }

    public function test_a_dry_run_asks_nobody(): void
    {
        $word = $this->word('bank', 'We walked for hours until we finally sat down on the bank of the quiet river.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->artisan('vocabulary:simple-sentences', ['--dry-run' => true, '--pause' => 0])
            ->expectsOutputToContain('bank: We walked')
            ->assertSuccessful();

        $this->assertSame(0, WordSentence::count());
    }

    public function test_it_stops_after_five_failures_in_a_row(): void
    {
        foreach (range(1, 8) as $number) {
            $this->word('word'.$number, null);
        }
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('quota')));

        $this->artisan('vocabulary:simple-sentences', ['--pause' => 0])
            ->expectsOutputToContain('Five failures in a row')
            ->assertSuccessful();
    }
}
