<?php

namespace Database\Seeders;

use App\Models\PersianGloss;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Loads resources/data/persian-glosses.jsonl (the offline English -> Persian
 * dictionary, built from Wiktionary by scripts/persian-glosses/build.py)
 * into persian_glosses. Safe to run on every deploy: it does nothing when
 * the file is the one already loaded, and otherwise upserts by word and
 * part of speech.
 */
class PersianGlossSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(?string $path = null): void
    {
        $path ??= resource_path('data/persian-glosses.jsonl');

        if (! is_file($path)) {
            return;
        }

        $hash = md5_file($path);

        if (Cache::get('persian-glosses-loaded') === $hash && PersianGloss::exists()) {
            return;
        }

        $rows = [];
        $now = now();

        foreach (new \SplFileObject($path) as $line) {
            $entry = json_decode(trim((string) $line), true);

            if (! is_array($entry) || ! isset($entry['w'], $entry['pos'], $entry['fa'])) {
                continue;
            }

            $rows[] = [
                'word' => $entry['w'],
                'pos' => $entry['pos'],
                'words' => json_encode($entry['fa'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::CHUNK) {
                PersianGloss::upsert($rows, ['word', 'pos'], ['words', 'updated_at']);
                $rows = [];
            }
        }

        if ($rows !== []) {
            PersianGloss::upsert($rows, ['word', 'pos'], ['words', 'updated_at']);
        }

        Cache::forever('persian-glosses-loaded', $hash);
    }
}
