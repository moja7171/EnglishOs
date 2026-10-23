<?php

namespace Tests\Feature;

use App\Models\PhraseForm;
use Database\Seeders\PhraseFormSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhraseFormSeederTest extends TestCase
{
    use RefreshDatabase;

    private function phraseFile(string ...$lines): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phrases');
        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }

    public function test_it_loads_each_phrase_with_its_forms_and_itself(): void
    {
        $path = $this->phraseFile(
            '{"p": "give up", "f": ["gives up", "gave up", "given up"]}',
            '{"p": "by the way"}',
            'not json at all',
        );

        (new PhraseFormSeeder)->run($path);

        $this->assertDatabaseCount('phrase_forms', 5);
        $this->assertSame('give up', PhraseForm::baseOf('gave up'));
        $this->assertSame('by the way', PhraseForm::baseOf('by the way'));
        $this->assertNull(PhraseForm::baseOf('went to the shop'));
    }

    public function test_a_changed_file_adds_new_forms_without_duplicating_rows(): void
    {
        $seeder = new PhraseFormSeeder;
        $seeder->run($this->phraseFile('{"p": "give up", "f": ["gave up"]}'));

        $seeder->run($this->phraseFile('{"p": "give up", "f": ["gave up", "giving up"]}', '{"p": "lie down", "f": ["lay down"]}', '{"p": "lay down", "f": ["laid down"]}'));

        $this->assertDatabaseCount('phrase_forms', 7);
        $this->assertSame('lay down', PhraseForm::baseOf('lay down'));
    }

    public function test_the_shipped_data_file_loads(): void
    {
        (new PhraseFormSeeder)->run();

        $this->assertSame('give up', PhraseForm::baseOf('gave up'));
        $this->assertSame('look forward to', PhraseForm::baseOf('looking forward to'));
        $this->assertNull(PhraseForm::baseOf('going to'));
        $this->assertGreaterThan(3000, PhraseForm::distinct()->count('phrase'));
    }
}
