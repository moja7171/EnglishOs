{{--
    Just a Minute with Teddy. On the learner's turn they talk on the topic,
    the target words they used light up, Teddy asks one question and they
    answer. On his turn he talks, the learner taps the words they hear and
    asks him one question. Needs $practice, $card, $current.
--}}
@php
    $mine = $practice->isLearnersGameTurn();
    $used = $current['used'] ?? [];
    $ticked = $current['ticked'] ?? [];
    $talk = $current['talk'] ?? null;
    $question = $current['question'] ?? null;
    $reply = $current['reply'] ?? null;
    $step = count(array_filter([$mine ? ($current['heard'] ?? null) : $talk, $question, $reply]));
@endphp

<div wire:key="minute-{{ $practice->position }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if ($mine)
        <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
            @svg('heroicon-o-microphone', 'h-5 w-5')
            <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— {{ $question ? 'answer Teddy' : 'talk for a minute' }}</span></p>
        </div>
    @else
        <div class="flex items-center gap-2 bg-surface-sunken px-5 py-3 dark:bg-surface-sunken-dark">
            <x-teddy-avatar class="h-6 w-6" />
            <p class="text-sm font-bold text-ink dark:text-ink-dark">TEDDY’S TURN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— tap the words you hear</span></p>
        </div>
    @endif

    <div class="space-y-4 p-5 text-center">
        <div>
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Topic</p>
            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['word'] }}</p>
        </div>

        <div class="flex flex-wrap justify-center gap-2">
            @foreach ($card['words'] as $word)
                @if ($mine)
                    <span wire:key="word-{{ $loop->index }}" @class([
                        'rounded-full px-3 py-1 text-sm font-bold',
                        'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => in_array($word, $used, true),
                        'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ! in_array($word, $used, true),
                    ])>{{ in_array($word, $used, true) ? '✓ ' : '' }}{{ $word }}</span>
                @else
                    <button type="button" wire:key="word-{{ $loop->index }}" wire:click="tickWord(@js($word))" wire:loading.attr="disabled" wire:target="tickWord" @disabled($talk === null) @class([
                        'min-h-10 cursor-pointer rounded-full px-3 py-1 text-sm font-bold disabled:opacity-50',
                        'bg-success text-white dark:bg-success-dark dark:text-ground-dark' => in_array($word, $ticked, true),
                        'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ! in_array($word, $ticked, true),
                    ])>{{ in_array($word, $ticked, true) ? '✓ ' : '' }}{{ $word }}</button>
                @endif
            @endforeach
        </div>

        @if ($mine && isset($current['heard']))
            <div class="rounded-xl bg-success-soft p-3 text-start dark:bg-success-soft-dark">
                @if (($current['used'] ?? []) !== [])
                    <x-feedback-cue wire:key="minute-cue-{{ $practice->position }}" kind="point" />
                @endif
                <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">I heard</p>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $current['heard'] }}</p>
            </div>
            <x-teddy-says wire:key="teddy-asks-{{ $practice->position }}" :say="$question" label="Teddy asks">
                <p class="text-base font-bold text-ink dark:text-ink-dark">{{ $question }}</p>
            </x-teddy-says>
        @elseif (! $mine && $talk !== null)
            <x-teddy-says wire:key="teddy-talks-{{ $practice->position }}" :say="$talk">
                <div x-data="{ showText: false }">
                    <button type="button" x-show="! showText" x-on:click="showText = true" class="block w-full cursor-pointer text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Show text</button>
                    @php
                        $sentences = $current['slip']['sentences'] ?? App\Models\AiPractice::sentencesOf($talk);
                        $slipAt = $current['slip']['slip'] ?? -1;
                        $picked = $current['slip_pick'] ?? null;
                    @endphp
                    <div x-show="showText" x-cloak class="space-y-1.5 text-start">
                        <p class="text-xs font-extrabold text-warning-ink">
                            @if ($picked === null)
                                Is there a mistake? Tap the sentence, or say there isn’t one.
                            @elseif ($picked === $slipAt)
                                {{ $slipAt === -1 ? '✓ Right, no mistake.' : '✓ You found it!' }}
                            @else
                                {{ $slipAt === -1 ? 'There was no mistake this time.' : 'Not that one.' }}
                            @endif
                        </p>
                        @foreach ($sentences as $index => $sentence)
                            <button type="button" wire:key="talk-sentence-{{ $index }}" @disabled($picked !== null) wire:click="pickTalkSlip({{ $index }})" @class([
                                'block w-full rounded-lg px-2 py-1.5 text-start text-sm font-semibold text-ink disabled:cursor-default dark:text-ink-dark',
                                'cursor-pointer bg-surface dark:bg-surface-dark' => $picked === null,
                                'bg-success-soft dark:bg-success-soft-dark' => $picked !== null && $index === $slipAt,
                                'bg-warning-soft' => $picked === $index && $index !== $slipAt,
                            ])>{{ $sentence }}@if ($picked !== null && $index === $slipAt)<span class="mt-0.5 block text-xs font-extrabold text-success dark:text-success-dark">Better: {{ $current['slip']['fix'] }}</span>@endif</button>
                        @endforeach
                        @if ($picked === null)
                            <button type="button" wire:click="pickTalkSlip(-1)" class="block w-full cursor-pointer rounded-lg border border-dashed border-line px-2 py-1.5 text-center text-sm font-extrabold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">No mistake</button>
                        @else
                            <x-feedback-cue wire:key="talk-slip-cue" :kind="$picked === $slipAt ? 'right' : 'wrong'" />
                        @endif
                    </div>
                </div>
            </x-teddy-says>
        @elseif (! $mine)
            <button type="button" wire:click="aiTalks" wire:loading.attr="disabled" wire:target="aiTalks" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">Try again</button>
        @endif

        @if ($reply !== null)
            @if ($mine)
                <div class="rounded-xl bg-success-soft p-3 text-start dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">You said</p>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $reply }}</p>
                </div>
            @else
                <div class="rounded-xl bg-success-soft p-3 text-start dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">You asked</p>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $question }}</p>
                </div>
                <x-teddy-says wire:key="teddy-replies-{{ $practice->position }}" :say="$reply">
                    <p class="text-base font-bold text-ink dark:text-ink-dark">{{ $reply }}</p>
                </x-teddy-says>
            @endif
        @elseif ($mine || $talk !== null)
            @if (! $mine)
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Ask Teddy one question</p>
            @endif
            <div wire:key="minute-recorder-{{ $practice->position }}-{{ $step }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
            </div>
        @endif
    </div>
</div>

@if ($reply !== null)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif
