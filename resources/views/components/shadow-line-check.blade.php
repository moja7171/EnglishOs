{{--
    The AI's verdict under one shadowing recorder — the "listening" spinner
    while checkShadowLine() runs, then the verdict (see ChecksShadowLines).
    Used by Video Shadowing and the Listen Again days.

    @param int $index The shadow line's index.
    @param array|null $feedback The line's verdict, null before any check.
    @param string|null $error A check failure message.
--}}
@props(['index', 'feedback' => null, 'error' => null])

<x-ai-thinking wire:loading wire:target="checkShadowLine({{ $index }})" class="mt-2" label="Listening to your recording…" />
@if ($feedback)
    <p class="mt-2 text-xs {{ $feedback['severity'] === 'none' ? 'text-success dark:text-success-dark' : 'text-warning-ink' }}">
        @if ($feedback['unchecked'] ?? false)
            @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') Couldn't check this one just now — your recording is saved and it counts.
        @elseif ($feedback['severity'] === 'none')
            @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') Nice — that counts.
        @else
            {{ $feedback['hint'] }}
        @endif
    </p>
@endif
@if ($error)
    <p class="mt-2 text-xs text-danger-ink">{{ $error }}</p>
@endif
