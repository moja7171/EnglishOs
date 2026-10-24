<?php

namespace App\Services;

use App\Models\NotebookEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes a LearnerFeedback review into the learner's two notebooks — no
 * tap needed. Mistakes ("fix" sentences) are keyed by their category
 * (LearnerFeedback::MISTAKE_CATEGORIES, else the fixed sentence), so the
 * same mistake made again bumps a counter instead of adding a line; tips
 * are the "better" phrase swaps and the suggested speaking techniques.
 * One sentence of the learner's is one line: feedback asked again on the
 * same text words its fix a little differently or files it under another
 * category, and that refreshes the line instead of adding a twin — and a
 * sentence already in the mistakes is not a tip as well.
 * A new line is first due in Daily Review tomorrow, not right away — the
 * learner has just read it.
 */
class FeedbackNotebook
{
    /** @var array<string, string> source => label shown in the notebook */
    public const SOURCES = [
        'writing' => 'Writing',
        'story_sequence' => 'Picture Story',
        'picture_description' => 'Picture Description',
        'ai_conversation_1' => 'Talk It Out',
        'partner_speaking' => 'Speaking',
        'friends_chat' => 'Chat',
        'pi' => 'Pi',
    ];

    /**
     * @param  array{sentences: list<array{original: string, status: string, corrected: ?string, why: ?string, pattern: ?string}>, techniques: list<array{key: string, tip: string, example: string}>}  $feedback  A LearnerFeedback::normalize() result.
     * @return array{mistakes: int, tips: int} How many lines were added or seen again.
     */
    public function record(User $learner, array $feedback, string $source): array
    {
        $mistakes = 0;
        $tips = 0;

        foreach ($feedback['sentences'] as $sentence) {
            if ($sentence['status'] === 'fix') {
                $this->keep($learner, NotebookEntry::KIND_MISTAKE, 'm:'.self::key($sentence['pattern'] ?? $sentence['corrected']), [
                    'label' => $sentence['pattern'],
                    'original' => $sentence['original'],
                    'better' => $sentence['corrected'],
                    'note' => $sentence['why'],
                ], $source);
                $mistakes++;
            } elseif ($sentence['status'] === 'better' && ! $this->sameSentence($learner, NotebookEntry::KIND_MISTAKE, $sentence['original'])) {
                $this->keep($learner, NotebookEntry::KIND_TIP, 'p:'.self::key($sentence['corrected']), [
                    'label' => null,
                    'original' => $sentence['original'],
                    'better' => $sentence['corrected'],
                    'note' => $sentence['why'],
                ], $source);
                $tips++;
            }
        }

        foreach ($feedback['techniques'] as $technique) {
            $this->keep($learner, NotebookEntry::KIND_TIP, 't:'.$technique['key'], [
                'label' => $technique['key'],
                'original' => null,
                'better' => $technique['example'],
                'note' => $technique['tip'] ?: null,
            ], $source);
            $tips++;
        }

        return ['mistakes' => $mistakes, 'tips' => $tips];
    }

    /**
     * Adds the line, or refreshes it with the newest example. The counter
     * only moves for a NEW sentence showing the same thing — asking for
     * feedback twice on the same text must not count it twice. A mistake
     * that comes back after "learned" is back on the list and due now.
     *
     * @param  array{label: ?string, original: ?string, better: string, note: ?string}  $content
     */
    private function keep(User $learner, string $kind, string $dedupeKey, array $content, string $source): void
    {
        $entry = NotebookEntry::query()
            ->where('learner_id', $learner->id)
            ->where('dedupe_key', $dedupeKey)
            ->first() ?? $this->sameSentence($learner, $kind, $content['original']);

        if (! $entry) {
            NotebookEntry::create($content + [
                'learner_id' => $learner->id,
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'source' => $source,
                'last_seen_at' => now(),
                'next_review_at' => now()->addDay(),
            ]);

            return;
        }

        $isNewExample = $content['original'] !== null
            ? self::sentenceKey($entry->original) !== self::sentenceKey($content['original'])
            : $entry->better !== $content['better'];

        if ($isNewExample) {
            $entry->times_seen++;
        }

        if ($isNewExample && $entry->learned_at !== null && $kind === NotebookEntry::KIND_MISTAKE) {
            $entry->learned_at = null;
            $entry->next_review_at = now();
        }

        $entry->fill($content + ['source' => $source, 'last_seen_at' => now()])->save();
    }

    /**
     * The learner's line of this kind for the same sentence of theirs, if
     * any.
     */
    private function sameSentence(User $learner, string $kind, ?string $original): ?NotebookEntry
    {
        if ($original === null) {
            return null;
        }

        return NotebookEntry::query()
            ->where('learner_id', $learner->id)
            ->where('kind', $kind)
            ->whereNotNull('original')
            ->get()
            ->first(fn (NotebookEntry $entry) => self::sentenceKey($entry->original) === self::sentenceKey($original));
    }

    /**
     * A sentence of the learner's with every comma, full stop and quote
     * dropped — a spoken answer comes back from Whisper punctuated a little
     * differently each time, and it is still the same sentence.
     */
    public static function sentenceKey(?string $text): string
    {
        $words = preg_replace("/[^\p{L}\p{N}' ]+/u", ' ', str_replace('’', "'", mb_strtolower((string) $text)));

        return trim(preg_replace('/\s+/', ' ', $words));
    }

    /**
     * Lower-case, single-spaced, no end punctuation — so "Present perfect
     * with for." and "present perfect with  for" are one line.
     */
    public static function key(?string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', mb_strtolower(rtrim(trim((string) $text), '.!?'))) ?? ''), 180, '');
    }
}
