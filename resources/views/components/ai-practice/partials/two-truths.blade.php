{{--
    Two Truths with Teddy. On the learner's turn they mark their lie, say
    three things aloud and he guesses. On his turn he says three things,
    the learner may ask two questions, then picks the lie. Needs $practice,
    $card, $current.
--}}
@php
    $mine = $practice->isLearnersGameTurn();
    $lie = $current['lie'] ?? null;
    $statements = $current['statements'] ?? null;
    $asked = $current['asked'] ?? [];
    $picked = $current['picked'] ?? null;
    $heard = $current['heard'] ?? null;
    $turnOver = $heard !== null || $picked !== null;
    $numbers = range(1, App\Models\PairGame::STATEMENTS);
@endphp

<div wire:key="truths-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if ($mine)
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— say 3 things, one is a lie</span></p>
        </div>
    @else
        <div class="flex items-center gap-2 bg-surface-sunken px-5 py-3 dark:bg-surface-sunken-dark">
            <x-teddy-avatar class="h-6 w-6" />
            <p class="text-sm font-bold text-ink dark:text-ink-dark">TEDDY’S TURN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— find his lie</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        <div>
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Topic</p>
            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['word'] }}</p>
            @if (filled($card['hint'] ?? null))
                <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $card['hint'] }}</p>
            @endif
        </div>

        @if ($mine && $heard === null)
            <div>
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Which one will be your lie?</p>
                <div class="mt-1.5 grid grid-cols-3 gap-2">
                    @foreach ($numbers as $number)
                        <button type="button" wire:key="lie-{{ $number }}" wire:click="markLie({{ $number }})" wire:loading.attr="disabled" wire:target="markLie" @class(['h-12 cursor-pointer rounded-xl border-2 text-lg font-extrabold', 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $lie === $number, 'border-line text-ink dark:border-line-dark dark:text-ink-dark' => $lie !== $number])>{{ $number }}</button>
                    @endforeach
                </div>
            </div>
            @if ($lie !== null)
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Say them: One… Two… Three…</p>
                <div wire:key="truths-recorder-{{ $practice->position }}" class="flex flex-col items-center gap-2">
                    <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                    <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
                </div>
            @endif
        @elseif ($mine)
            <div class="rounded-xl bg-success-soft p-3 text-start dark:bg-success-soft-dark">
                <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">I heard</p>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $heard }}</p>
            </div>
            <div wire:key="teddy-guess-{{ $practice->position }}" x-data x-init="window.eosVoice?.speak(@js($current['line']))" class="rounded-xl bg-surface-sunken p-3 dark:bg-surface-sunken-dark">
                <p class="text-base font-bold text-ink dark:text-ink-dark">{{ $current['line'] }}</p>
                <div class="mt-2"><x-speak-button :text="$current['line']" /></div>
            </div>
            <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $current['pick'] === $lie ? 'Teddy found it!' : 'You tricked Teddy!' }}</p>
            <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Your lie: number {{ $lie }}</p>
        @elseif ($statements !== null)
            <div wire:key="teddy-three-{{ $practice->position }}" x-data x-init="window.eosVoice?.speak(@js(implode(' ', $statements)))" class="space-y-2">
                @foreach ($statements as $index => $statement)
                    @php $number = $index + 1; @endphp
                    <button type="button" wire:key="statement-{{ $number }}" wire:click="pickLie({{ $number }})" wire:loading.attr="disabled" wire:target="pickLie" @disabled($picked !== null) @class([
                        'flex w-full cursor-pointer items-start gap-2.5 rounded-xl border-2 p-3 text-start text-sm font-semibold text-ink disabled:cursor-default dark:text-ink-dark',
                        'border-success bg-success-soft dark:border-success-dark dark:bg-success-soft-dark' => $picked !== null && $number === $lie,
                        'border-warning bg-warning-soft' => $picked === $number && $number !== $lie,
                        'border-line dark:border-line-dark' => $picked === null || ($number !== $lie && $picked !== $number),
                    ])>
                        <span class="shrink-0 font-extrabold">{{ $number }}</span>
                        <span class="flex-1">{{ $statement }}</span>
                        @if ($picked !== null && $number === $lie)
                            <span class="shrink-0 text-xs font-extrabold text-success dark:text-success-dark">The lie</span>
                        @endif
                    </button>
                @endforeach
                <x-speak-button :text="implode(' ', $statements)" />
            </div>

            @if ($picked === null)
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Tap the lie</p>
            @else
                <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $picked === $lie ? 'You found it!' : 'Teddy tricked you!' }}</p>
            @endif

            @foreach ($asked as $row)
                <div wire:key="asked-{{ $loop->index }}" class="space-y-1 rounded-xl bg-surface-sunken p-3 text-start text-sm dark:bg-surface-sunken-dark">
                    <p class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $row['q'] }}</p>
                    <p class="font-bold text-ink dark:text-ink-dark">{{ $row['a'] }}</p>
                </div>
            @endforeach
            @if (filled($asked))
                <div wire:key="teddy-answers-{{ count($asked) }}" x-data x-init="window.eosVoice?.speak(@js(collect($asked)->last()['a']))"></div>
            @endif

            @if ($picked === null && count($asked) < App\Models\AiPractice::TRUTHS_QUESTIONS)
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Ask Teddy a question first?</p>
                <div wire:key="truths-recorder-{{ $practice->position }}-{{ count($asked) }}" class="flex flex-col items-center gap-2">
                    <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                    <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
                </div>
            @endif
        @else
            <button type="button" wire:click="aiTellsThree" wire:loading.attr="disabled" wire:target="aiTellsThree" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Try again</button>
        @endif
    </div>
</div>

@if ($turnOver)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif
