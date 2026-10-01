<?php

use App\Livewire\Concerns\ChecksVocabularyWordSentences;
use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\SpeakingPrompt;
use App\Models\VocabularyWord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use ChecksVocabularyWordSentences;
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
     * True once the meaning/correction has been revealed for the current
     * item — error patterns and grammar points start hidden ("what was
     * wrong?" / "quick reminder?") so grading is an honest self-test, not
     * just reading and tapping Easy. A word never uses this flag: see
     * $wordSentence below for why it has its own gate instead.
     */
    public bool $revealed = false;

    /**
     * The written-review path for a word item — see
     * ChecksVocabularyWordSentences and VocabularyWord::needsWrittenReview().
     * Mirrors My Words' own $sentence/$feedback/$checkError/$diagnosticDone
     * under distinct names (this component already has unrelated
     * $recording/$revealed state to keep separate from).
     */
    public string $wordSentence = '';

    /** @var array{severity: string, hint: string}|null */
    public ?array $wordFeedback = null;

    public ?string $wordCheckError = null;

    public bool $wordDiagnosticDone = false;

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

    public function mount(): void
    {
        $this->shuffleSeed = Str::random(16);
    }

    /**
     * A fast, mixed session across every review system the app has —
     * vocabulary (My Words), Speaking Recall, recurring grammar-mistake
     * patterns, and taught grammar points (Grammar in Context) —
     * interleaved into one queue instead of four separate places to
     * check. Most types use the LIGHT self-assessment interaction here
     * (Again/Good/Easy after a reveal, or after a fresh recording) — but
     * a word that still needsWrittenReview() (brand new, or just knocked
     * back to day 1 by a failed review) gets the exact same deeper,
     * AI-checked sentence-writing flow My Words uses, not a shortcut
     * version of it. Letting a fresh word skip straight to a one-tap
     * grade here would let a learner permanently dodge the one AI check
     * that verifies they can actually use the word, just by reviewing it
     * from this page instead of My Words.
     *
     * @return list<array{type: string, id: int}>
     */
    #[Computed]
    public function queue(): array
    {
        $words = auth()->user()->vocabularyWords()->where('next_review_at', '<=', now())->get()
            ->map(fn ($item) => ['type' => 'word', 'id' => $item->id]);

        $prompts = auth()->user()->speakingPrompts()->where('next_review_at', '<=', now())->get()
            ->map(fn ($item) => ['type' => 'speaking', 'id' => $item->id]);

        $errors = auth()->user()->errorPatternReviews()->where('next_review_at', '<=', now())->get()
            ->map(fn ($item) => ['type' => 'error', 'id' => $item->id]);

        $grammarPoints = auth()->user()->grammarPoints()->where('next_review_at', '<=', now())->get()
            ->map(fn ($item) => ['type' => 'grammar', 'id' => $item->id]);

        // A seeded sort, not ->shuffle() — see $shuffleSeed for why this
        // has to stay stable across requests rather than re-randomizing
        // on every single one.
        $all = $words->concat($prompts)->concat($errors)->concat($grammarPoints)
            ->sortBy(fn (array $entry) => md5($this->shuffleSeed.$this->itemKey($entry['type'], $entry['id'])))
            ->values();

        // Items skipped THIS session (see skip()) move to the back, in
        // their relative order — lets a learner stuck on one item (e.g.
        // microphone access denied, see voice-recorder.blade.php's own
        // error state) keep moving through the rest of today's queue
        // instead of every later item becoming unreachable behind it.
        [$ready, $skipped] = $all->partition(fn ($entry) => ! in_array($this->itemKey($entry['type'], $entry['id']), $this->skippedKeys, true));

        return $ready->concat($skipped)->values()->all();
    }

    /**
     * True once every item left in the queue has already been skipped
     * this session — the "skipped all the way around" case skip() must
     * not spin into an infinite loop over. currentItem() treats this the
     * same as an empty queue (see its own check below), and the view
     * shows a distinct "come back to the rest later" message rather than
     * silently re-showing the first skipped item.
     */
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
     * @return array{type: string, model: VocabularyWord|SpeakingPrompt|ErrorPatternReview|GrammarPoint}|null
     */
    #[Computed]
    public function currentItem(): ?array
    {
        $entry = $this->queue[0] ?? null;

        if (! $entry || $this->hasSkippedEverything) {
            return null;
        }

        $model = match ($entry['type']) {
            'word' => VocabularyWord::find($entry['id']),
            'speaking' => SpeakingPrompt::find($entry['id']),
            'error' => ErrorPatternReview::find($entry['id']),
            'grammar' => GrammarPoint::find($entry['id']),
        };

        return $model ? ['type' => $entry['type'], 'model' => $model] : null;
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
        $item['model']->update(['last_recording_url' => Storage::disk('public')->url($path)]);

        $this->recording = null;
        $this->recordedThisTurn = true;
    }

    /**
     * Again/Good/Easy → SM-2's 0-5 quality scale (1/4/5), same mapping
     * every other review flow in the app already uses. A speaking item
     * needs a fresh recording first (see recordedThisTurn); an error
     * pattern or grammar point needs the correction/reminder revealed
     * first (see $revealed) — both just guard against grading something
     * without actually looking at or attempting it. A word that still
     * needsWrittenReview() is NEVER gradable through this quick path at
     * all — checked server-side here too, not just hidden in the view —
     * it only ever advances via checkWordSentence()'s AI-checked flow.
     */
    public function gradeSelf(int $quality): void
    {
        $item = $this->currentItem;

        if (! $item) {
            return;
        }

        if ($item['type'] === 'word' && $item['model']->needsWrittenReview()) {
            return;
        }

        if ($item['type'] === 'speaking' && ! $this->recordedThisTurn) {
            return;
        }

        if ($item['type'] !== 'speaking' && ! $this->revealed) {
            return;
        }

        $item['model']->review($quality);
        $this->advance();
    }

    /**
     * The written-review path for the current item, when it's a word —
     * see ChecksVocabularyWordSentences for the shared SentenceChecker
     * judgment + grading this calls. Identical behavior to My Words' own
     * checkSentence(), just reading/writing this component's own
     * $wordSentence/$wordFeedback/$wordCheckError instead. No
     * needsWrittenReview() guard here either, same as checkSentence() —
     * the view only ever renders the form that calls this when
     * needsWrittenReview() is true (see currentWordDiagnosticCard()'s
     * sibling branch below), so the gate that matters is on gradeSelf(),
     * the method this one is NOT.
     */
    public function checkWordSentence(): void
    {
        $item = $this->currentItem;
        $text = trim($this->wordSentence);

        if (! $item || $item['type'] !== 'word') {
            return;
        }

        if ($text === '') {
            $this->wordCheckError = 'Write a sentence first.';

            return;
        }

        $this->wordCheckError = null;

        $result = $this->runWordSentenceCheck($item['model'], $text);
        $this->wordFeedback = $result['feedback'];
        $this->wordCheckError = $result['error'];
    }

    /**
     * @return array{prompt: string, options: list<string>, correct: int}|null
     */
    public function currentWordDiagnosticCard(): ?array
    {
        $item = $this->currentItem;

        return ($item && $item['type'] === 'word') ? $this->wordDiagnosticCard($item['model']) : null;
    }

    /**
     * Public (unlike most of this component's internals) because the
     * word written-review path's own "Next" button (shown after
     * checkWordSentence() returns a verdict) calls it directly from the
     * view, the same way My Words' nextWord() wraps it for its own Next
     * button.
     */
    public function advance(): void
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
        $this->wordSentence = '';
        $this->wordFeedback = null;
        $this->wordCheckError = null;
        $this->wordDiagnosticDone = false;
        $this->recording = null;
        $this->recordedThisTurn = false;
        $this->revealed = false;
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-bolt', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Daily Review</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Words, speaking, and grammar — one fast mixed session.</p>
        </div>
    </header>

    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
        Prefer to focus on just one?
        <a href="{{ route('vocabulary.index') }}" wire:navigate class="font-semibold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark">My Words</a>
        ·
        <a href="{{ route('speaking.index') }}" wire:navigate class="font-semibold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark">Speaking Recall</a>
    </p>

    @if (! $this->currentItem)
        @if ($this->hasSkippedEverything)
            {{--
                Every item left today got a "Skip for now" — not the same
                as actually being caught up, so this says so rather than
                reusing the success copy below. Nothing was graded, so
                every one of these is exactly as due as it was before;
                they're just one page reload away from showing again.
            --}}
            <div class="flex flex-col items-center gap-2 rounded-2xl border border-line bg-surface p-8 text-center dark:border-line-dark dark:bg-surface-dark">
                @svg('heroicon-o-forward', 'h-6 w-6 text-ink-faint dark:text-ink-faint-dark')
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">You've skipped everything for today.</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing here was graded — come back to the rest whenever you're ready.</p>
            </div>
        @else
            <div class="flex flex-col items-center gap-2 rounded-2xl border border-line bg-surface p-8 text-center dark:border-line-dark dark:bg-surface-dark">
                @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due across My Words, Speaking Recall, grammar patterns, or grammar points — come back later.</p>
            </div>
        @endif
    @else
        @php
            $item = $this->currentItem;
            $type = $item['type'];
            $model = $item['model'];
        @endphp
        <div wire:key="review-{{ $type }}-{{ $model->id }}" class="space-y-4 rounded-2xl border border-line bg-surface p-5 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                @if ($type === 'word')
                    @svg('heroicon-o-book-open', 'h-3.5 w-3.5') Word
                @elseif ($type === 'speaking')
                    @svg('heroicon-o-microphone', 'h-3.5 w-3.5') Speaking
                @elseif ($type === 'error')
                    @svg('heroicon-o-pencil', 'h-3.5 w-3.5') Grammar pattern
                @else
                    @svg('heroicon-o-academic-cap', 'h-3.5 w-3.5') Grammar point
                @endif
                · {{ count($this->queue) }} left today
            </p>

            @if ($type === 'word')
                <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $model->word }}</p>

                {{--
                    Checked first, ahead of needsWrittenReview() — same
                    ordering reason as My Words: checkWordSentence()
                    already calls $word->review() the moment it gets a
                    verdict, which immediately changes repetitions, so
                    branching on the word's live state here would yank the
                    feedback UI away before the learner ever sees it.
                --}}
                @if ($wordFeedback)
                    <x-severity-feedback :feedback="$wordFeedback" />

                    <button
                        type="button"
                        wire:click="advance"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    >Next @svg('heroicon-o-arrow-right', 'h-3.5 w-3.5')</button>
                @elseif ($model->needsWrittenReview() && ! $wordDiagnosticDone && ($wordDiagnosticCard = $this->currentWordDiagnosticCard()))
                    {{-- A quick meaning-match warm-up before the deeper
                         written review below — ungraded, always skippable,
                         same as My Words. --}}
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Quick check before you write — pick the right meaning.</p>
                    <x-quick-round
                        :cards="[$wordDiagnosticCard]"
                        on-complete="$wire.set('wordDiagnosticDone', true)"
                        on-skip="$wire.set('wordDiagnosticDone', true)"
                    />
                @elseif ($model->needsWrittenReview())
                    {{--
                        The deeper, AI-checked written review — a brand-new
                        word (or one just knocked back to day 1 by a failed
                        review) gets exactly the same flow here as it would
                        on My Words, so there's no shortcut path to clear
                        it from Daily Review instead.
                    --}}
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $model->meaning }}</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Write a sentence using this word.</p>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="wordSentence"
                            wire:keydown.enter="checkWordSentence"
                            placeholder="My example…"
                            wire:loading.attr="disabled"
                            wire:target="checkWordSentence"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        <button
                            type="button"
                            wire:click="checkWordSentence"
                            wire:loading.attr="disabled"
                            wire:target="checkWordSentence"
                            class="shrink-0 cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                        >Check</button>
                    </div>
                    <x-ai-thinking wire:loading wire:target="checkWordSentence" />
                    @if ($wordCheckError)
                        <p class="text-xs text-red-600">{{ $wordCheckError }}</p>
                    @endif
                @else
                    @if (! $revealed)
                        <button
                            type="button"
                            wire:click="reveal"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-eye', 'h-4 w-4') Show meaning</button>
                    @else
                        <p class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $model->meaning }}</p>
                    @endif
                @endif
            @elseif ($type === 'speaking')
                <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $model->prompt }}</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Answer out loud, without preparing first.</p>

                @if ($model->last_recording_url && ! $recordedThisTurn)
                    <div>
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your last attempt:</p>
                        <div class="mt-1"><x-audio-player :url="$model->last_recording_url" /></div>
                    </div>
                @endif

                <x-voice-recorder field="recording" :file="$recording" on-recorded="recorded" file-name="daily-review.webm" />
            @elseif ($type === 'error')
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                    You've mixed this up before: <span class="text-red-600 line-through decoration-red-500">{{ $model->last_error }}</span>
                </p>
                @if (! $revealed)
                    <button
                        type="button"
                        wire:click="reveal"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-eye', 'h-4 w-4') Show the fix</button>
                @else
                    <p class="text-sm text-success dark:text-success-dark">{{ $model->last_correction }}</p>
                @endif
            @else
                <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $model->focus }}</p>
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Can you still write a sentence like this? <span class="text-ink-faint italic dark:text-ink-faint-dark">"{{ $model->example_sentence }}"</span></p>
                @if (! $revealed)
                    <button
                        type="button"
                        wire:click="reveal"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-eye', 'h-4 w-4') Show a quick reminder</button>
                @else
                    <p class="text-sm text-success dark:text-success-dark">{{ $model->rule_reminder }}</p>
                @endif
            @endif

            @if ($type === 'speaking' ? $recordedThisTurn : $revealed)
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                        @if ($type === 'speaking') How did that feel? @else Did you remember it? @endif
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="gradeSelf(1)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-red-300 hover:bg-red-50 hover:text-red-600 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-red-950"
                        >Forgot it</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(4)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >Remembered it</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(5)"
                            class="cursor-pointer rounded-full border border-success/40 px-4 py-2 text-sm font-semibold text-success transition-colors hover:bg-success-soft dark:border-success-dark/40 dark:text-success-dark dark:hover:bg-success-soft-dark"
                        >Knew it instantly</button>
                    </div>
                </div>
            @endif

            {{--
                Deliberately far lighter than Again/Good/Easy above — a
                plain text link, not a pill button — this is an escape
                hatch for a stuck item (e.g. denied microphone access, see
                voice-recorder.blade.php), not a normal everyday action.
                Hidden once a word's AI check has already returned a
                verdict ($wordFeedback): at that point it's already
                reviewed, so "Next" is the only action that makes sense.
            --}}
            @unless ($type === 'word' && $wordFeedback)
                <div class="pt-1 text-center">
                    <button
                        type="button"
                        wire:click="skip"
                        class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark"
                    >@svg('heroicon-o-forward', 'h-3.5 w-3.5') Skip for now</button>
                </div>
            @endunless
        </div>
    @endif
</div>
