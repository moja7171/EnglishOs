<?php

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\SpeakingPrompt;
use App\Models\VocabularyWord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?UploadedFile $recording = null;

    public bool $recordedThisTurn = false;

    /**
     * True once the meaning/correction has been revealed for the current
     * item — words and error patterns start hidden ("what does this
     * mean?" / "what was wrong?") so grading is an honest self-test, not
     * just reading and tapping Easy.
     */
    public bool $revealed = false;

    /**
     * A fast, mixed session across every review system the app has —
     * vocabulary (My Words), Speaking Recall, recurring grammar-mistake
     * patterns, and taught grammar points (Grammar in Context) —
     * interleaved into one queue instead of four separate places to
     * check. Deliberately the LIGHT self-assessment interaction for every
     * type here (Again/Good/Easy), even for a brand-new word or pattern
     * that would normally get My Words' or Active Recall's deeper
     * AI-checked writing flow — this is the fast daily pass; that deeper
     * practice still lives on those dedicated pages for whoever wants it.
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

        return $words->concat($prompts)->concat($errors)->concat($grammarPoints)->shuffle()->values()->all();
    }

    /**
     * @return array{type: string, model: VocabularyWord|SpeakingPrompt|ErrorPatternReview|GrammarPoint}|null
     */
    #[Computed]
    public function currentItem(): ?array
    {
        $entry = $this->queue[0] ?? null;

        if (! $entry) {
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
     * needs a fresh recording first (see recordedThisTurn); a word or
     * error pattern needs the meaning/correction revealed first (see
     * $revealed) — both just guard against grading something without
     * actually looking at or attempting it.
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

        $item['model']->review($quality);
        $this->advance();
    }

    private function advance(): void
    {
        $this->recording = null;
        $this->recordedThisTurn = false;
        $this->revealed = false;
        unset($this->queue, $this->currentItem);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
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
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-line bg-surface p-8 text-center dark:border-line-dark dark:bg-surface-dark">
            @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due across My Words, Speaking Recall, grammar patterns, or grammar points — come back later.</p>
        </div>
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
                @if (! $revealed)
                    <button
                        type="button"
                        wire:click="reveal"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-eye', 'h-4 w-4') Show meaning</button>
                @else
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $model->meaning }}</p>
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
                        >Again</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(4)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >Good</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(5)"
                            class="cursor-pointer rounded-full border border-success/40 px-4 py-2 text-sm font-semibold text-success transition-colors hover:bg-success-soft dark:border-success-dark/40 dark:text-success-dark dark:hover:bg-success-soft-dark"
                        >Easy</button>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
