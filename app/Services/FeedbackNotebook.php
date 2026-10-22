<?php

namespace App\Services;

use App\Models\NotebookEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Writes a LearnerFeedback review into the learner's two notebooks — no
 * tap needed. Mistakes ("fix" sentences) are keyed by their rule, so the
 * same mistake made again bumps a counter instead of adding a line; tips
 * are the "better" phrase swaps and the suggested speaking techniques.
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
            } elseif ($sentence['status'] === 'better') {
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
            ->first();

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

        $isNewExample = $entry->original !== $content['original'] || $entry->better !== $content['better'];

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
     * Lower-case, single-spaced, no end punctuation — so "Present perfect
     * with for." and "present perfect with  for" are one line.
     */
    public static function key(?string $text): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', mb_strtolower(rtrim(trim((string) $text), '.!?'))) ?? ''), 180, '');
    }
}
