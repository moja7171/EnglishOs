<?php

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
     * (Again/Good/Easy after a reveal, or after a fresh recording).
     *
     * @return list<array{type: string, id: int}>
     */
    #[Computed]
    public function queue(): array
    {
        // Today's eight, not the whole backlog — see User::dailyReviewItems().
        // A seeded sort, not ->shuffle() — see $shuffleSeed for why this
        // has to stay stable across requests rather than re-randomizing
        // on every single one.
        $all = auth()->user()->dailyReviewItems()
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
        $item['model']->update(['last_recording_url' => Storage::disk('public')->url($path)]);

        $this->recording = null;
        $this->recordedThisTurn = true;
    }

    /**
     * Again/Good/Easy → SM-2's 0-5 quality scale (1/4/5), same mapping
     * every other review flow in the app already uses, for the speaking,
     * grammar-pattern and grammar-point cards. A speaking item needs a
     * fresh recording first (see recordedThisTurn); the others need their
     * answer revealed first (see $revealed) — both just guard against
     * grading something without actually looking at or attempting it.
     * Words have their own entry point, gradeWord().
     */
    public function gradeSelf(int $quality): void
    {
        $item = $this->currentItem;

        if (! $item || $item['type'] === 'word') {
            return;
        }

        if ($item['type'] === 'speaking' && ! $this->recordedThisTurn) {
            return;
        }

        if ($item['type'] !== 'speaking' && ! $this->revealed) {
            return;
        }

        $this->gradeCurrent($item['model'], $quality);
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

    private function gradeCurrent(VocabularyWord|SpeakingPrompt|ErrorPatternReview|GrammarPoint $model, int $quality): void
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
        $this->revealed = false;
        $this->pickedOption = null;
        unset($this->grammarReview);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
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
            <div class="flex flex-col items-center gap-2 card p-8 text-center">
                @svg('heroicon-o-forward', 'h-6 w-6 text-ink-faint dark:text-ink-faint-dark')
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">You've skipped everything for today.</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing here was graded — come back to the rest whenever you're ready.</p>
            </div>
        @elseif ($sessionGraded > 0)
            {{--
                This page session's wrap-up: how many were graded and how
                they split into "remembered" vs "back tomorrow" (a failed
                review is rescheduled a day out). Shown only after at
                least one grade this session — arriving on an already
                finished day still gets the plain "all caught up" below.
            --}}
            <div class="flex flex-col items-center gap-3 card p-8 text-center" @if ($sessionRemembered > 0) x-data x-init="window.eosConfetti?.burst()" @endif>
                <span class="animate-trophy-pop inline-flex h-14 w-14 items-center justify-center rounded-full bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                    @svg('heroicon-o-check-badge', 'h-8 w-8')
                </span>
                <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Session done!</p>
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sessionGraded }} {{ Str::plural('item', $sessionGraded) }} reviewed</p>
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
                <a
                    href="{{ route('home') }}"
                    wire:navigate
                    class="mt-2 inline-flex cursor-pointer items-center rounded-full bg-accent px-6 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Done</a>
            </div>
        @else
            <div class="flex flex-col items-center gap-2 card p-8 text-center">
                @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
                @if (auth()->user()->reviewedTodayCount() > 0)
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">That's today's review done — whatever is still waiting rolls into tomorrow.</p>
                @else
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due across My Words, Speaking Recall, grammar patterns, or grammar points — come back later.</p>
                @endif
            </div>
        @endif
    @else
        @php
            $item = $this->currentItem;
            $type = $item['type'];
            $model = $item['model'];
            $position = $sessionGraded + 1;
            $total = $sessionGraded + count($this->queue);
            $remaining = count($this->queue) - 1;
            $label = match ($type) {
                'speaking' => 'Speaking',
                'error' => 'Grammar pattern',
                default => 'Grammar point',
            };
            $grammarReview = $this->grammarReview;
            $hasQuestion = $type === 'grammar' && ! empty($grammarReview['card']);
            $icon = match ($type) {
                'speaking' => 'heroicon-o-microphone',
                'error' => 'heroicon-o-pencil',
                default => 'heroicon-o-academic-cap',
            };
        @endphp

        @if ($type === 'word')
            <div wire:key="review-word-{{ $model->id }}">
                <x-vocabulary-review-card :word="$model" :position="$position" :total="$total" :remaining="$remaining" />
            </div>
        @else
            <div wire:key="review-{{ $type }}-{{ $model->id }}">
                <x-review-shell
                    :label="$label"
                    :icon="$icon"
                    :position="$position"
                    :total="$total"
                    :remaining="$remaining"
                    class="animate-review-card-in"
                >
                    @if ($type === 'speaking')
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
                            You've mixed this up before: <span class="text-danger-ink line-through decoration-danger">{{ $model->last_error }}</span>
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
                    @elseif ($hasQuestion)
                        @php
                            $card = $grammarReview['card'];
                            $answered = $pickedOption !== null;
                            $gotItRight = $answered && $pickedOption === $card['correct'];
                        @endphp

                        <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $model->focus }}</p>

                        <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                            <p class="text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Fix this sentence</p>
                            <p class="mt-1.5 text-lg leading-snug font-semibold text-ink dark:text-ink-dark">&ldquo;{{ $card['wrong'] }}&rdquo;</p>
                        </div>

                        <div class="grid gap-2.5">
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
                                    @if ($state === 'correct') <span class="shrink-0">@svg('heroicon-s-check-circle', 'h-5 w-5')</span> @endif
                                    @if ($state === 'wrong') <span class="shrink-0">@svg('heroicon-s-x-circle', 'h-5 w-5')</span> @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($answered)
                            <div class="space-y-4">
                                <p class="font-display text-lg font-bold {{ $gotItRight ? 'text-success dark:text-success-dark' : 'text-danger-ink' }}">
                                    {{ $gotItRight ? 'Yes — that\'s right!' : 'Not quite — the green one is correct.' }}
                                </p>

                                @if ($grammarReview['rules'])
                                    <div class="space-y-3 rounded-2xl border border-accent/30 bg-accent-soft p-4 dark:border-accent-dark/40 dark:bg-accent-soft-dark">
                                        <p class="inline-flex items-center gap-1.5 text-xs font-bold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">
                                            @svg('heroicon-o-light-bulb', 'h-4 w-4') Remember
                                        </p>
                                        @foreach ($grammarReview['rules'] as $rule)
                                            <div>
                                                <p class="text-base leading-snug font-semibold text-ink dark:text-ink-dark">{!! $rule['text'] !!}</p>
                                                @if ($rule['fa'])
                                                    <p dir="rtl" class="mt-1 text-start text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">{!! $rule['fa'] !!}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Your own sentence: <span class="text-ink italic dark:text-ink-dark">&ldquo;{{ $model->example_sentence }}&rdquo;</span></p>

                                <button
                                    type="button"
                                    wire:click="continueGrammar"
                                    class="w-full cursor-pointer rounded-full bg-accent px-5 py-3 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                                >Continue</button>
                            </div>
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

                    @if (! $hasQuestion && ($type === 'speaking' ? $recordedThisTurn : $revealed))
                        <div>
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                                @if ($type === 'speaking') How did that feel? @else Did you remember it? @endif
                            </p>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button
                                    type="button"
                                    wire:click="gradeSelf(1)"
                                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-danger-line bg-danger-soft px-2 py-2.5 text-danger-ink transition-colors hover:opacity-90"
                                >
                                    <span class="text-sm font-bold">Again</span>
                                    <span class="text-[11px] font-semibold opacity-70">{{ $model->nextIntervalLabel(1) }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="gradeSelf(4)"
                                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-line px-2 py-2.5 text-ink transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
                                >
                                    <span class="text-sm font-bold">Good</span>
                                    <span class="text-[11px] font-semibold opacity-70">{{ $model->nextIntervalLabel(4) }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="gradeSelf(5)"
                                    class="flex cursor-pointer flex-col items-center gap-0.5 rounded-xl border border-success/40 bg-success-soft px-2 py-2.5 text-success transition-colors hover:opacity-90 dark:border-success-dark/40 dark:bg-success-soft-dark dark:text-success-dark"
                                >
                                    <span class="text-sm font-bold">Easy</span>
                                    <span class="text-[11px] font-semibold opacity-70">{{ $model->nextIntervalLabel(5) }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                </x-review-shell>
            </div>
        @endif

        {{--
            Deliberately far lighter than the grade buttons — a plain text
            link, not a pill button — this is an escape hatch for a stuck
            item (e.g. denied microphone access, see voice-recorder.blade.php),
            not a normal everyday action.
        --}}
        <div class="pt-1 text-center">
            <button
                type="button"
                wire:click="skip"
                class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-forward', 'h-3.5 w-3.5') Skip for now</button>
        </div>
    @endif
</div>
