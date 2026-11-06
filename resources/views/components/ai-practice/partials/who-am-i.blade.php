{{--
    Who am I with Teddy. On even turns he holds a secret and the learner asks
    aloud; on odd turns the learner holds it, he asks and they tap the
    answer. Needs $practice, $card, $current.
--}}
@php
    $aiHolds = $practice->aiHoldsSecret();
    $asked = $current['qa'] ?? [];
    $finished = isset($current['won']);
    $question = ! $aiHolds && ! $finished ? (collect($asked)->last()['q'] ?? null) : null;
    $turnKey = $practice->position.'-'.count($asked);
@endphp

<div wire:key="who-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if ($aiHolds)
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— guess the secret</span></p>
        </div>
    @else
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— you are the secret</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        @if ($aiHolds && ! $finished)
            <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">It’s {{ $card['category'] ?? 'a secret' }} · question {{ count($asked) + 1 }} of {{ App\Models\AiPractice::MAX_QUESTIONS }}</p>
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Ask a yes/no question: Are you…? Do you…? Can you…?</p>
            <div wire:key="who-recorder-{{ $turnKey }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
        @elseif (! $aiHolds)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your secret</p>
            <p class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">{{ $card['word'] }}</p>
            @if ($question)
                <div wire:key="ai-question-{{ $turnKey }}" x-data x-init="window.eosVoice?.speak(@js($question))" class="rounded-xl bg-surface-sunken p-3 dark:bg-surface-sunken-dark">
                    <p class="text-lg font-bold text-ink dark:text-ink-dark">{{ $question }}</p>
                    <div class="mt-2"><x-speak-button :text="$question" /></div>
                </div>
            @elseif (! $finished)
                <button type="button" wire:click="aiAsks" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Try again</button>
            @endif
        @endif

        @if ($finished)
            <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">
                {{ match (true) {
                    $aiHolds && $current['won'] => 'You got it!',
                    $aiHolds => 'Not this time',
                    $current['won'] => 'Teddy got it!',
                    default => 'Teddy couldn’t guess it',
                } }}
            </p>
            <p class="text-base font-semibold text-ink-soft dark:text-ink-soft-dark">The secret: {{ $card['word'] }}</p>
        @endif

        @if ($asked !== [])
            <ol class="space-y-1.5 text-start">
                @foreach (array_reverse($asked) as $row)
                    @if ($row['a'] !== null)
                        <li wire:key="asked-{{ $loop->index }}" class="flex items-center justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 text-sm text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">
                            <span class="min-w-0">{{ $row['q'] }}</span>
                            <span class="shrink-0 font-extrabold capitalize">{{ $row['a'] }}</span>
                        </li>
                    @endif
                @endforeach
            </ol>
        @endif
    </div>
</div>

@if ($finished)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@elseif (! $aiHolds && $question)
    <div class="mt-auto space-y-2">
        <div class="grid grid-cols-3 gap-2">
            @foreach (App\Models\PairGame::ANSWERS as $answer)
                <button type="button" wire:key="answer-{{ $answer }}" wire:click="answerAi('{{ $answer }}')" wire:loading.attr="disabled" wire:target="answerAi,aiGotIt" class="min-h-14 cursor-pointer rounded-2xl border border-line bg-surface font-extrabold text-ink capitalize disabled:opacity-50 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">{{ $answer }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="aiGotIt" wire:loading.attr="disabled" wire:target="answerAi,aiGotIt" class="block min-h-14 w-full cursor-pointer rounded-full bg-success font-extrabold text-white disabled:opacity-50 dark:bg-success-dark dark:text-ground-dark">Teddy got it</button>
    </div>
@endif
