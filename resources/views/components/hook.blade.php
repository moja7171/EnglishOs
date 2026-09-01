@props(['text'])

@if (! empty($text))
    <p class="border-l-2 border-accent/40 pl-3 text-sm text-ink-soft italic dark:border-accent-dark/40 dark:text-ink-soft-dark">{{ $text }}</p>
@endif
