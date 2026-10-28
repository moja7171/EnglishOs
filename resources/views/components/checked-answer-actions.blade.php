{{--
    The bottom of a "write or say it, then Check" lesson screen (New Words,
    Grammar Time, Reading): a full-width Check while the answer is new or
    edited, and once it is checked, the answer sheet — ✓ Great! / Almost! /
    Not quite with the one-line comment (also when it is right), then Continue (or Try again, plus
    "Show me a right one" after enough tries, when it's not right yet).

    @param int|string $index The answer's key, as the page's Alpine
        `filled` and `edited` objects and the methods know it.
    @param array|null $feedback The answer's verdict ({severity, hint}),
        null before any check.
    @param bool $checked True when the verdict is for the text in the box now.
    @param string $check Raw Alpine statement that runs the check (a promise).
    @param string $next Raw Alpine statement for Continue.
    @param string $wireTarget Methods that disable the buttons while running.
    @param bool $offerReveal
    @param bool $almostRevealing
    @param string|null $reveal The component method that writes a right one.
    @slot extra An extra line in a good sheet (e.g. "+5 words in My Words").
--}}
@props(['index', 'feedback' => null, 'checked' => false, 'check', 'next', 'wireTarget', 'offerReveal' => false, 'almostRevealing' => false, 'reveal' => 'revealCorrection'])

@php
    $severity = $feedback['severity'] ?? null;
    $tone = $severity === 'none' ? "'good'" : "'almost'";
    $title = match ($severity) { 'none' => "'✓ Great!'", 'minor' => "'Almost!'", default => "'Not quite'" };
@endphp

@if ($checked)
    <x-answer-sheet when="! edited[{{ Illuminate\Support\Js::from($index) }}]" :tone="$tone" :title="$title">
        @if (filled($feedback['hint'] ?? null))
            {{ $feedback['hint'] }}
        @endif
        @if ($severity === 'none' && isset($extra))
            {{ $extra }}
        @endif
        <x-almost-reveal-notice :show="$almostRevealing" />

        <x-slot:actions>
            @if ($severity === 'major')
                <x-continue-button on-click="edited[{{ Illuminate\Support\Js::from($index) }}] = true" :wire-target="$wireTarget">Try again</x-continue-button>
                @if ($offerReveal)
                    <button type="button" wire:click="{{ $reveal }}({{ Illuminate\Support\Js::from($index) }})" class="cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Show me a right one</button>
                @endif
            @else
                <x-continue-button :on-click="$next" :wire-target="$wireTarget" loading-label="Saving…" />
            @endif
        </x-slot:actions>
    </x-answer-sheet>
@endif

<div @if ($checked) x-show="edited[{{ Illuminate\Support\Js::from($index) }}]" x-cloak @endif class="flex flex-col gap-3">
    <x-continue-button
        on-click="({{ $check }}).then(() => { edited[{{ Illuminate\Support\Js::from($index) }}] = false })"
        :wire-target="$wireTarget"
        loading-label="Checking…"
        ready-when="filled[{{ Illuminate\Support\Js::from($index) }}]"
    >Check</x-continue-button>
</div>
