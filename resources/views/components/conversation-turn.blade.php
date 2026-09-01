@props(['prompt', 'answer', 'followup'])

<div class="rounded-xl border border-line p-3 text-sm dark:border-line-dark">
    <p class="font-semibold text-ink dark:text-ink-dark">{{ $prompt }}</p>
    <p class="mt-1 text-ink-soft dark:text-ink-soft-dark">You: {{ $answer }}</p>
    <p class="mt-1 text-ink-faint italic dark:text-ink-faint-dark">AI Instructor: {{ $followup }}</p>
</div>
