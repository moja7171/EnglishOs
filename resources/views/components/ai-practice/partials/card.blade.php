{{--
    A word, grammar or speaking card with Teddy. On the learner's turn they
    answer aloud and Teddy checks it; on his turn he says the deck's answer
    and the learner listens. Needs $practice, $card, $current, $stage.
--}}
@php
    $mine = $practice->learnerAnswers();
    $isTalk = ($card['type'] ?? null) === 'talk';
    $isSpeaking = $stage === App\Models\AiPractice::STAGE_SPEAKING;
    $task = match (true) {
        $isTalk => 'talk',
        $isSpeaking => 'answer',
        default => 'say it',
    };
@endphp

<div wire:key="card-{{ $stage }}-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if ($mine)
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— {{ $task }}</span></p>
        </div>
    @else
        <div class="flex items-center gap-2 bg-surface-sunken px-5 py-3 dark:bg-surface-sunken-dark">
            <x-teddy-avatar class="h-6 w-6" />
            <p class="text-sm font-bold text-ink dark:text-ink-dark">TEDDY’S TURN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— listen</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        @if ($stage === App\Models\AiPractice::STAGE_VOCABULARY)
            @if (($card['prompt_lang'] ?? 'fa') === 'en')
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Which word is this?</p>
                <p class="font-display text-2xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
            @else
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it in English</p>
                <p dir="rtl" class="font-display text-3xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
            @endif
            @if ($mine && $current === null && ($hints = App\Models\AiPractice::wordHints($card)) !== [])
                <div wire:key="hint-{{ $practice->position }}" x-data="{ shown: 0 }" class="space-y-2">
                    @foreach ($hints as $hint)
                        <p x-show="shown > {{ $loop->index }}" x-cloak class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $hint }}</p>
                    @endforeach
                    <button type="button" x-show="shown < {{ count($hints) }}" x-on:click="shown++" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">💡 Hint</button>
                </div>
            @endif
        @elseif ($stage === App\Models\AiPractice::STAGE_GRAMMAR)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it correctly</p>
            <p class="text-lg font-semibold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
        @elseif ($isTalk)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Talk for a minute using these words</p>
            <div class="flex flex-wrap justify-center gap-2">
                @foreach ($card['words'] as $word)
                    <span wire:key="talk-{{ $loop->index }}" class="rounded-full bg-accent-soft px-4 py-1.5 font-display text-lg font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $word }}</span>
                @endforeach
            </div>
        @else
            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['question'] }}</p>
        @endif

        @if (! $mine)
            @php
                $teddyLine = $practice->teddyCardLine();
                $judged = $current['judged'] ?? null;
            @endphp
            <x-teddy-says wire:key="ai-says-{{ $stage }}-{{ $practice->position }}" :say="$teddyLine">
                <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $teddyLine }}</p>
            </x-teddy-says>
            @if ($judged === null)
                <div class="space-y-2">
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Did Teddy get it right?</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="judgeTeddy('right')" wire:loading.attr="disabled" wire:target="judgeTeddy" class="cursor-pointer rounded-2xl bg-success-soft py-3 text-sm font-extrabold text-success disabled:opacity-50 dark:bg-success-soft-dark dark:text-success-dark">✓ Right</button>
                        <button type="button" wire:click="judgeTeddy('mistake')" wire:loading.attr="disabled" wire:target="judgeTeddy" class="cursor-pointer rounded-2xl bg-warning-soft py-3 text-sm font-extrabold text-warning-ink disabled:opacity-50">✗ Mistake</button>
                    </div>
                </div>
            @else
                <div wire:key="judged-{{ $stage }}-{{ $practice->position }}" @class(['rounded-xl p-3 text-start', 'bg-success-soft dark:bg-success-soft-dark' => $current['right_call'], 'bg-warning-soft' => ! $current['right_call']])>
                    <x-feedback-cue :kind="$current['right_call'] ? 'right' : 'wrong'" />
                    <p @class(['text-sm font-bold', 'text-success dark:text-success-dark' => $current['right_call'], 'text-warning-ink' => ! $current['right_call']])>
                        @if ($current['teddy_wrong'])
                            {{ $current['right_call'] ? '✓ You caught it! Teddy slipped.' : 'Teddy slipped, and you missed it.' }}
                        @else
                            {{ $current['right_call'] ? '✓ Right, Teddy had it.' : 'Teddy was right this time.' }}
                        @endif
                    </p>
                    <p class="mt-1 flex items-center gap-2 text-base font-semibold text-ink dark:text-ink-dark">{{ $card['answer'] }} <x-speak-button :text="$card['answer']" voice="teddy" /></p>
                </div>
            @endif
        @elseif ($current === null)
            <div wire:key="recorder-{{ $stage }}-{{ $practice->position }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
        @else
            @php $wentWell = $current['correct'] !== false; @endphp
            @if ($current['correct'] !== null)
                <x-feedback-cue wire:key="cue-{{ $stage }}-{{ $practice->position }}" :kind="$wentWell ? 'right' : 'wrong'" />
            @endif
            <div @class(['rounded-xl p-3', 'bg-success-soft dark:bg-success-soft-dark' => $wentWell, 'bg-warning-soft' => ! $wentWell])>
                <p @class(['text-xs font-semibold', 'text-ink-soft dark:text-ink-soft-dark' => $wentWell, 'text-warning-ink' => ! $wentWell])>I heard</p>
                <p @class(['text-base font-semibold', 'text-ink dark:text-ink-dark' => $wentWell, 'text-warning-ink' => ! $wentWell])>{{ $current['heard'] }}</p>
                @if ($current['correct'] === false)
                    <p class="mt-2 text-sm font-bold text-warning-ink">Not yet — it’s {{ $card['answer'] }}</p>
                @elseif ($current['correct'] === true)
                    <p class="mt-2 text-sm font-bold text-success dark:text-success-dark">✓ Right</p>
                @endif
            </div>
            @if (filled($current['feedback'] ?? null))
                <p dir="auto" class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark">{{ $current['feedback'] }}</p>
            @endif
        @endif
    </div>
</div>

@if ($mine ? $current !== null : isset($current['judged']))
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif

<p class="text-center text-[11px] text-ink-faint dark:text-ink-faint-dark">{{ $card['source'] ?? '' }}</p>
