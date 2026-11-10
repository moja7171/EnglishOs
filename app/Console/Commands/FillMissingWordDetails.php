<?php

namespace App\Console\Commands;

use App\Models\VocabularyWord;
use App\Services\WordDetails;
use Illuminate\Console\Command;

/**
 * Gives every saved word that has no type, English meaning, Persian or
 * sentence the ones it can find (see WordDetails::fill()). Words the sources cannot help
 * are left as they were and tried again on the next run.
 */
class FillMissingWordDetails extends Command
{
    protected $signature = 'vocabulary:fill-details
        {--dry-run : List the words that miss something without asking anyone}
        {--limit=0 : Stop after this many words (0 = all)}
        {--pause=2 : Seconds to wait between words, for the Gemini quota}';

    protected $description = 'Fills the missing type, meaning, Persian and sentence of saved words';

    public function handle(WordDetails $details): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $pause = max(0, (int) $this->option('pause'));
        $seen = 0;
        $filled = 0;

        VocabularyWord::query()
            ->with(['sentences', 'learner'])
            ->chunkById(100, function ($words) use ($details, $dryRun, $limit, $pause, &$seen, &$filled) {
                foreach ($words as $word) {
                    if (! WordDetails::isMissingDetails($word) && ! WordDetails::lacksSentence($word)) {
                        continue;
                    }

                    if ($limit > 0 && $seen >= $limit) {
                        return false;
                    }

                    $seen++;

                    if ($dryRun) {
                        $this->line("  {$word->word}: ".collect(['pos' => $word->pos, 'meaning' => $word->meaning, 'meaning_fa' => $word->meaning_fa, 'sentence' => $word->learningSentences() === [] ? null : 'yes'])->filter(fn ($value) => blank($value))->keys()->implode(', ').' missing');

                        continue;
                    }

                    if ($details->fill($word)) {
                        $filled++;
                        $this->line("  {$word->word}: filled");
                    } else {
                        $this->line("  {$word->word}: nothing found");
                    }

                    if ($pause > 0) {
                        sleep($pause);
                    }
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '')."{$seen} words miss something".($dryRun ? '.' : ", {$filled} filled (run again for the rest)."));

        return self::SUCCESS;
    }
}
