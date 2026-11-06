<?php

namespace App\Console\Commands;

use App\Models\PersianGloss;
use App\Models\PhraseForm;
use App\Services\GeminiWordDefiner;
use App\Services\PersianGlossary;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Fills the offline Persian dictionary with the words learners meet most
 * that Wiktionary has no Persian for ("boost", "reduce", "deadline"): the
 * most used words of resources/data/word-frequency.txt, and with --phrases
 * the listed phrasal verbs and phrases, that PersianGlossary finds nothing
 * for, are asked of Gemini in batches and saved in persian_glosses with
 * source "gemini". A word Gemini declines (a name, an inflected form) is
 * remembered and not asked again; one it just skipped is tried on the next
 * run, so the command can be run again until it says nothing is left.
 */
class FillPersianGlosses extends Command
{
    protected $signature = 'dictionary:fill-persian
        {--top=5000 : How many of the most used words to look at}
        {--phrases : Also fill the listed phrasal verbs and phrases}
        {--chunk=40 : Words sent to Gemini in one call}
        {--limit=0 : Stop after this many words (0 = all)}
        {--pause=5 : Seconds to wait between calls, for the Gemini quota}
        {--dry-run : Show the Persian without saving it}';

    protected $description = 'Fills the offline Persian dictionary with common words and phrases Wiktionary has no Persian for, using Gemini';

    public function handle(GeminiWordDefiner $gemini, PersianGlossary $glossary): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $chunk = max(1, min(60, (int) $this->option('chunk')));
        $pause = max(0, (int) $this->option('pause'));

        $missing = $this->candidates((int) $this->option('top'), (bool) $this->option('phrases'))
            ->reject(fn (string $word) => Cache::has($this->askedKey($word)) || $glossary->senses($word) !== [])
            ->values();

        if ($limit > 0) {
            $missing = $missing->take($limit);
        }

        $this->info("{$missing->count()} words without Persian.");
        $filled = 0;
        $declined = 0;
        $skipped = 0;

        foreach ($missing->chunk($chunk) as $words) {
            try {
                $answers = $gemini->persianGlosses($words->all());
            } catch (Throwable $exception) {
                $this->error("Gemini could not be asked: {$exception->getMessage()}");

                return self::FAILURE;
            }

            foreach ($words as $word) {
                if (! isset($answers[$word])) {
                    // Gemini answered for other words of the batch but not this one: a name or a form.
                    if ($answers !== []) {
                        $declined++;
                        $dryRun || Cache::forever($this->askedKey($word), true);
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $filled++;
                $this->line("  {$word}: ".collect($answers[$word])->map(fn (array $sense) => $sense['pos'].' '.implode('، ', $sense['words']))->implode(' | '));

                if ($dryRun) {
                    continue;
                }

                foreach ($answers[$word] as $sense) {
                    PersianGloss::updateOrCreate(
                        ['word' => $word, 'pos' => $sense['pos']],
                        ['words' => $sense['words'], 'source' => 'gemini'],
                    );
                }

                Cache::forever($this->askedKey($word), true);
            }

            if ($pause > 0) {
                sleep($pause);
            }
        }

        $this->info(($dryRun ? 'Dry run: ' : '')."{$filled} filled, {$declined} declined by Gemini, {$skipped} not answered (run again).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, string>
     */
    private function candidates(int $top, bool $phrases)
    {
        $words = collect(file(resource_path('data/word-frequency.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            ->take(max(1, $top));

        return $phrases ? $words->merge(PhraseForm::query()->distinct()->orderBy('phrase')->pluck('phrase')) : $words;
    }

    private function askedKey(string $word): string
    {
        return 'fill-persian-asked:'.$word;
    }
}
