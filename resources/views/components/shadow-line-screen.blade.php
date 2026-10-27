{{--
    One shadowing line on its own lesson screen (lesson designs 4 and 7):
    the line big with its stress marks, a "▶ Hear it" that plays only that
    line, the big mic, and the check coming up from the bottom once the take
    is in — Continue is there after any take ("Almost" too), the mic stays
    for another try. The component wires the recorder to checkShadowLine
    (see ChecksShadowLines).

    @param string $when Raw Alpine expression for when this screen shows.
    @param int $index The line's index.
    @param string $line The line (may carry **stress** marks).
    @param string $kind The chip, e.g. "Say it · 1 of 2".
    @param string $hear Raw Alpine statement that plays the line.
    @param string|null $recordingUrl The kept take, if any.
    @param string $fileName
    @param array|null $feedback The line's verdict.
    @param string|null $error
    @param string $next Raw Alpine statement for Continue.
    @param string $wireTarget
    @slot media Optional picture/video above the line.
--}}
@props(['when', 'index', 'line', 'kind', 'hear', 'recordingUrl' => null, 'fileName', 'feedback' => null, 'error' => null, 'next', 'wireTarget' => 'save'])

@php
    $isGood = ($feedback['severity'] ?? null) === 'none';
    $sheetTone = $isGood ? "'good'" : "'almost'";
    $sheetTitle = $isGood ? "'✓ Nice!'" : "'Almost!'";
@endphp

<x-lesson-screen :when="$when" wire:key="shadow-screen-{{ $index }}">
    <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $kind }}</span>

    {{ $media ?? '' }}

    <p x-lookup class="rounded-3xl bg-surface px-4 py-5 text-lg leading-relaxed font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark">“<x-stress-marked-line :text="$line" />”</p>

    <button type="button" x-on:click="{{ $hear }}" class="inline-flex w-fit cursor-pointer items-center gap-2 rounded-full border-2 border-line bg-surface py-1.5 pr-4 pl-1.5 text-sm font-extrabold text-ink transition-colors hover:border-ink-faint dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">
        <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">@svg('heroicon-s-play', 'h-3.5 w-3.5')</span>
        Hear it
    </button>

    <div class="mt-2 flex justify-center">
        <x-voice-recorder big field="shadowRecordings.{{ $index }}" :file="$recordingUrl" :file-name="$fileName" on-recorded="checkShadowLine" :on-recorded-param="$index" />
    </div>
    <x-ai-thinking wire:loading wire:target="checkShadowLine({{ $index }})" label="Listening to your recording…" />
    @if ($error)
        <p class="text-sm text-danger-ink">{{ $error }}</p>
    @endif

    <x-slot:actions>
        @if ($feedback)
            <x-answer-sheet when="true" :tone="$sheetTone" :title="$sheetTitle">
                @if ($feedback['unchecked'] ?? false)
                    Couldn't check this one just now — your take is saved and it counts.
                @elseif (! $isGood && filled($feedback['hint'] ?? null))
                    {{ $feedback['hint'] }}
                @endif

                <x-slot:actions>
                    <x-continue-button :on-click="$next" :wire-target="$wireTarget" />
                </x-slot:actions>
            </x-answer-sheet>
        @else
            <x-continue-button :on-click="$next" :wire-target="$wireTarget" ready-when="false" />
        @endif
    </x-slot:actions>
</x-lesson-screen>
