<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The review card's extra details (pos, example, the learner's own
 * sentence) and the best-effort backfill that fills them in for words
 * saved before those columns existed.
 */
class VocabularyWordCardDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [
                    [
                        'key' => 'vocabulary_builder_1',
                        'words' => [
                            ['phrase' => 'oversleep', 'meaning' => 'to sleep too long', 'pos' => 'verb', 'example' => 'I overslept and missed the bus.'],
                        ],
                    ],
                    [
                        'key' => 'listening',
                        'target_phrases' => [
                            ['phrase' => 'sleep in', 'meaning' => 'to stay in bed', 'gap_before' => 'I like to ', 'gap_after' => ' at weekends.'],
                        ],
                    ],
                ],
            ]],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function makeWord(MissionRun $run, string $word, array $attributes = []): VocabularyWord
    {
        return VocabularyWord::create(array_merge([
            'learner_id' => $run->learner_id,
            'source_mission_run_id' => $run->id,
            'word' => $word,
            'meaning' => 'x',
            'next_review_at' => now(),
        ], $attributes));
    }

    public function test_the_backfill_copies_pos_example_and_the_learners_own_sentence_from_the_source_run(): void
    {
        $run = $this->makeRun();
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['examples' => [['word' => 'oversleep', 'example' => 'I oversleep every Monday.']]]),
        ]);
        $word = $this->makeWord($run, 'Oversleep'); // case-insensitive match

        $word->fillMissingDetailsFromSource();

        $fresh = $word->fresh();
        $this->assertSame('verb', $fresh->pos);
        $this->assertSame('I overslept and missed the bus.', $fresh->example);
        $this->assertSame('I oversleep every Monday.', $fresh->user_sentence);
    }

    public function test_the_backfill_never_overwrites_what_a_word_already_has(): void
    {
        $run = $this->makeRun();
        $word = $this->makeWord($run, 'oversleep', ['pos' => 'noun', 'example' => 'Already here.']);

        $word->fillMissingDetailsFromSource();

        $fresh = $word->fresh();
        $this->assertSame('noun', $fresh->pos);
        $this->assertSame('Already here.', $fresh->example);
    }

    public function test_a_listening_word_gets_its_gap_fill_sentence_as_the_example(): void
    {
        $run = $this->makeRun();
        $word = $this->makeWord($run, 'sleep in');

        $word->fillMissingDetailsFromSource();

        $fresh = $word->fresh();
        $this->assertSame('I like to sleep in at weekends.', $fresh->example);
        $this->assertNull($fresh->pos);
        $this->assertNull($fresh->user_sentence);
    }

    public function test_a_word_with_no_source_run_or_no_match_is_left_alone(): void
    {
        $run = $this->makeRun();
        $orphan = $this->makeWord($run, 'oversleep', ['source_mission_run_id' => null]);
        $unmatched = $this->makeWord($run, 'something-else');

        $orphan->fillMissingDetailsFromSource();
        $unmatched->fillMissingDetailsFromSource();

        $this->assertNull($orphan->fresh()->pos);
        $this->assertNull($unmatched->fresh()->example);
    }

    public function test_the_target_word_is_picked_out_in_a_sentence(): void
    {
        $run = $this->makeRun();
        $bold = fn (string $word) => '<strong class="font-bold text-accent-ink dark:text-accent-ink-dark">'.$word.'</strong>';

        $commute = $this->makeWord($run, 'commute');
        $this->assertSame('I '.$bold('commute').' by train.', (string) $commute->highlightIn('I commute by train.'));
        $this->assertSame('He '.$bold('commutes').' daily.', (string) $commute->highlightIn('He commutes daily.')); // an ending is allowed

        $phrasal = $this->makeWord($run, 'get up');
        $this->assertSame('She '.$bold('gets up').' at six.', (string) $phrasal->highlightIn('She gets up at six.'));

        $this->assertSame('I '.$bold('Commute').' a lot.', (string) $commute->highlightIn('I Commute a lot.')); // case-insensitive
    }

    public function test_the_highlight_leaves_unmatched_sentences_plain_and_escapes_html(): void
    {
        $run = $this->makeRun();

        $oversleep = $this->makeWord($run, 'oversleep');
        $this->assertSame('I overslept again.', (string) $oversleep->highlightIn('I overslept again.')); // irregular form: no guess

        // A short word is only matched exactly, never as the start of another word.
        $go = $this->makeWord($run, 'go');
        $this->assertSame('That is good.', (string) $go->highlightIn('That is good.'));

        $this->assertSame('a &lt;b&gt;bold&lt;/b&gt; move', (string) $go->highlightIn('a <b>bold</b> move'));
    }

    public function test_a_gap_with_no_surrounding_text_gives_no_example(): void
    {
        $this->assertNull(VocabularyWord::exampleFromGap(['phrase' => 'sleep in']));
        $this->assertSame('Sleep in!', VocabularyWord::exampleFromGap(['phrase' => 'Sleep in', 'gap_after' => '!']));
    }
}
