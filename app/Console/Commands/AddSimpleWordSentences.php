<?php

namespace App\Console\Commands;

use App\Models\VocabularyWord;
use App\Services\WordSentences;
use Illuminate\Console\Command;

/**
 * Gives the words in the Words tab a simple sentence when the best one they
 * have is long — typically the whole line a learner tapped in a text — or
 * when they have none. The AI makes one short sentence per word (see
 * WordSentences::makeOne()); a word it could not make one for is tried
 * again on the next run.
 */
class AddSimpleWordSentences extends Command
{
    protected $signature = 'vocabulary:simple-sentences
        {--dry-run : List the words that need a sentence without asking the AI}
        {--limit=0 : Stop after this many words (0 = all)}
        {--pause=4 : Seconds to wait between words, for the Gemini quota}';

    protected $description = 'Makes a simple sentence for the words whose best sentence is long or missing';

    public function handle(WordSentences $sentences): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $pause = max(0, (int) $this->option('pause'));
        $seen = 0;
        $made = 0;
        $failed = 0;
        $inARow = 0;

        VocabularyWord::query()
            ->with(['sentences', 'learner'])
            ->chunkById(100, function ($words) use ($sentences, $dryRun, $limit, $pause, &$seen, &$made, &$failed, &$inARow) {
                foreach ($words as $word) {
                    $best = $word->displaySentence();

                    if ($best !== null && VocabularyWord::isSimple($best)) {
                        continue;
                    }

                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }

                    $seen++;

                    if ($dryRun) {
                        $this->line("  {$word->word}: ".($best ?? '(no sentence)'));

                        continue;
                    }

                    $sentence = $word->learner === null ? null : $sentences->makeOne($word, $word->learner);

                    if ($sentence === null) {
                        $failed++;
                        $this->line("  {$word->word}: no sentence made");

                        if (++$inARow >= 5) {
                            $this->error('Five failures in a row — the AI is probably out of quota. Stopping; run again later.');

                            return false;
                        }
                    } else {
                        $inARow = 0;
                        $made++;
                        $this->line("  {$word->word}: {$sentence->sentence}");
                    }

                    if ($pause > 0) {
                        sleep($pause);
                    }
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '')."{$seen} words need a simple sentence".($dryRun ? '.' : ", {$made} made, {$failed} not made (run again)."));

        return self::SUCCESS;
    }
}
