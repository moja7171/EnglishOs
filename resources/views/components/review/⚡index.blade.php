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
     * just reading and tapping Easy. A word's recall card shares this flag.
     */
    public bool $revealed = false;

    /** True when the learner said "I remember" on a word before opening its card. */
    public bool $recalledWord = false;

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
     * A word's recall card — "I remember" or "Not sure — show me", then
     * the card opens either way (see <x-vocabulary-review-card>).
     */
    public function revealWord(bool $remembered = false): void
    {
        $this->revealed = true;
        $this->recalledWord = $remembered;
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
     * needs a fresh recording first (see recordedThisTurn); every other
     * item needs its answer revealed first (see $revealed) — both just
     * guard against grading something without actually looking at or
     * attempting it. A word the learner wasn't sure about ("show me") can
     * only ever be graded as forgotten — enforced here, not just hidden
     * in the card.
     */
    public function gradeSelf(int $quality): void
    {
        $item = $this->currentItem;

        if (! $item) {
            return;
        }

        if ($item['type'] === 'speaking' && ! $this->recordedThisTurn) {
            return;
        }

        if ($item['type'] !== 'speaking' && ! $this->revealed) {
            return;
        }

        if ($item['type'] === 'word' && ! $this->recalledWord) {
            $quality = 1;
        }

        $item['model']->review($quality);
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
        $this->recalledWord = false;
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
        @endphp
        <div wire:key="review-{{ $type }}-{{ $model->id }}" class="space-y-4 card p-5">
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
                <x-speak-word :word="$model->word" block class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark" />

                <x-vocabulary-review-card :word="$model" :revealed="$revealed" :recalled="$recalledWord" />
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

            @if ($type === 'speaking' ? $recordedThisTurn : ($type !== 'word' && $revealed))
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                        @if ($type === 'speaking') How did that feel? @else Did you remember it? @endif
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="gradeSelf(1)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-danger-line hover:bg-danger-soft hover:text-danger-ink dark:border-line-dark dark:text-ink-soft-dark"
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
            --}}
            <div class="pt-1 text-center">
                <button
                    type="button"
                    wire:click="skip"
                    class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark"
                >@svg('heroicon-o-forward', 'h-3.5 w-3.5') Skip for now</button>
            </div>
        </div>
    @endif
</div>
