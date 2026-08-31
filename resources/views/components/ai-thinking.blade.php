@props(['label' => 'AI is thinking…'])

<div {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-neutral-800 dark:bg-neutral-900']) }}>
    <span class="flex shrink-0 items-center gap-1">
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 0ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 200ms"></span>
        <span class="h-1.5 w-1.5 shrink-0 animate-typing-dot rounded-full bg-neutral-400" style="animation-delay: 400ms"></span>
    </span>
    <p class="text-sm text-neutral-500">{{ $label }}</p>
</div>
