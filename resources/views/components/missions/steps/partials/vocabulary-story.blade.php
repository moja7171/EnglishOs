{{--
    Read-only highlighted story text for one day's Vocabulary Builder —
    no click, no selection (that whole mechanic was removed; today's
    words are already fixed by the mission's own seeded content). The
    story itself only highlights each word (no inline meaning — that
    used to sit in parentheses right after the word and overflowed the
    box on longer meanings); the full breakdown (word type, meaning, an
    example) is a separate card list below, in colors a learner can
    actually read at a glance, not the meta-info grey used elsewhere.

    @param list<array{type: string, value: string}> $segments
    @param list<array{phrase: string, meaning: string, pos?: string, example?: string}> $words
--}}
<p class="text-sm leading-loose text-ink dark:text-ink-dark">
    @foreach ($segments as $segment)
        @if ($segment['type'] === 'text')
            {{ $segment['value'] }}
        @else
            <mark class="rounded bg-accent/15 px-1 font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $segment['value'] }}</mark>
        @endif
    @endforeach
</p>

<div class="mt-4 space-y-2.5 border-t border-line pt-4 dark:border-line-dark">
    @foreach ($words as $word)
        <div class="rounded-xl border border-line p-2.5 dark:border-line-dark">
            <p class="flex items-baseline gap-2">
                <span class="text-sm font-bold text-ink dark:text-ink-dark">{{ $word['phrase'] }}</span>
                @if (! empty($word['pos']))
                    <span class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $word['pos'] }}</span>
                @endif
            </p>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $word['meaning'] }}</p>
            @if (! empty($word['example']))
                <p class="mt-1 text-sm text-ink-soft italic dark:text-ink-soft-dark">"{{ $word['example'] }}"</p>
            @endif
        </div>
    @endforeach
</div>
