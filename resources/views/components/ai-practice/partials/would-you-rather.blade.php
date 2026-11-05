{{--
    Would You Rather with Tara: the learner picks a side and guesses hers,
    she says why, then the learner says why aloud. Needs $practice,
    $card, $current.
--}}
<div wire:key="rather-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— pick a side</span></p>
        </div>

    <div class="space-y-4 p-5 text-center">
        <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">Would you rather…</p>

        @if ($current === null)
            <div x-data="{ mine: null, guess: null }" class="space-y-4">
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">You</p>
                    @foreach (App\Models\PairGame::SIDES as $side)
                        <button type="button" wire:key="mine-{{ $side }}" x-on:click="mine = @js($side)" x-bind:class="mine === @js($side) && 'border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark'" class="block min-h-12 w-full cursor-pointer rounded-2xl border-2 border-line px-4 py-2 font-bold text-ink dark:border-line-dark dark:text-ink-dark">{{ $card[$side] }}</button>
                    @endforeach
                </div>
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">What will Tara pick?</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach (App\Models\PairGame::SIDES as $side)
                            <button type="button" wire:key="guess-{{ $side }}" x-on:click="guess = @js($side)" x-bind:class="guess === @js($side) && 'border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark'" class="min-h-12 cursor-pointer rounded-2xl border-2 border-line px-3 py-2 text-sm font-bold text-ink dark:border-line-dark dark:text-ink-dark">{{ $card[$side] }}</button>
                        @endforeach
                    </div>
                </div>
                <button type="button" x-bind:disabled="! mine || ! guess" x-on:click="$wire.pickSide(mine, guess)" wire:loading.attr="disabled" wire:target="pickSide" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-40 dark:bg-accent-dark">Show</button>
            </div>
        @else
            <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">You: {{ $card[$current['mine']] }}</p>
            <div wire:key="ai-reason-{{ $practice->position }}" x-data x-init="window.eosVoice?.speak(@js($current['reason']))" class="rounded-xl bg-surface-sunken p-3 dark:bg-surface-sunken-dark">
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Tara: {{ $card[$current['ai']] }}</p>
                <p class="mt-1 text-base font-semibold text-ink dark:text-ink-dark">{{ $current['reason'] }}</p>
                <div class="mt-2"><x-speak-button :text="$current['reason']" /></div>
            </div>
            <p @class(['text-sm font-bold', 'text-success dark:text-success-dark' => $current['guess'] === $current['ai'], 'text-ink-soft dark:text-ink-soft-dark' => $current['guess'] !== $current['ai']])>{{ $current['guess'] === $current['ai'] ? '✓ You guessed right' : 'Not this time' }}</p>

            @if (! isset($current['heard']))
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Your turn: I’d rather… because…</p>
                <div wire:key="rather-recorder-{{ $practice->position }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
            @else
                <div class="rounded-xl bg-success-soft p-3 dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">I heard</p>
                    <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $current['heard'] }}</p>
                </div>
                @if (filled($current['feedback'] ?? null))
                    <p dir="auto" class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark">{{ $current['feedback'] }}</p>
                @endif
            @endif
        @endif
    </div>
</div>

@if (isset($current['heard']))
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@elseif ($current !== null)
    <button type="button" wire:click="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif
