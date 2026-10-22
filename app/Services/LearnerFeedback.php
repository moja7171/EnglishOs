<?php

namespace App\Services;

use App\Models\User;
use Closure;

/**
 * The one feedback shape every free-production task shares — Writing,
 * Picture Story, Picture Description, Talk It Out, the solo Partner
 * Speaking Session, a friends chat and a pasted Pi chat: a one-line
 * summary, the learner's sentences one by one (ok / better / fix), a few
 * good and weak points, at most three speaking techniques from
 * TECHNIQUES, and the learner's own text one level up to listen to and
 * repeat. Persian for everything that isn't quoted English. Given once,
 * complete — the AI never asks the learner a question back.
 *
 * Every review is also written into the learner's notebooks (see
 * FeedbackNotebook): the mistakes and the "say it like this" tips.
 */
class LearnerFeedback
{
    /** Shown expanded; any further mistakes only go to the notebook. */
    public const MAX_FIXES_SHOWN = 3;

    public const MAX_POINTS = 3;

    public const MAX_TECHNIQUES = 3;

    public const MAX_SENTENCES = 12;

    /**
     * The fixed list the AI picks from — never a technique of its own. Each
     * has the situation that calls for it (for the prompt) and a stock
     * example (shown when the AI's own example is missing).
     *
     * @var array<string, array{name: string, when: string, example: string}>
     */
    public const TECHNIQUES = [
        'linking' => [
            'name' => 'Link your ideas',
            'when' => 'the sentences are short and separate',
            'example' => 'I was tired, so I stayed home.',
        ],
        'buying_time' => [
            'name' => 'Buy time',
            'when' => 'the answer stops half-way or has long pauses (mostly spoken answers)',
            'example' => 'Well, let me think…',
        ],
        'reason_example' => [
            'name' => 'Give a reason and an example',
            'when' => 'an opinion is given with no reason or example',
            'example' => 'I like it because… For example…',
        ],
        'extend' => [
            'name' => 'Say a bit more',
            'when' => 'the answer is only yes/no or one short sentence',
            'example' => 'Yes, I do. Actually, I…',
        ],
        'ask_back' => [
            'name' => 'Ask back',
            'when' => 'in a chat, the learner never keeps the conversation going',
            'example' => 'What about you?',
        ],
        'chunks' => [
            'name' => 'Use English chunks, not Persian word order',
            'when' => 'a sentence is translated word by word from Persian',
            'example' => "I'm 25. (not: I have 25 years)",
        ],
        'soften' => [
            'name' => 'Soften your opinion',
            'when' => 'an opinion sounds too blunt',
            'example' => "I'd say… / In my opinion…",
        ],
        'paraphrase' => [
            'name' => 'Talk around a missing word',
            'when' => 'a Persian word is used or the learner says they don\'t know the word',
            'example' => "It's a kind of… / the thing you use to…",
        ],
        'react' => [
            'name' => 'React to the other person',
            'when' => 'in a chat, the learner ignores what the other person just said',
            'example' => "Really? That's great!",
        ],
        'sequence' => [
            'name' => 'Tell it in order',
            'when' => 'a past event or story is told with no clear order',
            'example' => 'First… then… in the end…',
        ],
    ];

    public function __construct(private readonly AiFeedbackCard $card) {}

    /**
     * @param  string  $text  What the AI reads, exactly as given.
     * @param  string  $situation  One or two English sentences: what the text is and which lines are the
     *                             learner's own (the only ones ever judged).
     * @param  bool  $spoken  True for a speech-to-text transcript: punctuation and capitals are not judged,
     *                        pronunciation is never judged, very short answers are worth a mention.
     * @param  string  $extraGuidance  Task-specific judging notes (the tense to use, the sequencing words, ...).
     * @param  string  $source  Where it came from, for the notebook (see FeedbackNotebook::SOURCES).
     * @return array{summary: string, severity: string, sentences: list<array{original: string, status: string, corrected: ?string, why: ?string, pattern: ?string}>, positives: list<string>, negatives: list<string>, techniques: list<array{key: string, tip: string, example: string}>, better_version: ?string, saved?: array{mistakes: int, tips: int}}
     */
    public function review(User $learner, string $text, string $situation, string $source, bool $spoken = false, string $extraGuidance = '', ?Closure $onCallSucceeded = null): array
    {
        $data = $this->card->generate(
            [['role' => 'user', 'text' => $text]],
            systemPrompt: $this->systemPrompt($learner->levelDescription(), $situation, $spoken, $extraGuidance),
            requiredKeys: ['summary', 'sentences', 'positives', 'negatives'],
            onCallSucceeded: $onCallSucceeded,
        );

        $feedback = self::normalize($data);
        $feedback['saved'] = app(FeedbackNotebook::class)->record($learner, $feedback, $source);

        return $feedback;
    }

    /**
     * Turns an AI reply — or feedback saved before this shape existed
     * ({strength, expression, correction}) — into the shape the
     * <x-learner-feedback> card reads, dropping anything malformed and
     * capping every list.
     *
     * @param  array<string, mixed>  $data
     * @return array{summary: string, severity: string, sentences: list<array{original: string, status: string, corrected: ?string, why: ?string, pattern: ?string}>, positives: list<string>, negatives: list<string>, techniques: list<array{key: string, tip: string, example: string}>, better_version: ?string, saved?: array{mistakes: int, tips: int}}
     */
    public static function normalize(array $data): array
    {
        if (! array_key_exists('sentences', $data) && array_key_exists('strength', $data)) {
            return self::fromLegacy($data);
        }

        $sentences = collect(is_array($data['sentences'] ?? null) ? $data['sentences'] : [])
            ->filter(fn ($s) => is_array($s) && self::text($s['original'] ?? null) !== '')
            ->map(function (array $s): array {
                $status = in_array($s['status'] ?? null, ['ok', 'better', 'fix'], true) ? $s['status'] : 'ok';
                $corrected = self::text($s['corrected'] ?? null);

                // A "fix" or "better" with nothing to show instead is just ok.
                if ($status !== 'ok' && ($corrected === '' || $corrected === self::text($s['original']))) {
                    $status = 'ok';
                }

                return [
                    'original' => self::text($s['original']),
                    'status' => $status,
                    'corrected' => $status === 'ok' ? null : $corrected,
                    'why' => $status === 'ok' ? null : (self::text($s['why'] ?? null) ?: null),
                    'pattern' => $status === 'fix' ? (self::text($s['pattern'] ?? null) ?: null) : null,
                ];
            })
            ->take(self::MAX_SENTENCES)
            ->values()
            ->all();

        $techniques = collect(is_array($data['techniques'] ?? null) ? $data['techniques'] : [])
            ->filter(fn ($t) => is_array($t) && isset(self::TECHNIQUES[$t['key'] ?? '']))
            ->unique('key')
            ->map(fn (array $t) => [
                'key' => $t['key'],
                'tip' => self::text($t['tip'] ?? null),
                'example' => self::text($t['example'] ?? null) ?: self::TECHNIQUES[$t['key']]['example'],
            ])
            ->take(self::MAX_TECHNIQUES)
            ->values()
            ->all();

        $hasFix = collect($sentences)->contains('status', 'fix');
        $severity = in_array($data['severity'] ?? null, ['none', 'minor', 'major'], true)
            ? $data['severity']
            : ($hasFix ? 'minor' : 'none');

        $feedback = [
            'summary' => self::text($data['summary'] ?? null),
            'severity' => $severity === 'none' && $hasFix ? 'minor' : $severity,
            'sentences' => $sentences,
            'positives' => self::points($data['positives'] ?? []),
            'negatives' => self::points($data['negatives'] ?? []),
            'techniques' => $techniques,
            'better_version' => self::text($data['better_version'] ?? null) ?: null,
        ];

        if (is_array($data['saved'] ?? null)) {
            $feedback['saved'] = ['mistakes' => (int) ($data['saved']['mistakes'] ?? 0), 'tips' => (int) ($data['saved']['tips'] ?? 0)];
        }

        return $feedback;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{summary: string, severity: string, sentences: list<array{original: string, status: string, corrected: ?string, why: ?string, pattern: ?string}>, positives: list<string>, negatives: list<string>, techniques: list<array{key: string, tip: string, example: string}>, better_version: ?string}
     */
    private static function fromLegacy(array $data): array
    {
        $correction = $data['correction'] ?? null;
        $sentences = [];
        $negatives = [];

        if (is_array($correction) && self::text($correction['original'] ?? null) !== '' && self::text($correction['corrected'] ?? null) !== '') {
            $sentences[] = [
                'original' => self::text($correction['original']),
                'status' => 'fix',
                'corrected' => self::text($correction['corrected']),
                'why' => self::text($correction['why'] ?? null) ?: null,
                'pattern' => null,
            ];
            $negatives = self::points([$correction['suggestion'] ?? null]);
        } elseif (is_string($correction)) {
            $negatives = self::points([$correction]);
        }

        return [
            'summary' => '',
            'severity' => in_array($data['severity'] ?? null, ['none', 'minor', 'major'], true) ? $data['severity'] : 'minor',
            'sentences' => $sentences,
            'positives' => self::points([$data['strength'] ?? null, $data['expression'] ?? null]),
            'negatives' => $negatives,
            'techniques' => [],
            'better_version' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private static function points(mixed $points): array
    {
        return collect(is_array($points) ? $points : [$points])
            ->map(fn ($point) => self::text($point))
            ->filter()
            ->take(self::MAX_POINTS)
            ->values()
            ->all();
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function systemPrompt(string $learnerDescription, string $situation, bool $spoken, string $extraGuidance): string
    {
        $techniques = collect(self::TECHNIQUES)
            ->map(fn (array $t, string $key) => "\"{$key}\" ({$t['name']}: use when {$t['when']}; e.g. \"{$t['example']}\")")
            ->implode('; ');

        return 'You are a warm, practical English speaking coach for '.$learnerDescription.'. You are reviewing '
            .$situation.' Judge ONLY the learner\'s own English lines — any other line is context. Ignore lines '
            .'that are not in English. '
            .($spoken
                ? 'This was SPOKEN and written down by speech-to-text: never judge punctuation, capital letters or '
                    .'pronunciation. If the answers are very short or stop half-way, say so in "negatives". '
                : '')
            .($extraGuidance !== '' ? $extraGuidance.' ' : '')
            .'Go through the learner\'s sentences in order (at most '.self::MAX_SENTENCES.'; in a longer text keep '
            .'the ones that teach the most). Mark each one "ok" (correct and natural), "better" (correct, but a '
            .'native speaker would say it more naturally) or "fix" (a real grammar or vocabulary error). Never '
            .'invent a mistake. Pick speaking techniques ONLY from this list, by key — at most '.self::MAX_TECHNIQUES
            .', usually one or two, only where this text shows the learner needs it; a technique they already '
            .'used well goes under "positives" instead: '.$techniques.'. '
            .'Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
            .'{"summary": "one short sentence in Persian: the overall impression and the main thing to work on", '
            .'"severity": "none, minor or major — major only for errors that block understanding", '
            .'"sentences": [{"original": "the learner\'s sentence exactly, in English", '
            .'"status": "ok, better or fix", '
            .'"corrected": "for better/fix: the natural or fixed English version; null when ok", '
            .'"why": "for better/fix: one short line in Persian; null when ok", '
            .'"pattern": "for fix only: 2-5 English words naming the rule, e.g. present perfect with for; else null"}], '
            .'"positives": ["one short line in Persian", "... at most '.self::MAX_POINTS.'"], '
            .'"negatives": ["one short line in Persian", "... at most '.self::MAX_POINTS.', empty when nothing"], '
            .'"techniques": [{"key": "a key from the list", "tip": "one short line in Persian: why it helps here", '
            .'"example": "English, built from the learner\'s own sentence"}], '
            .'"better_version": "the learner\'s own text rewritten one level higher in English — same ideas, '
            .'natural, at most 60 words"}. '
            .'Language: every field except the English sentences, corrections, examples and better_version is '
            .'simple, plain Persian. Inside Persian text, keep every English word or phrase in Latin letters '
            .'inside «», e.g. «since» — never spell English in Persian letters and never translate the learner\'s '
            .'English. Keep every line short; never ask the learner a question.';
    }
}
