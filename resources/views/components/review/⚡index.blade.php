<?php

use App\Livewire\Concerns\GivesQuickSpokenFeedback;
use App\Livewire\Concerns\PractisesNotebookLines;
use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\NotebookEntry;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\FixChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use GivesQuickSpokenFeedback;
    use PractisesNotebookLines;
    use WithFileUploads;

    /**
     * Fixed once per page load (see mount()) so queue()'s interleaving
     * order stays STABLE across every separate request in this session
     * (reveal, skip, gradeSelf, …) — a plain ->shuffle() would re-randomize
     * on every single one of those, meaning an action could silently end
     * up acting on a different item than the one actually on screen the
     * moment the learner tapped a button. Used as a sort seed, not true
     * randomness, so the SAME due items always land in the SAME relative
     * order for the rest of this session.
     */
    public string $shuffleSeed = '';

    public ?UploadedFile $recording = null;

    public bool $recordedThisTurn = false;

    /**
     * What the learner typed as the corrected sentence on a grammar
     * pattern (error) card — judged once, see checkAttempt().
     */
    public string $attempt = '';

    /**
     * Whether that attempt is a right fix — null until checked (or when
     * the learner just looked). Kept, so a re-render never asks the AI
     * again.
     */
    public ?bool $attemptCorrect = null;

    /**
     * One line of AI feedback on the current speaking recording — null
     * when there isn't one yet or the AI couldn't give it (see
     * recorded()). Purely a bonus: grading never waits on it.
     */
    public ?string $feedback = null;

    /**
     * True once the meaning/correction has been revealed for the current
     * item — error patterns and grammar points start hidden ("what was
     * wrong?" / "quick reminder?") so grading is an honest self-test, not
     * just reading and tapping Easy. (A word's card flips in the browser
     * and never uses this — see gradeWord().)
     */
    public bool $revealed = false;

    /**
     * The answer the learner tapped on a grammar point's "fix this
     * sentence" question (index into its options), null until they do.
     * Grading follows from it — see continueGrammar().
     */
    public ?int $pickedOption = null;

    /**
     * How many items were graded in THIS page session, and how many of
     * those the learner remembered (quality 3+) — the progress counter's
     * "3 / 8" and the end-of-session summary. Plain component state: a
     * reload starts a fresh session, and what was already graded simply
     * isn't due any more.
     */
    public int $sessionGraded = 0;

    public int $sessionRemembered = 0;

    /**
     * "type:id" keys (see itemKey()) for every item the learner has
     * tapped "Skip for now" on THIS session — see skip(). Deliberately
     * plain in-memory component state, never written to the database:
     * skipping must never touch next_review_at/repetitions, and a reload
     * of this page is a perfectly fine way to "un-skip" everything, since
     * nothing about the underlying item actually changed.
     *
     * @var list<string>
     */
    public array $skippedKeys = [];

    /**
     * "note:id" keys of notebook lines answered wrong THIS session — each
     * comes back once at the end of today's queue (see afterDrill()). Its
     * review was already graded the first time; the second go only moves
     * its practice rung.
     *
     * @var list<string>
     */
    public array $retryKeys = [];

    /**
     * A dictation card's typed spelling, and whether it was right — null
     * until Check (see checkSpelling()).
     */
    public string $spelling = '';

    public ?bool $spellingCorrect = null;

    /**
     * The speaking card's previous recording, kept when a new take
     * replaces it — "▶ Last time" next to the new one.
     */
    public ?string $previousRecordingUrl = null;

    /**
     * Which of Daily Review's two paths this is — 'words' or 'talk' (see
     * User::REVIEW_TRACKS); null runs both in one queue.
     */
    public ?string $track = null;

    /**
     * How many extra batches the learner asked for with "6 more" — kept in
     * the address, so a reload stays in the same batch.
     */
    #[Url(as: 'more')]
    public int $more = 0;

    /**
     * Ids of words whose dictation the learner couldn't listen to this
     * session — they come as a
     * plain word card instead, ungraded as a miss.
     *
     * @var list<int>
     */
    public array $plainWordIds = [];

    public function mount(?string $track = null): void
    {
        $this->track = $track;
        $this->more = $track === null ? 0 : max(0, min($this->more, 20));
        $this->shuffleSeed = Str::random(16);
    }

    /**
     * A word comes as a dictation card (hear it, type it) once it has been
     * remembered at least once — see strengthLevel(): a word still being
     * met for the first time gets the plain card.
     */
    public const DICTATION_FROM_STRENGTH = 2;

    /**
     * A fast, mixed session across one path's review systems — the words
     * (My Words), or Speaking Recall, recurring grammar-mistake patterns,
     * taught grammar points (Grammar in Context) and notebook lines —
     * interleaved into one queue instead of separate places to check. Most types use the LIGHT self-assessment interaction here
     * (Again/Good/Easy after a reveal, or after a fresh recording).
     *
     * @return list<array{type: string, id: int}>
     */
    #[Computed]
    public function queue(): array
    {
        // Today's batch, not the whole backlog — see User::dailyReviewItems().
        // A seeded sort, not ->shuffle() — see $shuffleSeed for why this
        // has to stay stable across requests rather than re-randomizing
        // on every single one.
        $all = auth()->user()->dailyReviewItems($this->track, $this->more)
            ->sortBy(fn (array $entry) => md5($this->shuffleSeed.$this->itemKey($entry['type'], $entry['id'])))
            ->concat(collect($this->retryKeys)->map(fn (string $key) => ['type' => 'note', 'id' => (int) Str::after($key, ':')]))
            ->unique(fn (array $entry) => $this->itemKey($entry['type'], $entry['id']))
            ->values();

        // Items skipped THIS session (see skip()) move to the back, in
        // their relative order — lets a learner stuck on one item (e.g.
        // microphone access denied, see voice-recorder.blade.php's own
        // error state) keep moving through the rest of today's queue
        // instead of every later item becoming unreachable behind it.
        $all = $this->withDictations($all);

        [$ready, $skipped] = $all->partition(fn ($entry) => ! in_array($this->itemKey($entry['type'], $entry['id']), $this->skippedKeys, true));

        return $ready->concat($skipped)->values()->all();
    }

    /**
     * Turns the words that are strong enough and can be spelled from
     * hearing them into dictation cards (see DICTATION_FROM_STRENGTH and
     * VocabularyWord::canBeDictated()), except the ones the learner
     * couldn't listen to.
     *
     * @param  \Illuminate\Support\Collection<int, array{type: string, id: int}>  $entries
     * @return \Illuminate\Support\Collection<int, array{type: string, id: int}>
     */
    private function withDictations($entries)
    {
        $dictatedIds = VocabularyWord::query()
            ->whereIn('id', $entries->where('type', 'word')->pluck('id'))
            ->get()
            ->filter(fn (VocabularyWord $word) => $word->strengthLevel() >= self::DICTATION_FROM_STRENGTH
                && $word->canBeDictated()
                && ! in_array($word->id, $this->plainWordIds, true))
            ->pluck('id')
            ->all();

        return $entries->map(fn (array $entry) => $entry['type'] === 'word' && in_array($entry['id'], $dictatedIds, true)
            ? ['type' => 'dictation', 'id' => $entry['id']]
            : $entry);
    }

    /**
     * True once every item left in the queue has already been skipped
     * this session — the "skipped all the way around" case skip() must
     * not spin into an infinite loop over. currentItem() treats this the
     * same as an empty queue (see its own check below), and the view
     * shows a distinct "come back to the rest later" message rather than
     * silently re-showing the first skipped item.
     */
    /**
     * Items due beyond today's batch — they roll into the next days, so
     * the learner sees the size of the backlog, not just today's number.
     */
    #[Computed]
    public function waitingCount(): int
    {
        return max(0, auth()->user()->dueReviewCount($this->track) - auth()->user()->dailyReviewCount($this->track));
    }

    /** How many days of a full batch the waiting items make. */
    #[Computed]
    public function waitingDays(): int
    {
        $batch = $this->track === 'talk' ? User::DAILY_TALK_LIMIT : User::DAILY_WORD_LIMIT;

        return max(1, (int) ceil($this->waitingCount / $batch));
    }

    /**
     * Whether "6 more" has anything to bring — on either path, while a next
     * batch would not be empty.
     */
    #[Computed]
    public function moreAvailable(): bool
    {
        if ($this->track === null) {
            return false;
        }

        $user = auth()->user();

        return $user->dailyReviewItems($this->track, $this->more + 1)->count() > $user->dailyReviewItems($this->track, $this->more)->count();
    }

    /**
     * "6 more": opens the next batch — the due items first, and on the
     * words path the ones coming up soonest after them.
     */
    public function loadMore(): void
    {
        if (! $this->moreAvailable) {
            return;
        }

        $this->more++;
        unset($this->queue, $this->currentItem, $this->hasSkippedEverything, $this->waitingCount, $this->moreAvailable);
    }

    /**
     * A dictation card the learner can't listen to right now (no
     * headphones, a noisy place): the same
     * word comes as a plain word card, and nothing is graded.
     */
    public function cantListen(): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'dictation') {
            return;
        }

        $this->plainWordIds[] = $item['model']->id;
        $this->resetItemState();
        unset($this->queue, $this->currentItem, $this->hasSkippedEverything);
    }

    #[Computed]
    public function hasSkippedEverything(): bool
    {
        $queue = $this->queue;

        return $queue !== [] && collect($queue)->every(
            fn (array $entry) => in_array($this->itemKey($entry['type'], $entry['id']), $this->skippedKeys, true)
        );
    }

    private function itemKey(string $type, int $id): string
    {
        return "{$type}:{$id}";
    }

    /**
     * @return array{type: string, model: VocabularyWord|SpeakingPrompt|ErrorPatternReview|GrammarPoint|NotebookEntry}|null
     */
    #[Computed]
    public function currentItem(): ?array
    {
        $entry = $this->queue[0] ?? null;

        if (! $entry || $this->hasSkippedEverything) {
            return null;
        }

        $model = match ($entry['type']) {
            'word', 'dictation' => VocabularyWord::find($entry['id']),
            'speaking' => SpeakingPrompt::find($entry['id']),
            'error' => ErrorPatternReview::find($entry['id']),
            'grammar' => GrammarPoint::find($entry['id']),
            'note' => NotebookEntry::find($entry['id']),
        };

        return $model ? ['type' => $entry['type'], 'model' => $model] : null;
    }

    /**
     * The current grammar point's question and rules (see
     * GrammarPoint::reviewContent()), or null when the current item isn't
     * a grammar point.
     *
     * @return array{card: array{wrong: string, options: list<string>, correct: int}|null, rules: list<array{text: string, fa: string|null}>}|null
     */
    #[Computed]
    public function grammarReview(): ?array
    {
        $item = $this->currentItem;

        return $item && $item['type'] === 'grammar' ? $item['model']->reviewContent() : null;
    }

    /**
     * Moves the current item to the back of today's queue WITHOUT calling
     * review() on it — next_review_at and repetitions are left completely
     * untouched, so it comes back later in this same session exactly as
     * due as it is now, not removed or rescheduled. See $skippedKeys and
     * hasSkippedEverything() for how "skip everything" is handled.
     */
    public function skip(): void
    {
        $item = $this->currentItem;

        if (! $item) {
            return;
        }

        $key = $this->itemKey($item['type'], $item['model']->id);

        if (! in_array($key, $this->skippedKeys, true)) {
            $this->skippedKeys[] = $key;
        }

        $this->resetItemState();
        unset($this->queue, $this->currentItem, $this->hasSkippedEverything);
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    /**
     * A grammar pattern card: shows the fix after the learner wrote their own corrected sentence,
     * judged by FixChecker (another right fix counts too) — an empty
     * attempt does nothing, since "Show the fix" is the way to look
     * without trying.
     */
    public function checkAttempt(): void
    {
        $model = $this->currentItem['model'] ?? null;

        if (trim($this->attempt) === '' || ! $model instanceof ErrorPatternReview) {
            return;
        }

        $this->attemptCorrect = app(FixChecker::class)->accepts((string) $model->last_error, $this->attempt, (string) $model->last_correction);
        $this->revealed = true;
    }

    /**
     * A grammar point's question: tapping an answer locks it in and shows
     * the rules; nothing is graded until continueGrammar(), so the learner
     * has time to read why.
     */
    public function pickOption(int $option): void
    {
        $card = $this->grammarReview['card'] ?? null;

        if (! $card || $this->pickedOption !== null || ! isset($card['options'][$option])) {
            return;
        }

        $this->pickedOption = $option;
        $this->revealed = true;
    }

    /**
     * Right answer → "Good", wrong → "Again" — the same SM-2 qualities the
     * self-graded cards use, but decided by what the learner actually
     * answered rather than by how they say they feel.
     */
    public function continueGrammar(): void
    {
        $card = $this->grammarReview['card'] ?? null;

        if (! $card || $this->pickedOption === null) {
            return;
        }

        $this->gradeCurrent($this->currentItem['model'], $this->pickedOption === $card['correct'] ? 4 : 1);
    }

    /**
     * Fired automatically once a speaking recording uploads (see
     * <x-voice-recorder>'s on-recorded) — same idea as Speaking Recall's
     * own page: the recording itself is the artifact, no extra send step.
     */
    public function recorded(): void
    {
        $item = $this->currentItem;

        if (! $this->recording || ! $item || $item['type'] !== 'speaking') {
            return;
        }

        $path = $this->recording->store('speaking-recall/'.auth()->id(), 'public');
        $this->previousRecordingUrl ??= $item['model']->last_recording_url;
        $item['model']->update(['last_recording_url' => Storage::disk('public')->url($path)]);

        $this->feedback = $this->quickSpokenFeedback($this->recording, $item['model']->prompt);

        $this->recording = null;
        $this->recordedThisTurn = true;
    }

    /**
     * A grammar-point reminder card: the one card whose answer the app
     * cannot check ("can you still say a sentence like this?"), so the
     * learner grades themselves — Forget / I know → SM-2 quality 1 / 4,
     * once the reminder has been revealed.
     */
    public function gradeSelf(int $quality): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'grammar' || ! $this->revealed) {
            return;
        }

        $this->gradeCurrent($item['model'], $quality);
    }

    /**
     * An error-pattern card, once the fix is shown: the app already knows
     * whether the learner's own fix was right (FixChecker), so that decides
     * the grade — right → Good, wrong or "just show the fix" → Again.
     */
    public function continueError(): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'error' || ! $this->revealed) {
            return;
        }

        $this->gradeCurrent($item['model'], $this->attemptCorrect ? 4 : 1);
    }

    /**
     * A speaking card, once a fresh recording is in: there is no right
     * answer to check, and the AI line is only a comment, so doing the
     * task counts as Good.
     */
    public function continueSpeaking(): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'speaking' || ! $this->recordedThisTurn) {
            return;
        }

        $this->gradeCurrent($item['model'], 4);
    }

    /**
     * A word's one round-trip: the card flips, swipes and animates out in
     * the browser (see <x-vocabulary-review-card>) and then reports the
     * grade. A learner who wasn't sure ("show me") can only ever fail the
     * word — enforced here, not just by hiding the other buttons.
     */
    public function gradeWord(int $quality, bool $remembered): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'word') {
            return;
        }

        $this->gradeCurrent($item['model'], $remembered ? $quality : 1);
    }

    /**
     * A dictation card: the typed spelling against the word. Nothing is
     * graded until continueDictation(), so the learner can read the fix.
     */
    public function checkSpelling(): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'dictation' || trim($this->spelling) === '') {
            return;
        }

        $this->spellingCorrect = $this->normalizeSpelling($this->spelling) === $this->normalizeSpelling($item['model']->word);
    }

    /** Right → the card moves up a box like any other; wrong → Again. */
    public function continueDictation(): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] !== 'dictation' || $this->spellingCorrect === null) {
            return;
        }

        $this->gradeCurrent($item['model'], $this->spellingCorrect ? 4 : 1);
    }

    /**
     * Case, the full stop, a phone keyboard's curly apostrophe and a
     * hyphen written as a space ("ex partner") don't make a spelling wrong.
     */
    private function normalizeSpelling(string $text): string
    {
        $text = str_replace(['’', '‘', '`'], "'", mb_strtolower(rtrim(trim($text), '.!?')));

        return trim(preg_replace('/[\s-]+/u', ' ', $text));
    }

    /**
     * The right spelling in pieces, each marked whether the learner's
     * typing had it — the letters they missed show green on the answer
     * sheet. A longest-common-subsequence match, case-insensitive.
     *
     * @return list<array{text: string, missed: bool}>
     */
    public function spellingPieces(): array
    {
        $word = (string) ($this->currentItem['model']->word ?? '');
        $target = mb_str_split($word);
        $typed = mb_str_split(str_replace(['’', '‘', '`'], "'", mb_strtolower(trim($this->spelling))));
        $lower = mb_str_split(mb_strtolower($word));
        $rows = count($lower);
        $cols = count($typed);
        $lengths = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $lower[$i] === $typed[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $pieces = [];

        for ($i = 0, $j = 0; $i < $rows;) {
            $missed = ! ($j < $cols && $lower[$i] === $typed[$j]);

            if ($missed && $j < $cols && $lengths[$i][$j + 1] >= $lengths[$i + 1][$j]) {
                $j++;

                continue;
            }

            $last = count($pieces) - 1;

            if ($last >= 0 && $pieces[$last]['missed'] === $missed) {
                $pieces[$last]['text'] .= $target[$i];
            } else {
                $pieces[] = ['text' => $target[$i], 'missed' => $missed];
            }

            $i++;
            $j += $missed ? 0 : 1;
        }

        return $pieces;
    }

    protected function drillEntry(): ?NotebookEntry
    {
        $model = $this->currentItem['model'] ?? null;

        return $model instanceof NotebookEntry ? $model : null;
    }

    /**
     * A notebook line's first answer today is its review (right → Good,
     * wrong → Again); a wrong one also comes back once at the end of this
     * session — only when another card comes first, never straight after
     * itself — and that second go is not graded again.
     */
    protected function afterDrill(NotebookEntry $entry, bool $correct): void
    {
        $key = $this->itemKey('note', $entry->id);

        if (in_array($key, $this->retryKeys, true)) {
            $this->retryKeys = array_values(array_diff($this->retryKeys, [$key]));
            $this->advance();

            return;
        }

        if (! $correct && count($this->queue) > 1) {
            $this->retryKeys[] = $key;
        }

        $this->gradeCurrent($entry, $correct ? 4 : 1);
    }

    private function gradeCurrent(VocabularyWord|SpeakingPrompt|ErrorPatternReview|GrammarPoint|NotebookEntry $model, int $quality): void
    {
        $model->review($quality);

        $this->sessionGraded++;

        if ($quality >= 3) {
            $this->sessionRemembered++;
        }

        $this->advance();
    }

    private function advance(): void
    {
        $this->resetItemState();
        unset($this->queue, $this->currentItem, $this->hasSkippedEverything);
    }

    /**
     * Per-item state (an in-progress sentence, an uploaded recording, a
     * revealed meaning) that belongs to whichever item is currently
     * showing — reset whenever the component moves off of it, whether
     * that's a real review (advance()) or a skip() that leaves the
     * underlying item completely unreviewed.
     */
    private function resetItemState(): void
    {
        $this->recording = null;
        $this->recordedThisTurn = false;
        $this->feedback = null;
        $this->attempt = '';
        $this->attemptCorrect = null;
        $this->revealed = false;
        $this->pickedOption = null;
        $this->spelling = '';
        $this->spellingCorrect = null;
        $this->previousRecordingUrl = null;
        $this->resetDrill();
        unset($this->grammarReview);
    }
};
?>

