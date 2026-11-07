<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Services\AskAnswerFilter;
use App\Services\GeminiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class AskAnswerFilterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function item(): array
    {
        $level = fn (string $en, string $extra = 'Really?') => ['بگو', 'بیشتر بگو', $en, $extra, [], [['Where you work?', 'بعد از «Where»، «do» می‌آید.']]];

        return [
            'k' => 's', 's' => 'job', 'c' => null,
            'q' => [$level('Where do you work?'), $level('Where do you work, and why?'), $level('Where do you work, and how long have you worked there?')],
            'a' => [$level('I work at a bank.'), $level('I work at a bank in town.'), $level('I work at a bank in town because I like numbers.')],
        ];
    }

    public function test_a_good_situation_passes(): void
    {
        $this->assertSame([], app(AskAnswerFilter::class)->problems($this->item()));
    }

    public function test_structure_problems_are_named(): void
    {
        $filter = app(AskAnswerFilter::class);

        $missing = $this->item();
        unset($missing['q'][2]);
        $this->assertContains('question must have exactly 3 levels', $filter->problems($missing));

        $noContext = $this->item();
        $noContext['k'] = 'f';
        $this->assertContains('a follow-up needs a context line (c)', $filter->problems($noContext));

        $noWrong = $this->item();
        $noWrong['a'][1][5] = [];
        $this->assertContains('answer L2: no known wrong version', $filter->problems($noWrong));

        $wrongIsRight = $this->item();
        $wrongIsRight['q'][0][5] = [['where do you work', 'دلیل']];
        $this->assertNotEmpty(array_filter($filter->problems($wrongIsRight), fn (string $problem) => str_contains($problem, 'is also accepted')));
    }

    public function test_a_longer_first_level_and_a_shorter_last_one_are_caught(): void
    {
        $item = $this->item();
        $item['a'][2][2] = 'Yes.';

        $this->assertContains('answer: level 3 is shorter than level 1', app(AskAnswerFilter::class)->problems($item));
    }

    public function test_sensitive_topics_names_and_rare_words_are_caught_but_taught_words_are_not(): void
    {
        $filter = app(AskAnswerFilter::class);

        $beer = $this->item();
        $beer['q'][0][2] = 'Do you like beer?';
        $this->assertNotEmpty(array_filter($filter->problems($beer), fn (string $problem) => str_contains($problem, 'sensitive')));

        $update = $this->item();
        $update['q'][0][2] = 'Did you update your CV?';
        $this->assertSame([], array_filter($filter->problems($update), fn (string $problem) => str_contains($problem, 'sensitive')));

        $name = $this->item();
        $name['q'][0][2] = 'Do you work with Reza?';
        $this->assertNotEmpty(array_filter($filter->problems($name), fn (string $problem) => str_contains($problem, 'name')));

        $rare = $this->item();
        $rare['a'][0][2] = 'I work as a pharmacologist.';
        $this->assertNotEmpty(array_filter($filter->problems($rare), fn (string $problem) => str_contains($problem, 'beyond the level')));
        $this->assertSame([], array_filter($filter->problems($rare, ['pharmacologist']), fn (string $problem) => str_contains($problem, 'beyond the level')));
    }

    public function test_a_question_already_in_the_bank_is_caught(): void
    {
        $this->assertContains('the level 1 question is already in the bank', app(AskAnswerFilter::class)->problems($this->item(), [], ['where do you work']));
    }

    public function test_the_generator_keeps_only_situations_that_pass_and_writes_them_for_review(): void
    {
        Mission::create(['code' => 'M12', 'title' => 'Education', 'module' => 'Education', 'outcome' => 'x', 'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => []]]]);
        $bad = $this->item();
        $bad['s'] = 'beer';
        $bad['q'][0][2] = 'Do you like beer?';

        $this->mock(GeminiClient::class, fn (MockInterface $mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode(['items' => [$this->item(), $bad]])));

        $path = storage_path('app/ask-answer-review/m12_d1.json');
        File::delete($path);

        $this->artisan('ask-answer:generate', ['mission' => 'M12', 'day' => 1, '--grammar' => 'present simple'])->assertSuccessful();

        $written = json_decode(File::get($path), true);
        File::delete($path);

        $this->assertSame(12, $written['mission']);
        $this->assertCount(1, $written['items']);
        $this->assertSame('job', $written['items'][0]['s']);
    }
}
