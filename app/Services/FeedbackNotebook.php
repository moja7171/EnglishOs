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
 * learner has just read it. A notebook keeps at most NotebookEntry::MAX_ACTIVE
 * lines: past that the least important ones are put away, and a "correction"
 * that rewrites the whole sentence (not a fix of it) is not kept at all.
 */
class FeedbackNotebook
{
    /** @var array<string, string> source => label shown in the notebook */
    public const SOURCES = [
        'vocabulary_builder' => 'New Words',
        'grammar_in_context' => 'Grammar',
        'reading_comprehension' => 'Reading',
        'writing' => 'Writing',
        'story_sequence' => 'Picture Story',
        'picture_description' => 'Picture Description',
        'ai_conversation_1' => 'Talk It Out',
        'ai_conversation_2' => 'Final Talk',
        'partner_speaking' => 'Speaking',
        'friends_chat' => 'Chat',
        'pi' => 'Pi',
        'games' => 'Games',
    ];

    /**
     * @param  array{sentences: list<array{original: string, status: string, corrected: ?string, why: ?string, pattern: ?string}>, techniques: list<array{key: string, tip: string, example: string}>}  $feedback  A LearnerFeedback::normalize() result.
     * @param  int|null  $missionId  The mission the lines were made in; the one the learner is on when not given.
     * @return array{mistakes: int, tips: int} How many lines were added or seen again.
     */
    public function record(User $learner, array $feedback, string $source, ?int $missionId = null): array
    {
        $missionId ??= app(WordMissions::class)->forNewWord($learner, null);
        $mistakes = 0;
        $tips = 0;

        foreach ($feedback['sentences'] as $sentence) {
            if (in_array($sentence['status'], ['fix', 'better'], true) && self::isNoise($sentence['original'], (string) $sentence['corrected'])) {
                continue;
            }

            if ($sentence['status'] === 'fix') {
                $this->keep($learner, NotebookEntry::KIND_MISTAKE, 'm:'.self::key($sentence['pattern'] ?? $sentence['corrected']), [
                    'label' => $sentence['pattern'],
                    'original' => $sentence['original'],
                    'better' => $sentence['corrected'],
                    'note' => $sentence['why'],
                ], $source, $missionId);
                $mistakes++;
            } elseif ($sentence['status'] === 'better' && ! $this->sameSentence($learner, NotebookEntry::KIND_MISTAKE, $sentence['original'])) {
                $this->keep($learner, NotebookEntry::KIND_TIP, 'p:'.self::key($sentence['corrected']), [
                    'label' => null,
                    'original' => $sentence['original'],
                    'better' => $sentence['corrected'],
                    'note' => $sentence['why'],
                ], $source, $missionId);
                $tips++;
            }
        }

        foreach ($feedback['techniques'] as $technique) {
            $this->keep($learner, NotebookEntry::KIND_TIP, 't:'.$technique['key'], [
                'label' => $technique['key'],
                'original' => null,
                // The short, general example (not the learner's own long sentence) is what is worth learning by heart.
                'better' => LearnerFeedback::TECHNIQUES[$technique['key']]['example'] ?? $technique['example'],
                'note' => $technique['tip'] ?: null,
            ], $source, $missionId);
            $tips++;
        }

        return ['mistakes' => $mistakes, 'tips' => $tips];
    }

    /**
     * One checked lesson sentence that was wrong (New Words, Grammar,
     * Reading — see SentenceChecker): kept as a mistake line, the
     * learner's words above the fixed ones, so it comes back in Review and
     * in the day's My Fixes even after the box was put right.
     */
    public function recordCheck(User $learner, string $original, string $corrected, ?string $why, string $source, ?int $missionId = null): void
    {
        if (self::isNoise($original, $corrected)) {
            return;
        }

        $this->keep($learner, NotebookEntry::KIND_MISTAKE, 'm:'.self::key($corrected), [
            'label' => null,
            'original' => $original,
            'better' => $corrected,
            'note' => $why,
        ], $source, $missionId ?? app(WordMissions::class)->forNewWord($learner, null));
    }

    /**
     * Adds the line, or refreshes it with the newest example. The counter
     * only moves for a NEW sentence showing the same thing — asking for
     * feedback twice on the same text must not count it twice. A mistake
     * that comes back after "learned" is back on the list and due now.
     *
     * @param  array{label: ?string, original: ?string, better: string, note: ?string}  $content
     */
    private function keep(User $learner, string $kind, string $dedupeKey, array $content, string $source, ?int $missionId = null): void
    {
        $entry = NotebookEntry::query()
            ->where('learner_id', $learner->id)
            ->where('dedupe_key', $dedupeKey)
            ->first() ?? $this->sameSentence($learner, $kind, $content['original']);

        if (! $entry) {
            $created = NotebookEntry::create($content + [
                'learner_id' => $learner->id,
                'kind' => $kind,
                'dedupe_key' => $dedupeKey,
                'source' => $source,
                'mission_id' => $missionId,
                'last_seen_at' => now(),
                'next_review_at' => now()->addDay(),
            ]);

            $this->trim($learner, $kind, $created->id);

            return;
        }

        $isNewExample = $content['original'] !== null
            ? self::sentenceKey($entry->original) !== self::sentenceKey($content['original'])
            : $entry->better !== $content['better'];

        if ($isNewExample) {
            $entry->times_seen++;
        }

        // A mistake made again in a new sentence is back at once; a speaking technique
        // suggested again comes back as the Tip card once it was put away over a week ago.
        $isTechnique = str_starts_with($dedupeKey, 't:');

        if ($entry->learned_at !== null && (($isNewExample && $kind === NotebookEntry::KIND_MISTAKE) || ($isTechnique && $entry->learned_at->lessThan(now()->subWeek())))) {
            $entry->learned_at = null;
            $entry->next_review_at = now();
        }

        $entry->fill($content + ['source' => $source, 'last_seen_at' => now()] + ($missionId !== null ? ['mission_id' => $missionId] : []))->save();
    }

    /**
     * Keeps a notebook to NotebookEntry::MAX_ACTIVE active lines: past it, the
     * least important ones are put away as learned — the ones seen least
     * often and longest ago, never a starred line and never the one just
     * added. They stay in the notebook's "Learned and older" list, and a
     * mistake made again brings its line back.
     */
    private function trim(User $learner, string $kind, int $keepId): void
    {
        $active = NotebookEntry::query()->where('learner_id', $learner->id)->where('kind', $kind)->whereNull('learned_at');
        $over = (clone $active)->count() - NotebookEntry::MAX_ACTIVE;

        if ($over > 0) {
            $active->whereNull('starred_at')->where('id', '!=', $keepId)->orderBy('times_seen')->orderBy('last_seen_at')->limit($over)->update(['learned_at' => now()]);
        }
    }

    /**
     * A "correction" not worth a line: the learner's sentence written back
     * the same (only punctuation changed), or rewritten into something much
     * longer — an AI's new sentence, not a fix of theirs.
     */
    public static function isNoise(?string $original, string $corrected): bool
    {
        if ($original === null) {
            return false;
        }

        if (self::sentenceKey($original) === self::sentenceKey($corrected)) {
            return true;
        }

        $before = str_word_count(self::sentenceKey($original));
        $after = str_word_count(self::sentenceKey($corrected));

        return $after > $before * 2 && $after - $before >= 5;
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
