{{--
    Story Chain with Teddy. The learner records a line with their word and he
    goes on at once; his last line ends the story. Skip lets him go on alone.
    Needs $practice, $card, $current.
--}}
@php
    $lines = $current['lines'] ?? [];
    $ended = isset($current['ended']);
    $lastLine = collect($lines)->last();
@endphp

<div wire:key="story" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
    @if (filled($card['image'] ?? null))
        <img src="{{ $card['image'] }}" alt="" class="h-28 w-full object-cover">
    @endif

    <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
        @svg($ended ? 'heroicon-o-book-open' : 'heroicon-o-microphone', 'h-5 w-5')
        <p class="text-sm font-bold">{{ $ended ? 'YOUR STORY' : 'YOUR TURN' }} @unless ($ended)<span class="font-semibold opacity-90">— say one or two sentences</span>@endunless</p>
    </div>

    <div class="space-y-3 p-5">
        <p class="text-sm font-semibold text-ink-soft italic dark:text-ink-soft-dark">{{ $card['line'] }}</p>

        @foreach ($lines as $line)
            <div wire:key="line-{{ $loop->index }}" @class([
                'rounded-xl p-3 text-sm font-semibold text-ink dark:text-ink-dark',
                'ms-6 bg-success-soft dark:bg-success-soft-dark' => $line['who'] === 'you',
                'me-6 bg-surface-sunken dark:bg-surface-sunken-dark' => $line['who'] !== 'you',
            ])>
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $line['who'] === 'you' ? 'You' : 'Teddy' }}</p>
                {{ $line['text'] }}
            </div>
        @endforeach

        @if ($lastLine)
            <div wire:key="teddy-tells-{{ count($lines) }}" x-data x-init="window.eosVoice?.teddy(@js($lastLine['text']), { delay: 900 })" class="text-center"><x-speak-button :text="$lastLine['text']" voice="teddy" /></div>
        @endif

        @unless ($ended)
            <div class="space-y-2 text-center">
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Use this word</p>
                <span class="inline-block rounded-full bg-accent-soft px-4 py-1.5 font-display text-lg font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $card['word'] }}</span>
                <div wire:key="story-recorder-{{ $practice->position }}" class="flex flex-col items-center gap-2 pt-1">
                    <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAnswer" file-name="answer.webm" />
                    <p wire:loading wire:target="submitAnswer" class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Listening…</p>
                </div>
            </div>
        @endunless
    </div>
</div>

@if ($ended)
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next</button>
@else
    <button type="button" wire:click="next" wire:loading.attr="disabled" wire:target="next" class="mx-auto block min-h-11 cursor-pointer text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Skip</button>
@endif
