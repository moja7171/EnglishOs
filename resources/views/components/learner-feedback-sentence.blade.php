{{--
    One sentence that needs work, inside <x-learner-feedback>: a mistake
    (red, struck through → green fix) or a "say it like this" (plain → sky-blue
    natural version), with its one-line Persian why.

    @param array{original: string, status: string, corrected: ?string, why: ?string} $sentence
--}}
@props(['sentence'])

<div class="space-y-1 border-t border-line pt-2 first:border-t-0 first:pt-0 dark:border-line-dark">
    @if ($sentence['status'] === 'fix')
        <p class="text-right text-sm text-danger-ink line-through decoration-danger" dir="ltr">{{ $sentence['original'] }}</p>
        <p class="text-right text-sm font-semibold text-success dark:text-success-dark" dir="ltr">{{ $sentence['corrected'] }}</p>
    @else
        <p class="font-fa text-xs font-semibold text-sky-800 dark:text-sky-300">این‌طوری بگو</p>
        <p class="text-right text-sm text-ink-soft dark:text-ink-soft-dark" dir="ltr">{{ $sentence['original'] }}</p>
        <p class="text-right text-sm font-semibold text-sky-800 dark:text-sky-300" dir="ltr">{{ $sentence['corrected'] }}</p>
    @endif
    @if ($sentence['why'])
        <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sentence['why'] }}</p>
    @endif
</div>
