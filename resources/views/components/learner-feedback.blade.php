{{--
    The one feedback card every free-production task shows (see
    App\Services\LearnerFeedback), in one fixed order:

    1. Summary — one Persian line, "N mistakes · M tips" chips, and the
       good/weak points as two short lines (no separate colored boxes).
    2. One card per answer when $answers is given (question faint, the
       learner's own words, and that answer's fixes right under it), or
       one card per sentence that needs work when it isn't; fine
       sentences fold away.
    3. "Next time" — the speaking techniques (inside "More" when
       $foldTechniques).
    4. "Your better version" to listen to and repeat.
    5. "More" — whatever the page passes in the `more` slot (recordings,
       the full text), collapsed.

    Colors mean one thing each: red = what was wrong, green = the right
    version, sky blue = a tip. Never the accent color — it changes with
    each mission's mood and can itself be red or green. The whole card is right-to-left; English lines
    inside it are left-to-right text, right-aligned, so the eye never
    jumps sides.

    @param array|null $feedback A LearnerFeedback::normalize()-able array;
        null still shows the answers (e.g. when the AI was down).
    @param list<array{question: ?string, text: string}> $answers The
        learner's answers in order, matched to each sentence's 'answer'.
    @param bool $foldTechniques Puts "Next time" inside "More" (the
        lesson's feedback screen, <x-feedback-screen>, keeps the first view
        to the summary and the fixes).
--}}
@props(['feedback' => null, 'answers' => [], 'foldTechniques' => false])

@use('App\Services\LearnerFeedback')

