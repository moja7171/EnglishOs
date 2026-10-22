{{--
    One question and the learner's answer. Under it: the one-line check
    (Talk It Out — see SpokenAnswerChecker::quickCorrection(): ✓ with a few
    words of praise, or wrong → right with why), or for older answers and
    Final Talk, the AI's follow-up question.

    @param array{status: string, original: ?string, corrected: ?string, why: string}|null $check
--}}
@props(['prompt', 'answer', 'followup' => null, 'check' => null])

<div class="rounded-xl border border-line p-3 text-sm dark:border-line-dark">
    <p class="font-semibold text-ink dark:text-ink-dark">{{ $prompt }}</p>
    <p class="mt-1 text-ink-soft dark:text-ink-soft-dark">You: {{ $answer }}</p>
    @if ($check)
        <div class="mt-2 space-y-0.5 border-t border-line pt-2 dark:border-line-dark">
            @if ($check['status'] === 'fix')
                <p class="text-danger-ink line-through decoration-danger">{{ $check['original'] }}</p>
                <p class="font-semibold text-success dark:text-success-dark">{{ $check['corrected'] }}</p>
                <p class="font-fa text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $check['why'] }}</p>
            @else
                <p class="font-fa flex items-start gap-1.5 text-ink-soft dark:text-ink-soft-dark" dir="rtl"><span class="shrink-0 text-success dark:text-success-dark">✓</span> {{ $check['why'] }}</p>
            @endif
        </div>
    @elseif (filled($followup))
        <p class="mt-1 text-ink-faint italic dark:text-ink-faint-dark">AI Instructor: {{ $followup }}</p>
    @endif
</div>
