<?php

namespace App\Console\Commands;

use App\Models\Mission;
use App\Services\AskAnswerChecker;
use App\Services\AskAnswerFilter;
use App\Services\PairPractice;
use Illuminate\Console\Command;

/**
 * Runs the automatic checks (AskAnswerFilter) over the Ask & Answer data
 * files, so every edit is checked before the bank is seeded.
 */
class AskAnswerCheck extends Command
{
    protected $signature = 'ask-answer:check {file? : One data file (default: every file in database/seeders/data/ask_answer)}';

    protected $description = 'Check the Ask & Answer situation files for structure, level, topics and duplicates';

    public function handle(AskAnswerFilter $filter, PairPractice $pairPractice): int
    {
        $files = $this->argument('file') ? [$this->argument('file')] : (glob(database_path('seeders/data/ask_answer/*.json')) ?: []);
        $taken = [];
        $failed = 0;
        $checked = 0;

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data) || ! isset($data['mission'], $data['items'])) {
                $this->error(basename($file).': not a situation file');
                $failed++;

                continue;
            }

            $allowed = $this->wordsTaughtUpTo((int) $data['mission'], $pairPractice);

            foreach ($data['items'] as $item) {
                $problems = $filter->problems($item, $allowed, $taken);
                $taken[] = AskAnswerChecker::normalize($item['q'][0][2] ?? '');
                $checked++;

                foreach ($problems as $problem) {
                    $this->line(basename($file).' · '.($item['s'] ?? '?').' · '.$problem);
                }

                $failed += $problems === [] ? 0 : 1;
            }
        }

        $this->info("$checked situations checked, $failed with problems.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every word (lower case) of the vocabulary of the missions up to this one.
     *
     * @return list<string>
     */
    private function wordsTaughtUpTo(int $mission, PairPractice $pairPractice): array
    {
        return Mission::all()
            ->filter(fn (Mission $row) => $row->number() <= $mission)
            ->flatMap(fn (Mission $row) => collect(range(1, 4))->flatMap(fn (int $day) => collect($pairPractice->dayContent($row, $day)['vocabulary'])->pluck('answer')))
            ->flatMap(fn (string $phrase) => preg_split('/[^a-z]+/', mb_strtolower($phrase), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->unique()
            ->values()
            ->all();
    }
}
