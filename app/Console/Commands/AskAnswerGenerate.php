<?php

namespace App\Console\Commands;

use App\Models\AskAnswerItem;
use App\Models\Mission;
use App\Services\AskAnswerChecker;
use App\Services\AskAnswerFilter;
use App\Services\GeminiClient;
use App\Services\PairPractice;
use Illuminate\Console\Command;
use Throwable;

/**
 * Drafts Ask & Answer situations for one mission day with Gemini and keeps
 * only the ones that pass the automatic checks, in storage/app/ask-answer-review.
 * A person reads every kept situation before it is moved to
 * database/seeders/data/ask_answer — the generator saves time, it never
 * decides what a learner is taught.
 */
class AskAnswerGenerate extends Command
{
    protected $signature = 'ask-answer:generate
        {mission : The mission code, e.g. M12}
        {day : The mission day, 1-4}
        {--plain=5 : Plain situations to draft}
        {--followups=5 : Follow-up situations to draft}
        {--grammar= : What grammar the learner has by this mission, in plain words (e.g. "present simple, past simple, present perfect with for/since")}';

    protected $description = 'Draft Ask & Answer situations for a mission day with Gemini, filtered, for a person to review';

    public function handle(GeminiClient $gemini, AskAnswerFilter $filter, PairPractice $pairPractice): int
    {
        $mission = Mission::where('code', strtoupper((string) $this->argument('mission')))->first();
        $day = (int) $this->argument('day');

        if (! $mission || $day < 1 || $day > 4) {
            $this->error('Unknown mission or day.');

            return self::FAILURE;
        }

        $words = collect($pairPractice->dayContent($mission, $day)['vocabulary'])->pluck('answer')->implode(', ');
        $allowed = $this->wordsTaughtUpTo($mission->number(), $pairPractice);
        $existing = AskAnswerItem::all()->map(fn (AskAnswerItem $item) => AskAnswerChecker::normalize($item->level(AskAnswerItem::SIDE_QUESTION, 1)['en']))->all();

        $raw = $gemini->chat(
            [['role' => 'user', 'text' => $this->request($mission, $day, $words, (int) $this->option('plain'), (int) $this->option('followups'))]],
            systemPrompt: $this->rules((string) $this->option('grammar')),
            maxOutputTokens: 16000,
            profile: GeminiClient::PROFILE_JUDGE,
        );

        try {
            $items = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->error('The model did not answer with valid JSON.');
            $this->line($raw);

            return self::FAILURE;
        }

        $kept = [];
        $taken = $existing;

        foreach ((array) ($items['items'] ?? $items) as $item) {
            $problems = $filter->problems($item, $allowed, $taken);

            if ($problems === []) {
                $kept[] = $item;
                $taken[] = AskAnswerChecker::normalize($item['q'][0][2] ?? '');

                continue;
            }

            $this->warn(($item['s'] ?? '?').': '.implode(' | ', $problems));
        }

        $path = storage_path('app/ask-answer-review/m'.str_pad((string) $mission->number(), 2, '0', STR_PAD_LEFT).'_d'.$day.'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(['mission' => $mission->number(), 'day' => $day, 'items' => $kept], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(count($kept).' situations kept in '.$path.' — read every one before moving it to database/seeders/data/ask_answer.');

        return self::SUCCESS;
    }

    private function request(Mission $mission, int $day, string $words, int $plain, int $followups): string
    {
        return "Mission {$mission->code} \"{$mission->title}\", day {$day}.\n"
            ."Words taught on this day (use some, never force them): {$words}\n"
            ."Write {$plain} plain situations (k = \"s\") and {$followups} follow-up situations (k = \"f\") as one JSON object: {\"items\": [...]}.";
    }

    private function rules(string $grammar): string
    {
        return 'You write speaking practice for Persian-speaking learners of English, in the format of an Ask & Answer game: in each situation one friend asks a '
            .'question in English and the other answers, each from a PERSIAN line that says what to ask or say (the intent, never a word-for-word translation, '
            .'so the learner builds the English word order). Every question and every answer exists at three levels; a harder level is a longer or more '
            .'structured sentence (a second part, a time or place, a reason with because/but), NEVER harder words. Use only everyday words a learner at this '
            .'mission knows'.($grammar !== '' ? ' and only this grammar: '.$grammar : '').'. Questions must be things real people ask in real life '
            .'(work, plans, shopping, travel, health, small talk): nothing silly, nothing about alcohol, dating, politics, religion, salary or age, and no '
            .'names of people or places. A follow-up situation has "c": a Persian line "دوستت گفت: «…»" that the friend just said, and its question is about that. '
            .'Format of an item: {"k": "s" or "f", "s": "short-subject-slug", "c": null or the Persian line, "q": [L1, L2, L3], "a": [L1, L2, L3]} where each level is '
            .'[persian_required, persian_optional_extra, english_target, english_optional_extra, [other accepted English sentences], '
            .'[[a typical wrong version a Persian speaker says, a one-line Persian reason that names the rule, English words in Latin letters inside «»]]]. '
            .'Give 1-2 wrong versions per level, each really typical (missing do/does/did, wrong auxiliary, missing a/an/to, wrong preposition, wrong word '
            .'order). A wrong version must differ from every accepted sentence. Level 1 questions have at most 8 words. Reply with ONLY valid JSON.';
    }

    /**
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
