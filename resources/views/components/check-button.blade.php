@props(['method', 'index', 'keyPrefix' => '', 'wireTarget'])

@php $dismissedKey = "{$keyPrefix}{$index}"; @endphp

<button
    type="button"
    x-on:click="dismissed['{{ $dismissedKey }}'] = true; $wire.{{ $method }}({{ $index }}).then(() => { dismissed['{{ $dismissedKey }}'] = false })"
    wire:loading.attr="disabled"
    wire:target="{{ $wireTarget }}"
    class="shrink-0 cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
>
    <span wire:loading.remove wire:target="{{ $method }}({{ $index }})">Check</span>
    <span wire:loading wire:target="{{ $method }}({{ $index }})">Checking…</span>
</button>
