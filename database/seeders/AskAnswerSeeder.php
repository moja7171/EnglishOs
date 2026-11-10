<?php

namespace Database\Seeders;

use App\Models\AskAnswerItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The Ask & Answer situations in database/seeders/data/ask_answer: one JSON
 * file per mission day (m11_d1.json) or one per mission whose items each name
 * their day (m01.json, "d": 1-4). Safe to run again: an
 * item is found by its slug (mission, day, subject) and updated in place,
 * so edits to the files reach the table without duplicating rows, and a
 * situation removed from its mission's files is removed from the table.
 *
 * A level in a file is [fa, fa_extra, en, en_extra, [other accepted],
 * [[wrong sentence, Persian reason], …]]; an item is {k: s|f, s: subject,
 * c: context line or null, q: three question levels, a: three answer levels}.
 */
class AskAnswerSeeder extends Seeder
{
    public function run(): void
    {
        $kept = [];

        foreach (glob(database_path('seeders/data/ask_answer/*.json')) ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            foreach ($data['items'] ?? [] as $item) {
                $day = (int) ($item['d'] ?? $data['day'] ?? 0);
                $slug = self::slugFor((int) $data['mission'], $day, $item['s']);

                $kept[(int) $data['mission']][] = $slug;

                AskAnswerItem::updateOrCreate(['slug' => $slug], [
                    'mission_number' => (int) $data['mission'],
                    'day' => $day,
                    'kind' => $item['k'] === 'f' ? AskAnswerItem::KIND_FOLLOWUP : AskAnswerItem::KIND_SIMPLE,
                    'situation' => $item['s'],
                    'context_fa' => $item['c'] ?? null,
                    'questions' => self::levels($item['q']),
                    'answers' => self::levels($item['a']),
                ]);
            }
        }

        // A situation taken out of a file leaves the bank too.
        foreach ($kept as $mission => $slugs) {
            AskAnswerItem::where('mission_number', $mission)->whereNotIn('slug', $slugs)->delete();
        }
    }

    public static function slugFor(int $mission, int $day, string $subject): string
    {
        return 'm'.str_pad((string) $mission, 2, '0', STR_PAD_LEFT).'-d'.$day.'-'.Str::slug($subject);
    }

    /**
     * @param  list<list<mixed>>  $levels
     * @return array<int, array{fa: string, fa_extra: ?string, en: string, en_extra: ?string, alt: list<string>, wrong: list<array{text: string, why: string}>}>
     */
    public static function levels(array $levels): array
    {
        $result = [];

        foreach (array_values($levels) as $index => $level) {
            [$fa, $faExtra, $en, $enExtra, $alt, $wrong] = $level;

            $result[$index + 1] = [
                'fa' => $fa,
                'fa_extra' => $faExtra,
                'en' => $en,
                'en_extra' => $enExtra,
                'alt' => array_values($alt),
                'wrong' => array_map(fn (array $row) => ['text' => $row[0], 'why' => $row[1]], $wrong),
            ];
        }

        return $result;
    }
}
