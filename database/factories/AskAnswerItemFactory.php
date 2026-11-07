<?php

namespace Database\Factories;

use App\Models\AskAnswerItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AskAnswerItem>
 */
class AskAnswerItemFactory extends Factory
{
    /**
     * A simple "where do you work" situation of M11 Day 1, with three
     * question and three answer levels.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = 'm11-d1-'.fake()->unique()->slug(2);

        return [
            'slug' => $slug,
            'mission_number' => 11,
            'day' => 1,
            'kind' => AskAnswerItem::KIND_SIMPLE,
            'situation' => 'job',
            'context_fa' => null,
            'questions' => [
                1 => $this->level('بپرس کجا کار می‌کند', 'و بپرس راضی است', 'Where do you work?', 'Do you like it?', ['Where do you work at?'], [['text' => 'Where you work?', 'why' => 'بعد از Where، do می‌آید.']]),
                2 => $this->level('بپرس کجا کار می‌کند و چطور می‌رود', 'بپرس دور است', 'Where do you work, and how do you get there?', 'Is it far?', [], [['text' => 'Where do you work and how you get there?', 'why' => 'برای بخش دوم هم do لازم است.']]),
                3 => $this->level('بپرس کجا کار می‌کند و چند سال است', 'بپرس راضی است', 'Where do you work, and how long have you worked there?', 'Do you like it?', [], [['text' => 'How long you work there?', 'why' => 'بعد از How long: have you + قسمت سوم فعل.']]),
            ],
            'answers' => [
                1 => $this->level('بگو در یک شرکت کار می‌کنی', 'بگو کارت خوب است', 'I work for a small company.', 'It is a nice job.', ['I work at a small company.', 'I work in a small company.'], [['text' => 'I am work in a company.', 'why' => 'بعد از I am فعل ساده نمی‌آید.']]),
                2 => $this->level('بگو در شرکتی کوچک و بخش فروش', 'بگو دوستش داری', 'I work for a small company. I am in sales.', 'I like it.', [], [['text' => 'I works for a small company.', 'why' => 'با I فعل بدون s می‌آید.']]),
                3 => $this->level('بگو کار می‌کنی و دلیل دوست‌داشتنش', 'بگو روزها بلند است', 'I work for a small company, and I like it because the team is great.', 'The days are long.', [], [['text' => 'I like it because the team great.', 'why' => 'بعد از because جمله‌ی کامل با فعل is می‌آید.']]),
            ],
        ];
    }

    /**
     * The follow-up kind: a context line and a question about it.
     */
    public function followup(): static
    {
        return $this->state(fn () => [
            'slug' => 'm11-d1-followup-'.fake()->unique()->slug(2),
            'kind' => AskAnswerItem::KIND_FOLLOWUP,
            'context_fa' => 'دوستت گفت: «در یک شرکت کوچک کار می‌کنم.»',
        ]);
    }

    /**
     * @param  list<string>  $alt
     * @param  list<array{text: string, why: string}>  $wrong
     * @return array<string, mixed>
     */
    private function level(string $fa, string $faExtra, string $en, string $enExtra, array $alt, array $wrong): array
    {
        return ['fa' => $fa, 'fa_extra' => $faExtra, 'en' => $en, 'en_extra' => $enExtra, 'alt' => $alt, 'wrong' => $wrong];
    }
}
