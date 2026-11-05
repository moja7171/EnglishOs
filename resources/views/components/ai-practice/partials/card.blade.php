{{--
    A word, grammar or speaking card with the AI. On the learner's turn they
    answer aloud and the AI checks it; on the AI's turn it says the deck's
    answer and the learner listens. Needs $practice, $card, $current, $stage.
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
            @svg('heroicon-o-sparkles', 'h-5 w-5 text-ink-soft dark:text-ink-soft-dark')
            <p class="text-sm font-bold text-ink dark:text-ink-dark">AI’S TURN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— listen</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        @if ($stage === App\Models\AiPractice::STAGE_VOCABULARY)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it in English</p>
            <p dir="rtl" class="font-display text-3xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
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
            <div wire:key="ai-says-{{ $stage }}-{{ $practice->position }}" x-data x-init="window.eosVoice?.speak(@js($card['answer']))" class="rounded-xl bg-success-soft p-3 dark:bg-success-soft-dark">
                <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $card['answer'] }}</p>
                <div class="mt-2"><x-speak-button :text="$card['answer']" /></div>
            </div>
        @elseif ($current === null)
            <div wire:key="recorder-{{ $stage }}-{{ $practice->position }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
        @else
            @php $wentWell = $current['correct'] !== false; @endphp
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

@if (! $mine || $current !== null)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif

<p class="text-center text-[11px] text-ink-faint dark:text-ink-faint-dark">{{ $card['source'] ?? '' }}</p>
