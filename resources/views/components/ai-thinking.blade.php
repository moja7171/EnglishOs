@props(['label' => 'AI is thinking…'])

<div {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-xl border border-line bg-surface-sunken px-3 py-2 dark:border-line-dark dark:bg-surface-sunken-dark']) }}>
    <span class="flex shrink-0 items-center gap-1">
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 0ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 200ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-accent dark:bg-accent-dark" style="animation-delay: 400ms"></span>
    </span>
    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $label }}</p>
</div>
