<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FeedbackScreenComponentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function feedback(): array
    {
        return [
            'summary' => 'خوب بود',
            'sentences' => [
                ['original' => 'I go to gym every day.', 'status' => 'fix', 'corrected' => 'I go to the gym every day.', 'why' => 'the لازم است', 'pattern' => null],
            ],
            'techniques' => [
                ['key' => 'linking', 'tip' => 'جمله‌ها رو به هم وصل کن', 'example' => 'I was tired, so I stayed home.'],
            ],
            'positives' => [],
            'negatives' => [],
            'better_version' => null,
        ];
    }

    public function test_next_time_and_what_you_said_fold_under_more_after_the_fixes(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-feedback-screen when="screen === 'feedback'" :feedback="$feedback">
                <x-slot:said>My recording</x-slot:said>
                <x-slot:actions><button>Continue</button></x-slot:actions>
            </x-feedback-screen>
            BLADE, ['feedback' => $this->feedback()]);

        $this->assertStringContainsString('Feedback', $html);
        $this->assertStringContainsString('<button>Continue</button>', $html);

        $more = strpos($html, 'بیشتر');
        $this->assertNotFalse($more);
        $this->assertLessThan($more, strpos($html, 'I go to the gym every day.'));
        $this->assertGreaterThan($more, strpos($html, 'Link your ideas'));
        $this->assertGreaterThan($more, strpos($html, 'My recording'));
    }

    public function test_the_card_elsewhere_still_shows_next_time_openly(): void
    {
        $html = Blade::render('<x-learner-feedback :feedback="$feedback" />', ['feedback' => $this->feedback()]);

        $this->assertStringContainsString('Link your ideas', $html);
        $this->assertStringNotContainsString('بیشتر', $html);
    }

    public function test_it_shows_the_thinking_line_while_waiting(): void
    {
        $html = Blade::render('<x-feedback-screen when="true" waiting />');

        $this->assertStringContainsString('Checking your English…', $html);
        $this->assertStringNotContainsString('بیشتر', $html);
    }
}
