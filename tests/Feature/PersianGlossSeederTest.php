<?php

namespace Tests\Feature;

use App\Models\PersianGloss;
use Database\Seeders\PersianGlossSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PersianGlossSeederTest extends TestCase
{
    use RefreshDatabase;

    private function glossFile(string ...$lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gloss');
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    public function test_it_loads_each_word_and_part_of_speech(): void
    {
        $path = $this->glossFile(
            '{"w": "sleep", "pos": "noun", "fa": ["خواب"]}',
            '{"w": "sleep", "pos": "verb", "fa": ["خوابیدن", "خفتن"]}',
            'not json at all',
            '{"w": "missing-fields"}',
        );

        (new PersianGlossSeeder)->run($path);

        $this->assertDatabaseCount('persian_glosses', 2);
        $this->assertSame(['خوابیدن', 'خفتن'], PersianGloss::where(['word' => 'sleep', 'pos' => 'verb'])->value('words'));
    }

    public function test_running_it_again_on_the_same_file_changes_nothing(): void
    {
        $path = $this->glossFile('{"w": "sleep", "pos": "noun", "fa": ["خواب"]}');
        $seeder = new PersianGlossSeeder;

        $seeder->run($path);
        PersianGloss::where('word', 'sleep')->update(['words' => json_encode(['edited by hand'])]);
        $seeder->run($path);

        $this->assertSame(['edited by hand'], PersianGloss::where('word', 'sleep')->value('words'));
    }

    public function test_a_changed_file_updates_the_words_without_duplicating_rows(): void
    {
        $seeder = new PersianGlossSeeder;
        $seeder->run($this->glossFile('{"w": "sleep", "pos": "noun", "fa": ["خواب"]}'));

        $seeder->run($this->glossFile('{"w": "sleep", "pos": "noun", "fa": ["خواب", "رؤیا"]}', '{"w": "wake", "pos": "verb", "fa": ["بیدار شدن"]}'));

        $this->assertDatabaseCount('persian_glosses', 2);
        $this->assertSame(['خواب', 'رؤیا'], PersianGloss::where('word', 'sleep')->value('words'));
    }

    public function test_it_does_nothing_when_the_data_file_is_missing(): void
    {
        (new PersianGlossSeeder)->run('/nonexistent/persian-glosses.jsonl');

        $this->assertDatabaseCount('persian_glosses', 0);
        $this->assertNull(Cache::get('persian-glosses-loaded'));
    }

    public function test_the_shipped_data_file_loads(): void
    {
        (new PersianGlossSeeder)->run();

        $this->assertSame(['خواب', 'ژفک'], PersianGloss::where(['word' => 'sleep', 'pos' => 'noun'])->value('words'));
        $this->assertGreaterThan(10000, PersianGloss::count());
    }
}
