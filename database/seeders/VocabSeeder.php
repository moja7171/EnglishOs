<?php

namespace Database\Seeders;

use App\Models\VocabText;
use App\Models\VocabUnit;
use Illuminate\Database\Seeder;

/**
 * The Vocab in Use course. The 90 units (database/seeders/data/vocab_units.json:
 * number, title, the book's section, page) are always seeded. The texts and
 * bold words of each unit come from document/vocab/units/unit_005.json, ...
 * — the book's own text, so those files stay out of git like the book's PDF
 * (see .gitignore) — and only the units with a file get texts. Safe to run
 * again: rows are found by unit number / letter / position and updated in
 * place, so ids that learners' saved cards point at stay the same.
 *
 * A unit file is {number, sections: [{letter, title, kind, text, sentences:
 * [{text, start, end, audio_start, audio_end, fa}], items: [{phrase, start?, end?,
 * sentence_index?, sentence?, ipa, gloss, phrase_fa, audio_start, audio_end}], rows, notes,
 * audio_file}]}.
 */
class VocabSeeder extends Seeder
{
    public function run(): void
    {
        $units = json_decode((string) file_get_contents(database_path('seeders/data/vocab_units.json')), true);

        foreach ($units as $unit) {
            VocabUnit::updateOrCreate(['number' => $unit['number']], $unit);
        }

        foreach (glob(base_path('document/vocab/units/unit_*.json')) ?: [] as $file) {
            $this->seedContent(json_decode((string) file_get_contents($file), true));
        }
    }

    /**
     * @param  array{number: int, sections: list<array<string, mixed>>}  $data
     */
    private function seedContent(array $data): void
    {
        $unit = VocabUnit::where('number', $data['number'])->first();

        if ($unit === null) {
            return;
        }

        foreach ($data['sections'] as $section) {
            $text = VocabText::updateOrCreate(
                ['vocab_unit_id' => $unit->id, 'letter' => $section['letter']],
                [
                    'title' => $section['title'],
                    'kind' => $section['kind'],
                    'body' => $section['text'],
                    'sentences' => $section['sentences'],
                    'rows' => $section['rows'] ?: null,
                    'notes' => $section['notes'] ?? null,
                    'audio_file' => $section['audio_file'] ?? null,
                ],
            );

            foreach ($section['items'] as $position => $item) {
                $text->items()->updateOrCreate(['position' => $position + 1], [
                    'phrase' => $item['phrase'],
                    'start' => $item['start'] ?? null,
                    'end' => $item['end'] ?? null,
                    'sentence_index' => $item['sentence_index'] ?? null,
                    'sentence' => $item['sentence'] ?? null,
                    'ipa' => $item['ipa'] ?? null,
                    'gloss' => $item['gloss'] ?? null,
                    'phrase_fa' => $item['phrase_fa'] ?? null,
                    'audio_start' => $item['audio_start'] ?? null,
                    'audio_end' => $item['audio_end'] ?? null,
                ]);
            }

            $text->items()->where('position', '>', count($section['items']))->delete();
        }
    }
}
