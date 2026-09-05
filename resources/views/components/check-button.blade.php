@props(['method', 'index', 'keyPrefix' => '', 'wireTarget', 'extraArgs' => ''])

@php
    $dismissedKey = "{$keyPrefix}{$index}";
    // Optional trailing args (e.g. ", 'section_key'") for a generic Livewire
    // method whose signature needs more than just the index — see
    // active-recall's checkGrammarSentence(index, section). Built entirely
    // from developer-authored, trusted values (a section key from seeded
    // mission content, never learner input) — raw-output below is safe and
    // needed so the single quotes around it survive as real JS syntax
    // rather than being HTML-entity-escaped by {{ }}.
    $callArgs = "{$index}{$extraArgs}";
@endphp

<button
    type="button"
    x-on:click="dismissed['{{ $dismissedKey }}'] = true; $wire.{{ $method }}({!! $callArgs !!}).then(() => { dismissed['{{ $dismissedKey }}'] = false })"
    wire:loading.attr="disabled"
    wire:target="{{ $wireTarget }}"
    class="shrink-0 cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
>
    <span wire:loading.remove wire:target="{{ $method }}({!! $callArgs !!})">Check</span>
    <span wire:loading wire:target="{{ $method }}({!! $callArgs !!})">Checking…</span>
</button>
