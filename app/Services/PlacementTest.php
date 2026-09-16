<?php

namespace App\Services;

/**
 * The placement test's own content and scoring — the 8-10 minute check a
 * learner takes right after registering, so the app knows where they
 * actually start instead of trusting the self-assessment on the
 * registration form (which defaults to A2+ and nobody really reads).
 *
 * Deliberately does NOT change the curriculum. The 24-mission path stays
 * the same for everyone, in the same order: later missions reuse earlier
 * missions' vocabulary and grammar (see
 * [[feedback_thread_vocabulary_through_mission]]), and a B1 result is
 * almost always B1 *recognition* — the thing this app exists to convert
 * into B1 production. Nor does it set the pace: anyone, at any level, can
 * work through two program days in one sitting and finish in ~60 days.
 * What the result does change is how the app talks to the learner
 * (User::levelDescription() feeds every AI prompt), what /program tells
 * them to expect, and the baseline they get measured against when they
 * retake it at the end.
 *
 * Content lives here rather than in a seeder/table because it is fixed
 * app content, not per-mission material an author edits — same reasoning
 * as Mission::roadmapCatalog(). Only results are persisted (see the
 * placement_tests table).
 */
class PlacementTest
{
    public const LEVELS = ['A1', 'A2', 'B1'];

    /**
     * Recognition-band vocabulary: 4 words per band, each with one real
     * meaning and two plausible distractors. Cheap, fast, and a strong
     * predictor of overall level — but recognition only, which is why
     * part 3 exists.
     *
     * @return list<array{band: string, word: string, options: list<string>, correct: int}>
     */
    public function vocabulary(): array
    {
        return [
            ['band' => 'A1', 'word' => 'breakfast', 'options' => ['the first meal of the day', 'a small bedroom', 'a short holiday'], 'correct' => 0],
            ['band' => 'A1', 'word' => 'tired', 'options' => ['needing to rest or sleep', 'feeling very cold', 'walking quickly'], 'correct' => 0],
            ['band' => 'A1', 'word' => 'cheap', 'options' => ['not expensive', 'very old', 'quite heavy'], 'correct' => 0],
            ['band' => 'A1', 'word' => 'busy', 'options' => ['having a lot to do', 'feeling bored', 'far away'], 'correct' => 0],

            ['band' => 'A2', 'word' => 'borrow', 'options' => ['to take something and give it back later', 'to buy something new', 'to lose something'], 'correct' => 0],
            ['band' => 'A2', 'word' => 'crowded', 'options' => ['full of people', 'very quiet', 'badly lit'], 'correct' => 0],
            ['band' => 'A2', 'word' => 'on time', 'options' => ['not late', 'every week', 'by accident'], 'correct' => 0],
            ['band' => 'A2', 'word' => 'give up', 'options' => ['to stop doing something', 'to share something', 'to start again'], 'correct' => 0],

            ['band' => 'B1', 'word' => 'reliable', 'options' => ['can be trusted to do what is needed', 'easy to carry', 'happening by chance'], 'correct' => 0],
            ['band' => 'B1', 'word' => 'deadline', 'options' => ['the latest time something must be done', 'a long break from work', 'a type of meeting'], 'correct' => 0],
            ['band' => 'B1', 'word' => 'put up with', 'options' => ['to accept something unpleasant', 'to build something', 'to explain clearly'], 'correct' => 0],
            ['band' => 'B1', 'word' => 'come across', 'options' => ['to find something by chance', 'to arrive early', 'to agree with someone'], 'correct' => 0],
        ];
    }

    /**
     * Grammar in context, in the same order the missions teach it —
     * present simple, present continuous, modals, past simple, present
     * perfect — so a band the learner fails here maps onto real missions.
     *
     * @return list<array{band: string, prompt: string, options: list<string>, correct: int}>
     */
    public function grammar(): array
    {
        return [
            ['band' => 'A1', 'prompt' => 'She ___ coffee every morning.', 'options' => ['drinks', 'drink', 'is drink'], 'correct' => 0],
            ['band' => 'A1', 'prompt' => '___ you have a car?', 'options' => ['Do', 'Are', 'Is'], 'correct' => 0],
            ['band' => 'A1', 'prompt' => 'There ___ two chairs in the room.', 'options' => ['are', 'is', 'be'], 'correct' => 0],

            ['band' => 'A2', 'prompt' => 'Be quiet — the baby ___ .', 'options' => ['is sleeping', 'sleeps', 'sleep'], 'correct' => 0],
            ['band' => 'A2', 'prompt' => 'I ___ work on Saturdays. I stay at home.', 'options' => ["don't have to", "am not having to", 'not have to'], 'correct' => 0],
            ['band' => 'A2', 'prompt' => 'We ___ to the cinema last night.', 'options' => ['went', 'have gone', 'go'], 'correct' => 0],

            ['band' => 'B1', 'prompt' => "I ___ here since 2019, and I'm still enjoying it.", 'options' => ['have worked', 'worked', 'am working'], 'correct' => 0],
            ['band' => 'B1', 'prompt' => 'If I had more time, I ___ a new language.', 'options' => ['would learn', 'will learn', 'am learning'], 'correct' => 0],
            ['band' => 'B1', 'prompt' => 'The report ___ by my colleague yesterday.', 'options' => ['was written', 'wrote', 'has wrote'], 'correct' => 0],
        ];
    }

