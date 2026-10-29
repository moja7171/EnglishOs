{{--
    The bottom of a "write or say it, then Check" lesson screen (New Words,
    Grammar Time, Reading). One main button whose name follows the result,
    so there is never a choice between look-alike buttons:

    - nothing checked yet: Check;
    - right: the green sheet (✓ Great! + a short comment) and Continue;
    - nearly right (Almost!): the hint, Check again, and a quiet
      "Continue anyway" link;
    - not right (Not quite): the hint and Check again — no way on until the
      sentence is better ("Show me a right one" after enough tries);
    - the AI could not answer: a red card saying why, Try again, and a quiet
      "Continue without check" — a busy or broken service never holds a
      learner back. The earlier verdict is not shown then: it belonged to
      text that has since changed.

    Check again wakes up once the learner changes the text; the sheet keeps
    showing the last hint while they fix it. After a change even a right
    answer asks for Check again, so Continue never moves on with a sentence
    nobody has checked. Continue in a right answer's sheet stays off for a
    moment as the sheet appears, so the tap that pressed Check (or a second
    one) cannot slip through to the next screen before the answer is read.

    @param int|string $index The answer's key, as the page's Alpine
        `edited` object and the methods know it.
    @param string $model The wire:model path of the answer's box, e.g.
        "examples.2": Check is ready once that box holds some text, however
        the text got there (typed, said, or restored from a draft).
    @param string|null $error Why the last check could not run (the AI did
        not answer): a clear card above the buttons, not a line under the box.
        It replaces the verdict sheet while it is set.
    @param array|null $feedback The answer's verdict ({severity, hint}),
        null before any check.
    @param string $check Raw Alpine statement that runs the check (a promise
        of true when it ran, false when it could not).
    @param string $next Raw Alpine statement for Continue.
    @param string $wireTarget Methods that disable the buttons while running.
    @param bool $offerReveal
    @param bool $almostRevealing
    @param string|null $reveal The component method that writes a right one.
    @slot extra An extra line in a good sheet (e.g. "+5 words in My Words").
--}}
@props(['index', 'model', 'error' => null, 'feedback' => null, 'check', 'next', 'wireTarget', 'offerReveal' => false, 'almostRevealing' => false, 'reveal' => 'revealCorrection'])

@php
    $key = Illuminate\Support\Js::from($index);
    $severity = $feedback['severity'] ?? null;
    $tone = $severity === 'none' ? "'good'" : "'almost'";
    $title = match ($severity) { 'none' => "'✓ Great!'", 'minor' => "'Almost!'", default => "'Not quite'" };
    $hasText = "(\$wire.get(".Illuminate\Support\Js::from($model).") || '').trim() !== ''";
    $runCheck = "({$check}).then((ran) => { if (ran !== false) edited[{$key}] = false })";
    $changedAndReady = "edited[{$key}] && ({$hasText})";
@endphp

@if ($error)
    <x-error-card wire:key="answer-error-{{ $index }}">{{ $error }}</x-error-card>
    <div class="flex flex-col gap-3" wire:key="answer-error-actions-{{ $index }}">
        <x-continue-button :on-click="$runCheck" :wire-target="$wireTarget" loading-label="Checking…" :ready-when="$hasText">Try again</x-continue-button>
        <button type="button" x-on:click="{{ $next }}" wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" class="cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Continue without check</button>
    </div>
@elseif ($feedback)
    {{-- Own keys for the two states: without them Livewire's morph reuses the Check wrapper's element for the sheet and the sheet's tone never applies. --}}
    <x-answer-sheet when="true" :tone="$tone" :title="$title" wire:key="answer-sheet-{{ $index }}">
        @if (filled($feedback['hint'] ?? null))
            {{ $feedback['hint'] }}
        @endif
        @if ($severity === 'none' && isset($extra))
            {{ $extra }}
        @endif
        <x-almost-reveal-notice :show="$almostRevealing" />

        <x-slot:actions>
            @if ($severity === 'none')
                <div
                    x-show="! edited[{{ $key }}]"
                    x-data="{ armed: false }"
                    x-effect="if (! edited[{{ $key }}]) { armed = false; setTimeout(() => (armed = true), 600) }"
                    class="flex flex-col gap-3"
                >
                    <x-continue-button :on-click="$next" :wire-target="$wireTarget" loading-label="Saving…" ready-when="armed" />
                </div>
                <div x-show="edited[{{ $key }}]" x-cloak class="flex flex-col gap-3">
                    <x-continue-button :on-click="$runCheck" :wire-target="$wireTarget" loading-label="Checking…" :ready-when="$hasText">Check again</x-continue-button>
                </div>
            @else
                <x-continue-button :on-click="$runCheck" :wire-target="$wireTarget" loading-label="Checking…" :ready-when="$changedAndReady">Check again</x-continue-button>
                @if ($severity === 'minor')
                    <button type="button" x-show="! edited[{{ $key }}]" x-on:click="{{ $next }}" wire:loading.attr="disabled" wire:target="{{ $wireTarget }}" class="cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Continue anyway</button>
                @elseif ($offerReveal)
                    <button type="button" wire:click="{{ $reveal }}({{ $key }})" class="cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Show me a right one</button>
                @endif
            @endif
        </x-slot:actions>
    </x-answer-sheet>
@else
    <div class="flex flex-col gap-3" wire:key="answer-check-{{ $index }}">
        <x-continue-button :on-click="$runCheck" :wire-target="$wireTarget" loading-label="Checking…" :ready-when="$hasText">Check</x-continue-button>
    </div>
@endif
