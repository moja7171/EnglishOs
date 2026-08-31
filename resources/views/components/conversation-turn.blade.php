@props(['prompt', 'answer', 'followup'])

<div class="rounded border border-neutral-300 p-3 text-sm dark:border-neutral-700">
    <p class="font-semibold">{{ $prompt }}</p>
    <p class="mt-1 text-neutral-600 dark:text-neutral-400">You: {{ $answer }}</p>
    <p class="mt-1 text-neutral-500 italic">AI Instructor: {{ $followup }}</p>
</div>
