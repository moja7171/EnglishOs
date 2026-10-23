<?php

namespace Tests\Feature;

use App\Models\PersianGloss;
use App\Models\PhraseForm;
use App\Services\PersianGlossary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersianGlossaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_gives_the_persian_words_of_each_part_of_speech(): void
    {
        PersianGloss::factory()->create(['word' => 'sleep', 'pos' => 'noun', 'words' => ['خواب']]);
        PersianGloss::factory()->create(['word' => 'sleep', 'pos' => 'verb', 'words' => ['خوابیدن', 'استراحت کردن']]);

        $senses = app(PersianGlossary::class)->senses('sleep');

        $this->assertSame([
            ['pos' => 'noun', 'words' => 'خواب'],
            ['pos' => 'verb', 'words' => 'خوابیدن، استراحت کردن'],
        ], $senses);
    }

    public function test_it_follows_the_order_of_the_english_meanings(): void
    {
        PersianGloss::factory()->create(['word' => 'sleep', 'pos' => 'noun', 'words' => ['خواب']]);
        PersianGloss::factory()->create(['word' => 'sleep', 'pos' => 'verb', 'words' => ['خوابیدن']]);

        $senses = app(PersianGlossary::class)->senses('sleep', [
            ['pos' => 'verb', 'definitions' => [['definition' => 'to rest', 'example' => null]]],
            ['pos' => 'noun', 'definitions' => [['definition' => 'a rest', 'example' => null]]],
        ]);

        $this->assertSame(['verb', 'noun'], array_column($senses, 'pos'));
    }

    public function test_it_keeps_only_four_words_and_four_parts_of_speech(): void
    {
        foreach (['noun', 'verb', 'adjective', 'adverb', 'pronoun'] as $pos) {
            PersianGloss::factory()->create(['word' => 'well', 'pos' => $pos, 'words' => ['یک', 'دو', 'سه', 'چهار', 'پنج']]);
        }

        $senses = app(PersianGlossary::class)->senses('well');

        $this->assertCount(4, $senses);
        $this->assertSame('یک، دو، سه، چهار', $senses[0]['words']);
    }

    public function test_it_finds_the_base_form_named_by_the_english_definition(): void
    {
        PersianGloss::factory()->create(['word' => 'oversleep', 'pos' => 'verb', 'words' => ['دیر بیدار شدن']]);

        $senses = app(PersianGlossary::class)->senses('overslept', [
            ['pos' => 'verb', 'definitions' => [['definition' => 'simple past and past participle of oversleep', 'example' => null]]],
        ]);

        $this->assertSame([['pos' => 'verb', 'words' => 'دیر بیدار شدن']], $senses);
    }

    public function test_it_strips_a_plain_ending_when_there_is_no_english_meaning_to_follow(): void
    {
        PersianGloss::factory()->create(['word' => 'colleague', 'pos' => 'noun', 'words' => ['همکار']]);
        PersianGloss::factory()->create(['word' => 'run', 'pos' => 'verb', 'words' => ['دویدن']]);

        $this->assertSame('همکار', app(PersianGlossary::class)->senses('colleagues')[0]['words']);
        $this->assertSame('دویدن', app(PersianGlossary::class)->senses('running')[0]['words']);
    }

    public function test_it_prefers_the_word_itself_over_a_shorter_form_of_it(): void
    {
        PersianGloss::factory()->create(['word' => 'glass', 'pos' => 'noun', 'words' => ['شیشه']]);
        PersianGloss::factory()->create(['word' => 'glasses', 'pos' => 'noun', 'words' => ['عینک']]);

        $this->assertSame('عینک', app(PersianGlossary::class)->senses('glasses')[0]['words']);
    }

    public function test_it_returns_nothing_for_a_word_it_does_not_have(): void
    {
        $this->assertSame([], app(PersianGlossary::class)->senses('qzxvbnm'));
    }

    public function test_a_form_of_a_phrasal_verb_gets_the_persian_of_the_whole_phrase_not_of_its_verb(): void
    {
        PersianGloss::factory()->create(['word' => 'give', 'pos' => 'verb', 'words' => ['دادن']]);
        PersianGloss::factory()->create(['word' => 'give up', 'pos' => 'verb', 'words' => ['دست کشیدن']]);

        $senses = app(PersianGlossary::class)->senses('gave up', [
            ['pos' => 'verb', 'definitions' => [['definition' => 'simple past of give up', 'example' => null]]],
        ]);

        $this->assertSame([['pos' => 'verb', 'words' => 'دست کشیدن']], $senses);
    }

    public function test_a_phrase_is_found_under_its_base_form(): void
    {
        PhraseForm::create(['form' => 'looking forward to', 'phrase' => 'look forward to']);
        PersianGloss::factory()->create(['word' => 'look forward to', 'pos' => 'verb', 'words' => ['مشتاق بودن']]);

        $this->assertSame([['pos' => 'verb', 'words' => 'مشتاق بودن']], app(PersianGlossary::class)->senses('looking forward to'));
    }
}
