{{--
    A small, positive-only score badge for a step's completion recap
    (mission structure redesign, Epic H) — "3 of 3 sentences needed no
    fix at all" is a genuine, real signal (severity was already computed
    for every AI-checked item anyway), never a grade or a judgment.

    $previousCorrect is the SAME learner's own last attempt at this exact
    step (via ?retry=1) — comparison is always framed as encouragement,
    never as "you did worse": a lower score than last time says nothing
    negative, a higher or equal score gets a warm callout.

    @param int $correct
    @param int $total
    @param string $label Plural noun for what was checked, e.g. "sentences".
    @param int|null $previousCorrect
--}}
@props(['correct', 'total', 'label' => 'sentences', 'previousCorrect' => null])

@if ($total > 0)
    <div class="rounded-xl border border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark">
        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-ink-dark">
            @svg('heroicon-o-sparkles', 'h-4 w-4 text-accent-ink dark:text-accent-ink-dark')
            {{ $correct }} of {{ $total }} {{ $label }} needed no fix at all
        </p>
        @if (! is_null($previousCorrect) && $correct > $previousCorrect)
            <p class="mt-1 text-xs text-success dark:text-success-dark">Better than last time — you got {{ $previousCorrect }} of {{ $total }} then!</p>
        @elseif (! is_null($previousCorrect) && $correct === $previousCorrect)
            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Same as your last attempt — steady!</p>
        @endif
    </div>
@endif
