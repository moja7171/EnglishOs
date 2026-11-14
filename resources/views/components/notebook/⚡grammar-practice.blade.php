<?php

use App\Models\Mission;
use App\Models\User;
use App\Services\FeedbackNotebook;
use App\Services\GrammarPractice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A round of grammar practice from the Notebook's Grammar tab: seven cards
 * about one mission's grammar point (see GrammarPractice) — which one is
 * right, fix the sentence, build the sentence, then one right sentence to
 * say aloud. The cards are answered in the browser; the sentences the
 * learner got wrong are saved to the Mistakes tab once, at the end. The
 * spoken take is only recorded to be heard back, never checked. Practice
 * here never moves the Daily Review schedule.
 */
new class extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $missionId;

    /**
     * @var list<array<string, mixed>>
     */
    #[Locked]
    public array $cards = [];

    public $sayRecording = null;

    #[Locked]
    public bool $saved = false;

    public function mount(Mission $mission): void
    {
        /** @var User $learner */
        $learner = auth()->user();

        abort_unless($learner->missionRuns()->where('mission_id', $mission->id)->exists(), 404);

        $this->missionId = $mission->id;
        $this->cards = app(GrammarPractice::class)->round($mission);

        abort_if($this->cards === [], 404);
    }

    #[Computed]
    public function mission(): Mission
    {
        return Mission::findOrFail($this->missionId);
    }

    /**
     * Puts the sentences the learner missed in the Mistakes tab — once per
     * round, and only cards this round really had (the browser sends just
     * the positions).
     *
     * @param  list<int>  $missedPositions
     */
    public function finish(array $missedPositions): void
    {
        if ($this->saved) {
            return;
        }

        $this->saved = true;

        foreach (array_unique(array_map('intval', $missedPositions)) as $position) {
            $card = $this->cards[$position] ?? null;

            if ($card === null || ! isset($card['original'], $card['better'])) {
                continue;
            }

            app(FeedbackNotebook::class)->recordCheck(
                auth()->user(),
                $card['original'],
                $card['better'],
                $card['why'] ?? null,
                'grammar_in_context',
                $this->missionId,
            );
        }
    }

    public function again(): void
    {
        $this->redirectRoute('notebook.grammar', ['mission' => $this->mission->code], navigate: true);
    }
};
?>

@php
    $total = count($this->cards);
    $choiceCount = collect($this->cards)->whereIn('type', ['pick', 'fix'])->count();
    $backUrl = route('notebook.index', ['tab' => 'grammar']);
    $chip = 'inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark';
@endphp

<div
    wire:ignore.self
    class="mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-6"
    x-data="{
        screen: 0,
        total: {{ $total }},
        picked: {},
        missed: [],
        get share() { return Math.min(this.screen / this.total, 1) },
        choose(position, option, correct) {
            if (this.picked[position] !== undefined) { return; }
            this.picked[position] = option;
            if (option === correct) {
                window.eosSound?.playSuccess();
                setTimeout(() => { if (this.screen === position) { this.next(); } }, 900);
            } else {
                this.missed.push(position);
            }
        },
        next() {
            this.screen++;
            if (this.screen === this.total) { $wire.finish(this.missed); }
        },
    }"
>
    <x-lesson-header data-step-header :close="$backUrl" progress-expr="share" />

    <div>
        @foreach ($this->cards as $position => $card)
            @switch($card['type'])
                @case('pick')
                @case('fix')
                    <x-lesson-screen when="screen === {{ $position }}" wire:key="grammar-card-{{ $position }}">
                        <span class="{{ $chip }}">{{ $card['label'] }}</span>
                        @if (filled($card['prompt'] ?? null))
                            <p class="lesson-prompt text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                        @endif
                        <div class="flex flex-col gap-2.5">
                            @foreach ($card['options'] as $option => $text)
                                <button
                                    type="button"
                                    x-on:click="choose({{ $position }}, {{ $option }}, {{ $card['correct'] }})"
                                    class="choice text-left"
                                    :data-state="picked[{{ $position }}] === undefined ? 'idle' : ({{ $option }} === {{ $card['correct'] }} ? 'correct' : (picked[{{ $position }}] === {{ $option }} ? 'wrong' : 'muted'))"
                                ><span class="min-w-0 flex-1 leading-snug">{{ $text }}</span></button>
                            @endforeach
                        </div>

                        <x-slot:actions>
                            <x-answer-sheet when="picked[{{ $position }}] !== undefined && picked[{{ $position }}] !== {{ $card['correct'] }}" tone="'almost'" title="'Not quite'">
                                <span class="font-bold">{{ $card['better'] }}</span>
                                @if (filled($card['why'] ?? null))
                                    <span class="mt-1 block">{{ $card['why'] }}</span>
                                @endif
                                <x-slot:actions>
                                    <x-continue-button on-click="next()" wire-target="finish" />
                                </x-slot:actions>
                            </x-answer-sheet>
                        </x-slot:actions>
                    </x-lesson-screen>
                    @break

                @case('order')
                    <section wire:key="grammar-card-{{ $position }}" x-show="screen === {{ $position }}" x-cloak class="animate-lesson-screen-in">
                        <x-word-order-round
                            lesson
                            :cards="[['words' => $card['words'], 'answer' => $card['answer']]]"
                            on-complete="if (screen === {{ $position }}) next()"
                            on-skip="if (screen === {{ $position }}) next()"
                        />
                    </section>
                    @break

                @case('say')
                    <x-lesson-screen when="screen === {{ $position }}" wire:key="grammar-card-{{ $position }}">
                        <span class="{{ $chip }}">{{ $card['label'] }}</span>
                        <p class="lesson-prompt text-ink dark:text-ink-dark">{{ $card['sentence'] }}</p>
                        <div class="flex items-center gap-2 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">
                            <x-speak-button :text="$card['sentence']" /> Listen, then say it out loud.
                        </div>
                        <x-voice-recorder field="sayRecording" :file="$sayRecording" file-name="grammar-say.webm" big />

                        <x-slot:actions>
                            <x-continue-button on-click="next()" wire-target="finish">Done</x-continue-button>
                            <button type="button" x-on:click="next()" class="cursor-pointer text-center text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
                        </x-slot:actions>
                    </x-lesson-screen>
                    @break
            @endswitch
        @endforeach

        <x-lesson-screen when="screen >= total" wire:key="grammar-done">
            <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                <span class="animate-badge-pop flex h-12 w-12 items-center justify-center rounded-full bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">@svg('heroicon-s-check', 'h-7 w-7')</span>
                <p class="font-display text-xl font-bold text-ink dark:text-ink-dark" x-text="missed.length === 0 ? 'All {{ $choiceCount }} right — nice!' : ({{ $choiceCount }} - missed.length) + ' of {{ $choiceCount }} right'"></p>
                <p x-show="missed.length > 0" x-cloak class="text-sm text-ink-soft dark:text-ink-soft-dark">The sentences you missed are in Mistakes.</p>
                <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $this->mission->title }}</p>
            </div>

            <x-slot:actions>
                <x-continue-button on-click="$wire.again()" wire-target="again">Practice again</x-continue-button>
                <a href="{{ $backUrl }}" wire:navigate class="text-center text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Back to Grammar</a>
            </x-slot:actions>
        </x-lesson-screen>
    </div>
</div>