    /**
     * One real production task: the part that separates knowing English
     * from using it, and the only part a placement test for THIS app can
     * be honest without.
     *
     * @return array{prompt: string, bullets: list<string>, seconds: int}
     */
    public function speaking(): array
    {
        return [
            'prompt' => 'Tell me about a normal day in your life.',
            'bullets' => [
                'What you usually do in the morning',
                'What you do for work or study',
                'What you like doing in your free time',
            ],
            'seconds' => 45,
        ];
    }

    /**
     * Turns the three parts into one level. The objective parts set a
     * ceiling by band mastery (a band counts as passed at 75%), the
     * spoken part is what the learner can actually produce, and the
     * result leans toward production: an A2 speaker who recognises B1
     * words is an A2 learner here, and the reverse is worth a nudge up.
     *
     * @param  array<string, int>  $vocabularyAnswers  item index => chosen option
     * @param  array<string, int>  $grammarAnswers  item index => chosen option
     * @param  string|null  $spokenLevel  the AI's CEFR read of the recording, if it ran
     * @return array{level: string, recognitionLevel: string, spokenLevel: ?string, aboveRange: bool, bands: array<string, array{correct: int, total: int}>, provisional: bool}
     */
    public function score(array $vocabularyAnswers, array $grammarAnswers, ?string $spokenLevel): array
    {
        $bands = [];

        foreach (['vocabulary' => $this->vocabulary(), 'grammar' => $this->grammar()] as $part => $items) {
            $answers = $part === 'vocabulary' ? $vocabularyAnswers : $grammarAnswers;

            foreach ($items as $index => $item) {
                $band = $item['band'];
                $bands[$band] ??= ['correct' => 0, 'total' => 0];
                $bands[$band]['total']++;

                if (($answers[$index] ?? $answers[(string) $index] ?? null) === $item['correct']) {
                    $bands[$band]['correct']++;
                }
            }
        }

        // Highest band whose items are ≥75% right, and every band below it
        // passed too — a lucky B1 guess on a failed A2 doesn't promote.
        $recognition = 'A1';
        foreach (self::LEVELS as $band) {
            $stats = $bands[$band] ?? ['correct' => 0, 'total' => 0];
            $passed = $stats['total'] > 0 && $stats['correct'] / $stats['total'] >= 0.75;

            if (! $passed) {
                break;
            }

            $recognition = $band;
        }

        // A1 recognition that isn't even close to passing A1 still reads
        // as A1 — this app's floor — but it's worth knowing.
        $level = $recognition;

        if ($spokenLevel !== null && in_array($spokenLevel, ['below A1', ...self::LEVELS, 'above B1'], true)) {
            $level = $this->combine($recognition, $spokenLevel);
        }

        return [
            'level' => $level,
            'recognitionLevel' => $recognition,
            'spokenLevel' => $spokenLevel,
            'aboveRange' => $spokenLevel === 'above B1' && $recognition === 'B1',
            'bands' => $bands,
            'provisional' => $spokenLevel === null,
        ];
    }

    /**
     * Production leads, recognition follows at one step behind: speaking
     * decides, unless recognition is a whole level lower (in which case
     * the gap is more likely a generous grader than a real level).
     */
    private function combine(string $recognition, string $spoken): string
    {
        $rank = ['below A1' => 0, 'A1' => 1, 'A2' => 2, 'B1' => 3, 'above B1' => 4];
        $spokenRank = $rank[$spoken];
        $recognitionRank = $rank[$recognition];

        $final = min(max($spokenRank, $recognitionRank - 1), $recognitionRank + 1);
        $final = max(1, min(3, $final));

        return array_search($final, $rank, true);
    }
}
