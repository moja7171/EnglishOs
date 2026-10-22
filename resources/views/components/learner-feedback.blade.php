{{--
    The one feedback card every free-production task shows (see
    App\Services\LearnerFeedback): summary, sentence by sentence (only the
    first few mistakes expanded, correct ones folded away), good and weak
    points, speaking techniques, and the learner's own text one level up
    to listen to and repeat. Persian text is right-to-left; quoted English
    stays left-to-right.

    @param array $feedback A LearnerFeedback::normalize() result (older
        saved feedback is normalized here too).
--}}
@props(['feedback'])

@php
    $feedback = \App\Services\LearnerFeedback::normalize($feedback);
    $sentences = collect($feedback['sentences']);
    $fixes = $sentences->where('status', 'fix');
    $shownFixes = $fixes->take(\App\Services\LearnerFeedback::MAX_FIXES_SHOWN);
    $hiddenFixes = $fixes->count() - $shownFixes->count();
    $betters = $sentences->where('status', 'better')->take(\App\Services\LearnerFeedback::MAX_FIXES_SHOWN);
    $oks = $sentences->where('status', 'ok');
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @if ($feedback['summary'])
        <p class="font-fa text-sm font-semibold text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['summary'] }}</p>
    @endif

    @if ($shownFixes->isNotEmpty() || $betters->isNotEmpty() || $oks->isNotEmpty())
        <div class="space-y-2">
            @foreach ($shownFixes as $sentence)
                <div class="rounded-xl border-l-4 border-danger bg-danger-soft p-3">
                    <p class="text-sm text-danger-ink line-through decoration-danger">{{ $sentence['original'] }}</p>
                    <p class="mt-1 flex items-start gap-1.5 text-sm font-semibold text-success dark:text-success-dark">@svg('heroicon-o-check', 'mt-0.5 h-4 w-4 shrink-0') {{ $sentence['corrected'] }}</p>
                    @if ($sentence['why'])
                        <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $sentence['why'] }}</p>
                    @endif
                </div>
            @endforeach

            @if ($fixes->isEmpty())
                <p class="text-sm font-semibold text-success dark:text-success-dark">Nothing to fix — nice work.</p>
            @endif

            @foreach ($betters as $sentence)
                <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
                    <p class="text-xs font-semibold text-accent uppercase dark:text-accent-dark">Say it like this</p>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sentence['original'] }}</p>
                    <p class="mt-1 text-sm font-semibold text-ink dark:text-ink-dark">→ {{ $sentence['corrected'] }}</p>
                    @if ($sentence['why'])
                        <p class="font-fa mt-1 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $sentence['why'] }}</p>
                    @endif
                </div>
            @endforeach

            @if ($oks->isNotEmpty())
                <details class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <summary class="cursor-pointer text-sm font-semibold text-success dark:text-success-dark">✓ {{ $oks->count() }} {{ Str::plural('sentence', $oks->count()) }} fine</summary>
                    <ul class="mt-2 space-y-1">
                        @foreach ($oks as $sentence)
                            <li class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sentence['original'] }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
    @endif

    @if ($feedback['positives'])
        <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">@svg('heroicon-o-check-circle', 'h-4 w-4') Good</p>
            <ul class="font-fa mt-1 list-disc space-y-0.5 ps-5 text-sm text-ink dark:text-ink-dark" dir="rtl">
                @foreach ($feedback['positives'] as $point)
                    <li>{{ $point }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($feedback['negatives'])
        <div class="rounded-xl border-l-4 border-warning bg-warning-soft p-3">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-warning-ink uppercase">@svg('heroicon-o-exclamation-triangle', 'h-4 w-4') To work on</p>
            <ul class="font-fa mt-1 list-disc space-y-0.5 ps-5 text-sm text-ink dark:text-ink-dark" dir="rtl">
                @foreach ($feedback['negatives'] as $point)
                    <li>{{ $point }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($feedback['techniques'])
        <div class="rounded-xl border border-line p-3 dark:border-line-dark">
            <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">@svg('heroicon-o-light-bulb', 'h-4 w-4') Speak better</p>
            <div class="mt-2 space-y-2">
                @foreach ($feedback['techniques'] as $technique)
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ \App\Services\LearnerFeedback::TECHNIQUES[$technique['key']]['name'] }}</p>
                        @if ($technique['tip'])
                            <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $technique['tip'] }}</p>
                        @endif
                        <p class="text-sm text-ink italic dark:text-ink-dark">{{ $technique['example'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($feedback['better_version'])
        <div class="rounded-xl bg-accent-soft p-3 dark:bg-accent-soft-dark">
            <div class="flex items-center justify-between gap-2">
                <p class="flex items-center gap-1.5 text-xs font-semibold text-accent-ink uppercase dark:text-accent-ink-dark">@svg('heroicon-o-speaker-wave', 'h-4 w-4') Your better version</p>
                <x-speak-button :text="$feedback['better_version']" />
            </div>
            <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['better_version'] }}</p>
            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Listen, then say it out loud.</p>
        </div>
    @endif

    @if (($feedback['saved'] ?? null) && ($feedback['saved']['mistakes'] + $feedback['saved']['tips']) > 0)
        <a href="{{ route('notebook.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink hover:underline dark:text-accent-ink-dark">
            @svg('heroicon-o-book-open', 'h-3.5 w-3.5')
            Saved to your notebook: {{ $feedback['saved']['mistakes'] }} {{ Str::plural('mistake', $feedback['saved']['mistakes']) }} · {{ $feedback['saved']['tips'] }} {{ Str::plural('tip', $feedback['saved']['tips']) }}
            @if ($hiddenFixes > 0) (+{{ $hiddenFixes }} not shown) @endif
        </a>
    @endif
</div>
