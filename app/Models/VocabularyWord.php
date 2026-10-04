<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'word', 'meaning', 'pos', 'example', 'user_sentence', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class VocabularyWord extends Model
{
    use HasFactory;
    use HasSpacedRepetition;

    /**
     * Explicit PHP-side defaults, not left to the migration's DB column
     * defaults — Eloquent doesn't re-fetch a Postgres row's own defaults
     * after insert(), so a freshly created instance (e.g.
     * firstOrCreate() in Vocabulary Builder's save()) would otherwise
     * read these as null in the very same request instead of their real
     * value. Setting them here means every INSERT writes them
     * explicitly, so there's nothing to re-fetch.
     */
    protected $attributes = [
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /**
     * @return BelongsTo<MissionRun, $this>
     */
    public function sourceMissionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class, 'source_mission_run_id');
    }

    /**
     * Best-effort backfill of the review card's pos / example /
     * user_sentence from the mission run this word was first saved in —
     * only ever fills blanks, and leaves a column null when the source
     * content has nothing for it (or has since changed). Used once by the
     * migration that added these columns.
     */
    public function fillMissingDetailsFromSource(): void
    {
        $run = $this->sourceMissionRun;
        $mission = $run?->mission;

        if (! $mission) {
            return;
        }

        $needle = mb_strtolower($this->word);
        $matches = fn (?string $phrase): bool => mb_strtolower((string) $phrase) === $needle;
        $found = ['pos' => null, 'example' => null, 'user_sentence' => null];

        foreach (['vocabulary_builder_1', 'vocabulary_builder_2', 'vocabulary_builder_3'] as $stepKey) {
            foreach ($mission->stepContent($stepKey)['words'] ?? [] as $entry) {
                if ($matches($entry['phrase'] ?? null)) {
                    $found['pos'] ??= $entry['pos'] ?? null;
                    $found['example'] ??= $entry['example'] ?? null;
                }
            }

            $saved = json_decode($run->latestEvidence($stepKey)?->content_ref ?? '{}', true);

            foreach ($saved['examples'] ?? [] as $item) {
                if ($matches($item['word'] ?? null)) {
                    $found['user_sentence'] ??= $item['example'] ?? null;
                }
            }
        }

        foreach ($mission->stepContent('reading_comprehension')['highlighted_phrases'] ?? [] as $entry) {
            if ($matches($entry['phrase'] ?? null)) {
                $found['pos'] ??= $entry['pos'] ?? null;
                $found['example'] ??= $entry['example'] ?? null;
            }
        }

        foreach ($mission->stepContent('listening')['target_phrases'] ?? [] as $entry) {
            if ($matches($entry['phrase'] ?? null)) {
                $found['example'] ??= static::exampleFromGap($entry);
            }
        }

        $blanksFilled = collect($found)
            ->filter(fn (?string $value, string $column) => filled($value) && blank($this->{$column}))
            ->all();

        if ($blanksFilled !== []) {
            $this->update($blanksFilled);
        }
    }

    /**
     * Listening's gap-fill items carry the sentence around the gap — put
     * the phrase back in it to get a ready-made example sentence.
     *
     * @param  array{phrase?: string, gap_before?: string, gap_after?: string}  $targetPhrase
     */
    public static function exampleFromGap(array $targetPhrase): ?string
    {
        $before = $targetPhrase['gap_before'] ?? '';
        $after = $targetPhrase['gap_after'] ?? '';

        if (blank($targetPhrase['phrase'] ?? null) || (blank($before) && blank($after))) {
            return null;
        }

        return trim($before.$targetPhrase['phrase'].$after);
    }

    // isDue() and review() — the SM-2 spaced-
    // repetition schedule — come from HasSpacedRepetition, shared with
    // any future review-driven feature (Speaking Recall, Error Log
    // review, ...) instead of being re-derived per model.
}
