@props(['method', 'index', 'keyPrefix' => '', 'wireTarget'])

@php $dismissedKey = "{$keyPrefix}{$index}"; @endphp

<button
    type="button"
    x-on:click="dismissed['{{ $dismissedKey }}'] = true; $wire.{{ $method }}({{ $index }}).then(() => { dismissed['{{ $dismissedKey }}'] = false })"
    wire:loading.attr="disabled"
    wire:target="{{ $wireTarget }}"
    class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
>
    <span wire:loading.remove wire:target="{{ $method }}({{ $index }})">Check</span>
    <span wire:loading wire:target="{{ $method }}({{ $index }})">Checking…</span>
</button>
