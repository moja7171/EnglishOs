{{--
    One word in the Words tab: its status dot, the word with its Persian
    meaning, and one simple sentence with the word picked out. With "Cover
    the words" on (state `cover` in the tab) the word is a blank until
    tapped, then the learner says if they knew it.

    Needs $word, $courseFa, $commonness (by word id), $statusDot,
    $removeButton, $show (a JS expression for x-show) and $keyPrefix.
--}}
@php
    $meaningFa = $word->meaning_fa ?: ($courseFa[mb_strtolower($word->word)] ?? null);
    $sentence = $word->rowSentence();
    $parts = $sentence ? $word->splitAround($sentence) : null;
    $blank = $parts ? mb_substr($parts['hit'], 0, 1).'…' : null;
@endphp
<div wire:key="{{ $keyPrefix }}-{{ $word->id }}" role="button" tabindex="0" x-show="({{ $show }}) && ! gone.w{{ $word->id }}" x-on:click="if (! edit) { sheet = 'w{{ $word->id }}' }" x-on:keydown.enter="if (! edit) { sheet = 'w{{ $word->id }}' }" class="flex w-full cursor-pointer items-start gap-3 py-3 text-left">
    <span class="{{ $statusDot($word->studyStatus()) }} mt-2 h-2 w-2 shrink-0 rounded-full" title="{{ $word->strengthLabel() }}"></span>
    <span class="min-w-0 flex-1">
        <span class="flex flex-wrap items-baseline gap-x-2">
            <span class="text-base font-extrabold text-ink dark:text-ink-dark">{{ $word->word }}</span>
            @if (filled($word->pos))
                <span class="text-[11px] font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $word->pos }}</span>
            @endif
            @if ($level = $commonness[$word->id] ?? null)
                <span class="inline-flex gap-0.5 self-center" title="{{ ['Less common', 'Common', 'Very common'][$level - 1] }}" role="img" aria-label="{{ ['Less common', 'Common', 'Very common'][$level - 1] }}">@foreach ([1, 2, 3] as $dot)<span @class(['h-1.5 w-1.5 rounded-full', 'bg-accent dark:bg-accent-dark' => $dot <= $level, 'bg-line dark:bg-line-dark' => $dot > $level])></span>@endforeach</span>
            @endif
        </span>
        @if (filled($meaningFa))
            <span dir="rtl" class="font-fa block text-left text-sm text-ink-soft dark:text-ink-soft-dark">{{ $meaningFa }}</span>
        @endif
        @if ($sentence)
            <span class="mt-1 block text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">
                @if ($parts)
                    <span x-show="! cover || revealed[{{ $word->id }}]">{{ $parts['before'] }}<mark class="rounded bg-accent-soft px-1 font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $parts['hit'] }}</mark>{{ $parts['after'] }}</span>
                    <span x-show="cover && ! revealed[{{ $word->id }}]" x-cloak>{{ $parts['before'] }}<button type="button" x-on:click.stop="revealed[{{ $word->id }}] = true" aria-label="Show the word" class="inline-block min-w-16 cursor-pointer border-b-2 border-dashed border-accent px-1 text-center font-extrabold text-accent-ink dark:border-accent-dark dark:text-accent-ink-dark">{{ $blank }}</button>{{ $parts['after'] }}</span>
                @else
                    {{ $sentence }}
                @endif
            </span>
            @if ($parts)
                <span x-show="cover && revealed[{{ $word->id }}] && ! rated[{{ $word->id }}]" x-cloak class="mt-2 flex gap-2">
                    <button type="button" x-on:click.stop="rated[{{ $word->id }}] = true; window.eosFeedback?.right(); $wire.rateWord({{ $word->id }}, 'got')" class="flex-1 cursor-pointer rounded-2xl bg-success-soft py-2 text-sm font-extrabold text-success dark:bg-success-soft-dark dark:text-success-dark">Got it</button>
                    <button type="button" x-on:click.stop="rated[{{ $word->id }}] = true; window.eosFeedback?.wrong(); $wire.rateWord({{ $word->id }}, 'not')" class="flex-1 cursor-pointer rounded-2xl bg-warning-soft py-2 text-sm font-extrabold text-warning-ink">Not yet</button>
                </span>
            @endif
        @endif
    </span>
    <button type="button" x-show="! edit" data-text="{{ $word->word }}" x-on:click.stop="window.eosVoice?.teddy($el.dataset.text)" aria-label="Read aloud" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-speaker-wave', 'h-4 w-4')</button>
    <button type="button" x-show="edit" x-cloak x-on:click.stop="removeWordNow({{ $word->id }}, @js($word->word))" aria-label="Remove {{ $word->word }}" class="{{ $removeButton }}">@svg('heroicon-m-x-mark', 'h-4 w-4')</button>
</div>
