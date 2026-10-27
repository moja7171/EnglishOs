{{--
    "Next time" in the feedback card: the speaking techniques the AI picked,
    each with its name, a Persian tip and an example. Its own piece so the
    card can show it openly or fold it under "More" (see <x-learner-feedback>).

    @param list<array{key: string, tip: ?string, example: string}> $techniques
--}}
@props(['techniques'])

@use('App\Services\LearnerFeedback')

<div class="space-y-2 rounded-2xl border border-sky-200 bg-sky-50 p-3 dark:border-sky-900 dark:bg-sky-950" dir="rtl">
    <p class="font-fa flex items-center gap-1.5 text-xs font-semibold text-sky-800 dark:text-sky-300">@svg('heroicon-o-light-bulb', 'h-4 w-4') دفعه‌ی بعد</p>
    @foreach ($techniques as $technique)
        <div>
            <p class="text-sm font-semibold text-right text-ink dark:text-ink-dark" dir="ltr">{{ LearnerFeedback::TECHNIQUES[$technique['key']]['name'] }}</p>
            @if ($technique['tip'])
                <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark">{{ $technique['tip'] }}</p>
            @endif
            <p class="text-sm text-ink italic text-right dark:text-ink-dark" dir="ltr">{{ $technique['example'] }}</p>
        </div>
    @endforeach
</div>
