@props(['text'])

@if (! empty($text))
    <div class="rounded-lg border-2 border-neutral-900 bg-neutral-50 p-4 dark:border-white dark:bg-neutral-900">
        <p class="text-sm italic text-neutral-800 dark:text-neutral-200">{{ $text }}</p>
    </div>
@endif
