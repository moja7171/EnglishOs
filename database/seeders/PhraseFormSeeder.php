<?php

namespace Database\Seeders;

use App\Models\PhraseForm;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Loads resources/data/phrase-forms.jsonl (the English phrasal verbs and
 * fixed phrases with the ways each one is written, built from Wiktionary by
 * scripts/persian-glosses/build.py) into phrase_forms. Safe to run on every
 * deploy: it does nothing when the file is the one already loaded, and
 * otherwise adds the forms it does not have yet.
 */
class PhraseFormSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(?string $path = null): void
    {
        $path ??= resource_path('data/phrase-forms.jsonl');

        if (! is_file($path)) {
            return;
        }

        $hash = md5_file($path);

        if (Cache::get('phrase-forms-loaded') === $hash && PhraseForm::exists()) {
            return;
        }

        $rows = [];

        foreach (new \SplFileObject($path) as $line) {
            $entry = json_decode(trim((string) $line), true);

            if (! is_array($entry) || ! is_string($entry['p'] ?? null)) {
                continue;
            }

            foreach (array_unique([$entry['p'], ...($entry['f'] ?? [])]) as $form) {
                $rows[] = ['form' => $form, 'phrase' => $entry['p']];
            }

            if (count($rows) >= self::CHUNK) {
                PhraseForm::insertOrIgnore($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            PhraseForm::insertOrIgnore($rows);
        }

        Cache::forever('phrase-forms-loaded', $hash);
    }
}
