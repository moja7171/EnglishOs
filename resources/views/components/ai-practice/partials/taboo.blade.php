{{--
    Taboo with Teddy, no clock. On the learner's turn they see the word,
    explain it aloud without saying it and he guesses. On his turn he gives
    a clue, the learner says their guess, and each wrong one brings the next
    clue. Needs $practice, $card, $current.
--}}
@php
    $mine = $practice->isLearnersGameTurn();
    $clues = $current['clues'] ?? null;
    $guesses = $current['guesses'] ?? [];
    $tries = $current['tries'] ?? [];
    $over = isset($current['won']);
    $cluesShown = $clues === null ? [] : array_slice($clues, 0, min(count($clues), count($guesses) + 1));
    $newestClue = collect($cluesShown)->last();
    $lastTry = collect($tries)->last();
@endphp

<div wire:key="taboo-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if ($mine)
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— explain it, don’t say it</span></p>
        </div>
    @else
        <div class="flex items-center gap-2 bg-surface-sunken px-5 py-3 dark:bg-surface-sunken-dark">
            <x-teddy-avatar class="h-6 w-6" />
            <p class="text-sm font-bold text-ink dark:text-ink-dark">TEDDY’S TURN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— guess his word</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        @if ($mine || $over)
            <p class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">{{ $card['word'] }}</p>
        @endif

        @if ($mine)
            @if (! $over)
                <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ implode(' ', App\Services\TabooDeck::STARTERS) }}</p>
            @endif
            @if ($lastTry)
                <x-teddy-says wire:key="teddy-guess-{{ count($tries) }}" :say="'Is it '.$lastTry['guess'].'?'" :label="'Teddy’s guess '.count($tries).' of '.App\Models\AiPractice::TABOO_TRIES">
                    <p class="text-lg font-bold text-ink dark:text-ink-dark">Is it “{{ $lastTry['guess'] }}”?</p>
                </x-teddy-says>
                <div class="rounded-xl bg-success-soft p-3 text-start dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">I heard</p>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $lastTry['said'] }}</p>
                </div>
            @endif
        @elseif ($clues !== null)
            <x-teddy-says wire:key="teddy-clue-{{ count($cluesShown) }}" :say="$newestClue" label="Teddy’s clues">
                <div class="space-y-1.5">
                    @foreach ($cluesShown as $clue)
                        <p wire:key="clue-{{ $loop->index }}" class="text-base font-semibold text-ink dark:text-ink-dark">{{ $clue }}</p>
                    @endforeach
                </div>
            </x-teddy-says>
            @if ($guesses !== [])
                <div class="flex flex-wrap justify-center gap-2">
                    @foreach ($guesses as $guess)
                        <span wire:key="guess-{{ $loop->index }}" class="max-w-full truncate rounded-full bg-surface-sunken px-3 py-1 text-xs font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $guess }}</span>
                    @endforeach
                </div>
            @endif
        @else
            <button type="button" wire:click="aiGivesClues" wire:loading.attr="disabled" wire:target="aiGivesClues" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Try again</button>
        @endif

        @if ($over)
            <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ match (true) {
                $mine && $current['won'] => 'Teddy got it!',
                $mine => 'Teddy couldn’t guess it',
                $current['won'] => 'You got it!',
                default => 'Not this time',
            } }}</p>
        @elseif ($mine || $clues !== null)
            @if ($mine && $tries !== [])
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Not that. Explain it another way.</p>
            @elseif (! $mine)
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Say your guess</p>
            @endif
            <div wire:key="taboo-recorder-{{ $practice->position }}-{{ count($tries) + count($guesses) }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
        @endif
    </div>
</div>

@if ($over)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif
