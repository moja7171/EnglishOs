@props(['prompt', 'answer', 'followup' => null])

<div class="rounded-xl border border-line p-3 text-sm dark:border-line-dark">
    <p class="font-semibold text-ink dark:text-ink-dark">{{ $prompt }}</p>
    <p class="mt-1 text-ink-soft dark:text-ink-soft-dark">You: {{ $answer }}</p>
    @if (filled($followup))
        <p class="mt-1 text-ink-faint italic dark:text-ink-faint-dark">AI Instructor: {{ $followup }}</p>
    @endif
</div>