@php
    $feedback = LearnerFeedback::normalize($feedback ?? []);
    $sentences = collect($feedback['sentences']);
    $fixes = $sentences->where('status', 'fix');
    $shownFixKeys = $fixes->keys()->take(LearnerFeedback::MAX_FIXES_SHOWN)->all();
    $hiddenFixes = $fixes->count() - count($shownFixKeys);
    $issues = $sentences->filter(fn ($s, $key) => $s['status'] === 'better' || in_array($key, $shownFixKeys, true));
    $tipCount = $sentences->where('status', 'better')->count() + count($feedback['techniques']);
    $hasAnswers = count($answers) > 0;
    $orphanIssues = $hasAnswers
        ? $issues->filter(fn ($s) => ! isset($answers[($s['answer'] ?? 0) - 1]))
        : $issues;
    $oks = $sentences->where('status', 'ok');
    $hasFeedback = $feedback['summary'] !== '' || $sentences->isNotEmpty() || $feedback['positives'] || $feedback['negatives'];
    $english = 'text-right';
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }} dir="rtl">
    @if ($hasFeedback)
        <div class="space-y-2 rounded-2xl border border-line p-3 dark:border-line-dark">
            @if ($feedback['summary'])
                <p class="font-fa text-sm font-semibold text-ink dark:text-ink-dark">{{ $feedback['summary'] }}</p>
            @endif

            <div class="flex flex-wrap gap-1.5">
                @if ($fixes->isEmpty())
                    <span class="font-fa rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success-ink dark:bg-success-soft-dark dark:text-success-ink-dark">✓ بدون اشتباه</span>
                @else
                    <span class="font-fa rounded-full bg-danger-soft px-2.5 py-0.5 text-xs font-semibold text-danger-ink">{{ $fixes->count() }} اشتباه</span>
                @endif
                @if ($tipCount > 0)
                    <span class="font-fa rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-300">{{ $tipCount }} نکته</span>
                @endif
            </div>

            @foreach ($feedback['positives'] as $point)
                <p class="font-fa flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                    <span class="shrink-0 text-success dark:text-success-dark">✓</span> {{ $point }}
                </p>
            @endforeach
            @foreach ($feedback['negatives'] as $point)
                <p class="font-fa flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                    <span class="shrink-0">🎯</span> {{ $point }}
                </p>
            @endforeach
        </div>
    @endif

    @foreach ($answers as $index => $answer)
        @php $answerIssues = $issues->filter(fn ($s) => ($s['answer'] ?? null) === $index + 1); @endphp
        <div class="space-y-2 rounded-2xl border border-line p-3 dark:border-line-dark" wire:key="feedback-answer-{{ $index }}">
            <div class="flex items-start justify-between gap-2">
                @if (filled($answer['question'] ?? null))
                    <p class="min-w-0 text-xs text-ink-faint dark:text-ink-faint-dark {{ $english }}" dir="ltr">{{ $answer['question'] }}</p>
                @endif
                @if ($hasFeedback && $answerIssues->isEmpty())
                    <span class="shrink-0 text-success dark:text-success-dark">@svg('heroicon-s-check-circle', 'h-5 w-5')</span>
                @endif
            </div>
            <p class="text-sm text-ink dark:text-ink-dark {{ $english }}" dir="ltr">{{ $answer['text'] }}</p>
            @foreach ($answerIssues as $sentence)
                <x-learner-feedback-sentence :sentence="$sentence" />
            @endforeach
        </div>
    @endforeach

    @foreach ($orphanIssues as $sentence)
        <div class="rounded-2xl border border-line p-3 dark:border-line-dark">
            <x-learner-feedback-sentence :sentence="$sentence" />
        </div>
    @endforeach

    @if (! $hasAnswers && $oks->isNotEmpty())
        <details class="rounded-2xl border border-line p-3 dark:border-line-dark">
            <summary class="font-fa cursor-pointer text-sm font-semibold text-success dark:text-success-dark">✓ {{ $oks->count() }} جمله درست بود</summary>
            <ul class="mt-2 space-y-1">
                @foreach ($oks as $sentence)
                    <li class="text-sm text-ink-soft dark:text-ink-soft-dark {{ $english }}" dir="ltr">{{ $sentence['original'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($feedback['techniques'] && ! $foldTechniques)
        <x-feedback-techniques :techniques="$feedback['techniques']" />
    @endif

    @if ($feedback['better_version'])
        <div class="space-y-1 rounded-2xl border border-line p-3 dark:border-line-dark">
            <div class="flex items-center justify-between gap-2">
                <p class="font-fa flex items-center gap-1.5 text-xs font-semibold text-sky-800 dark:text-sky-300">@svg('heroicon-o-speaker-wave', 'h-4 w-4') نسخه‌ی بهترِ خودت</p>
                <x-speak-button :text="$feedback['better_version']" />
            </div>
            <p class="text-sm text-ink dark:text-ink-dark {{ $english }}" dir="ltr">{{ $feedback['better_version'] }}</p>
            <p class="font-fa text-xs text-ink-faint dark:text-ink-faint-dark">گوش کن، بعد بلند تکرارش کن.</p>
        </div>
    @endif

    @if (($feedback['saved'] ?? null) && ($feedback['saved']['mistakes'] + $feedback['saved']['tips']) > 0)
        <a href="{{ route('notebook.index') }}" wire:navigate class="font-fa inline-flex items-center gap-1 text-xs font-semibold text-sky-800 hover:underline dark:text-sky-300">
            @svg('heroicon-o-book-open', 'h-3.5 w-3.5')
            در دفترت ذخیره شد: {{ $feedback['saved']['mistakes'] }} اشتباه · {{ $feedback['saved']['tips'] }} نکته
            @if ($hiddenFixes > 0) ({{ $hiddenFixes }} اشتباهِ دیگر فقط در دفتر) @endif
        </a>
    @endif

    @php $foldedTechniques = $foldTechniques && $feedback['techniques']; @endphp
    @if ($foldedTechniques || (isset($more) && $more->isNotEmpty()))
        <details class="rounded-2xl border border-line p-3 dark:border-line-dark">
            <summary class="font-fa cursor-pointer text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">بیشتر</summary>
            <div class="mt-3 space-y-3" dir="ltr">
                @if ($foldedTechniques)
                    <x-feedback-techniques :techniques="$feedback['techniques']" />
                @endif
                {{ $more ?? '' }}
            </div>
        </details>
    @endif
</div>
