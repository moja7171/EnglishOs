<?php

use App\Models\SpeakingPrompt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?UploadedFile $recording = null;

    /**
     * True once a fresh recording has been made THIS review — required
     * before the self-assessment buttons appear, so a stale
     * last_recording_url left over from a much earlier review can't be
     * used to rate today's attempt without actually attempting it. Reset
     * on advance() for the next due prompt.
     */
    public bool $recordedThisTurn = false;

    #[Computed]
    public function duePrompts()
    {
        return auth()->user()->speakingPrompts()
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->get();
    }

    #[Computed]
    public function currentPrompt(): ?SpeakingPrompt
    {
        return $this->duePrompts->first();
    }

    #[Computed]
    public function allPrompts()
    {
        return auth()->user()->speakingPrompts()->orderBy('created_at')->get();
    }

    /**
     * Fired automatically once the recording uploads (see
     * <x-voice-recorder>'s on-recorded) — no separate "send" step needed,
     * the recording itself is the whole artifact, nothing to review or
     * edit first (unlike Sage's dictation flow).
     */
    public function recorded(): void
    {
        $prompt = $this->currentPrompt;

        if (! $this->recording || ! $prompt) {
            return;
        }

        $path = $this->recording->store('speaking-recall/'.auth()->id(), 'public');
        $prompt->update(['last_recording_url' => Storage::disk('public')->url($path)]);

        $this->recording = null;
        $this->recordedThisTurn = true;
    }

    /**
     * Again/Good/Easy → SM-2's 0-5 quality scale (1/4/5), same mapping
     * My Words already uses for its own self-assessment tap. Never
     * available until a fresh recording exists for this prompt this turn
     * — see $recordedThisTurn.
     */
    public function gradeSelf(int $quality): void
    {
        $prompt = $this->currentPrompt;

        if (! $prompt || ! $this->recordedThisTurn) {
            return;
        }

        $prompt->review($quality);
        $this->advance();
    }

    private function advance(): void
    {
        $this->recording = null;
        $this->recordedThisTurn = false;
        unset($this->duePrompts, $this->currentPrompt);
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
            @svg('heroicon-o-microphone', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Speaking Recall</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Real questions from missions you've finished, on their own schedule.</p>
        </div>
    </header>

    @if ($this->allPrompts->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-10 text-center dark:border-line-dark">
            @svg('heroicon-o-microphone', 'h-6 w-6 text-ink-faint/60 dark:text-ink-faint-dark/60')
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Finish a mission and pick a few questions in Mission Result — they'll show up here.</p>
        </div>
    @elseif (! $this->currentPrompt)
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-line bg-surface p-8 text-center dark:border-line-dark dark:bg-surface-dark">
            @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due for review right now — come back later.</p>
        </div>
    @else
        @php $prompt = $this->currentPrompt; @endphp
        <div wire:key="recall-{{ $prompt->id }}" class="space-y-4 rounded-2xl border border-line bg-surface p-5 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                {{ $this->duePrompts->count() }} {{ Str::plural('question', $this->duePrompts->count()) }} due for review
                @if ($prompt->mission_code)
                    · from {{ $prompt->mission_code }}
                @endif
            </p>

            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $prompt->prompt }}</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Answer out loud, without preparing first.</p>

            <x-practice-with-friend
                :text="$prompt->prompt"
                intro="Hey — want to practice this speaking question with me:"
                label="Practice this with a friend"
            />

            @if ($prompt->last_recording_url && ! $recordedThisTurn)
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your last attempt:</p>
                    <div class="mt-1"><x-audio-player :url="$prompt->last_recording_url" /></div>
                </div>
            @endif

            <div wire:key="recorder-{{ $prompt->id }}">
                <x-voice-recorder field="recording" :file="$recording" on-recorded="recorded" file-name="speaking-recall.webm" />
            </div>

            @if ($recordedThisTurn)
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">How did that feel?</p>
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

    @if ($this->allPrompts->isNotEmpty())
        <div x-data="{ showAll: false }">
            <button
                type="button"
                x-on:click="showAll = !showAll"
                class="flex w-full cursor-pointer items-center justify-between gap-2 text-xs font-semibold tracking-wide text-ink-faint uppercase transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
            >
                <span>All my questions ({{ $this->allPrompts->count() }})</span>
                <span class="transition-transform" :class="showAll ? 'rotate-180' : ''">
                    @svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')
                </span>
            </button>

            <div x-show="showAll" x-cloak x-transition.opacity.duration.150ms class="mt-2 space-y-1.5">
                @foreach ($this->allPrompts as $item)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-line px-3.5 py-2 dark:border-line-dark">
                        <span class="text-sm text-ink dark:text-ink-dark">{{ $item->prompt }}</span>
                        <span class="shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark">
                            @if ($item->isDue())
                                Due now
                            @else
                                Next review {{ $item->next_review_at->diffForHumans() }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