@php
    $item = $this->currentItem;
    $bigButton = 'flex-1 cursor-pointer rounded-2xl py-3.5 text-base font-extrabold transition-opacity hover:opacity-90 disabled:opacity-50';
    $forgetClass = $bigButton.' bg-danger-soft text-danger-ink';
    $knowClass = $bigButton.' bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark';
@endphp

{{-- A5: the review runs like a short lesson — ✕, one bar and "3/8" on
     top, one full-screen card, two big buttons at the thumb ("✕ Forget" /
     "✓ I know"), a small Skip. --}}
{{-- With a card open (the top bar is hidden then) the page is a fixed-height
     column, the screen minus the bottom nav: a long card scrolls inside itself
     while the grading buttons and the answer sheet stay in view. The end
     screens keep the top bar (about 3rem), so they are a minimum height that
     leaves room for it, and their Done button sticks above the nav instead of
     being left under it. --}}
<div @class(['mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-6', 'h-[calc(100dvh-6rem)]' => $item, 'min-h-[calc(100dvh-9rem)]' => ! $item])>
    @if (! $item)
        @if ($this->hasSkippedEverything)
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                @svg('heroicon-o-forward', 'h-8 w-8 text-ink-faint dark:text-ink-faint-dark')
                <p class="text-lg font-extrabold text-ink dark:text-ink-dark">You've skipped everything for today.</p>
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Nothing here was graded — come back to the rest whenever you're ready.</p>
            </div>
        @elseif ($sessionGraded > 0)
            <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center" @if ($sessionRemembered > 0) x-data x-init="window.eosConfetti?.burst()" @endif>
                <span class="animate-trophy-pop inline-flex h-20 w-20 items-center justify-center rounded-full bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                    @svg('heroicon-o-check-badge', 'h-10 w-10')
                </span>
                <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">Session done!</p>
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sessionGraded }} {{ Str::plural('item', $sessionGraded) }} reviewed</p>
                @if ($this->waitingCount > 0)
                    <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">{{ $this->waitingCount }} more waiting · about {{ $this->waitingDays }} {{ Str::plural('day', $this->waitingDays) }}</p>
                @endif
                <div class="flex flex-wrap justify-center gap-2 text-sm font-semibold">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-3 py-1 text-success dark:bg-success-soft-dark dark:text-success-dark">
                        @svg('heroicon-o-check', 'h-4 w-4') {{ $sessionRemembered }} remembered
                    </span>
                    @if ($sessionGraded - $sessionRemembered > 0)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-sunken px-3 py-1 text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                            @svg('heroicon-o-arrow-path', 'h-4 w-4') {{ $sessionGraded - $sessionRemembered }} coming back tomorrow
                        </span>
                    @endif
                </div>
                @if ($this->moreAvailable)
                    <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="cursor-pointer rounded-2xl border-2 border-line px-5 py-2.5 text-sm font-extrabold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-plus', 'mr-1 inline h-4 w-4') {{ $track === 'words' ? User::DAILY_WORD_LIMIT.' more words' : User::DAILY_TALK_LIMIT.' more' }}</button>
                @endif
            </div>
            <a href="{{ route('home') }}" wire:navigate class="sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] w-full rounded-2xl bg-accent py-3.5 text-center text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Done</a>
        @else
            <div class="flex flex-1 flex-col items-center justify-center gap-2 text-center">
                @svg('heroicon-o-check-badge', 'h-10 w-10 text-success dark:text-success-dark')
                <p class="text-lg font-extrabold text-ink dark:text-ink-dark">You're all caught up!</p>
                @if (auth()->user()->reviewedTodayCount($track) > 0)
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">That's today's review done.</p>
                @if ($this->waitingCount > 0)
                    <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">{{ $this->waitingCount }} more waiting · about {{ $this->waitingDays }} {{ Str::plural('day', $this->waitingDays) }}</p>
                @endif
                @else
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Nothing is due right now — come back later.</p>
                @endif
                @if ($this->moreAvailable)
                    <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="cursor-pointer rounded-2xl border-2 border-line px-5 py-2.5 text-sm font-extrabold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-plus', 'mr-1 inline h-4 w-4') {{ $track === 'words' ? User::DAILY_WORD_LIMIT.' more words' : User::DAILY_TALK_LIMIT.' more' }}</button>
                @endif
            </div>
        @endif
    @else
        @php
            $type = $item['type'];
            $model = $item['model'];
            $position = $sessionGraded + 1;
            $total = $sessionGraded + count($this->queue);
            $label = match ($type) {
                'word' => 'Word',
                'dictation' => 'Dictation',
                'speaking' => 'Speaking'.($model->mission_code ? ' · '.$model->mission_code : ''),
                'note' => $model->isMistake() ? 'Your mistake' : 'Your tip',
                'error' => 'Your mistake',
                default => 'Grammar',
            };
            $grammarReview = $this->grammarReview;
            $hasQuestion = $type === 'grammar' && ! empty($grammarReview['card']);
            $strength = $model->strengthLevel();
        @endphp

        <x-lesson-header data-step-header :close="route('review.index')" :progress="($position - 1) / max($total, 1)" :counter="$position.'/'.$total" />

        <div
            wire:key="review-{{ $type }}-{{ $model->id }}"
            x-data="{ forgot: false, meaning: false }"
            class="animate-review-card-in flex min-h-0 flex-1 flex-col gap-4"
        >
            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain rounded-[1.75rem] bg-surface p-5 dark:bg-surface-dark">
                <p class="flex items-center gap-2 text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                    {{ $label }}
                    @if ($type !== 'note')
                        <span class="flex gap-1" title="{{ $model->strengthLabel() }}" aria-hidden="true">
                            @for ($dot = 1; $dot <= 5; $dot++)
                                <span class="h-1 w-3 rounded-full {{ $dot <= $strength ? 'bg-success dark:bg-success-dark' : 'bg-line dark:bg-line-dark' }}"></span>
                            @endfor
                        </span>
                    @endif
                </p>

                @if ($type === 'word')
                    <div role="button" tabindex="0" x-on:click="meaning = ! meaning" x-on:keydown.enter="meaning = ! meaning" class="flex flex-1 cursor-pointer flex-col items-center justify-center gap-3 text-center">
                        <span x-show="! meaning" class="flex flex-col items-center gap-3">
                            <x-speak-word :word="$model->word" class="font-display text-3xl font-semibold text-ink dark:text-ink-dark" />
                            @if (filled($model->pos))
                                <span class="rounded-full bg-accent-soft px-3 py-0.5 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $model->pos }}</span>
                            @endif
                        </span>
                        <span x-show="meaning" x-cloak class="flex flex-col items-center gap-2">
                            <x-review-word-meaning :word="$model" />
                        </span>
                    </div>
                @elseif ($type === 'dictation')
                    <div class="flex flex-1 flex-col items-center justify-center gap-3" x-data="{ say: (rate) => window.eosVoice?.speak(@js($model->word), { rate }) }" x-init="$nextTick(() => say(1))">
                        <button type="button" x-on:click="say(1)" aria-label="Hear the word" class="flex h-20 w-20 cursor-pointer items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">@svg('heroicon-o-speaker-wave', 'h-9 w-9')</button>
                        <button type="button" x-on:click="say(0.6)" class="cursor-pointer text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">🐢 Slower</button>
                    </div>
                    <form wire:submit="checkSpelling" class="contents">
                        <input
                            type="text"
                            wire:model="spelling"
                            autocapitalize="off"
                            autocomplete="off"
                            spellcheck="false"
                            aria-label="Type the word you hear"
                            placeholder="Type what you hear"
                            @readonly($spellingCorrect !== null)
                            class="w-full rounded-2xl border-2 border-line bg-ground px-4 py-3 text-center text-lg font-bold text-ink focus:border-accent focus:outline-none dark:border-line-dark dark:bg-ground-dark dark:text-ink-dark dark:focus:border-accent-dark"
                        >
                    </form>
                    @if ($spellingCorrect === null)
                        <button type="button" wire:click="cantListen" class="cursor-pointer self-center text-sm font-bold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">@svg('heroicon-o-speaker-x-mark', 'mr-1 inline h-4 w-4') I can't listen right now</button>
                    @endif
                @elseif ($type === 'speaking')
                    <p class="font-display lesson-title text-ink dark:text-ink-dark">{{ $model->prompt }}</p>
                    <div class="mt-auto flex flex-col items-center gap-3">
                        <x-voice-recorder big field="recording" :file="$recording" on-recorded="recorded" file-name="daily-review.webm" />
                    </div>
                @elseif ($type === 'error')
                    <p class="text-lg text-ink-soft dark:text-ink-soft-dark">You've mixed this up before:</p>
                    <p class="text-xl text-danger-ink line-through decoration-danger">{{ $model->last_error }}</p>
                    @if (! $revealed)
                        <form wire:submit="checkAttempt" class="mt-auto flex flex-col gap-2">
                            <input
                                type="text"
                                wire:model="attempt"
                                aria-label="Write the sentence correctly"
                                placeholder="Write it correctly…"
                                class="w-full rounded-2xl border-2 border-line bg-ground px-4 py-3 text-base text-ink focus:border-accent focus:outline-none dark:border-line-dark dark:bg-ground-dark dark:text-ink-dark"
                            >
                            <button type="submit" class="w-full cursor-pointer rounded-2xl bg-accent py-3 text-base font-bold text-white dark:bg-accent-dark">Check</button>
                            <button type="button" wire:click="reveal" class="cursor-pointer text-sm font-bold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">Just show the fix</button>
                        </form>
                    @endif
                @elseif ($type === 'note')
                    <x-notebook-drill :entry="$model" :answer="$drillAnswer" :correct="$drillCorrect" :typing="$drillTyping" :heard="$drillHeard" :missed-audio="$drillMissedAudio" :recording="$drillRecording" />
                @elseif ($hasQuestion)
                    @php
                        $card = $grammarReview['card'];
                        $answered = $pickedOption !== null;
                    @endphp
                    <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">{{ $model->focus }}</p>
                    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Fix this sentence</p>
                    <p class="text-xl leading-snug text-ink dark:text-ink-dark">&ldquo;{{ $card['wrong'] }}&rdquo;</p>
                    <div class="mt-auto grid gap-2.5">
                        @foreach ($card['options'] as $i => $option)
                            @php $state = ! $answered ? 'idle' : ($i === $card['correct'] ? 'correct' : ($pickedOption === $i ? 'wrong' : 'muted')); @endphp
                            <button
                                type="button"
                                wire:key="grammar-option-{{ $model->id }}-{{ $i }}"
                                wire:click="pickOption({{ $i }})"
                                @disabled($answered)
                                data-state="{{ $state }}"
                                class="choice"
                            >
                                <span class="choice-key">{{ chr(65 + $i) }}</span>
                                <span class="min-w-0 flex-1 leading-snug">{{ $option }}</span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="font-display text-2xl leading-snug font-semibold text-ink dark:text-ink-dark">{{ $model->focus }}</p>
                    <p class="text-base text-ink-soft dark:text-ink-soft-dark">Can you still say a sentence like this? <span class="italic">"{{ $model->example_sentence }}"</span></p>
                    @if (! $revealed)
                        <button type="button" wire:click="reveal" class="mt-auto cursor-pointer self-center rounded-full border-2 border-line px-4 py-2 text-sm font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Show a quick reminder</button>
                    @endif
                @endif
            </div>

            {{-- The bottom of the screen: what to do next for this card. --}}
            @if ($type === 'word')
                <div x-show="! forgot" class="flex gap-3">
                    <button type="button" x-on:click="forgot = true" class="{{ $forgetClass }}">✕ Forget</button>
                    <button type="button" wire:click="gradeWord(4, true)" wire:loading.attr="disabled" class="{{ $knowClass }}">✓ I know</button>
                </div>
                <x-answer-sheet wire:key="review-forgot-{{ $model->id }}" when="forgot" tone="'almost'" title="'Here it is'">
                    <x-review-word-meaning :word="$model" />
                    <x-slot:actions>
                        <x-continue-button on-click="$wire.gradeWord(1, false)" wire-target="gradeWord" />
                    </x-slot:actions>
                </x-answer-sheet>
            @elseif ($type === 'dictation')
                @if ($spellingCorrect === null)
                    <div wire:key="dictation-check-{{ $model->id }}">
                        <x-continue-button on-click="$wire.checkSpelling()" wire-target="checkSpelling">Check</x-continue-button>
                    </div>
                @else
                    @php
                        $sheetTone = $spellingCorrect ? "'good'" : "'almost'";
                        $sheetTitle = $spellingCorrect ? "'Right!'" : "'Almost!'";
                    @endphp
                    <x-answer-sheet wire:key="dictation-sheet-{{ $model->id }}" when="true" :tone="$sheetTone" :title="$sheetTitle">
                        @unless ($spellingCorrect)
                            <p class="text-lg font-extrabold">
                                <span class="font-bold text-ink-faint line-through dark:text-ink-faint-dark">{{ $spelling }}</span> →
                                @foreach ($this->spellingPieces() as $piece)<span @class(['text-success dark:text-success-dark' => $piece['missed']])>{{ $piece['text'] }}</span>@endforeach
                            </p>
                        @endunless
                        <x-review-word-meaning :word="$model" />
                        <x-slot:actions>
                            <x-continue-button on-click="$wire.continueDictation()" wire-target="continueDictation" />
                        </x-slot:actions>
                    </x-answer-sheet>
                @endif
            @elseif ($type === 'speaking')
                @if ($recordedThisTurn)
                    <div class="-mx-4 flex flex-col gap-3 rounded-t-3xl bg-surface px-4 pt-4 pb-3 shadow-[0_-6px_18px_rgba(20,16,60,.12)] sm:-mx-6 sm:px-6 dark:bg-surface-dark">
                        @if ($feedback)
                            <p class="flex items-start gap-2 text-sm text-ink dark:text-ink-dark">@svg('heroicon-o-sparkles', 'mt-0.5 h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark') {{ $feedback }}</p>
                        @endif
                        @if ($previousRecordingUrl)
                            <div x-data="{ last: false }">
                                <button type="button" x-on:click="last = ! last" class="cursor-pointer text-sm font-bold text-ink-faint dark:text-ink-faint-dark">▶ Last time{{ $model->last_reviewed_at ? ' · '.$model->last_reviewed_at->diffForHumans() : '' }}</button>
                                <div x-show="last" x-cloak class="mt-2"><x-audio-player :url="$previousRecordingUrl" /></div>
                            </div>
                        @endif
                        <x-continue-button on-click="$wire.continueSpeaking()" wire-target="continueSpeaking" />
                    </div>
                @endif
            @elseif ($type === 'error' || ($type === 'grammar' && ! $hasQuestion))
                @if ($revealed)
                    @php
                        $sheetTone = $type === 'error' && $attemptCorrect ? "'good'" : "'almost'";
                        $sheetTitle = $type !== 'error' ? "'Quick reminder'" : ($attemptCorrect ? "'Spot on!'" : (trim($attempt) !== '' ? "'Not quite'" : "'The fix'"));
                    @endphp
                    <x-answer-sheet wire:key="review-reveal-{{ $type }}-{{ $model->id }}" when="true" :tone="$sheetTone" :title="$sheetTitle">
                        @if ($type === 'error')
                            @if (trim($attempt) !== '' && ! $attemptCorrect)
                                <p class="text-ink-soft dark:text-ink-soft-dark">You wrote: {{ $attempt }}</p>
                            @endif
                            <p class="text-base font-extrabold text-success dark:text-success-dark">{{ $model->last_correction }}</p>
                        @else
                            <p class="text-base">{{ $model->rule_reminder }}</p>
                        @endif
                        <x-slot:actions>
                            @if ($type === 'error')
                                <x-continue-button on-click="$wire.continueError()" wire-target="continueError" />
                            @else
                                <div class="flex gap-3">
                                    <button type="button" wire:click="gradeSelf(1)" class="{{ $forgetClass }}">✕ Forget</button>
                                    <button type="button" wire:click="gradeSelf(4)" class="{{ $knowClass }}">✓ I know</button>
                                </div>
                            @endif
                        </x-slot:actions>
                    </x-answer-sheet>
                @endif
            @elseif ($hasQuestion && $pickedOption !== null)
                @php
                    $gotItRight = $pickedOption === $grammarReview['card']['correct'];
                    $sheetTone = $gotItRight ? "'good'" : "'almost'";
                    $sheetTitle = $gotItRight ? "'Yes — that\\'s right!'" : "'Not quite — the green one is correct.'";
                @endphp
                <x-answer-sheet wire:key="review-grammar-{{ $model->id }}" when="true" :tone="$sheetTone" :title="$sheetTitle">
                    @foreach ($grammarReview['rules'] as $rule)
                        <p>{!! $rule['text'] !!}</p>
                        @if ($rule['fa'])
                            <p dir="rtl" class="font-fa text-ink-soft dark:text-ink-soft-dark">{!! $rule['fa'] !!}</p>
                        @endif
                    @endforeach
                    <x-slot:actions>
                        <x-continue-button on-click="$wire.continueGrammar()" wire-target="continueGrammar" />
                    </x-slot:actions>
                </x-answer-sheet>
            @endif

            <button type="button" wire:click="skip" class="cursor-pointer self-center pb-1 text-sm font-bold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">Skip</button>
        </div>
    @endif
</div>
