<?php

namespace Tests\Feature;

use App\Models\DictionaryEntry;
use App\Models\PersianGloss;
use App\Models\PhraseForm;
use App\Services\PhraseFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhraseFinderTest extends TestCase
{
    use RefreshDatabase;

    private function knownPhrase(string $phrase, string ...$forms): void
    {
        foreach ([$phrase, ...$forms] as $form) {
            PhraseForm::create(['form' => $form, 'phrase' => $phrase]);
        }
    }

    public function test_it_finds_the_phrasal_verb_an_inflected_verb_is_part_of(): void
    {
        $this->knownPhrase('give up', 'gave up', 'giving up');
        PersianGloss::factory()->create(['word' => 'give up', 'pos' => 'verb', 'words' => ['دست کشیدن', 'ترک کردن']]);

        $found = app(PhraseFinder::class)->find('gave', ['She'], ['up', 'smoking', 'last']);

        $this->assertSame(['phrase' => 'give up', 'from' => 0, 'to' => 1, 'translation_fa' => 'دست کشیدن، ترک کردن'], $found);
    }

    public function test_the_particle_finds_the_phrase_too_and_the_longest_phrase_wins(): void
    {
        $this->knownPhrase('look forward');
        $this->knownPhrase('look forward to', 'looking forward to');
        DictionaryEntry::factory()->create(['word' => 'look forward to', 'translation_fa' => 'مشتاق بودن']);

        $found = app(PhraseFinder::class)->find('forward', ['I', 'am', 'looking'], ['to', 'it']);

        $this->assertSame(['phrase' => 'look forward to', 'from' => -1, 'to' => 1, 'translation_fa' => 'مشتاق بودن'], $found);
    }

    public function test_it_finds_nothing_for_words_that_are_not_a_known_phrase_or_not_next_to_each_other(): void
    {
        $this->knownPhrase('give up', 'gave up');
        $finder = app(PhraseFinder::class);

        $this->assertNull($finder->find('gave', ['She'], ['it', 'up']));
        $this->assertNull($finder->find('gave', [], ['2', 'up']));
        $this->assertNull($finder->find('sleep', [], []));
    }
}
