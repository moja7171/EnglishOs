@props([
    // Collection<ErrorPatternReview> — categories the learner has
    // genuinely stopped making (User::masteredErrorPatterns()).
    'mastered',
    // Collection<ErrorPatternReview> — the softer signal: still tracked,
    // just absent lately (User::fadingErrorPatterns()).
    'fading' => null,
    // On Mission Result the list is a celebration beat between other
    // cards, so it draws its own box; on Progress it sits inside one.
    'boxed' => true,
])

@php
    $fading = $fading ?? collect();
    // A category slug is stored hyphenated, the way Error Log's AI is
    // asked to emit it ("third-person-s"). Nothing else in the app has
    // needed to show one to a learner before.
    $label = fn (string $category) => \Illuminate\Support\Str::of($category)->replace('-', ' ')->ucfirst()->toString();
@endphp

{{--
    "Mistakes you no longer make" — the cheapest possible answer to the
    rule governing this whole epic: the learner must be able to see they
    are improving WITHOUT sitting anything that feels like a test. Every
    number is deliberately absent. No count, no percentage, no score, no
    level — just named, concrete, positive evidence in the learner's own
    words. Adding a "4 fixed / 11 remaining" line here would quietly turn
    the one reassuring surface in the app back into a scoreboard.

    See User::masteredErrorPatterns() for why a category has to survive
    both the drill and the learner's own unprompted writing before it can
    appear here — an unearned entry would make every other entry cheap.
--}}
<div {{ $attributes->merge(['class' => $boxed ? 'rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark' : '']) }}>
    <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
        @svg('heroicon-o-sparkles', 'h-3.5 w-3.5')
        Mistakes you no longer make
    </p>

    @if ($mastered->isEmpty() && $fading->isEmpty())
        {{-- The empty state is the one most likely to be seen, and the
             one most able to deflate someone. It promises, it doesn't
             report a lack. --}}
        <p class="mt-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
            Nothing here yet — this fills up as you go. Every mistake you stop repeating gets its own line.
        </p>
    @else
        <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">
            You used to get these wrong. You don't anymore.
        </p>

        <ul class="mt-3 space-y-2.5">
            @foreach ($mastered as $pattern)
                <li class="rounded-xl bg-success-soft p-3 dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-success dark:text-success-dark">{{ $label($pattern->category) }}</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                        <span class="text-ink-faint line-through decoration-ink-faint dark:text-ink-faint-dark">{{ $pattern->last_error }}</span>
                        <span class="mx-1 text-ink-faint dark:text-ink-faint-dark">→</span>
                        <span class="font-semibold text-success dark:text-success-dark">{{ $pattern->last_correction }}</span>
                    </p>
                </li>
            @endforeach

            @foreach ($fading as $pattern)
                {{-- Hedged on purpose: "haven't lately" is a true, smaller
                     claim than "fixed", and it's what carries this list
                     through the early missions, before SM-2's growing
                     intervals have had the calendar time to let anything
                     reach the mastered threshold. --}}
                <li class="rounded-xl border border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $label($pattern->category) }}</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                        <span class="text-ink-faint line-through decoration-ink-faint dark:text-ink-faint-dark">{{ $pattern->last_error }}</span>
                        <span class="mx-1 text-ink-faint dark:text-ink-faint-dark">→</span>
                        <span class="font-semibold text-ink dark:text-ink-dark">{{ $pattern->last_correction }}</span>
                    </p>
                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Hasn't come up in your recent missions.</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
