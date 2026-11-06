<?php

namespace App\Console\Commands;

use App\Models\VocabularyWord;
use App\Services\GeminiWordDefiner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Corrects the Persian of the words learners added from the dictionary
 * before My Words kept the Persian of the meaning and sentence they were
 * added with (they hold the word's general, first-sense Persian). Words
 * from the missions are left alone — their Persian is written by hand.
 * The old Persian is kept in meaning_fa_previous, a word Gemini could not
 * answer for is left as it was, and the command can be run again until it
 * says nothing is left.
 */
#[Signature('vocabulary:fix-persian {--dry-run : Show the old and the new Persian without saving} {--limit=0 : Stop after this many words (0 = all)} {--chunk=20 : Words sent to Gemini in one call} {--pause=5 : Seconds to wait between calls, for the Gemini quota}')]
#[Description("Corrects the Persian of dictionary words learners saved earlier, using each word's meaning and sentence")]
class FixVocabularyPersian extends Command
{
    public function handle(GeminiWordDefiner $gemini): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, min(50, (int) $this->option('chunk')));
        $pause = max(0, (int) $this->option('pause'));
        $seen = 0;
        $changed = 0;
        $skipped = 0;
        $failed = false;

        VocabularyWord::query()
            ->whereNull('source_mission_run_id')
            ->whereNull('meaning_fa_checked_at')
            ->where(fn ($query) => $query->whereNotNull('meaning')->orWhereNotNull('example'))
            ->chunkById($chunk, function ($words) use ($gemini, $dryRun, $limit, $pause, &$seen, &$changed, &$skipped, &$failed) {
                if ($limit > 0) {
                    $words = $words->take($limit - $seen);
                }

                try {
                    $persian = $gemini->persianInContext($words->map(fn (VocabularyWord $word) => [
                        'id' => $word->id,
                        'word' => $word->word,
                        'meaning' => $word->meaning,
                        'pos' => $word->pos,
                        'sentence' => $word->example,
                    ])->values()->all());
                } catch (Throwable $exception) {
                    $this->error("Gemini could not be asked: {$exception->getMessage()}");
                    $failed = true;

                    return false;
                }

                foreach ($words as $word) {
                    $seen++;
                    $new = $persian[(string) $word->id] ?? null;

                    if ($new === null) {
                        $skipped++;
                        $this->line("  skipped  {$word->word} (no answer)");

                        continue;
                    }

                    if ($new !== $word->meaning_fa) {
                        $changed++;
                        $this->line("  {$word->word}: {$word->meaning_fa} → {$new}");
                    }

                    if (! $dryRun) {
                        $word->update([
                            'meaning_fa_previous' => $new !== $word->meaning_fa ? $word->meaning_fa : $word->meaning_fa_previous,
                            'meaning_fa' => $new,
                            'meaning_fa_checked_at' => now(),
                        ]);
                    }
                }

                if ($limit > 0 && $seen >= $limit) {
                    return false;
                }

                if ($pause > 0) {
                    sleep($pause);
                }
            });

        $this->info(($dryRun ? 'Dry run: ' : '')."{$seen} words looked at, {$changed} changed, {$skipped} left as they were.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
