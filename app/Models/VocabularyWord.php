<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

#[Fillable(['learner_id', 'source_mission_run_id', 'word', 'meaning', 'meaning_fa', 'meaning_fa_previous', 'meaning_fa_checked_at', 'pos', 'example', 'user_sentence', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
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
            'meaning_fa_checked_at' => 'datetime',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * The three-way status the Notebook's Words tab filters by: never
     * reviewed, still being learned, or strong (a 21-day gap or more).
     */
    public function studyStatus(): string
    {
        return match (true) {
            ! $this->last_reviewed_at => 'new',
            $this->strengthLevel() >= 4 => 'known',
            default => 'learning',
        };
    }

    /**
     * Whether the word can be spelled from hearing it: one to three plain
     * words, letters only (an apostrophe or hyphen inside is fine). A
     * pattern like "get (someone) into (something)" can't be dictated.
     */
    public function canBeDictated(): bool
    {
        $part = "[A-Za-z]+(?:['’-][A-Za-z]+)*";

        return preg_match("/^{$part}(?: {$part}){0,2}$/", trim($this->word)) === 1;
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
     * $sentence as safe HTML with this word picked out in bold — the
     * exact phrase if it's there, else the phrase with its first word
     * allowed to carry an ending ("get up" in "he gets up", "oversleep"
     * in "oversleeping"). An irregular form ("overslept") isn't found, and
     * then the sentence is returned plain rather than guessing.
     */
    public function highlightIn(string $sentence): HtmlString
    {
        $parts = $this->splitAround($sentence);

        if ($parts === null) {
            return new HtmlString(e($sentence));
        }

        return new HtmlString(
            e($parts['before'])
            .'<strong class="font-bold text-accent-ink dark:text-accent-ink-dark">'.e($parts['hit']).'</strong>'
            .e($parts['after'])
        );
    }

    /**
     * The sentence cut around this word as it appears in it (see
     * highlightIn() for what counts as found), or null when it isn't there.
     *
     * @return array{before: string, hit: string, after: string}|null
     */
    public function splitAround(string $sentence): ?array
    {
        $words = preg_split('/\s+/u', trim($this->word), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return null;
        }

        $quoted = array_map(fn (string $part) => preg_quote($part, '/'), $words);
        $patterns = [implode('\s+', $quoted)];

        // A lone short word ("go", "be") would match half the dictionary
        // once an ending is allowed, so it is only inflected when the rest
        // of a phrase ("get up" -> "gets up") pins the match down.
        if (mb_strlen($words[0]) >= 4 || count($words) > 1) {
            $stem = preg_quote(preg_replace('/e$/iu', '', $words[0]), '/');
            $patterns[] = implode('\s+', [$stem.'\w*', ...array_slice($quoted, 1)]);
        }

        foreach ($patterns as $pattern) {
            if (preg_match('/(?<![\w])'.$pattern.'(?![\w])/iu', $sentence, $match, PREG_OFFSET_CAPTURE)) {
                [$found, $offset] = $match[0];

                return [
                    'before' => substr($sentence, 0, $offset),
                    'hit' => $found,
                    'after' => substr($sentence, $offset + strlen($found)),
                ];
            }
        }

        return null;
    }

    /**
     * @return HasMany<WordSentence, $this>
     */
    public function sentences(): HasMany
    {
        return $this->hasMany(WordSentence::class);
    }

    /**
     * Every sentence worth learning this word from, simplest first: the
     * ones the learner wrote (corrected), the AI's, then the lesson's
     * example. Each is [text, source] with source "own", "ai" or "lesson".
     *
     * @return list<array{text: string, source: string}>
     */
    public function learningSentences(): array
    {
        $stored = $this->sentences->sortByDesc('id');
        $own = $stored->filter->isOwn()->map(fn (WordSentence $sentence) => ['text' => $sentence->shown(), 'source' => 'own']);
        $ai = $stored->reject->isOwn()->map(fn (WordSentence $sentence) => ['text' => $sentence->sentence, 'source' => 'ai']);
        $lesson = collect([$this->user_sentence ? ['text' => $this->user_sentence, 'source' => 'own'] : null, $this->example ? ['text' => $this->example, 'source' => 'lesson'] : null])->filter();

        return $own->concat($lesson->where('source', 'own'))->concat($ai)->concat($lesson->where('source', 'lesson'))->unique('text')->values()->all();
    }

    /**
     * The one sentence shown under the word: one the learner wrote if
     * there is one, else the shortest simple one (at most
     * SIMPLE_SENTENCE_WORDS words) so it can be learned, else the shortest.
     */
    public function displaySentence(): ?string
    {
        $sentences = collect($this->learningSentences());

        if ($sentences->isEmpty()) {
            return null;
        }

        $own = $sentences->firstWhere('source', 'own');

        if ($own !== null && static::isSimple($own['text'])) {
            return $own['text'];
        }

        $byLength = $sentences->sortBy(fn (array $sentence) => str_word_count($sentence['text']));

        return ($byLength->first(fn (array $sentence) => static::isSimple($sentence['text'])) ?? $byLength->first())['text'];
    }

    /** A sentence short enough to learn by heart. */
    public const SIMPLE_SENTENCE_WORDS = 8;

    public static function isSimple(string $sentence): bool
    {
        return str_word_count($sentence) <= self::SIMPLE_SENTENCE_WORDS;
    }

    /**
     * The four steps of knowing a word and which the learner has done: it
     * was seen, it was reviewed, it is strong (a gap of a week or more) and
     * it was used in a sentence of their own.
     *
     * @return array<string, bool>
     */
    public function ladder(): array
    {
        return [
            'Seen' => true,
            'Reviewed' => $this->last_reviewed_at !== null,
            'Strong' => $this->strengthLevel() >= 3,
            'Used' => $this->sentences->contains->isOwn() || filled($this->user_sentence),
        ];
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
