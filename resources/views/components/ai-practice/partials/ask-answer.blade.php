{{--
    Ask & Answer with Teddy. On the learner's turn they ask a question from
    a Persian line and Teddy answers it; on his turn Teddy asks and the
    learner answers from a Persian line. The sentence is checked on the
    spot; after a miss the learner repeats the right one. Needs $practice,
    $card, $current and the page's $audioFile.
--}}
@php
    $learnerAsks = $practice->isLearnersGameTurn();
    $target = $practice->askTarget();
    $step = $practice->askStep();
    $finished = $step['status'] !== null;
    $missed = $step['tries'] >= 1 && ! $finished;
    $levels = $practice->askLevels();
    $levelKey = $learnerAsks ? 'q' : 'a';
    $teddyLine = $practice->askTeddyLine();
@endphp

@if ($target)
    <div wire:key="ask-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— {{ $learnerAsks ? 'ask Teddy' : 'answer Teddy' }}</span></p>
        </div>

        <div class="space-y-4 p-5 text-center">
            @if (! $learnerAsks)
                <x-teddy-says wire:key="ask-teddy-{{ $practice->position }}" :say="$teddyLine" blur>
                    <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $teddyLine }}</p>
                </x-teddy-says>
            @endif

            @if ($learnerAsks && filled($card['context_fa'] ?? null))
                <p dir="rtl" class="rounded-xl bg-surface-sunken px-3 py-2 text-sm font-bold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">{{ $card['context_fa'] }}</p>
            @endif

            @if ($missed)
                <p class="text-xs font-extrabold tracking-wider text-warning-ink uppercase">Say it like this</p>
                <p class="font-display text-2xl leading-tight font-extrabold break-words text-ink dark:text-ink-dark" dir="ltr">{{ $step['correct'] }}</p>
                @if (filled($step['why']))
                    <p dir="rtl" class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $step['why'] }}</p>
                @endif
                <button type="button" x-on:click="window.eosVoice?.speak(@js($step['correct']))" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">🔊 Listen</button>
            @elseif (! $finished)
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $learnerAsks ? 'Ask in English' : 'Answer in English' }}</p>
                <p dir="rtl" class="font-display text-2xl leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $target['fa'] }}</p>
                @if (filled($target['fa_extra']))
                    <p dir="rtl" class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">✨ {{ $target['fa_extra'] }} <span class="text-xs">(اختیاری)</span></p>
                @endif
            @endif

            @if (! $finished)
                <div wire:ignore wire:key="ask-rec-{{ $practice->position }}-{{ $step['tries'] }}-{{ $step['unchecked'] }}" class="flex flex-col items-center gap-2">
                    <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                </div>
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Checking…</p>

                <div class="grid grid-cols-3 items-center gap-2 text-sm font-bold">
                    <button type="button" wire:click="setAskLevel('{{ $levelKey }}', {{ max(1, $levels[$levelKey] - 1) }})" @disabled($levels[$levelKey] <= 1) class="h-11 cursor-pointer rounded-xl text-ink-soft disabled:cursor-default disabled:opacity-40 dark:text-ink-soft-dark">− Easier</button>
                    <span class="text-center text-xs font-extrabold text-ink-faint dark:text-ink-faint-dark">Level {{ $levels[$levelKey] }}</span>
                    <button type="button" wire:click="setAskLevel('{{ $levelKey }}', {{ min(3, $levels[$levelKey] + 1) }})" @disabled($levels[$levelKey] >= 3) class="h-11 cursor-pointer rounded-xl text-accent-ink disabled:cursor-default disabled:opacity-40 dark:text-accent-ink-dark">Harder +</button>
                </div>
            @else
                @if (in_array($step['status'], ['ok', 'fixed'], true))
                    <x-feedback-cue wire:key="ask-cue-{{ $practice->position }}" kind="right" />
                    <div class="rounded-xl bg-success-soft p-3 dark:bg-success-soft-dark">
                        <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $step['status'] === 'ok' ? 'Well said' : 'Good — fixed it' }}</p>
                        <p class="text-base font-semibold text-ink dark:text-ink-dark" dir="ltr">{{ $step['heard'] }}</p>
                        @if ($step['extra'])
                            <p class="mt-1 text-xs font-bold text-success dark:text-success-dark">✨ You added more detail</p>
                        @endif
                    </div>
                @elseif ($step['status'] === 'passed')
                    <div class="rounded-xl bg-warning-soft p-3">
                        <p class="text-xs font-semibold text-warning-ink">Say it like this next time</p>
                        <p class="text-base font-semibold text-ink dark:text-ink-dark" dir="ltr">{{ $step['correct'] }}</p>
                        @if (filled($step['why']))
                            <p dir="rtl" class="mt-1 text-sm font-semibold text-warning-ink">{{ $step['why'] }}</p>
                        @endif
                    </div>
                @endif

                @if ($learnerAsks)
                    <x-teddy-says wire:key="ask-teddy-{{ $practice->position }}-reply" :say="$teddyLine" blur>
                        <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $teddyLine }}</p>
                    </x-teddy-says>
                @endif
            @endif
        </div>
    </div>

    @if ($finished)
        <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
    @else
        <button type="button" wire:click="passAskStep" wire:loading.attr="disabled" wire:target="passAskStep,submitAnswer" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
    @endif
@endif
