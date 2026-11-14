<?php

namespace App\Services;

use App\Models\Mission;
use Illuminate\Support\Collection;

/**
 * The Notebook's grammar practice: one round of seven cards about one
 * mission's grammar point, built from what the lesson already has — no new
 * content and no AI: three "Which one is right?" pairs, two "Fix the
 * sentence" cards, one "Build the sentence" and one "Say it" (the learner
 * hears a right sentence and says it aloud). Never a sentence written from
 * scratch.
 */
class GrammarPractice
{
    /** "Which one is right?" questions a lesson section gives (see sectionPairs()). */
    public const PAIRS_PER_SECTION = 3;

    public const PICK_CARDS = 3;

    public const FIX_CARDS = 2;

    /** The fewest right-or-wrong cards a grammar point needs to be worth a round. */
    public const MINIMUM_CHOICE_CARDS = 3;

    /**
     * A lesson section's "Which one is right?" pairs, up to three: its
     * spot-the-mistake block and don't/do pairs from the mission's seed
     * first, then config/grammar_practice.php's extra pairs for that
     * section. Empty when the section has none (e.g. "Make it personal").
     *
     * @param  array<string, mixed>  $section
     * @return list<array{wrong: string, right: string, why: ?string}>
     */
    public function sectionPairs(array $section, string $missionCode, int $sectionIndex): array
    {
        $pairs = [];

        foreach ($section['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'mistake_fix' && filled($block['wrong'] ?? null)) {
                $pairs[] = ['wrong' => $block['wrong'], 'right' => $block['right'], 'why' => $block['explanation'] ?? null];
            }
        }

        foreach ($section['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'do_dont') {
                foreach ($block['items'] ?? [] as $item) {
                    if (filled($item['wrong'] ?? null)) {
                        $pairs[] = ['wrong' => $item['wrong'], 'right' => $item['right'], 'why' => $item['note'] ?? null];
                    }
                }
            }
        }

        foreach (config("grammar_practice.{$missionCode}.{$sectionIndex}", []) as $pair) {
            $pairs[] = $pair + ['why' => null];
        }

        return array_slice($pairs, 0, self::PAIRS_PER_SECTION);
    }

    /**
     * Every "Which one is right?" pair of the mission's lesson.
     *
     * @return Collection<int, array{wrong: string, right: string, why: ?string}>
     */
    public function pairs(Mission $mission): Collection
    {
        $sections = $mission->stepContent('grammar_in_context')['lesson']['sections'] ?? [];

        return collect($sections)
            ->flatMap(fn (array $section, int $index) => $this->sectionPairs($section, $mission->code, $index))
            ->unique('wrong')
            ->values();
    }

    /**
     * Whether the mission has enough to make a round of.
     */
    public function isAvailable(Mission $mission): bool
    {
        $fixCards = count($mission->stepContent('grammar_in_context')['quick_check'] ?? []);

        return $this->pairs($mission)->count() + $fixCards >= self::MINIMUM_CHOICE_CARDS;
    }

    /**
     * One round, in the order it is played. A mission short of fix cards
     * gets more pairs instead (and the other way round), so the round is
     * as full as the lesson allows; a mission without a word-order list
     * simply has no "Build the sentence".
     *
     * @return list<array{type: string, label: string, prompt?: ?string, options?: list<string>, correct?: int, why?: ?string, original?: string, better?: string, words?: list<string>, answer?: string, sentence?: string}>
     */
    public function round(Mission $mission): array
    {
        $content = $mission->stepContent('grammar_in_context');
        $pairs = $this->pairs($mission)->shuffle()->values();
        $fixes = collect($content['quick_check'] ?? [])->filter(fn ($card) => filled($card['wrong'] ?? null) && is_array($card['options'] ?? null))->shuffle()->values();
        $orders = collect($content['word_order'] ?? [])->filter(fn ($card) => filled($card['answer'] ?? null) && is_array($card['words'] ?? null))->shuffle()->values();

        $fixCount = min(self::FIX_CARDS + max(0, self::PICK_CARDS - $pairs->count()), $fixes->count());
        $pickCount = min(self::PICK_CARDS + (self::FIX_CARDS - $fixCount), $pairs->count());

        $picks = $pairs->take($pickCount)->map(function (array $pair) {
            $rightFirst = random_int(0, 1) === 0;

            return [
                'type' => 'pick',
                'label' => 'Which one is right?',
                'prompt' => null,
                'options' => $rightFirst ? [$pair['right'], $pair['wrong']] : [$pair['wrong'], $pair['right']],
                'correct' => $rightFirst ? 0 : 1,
                'why' => $pair['why'],
                'original' => $pair['wrong'],
                'better' => $pair['right'],
            ];
        });

        $fixed = $fixes->take($fixCount)->map(fn (array $card) => [
            'type' => 'fix',
            'label' => 'Fix the sentence',
            'prompt' => $card['wrong'],
            'options' => array_values($card['options']),
            'correct' => (int) $card['correct'],
            'why' => $card['why'] ?? null,
            'original' => $card['wrong'],
            'better' => $card['options'][$card['correct']],
        ]);

        $choices = $picks->concat($fixed)->values();
        $cards = $choices->all();

        if ($orders->isNotEmpty()) {
            $cards[] = ['type' => 'order', 'label' => 'Build the sentence', 'words' => array_values($orders->first()['words']), 'answer' => $orders->first()['answer']];
        }

        if ($choices->isNotEmpty()) {
            $cards[] = ['type' => 'say', 'label' => 'Say it', 'sentence' => $choices->random()['better']];
        }

        return $cards;
    }
}
