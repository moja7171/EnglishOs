{{--
    Colors a transcript by Whisper segment confidence (see
    GroqClient::transcribeWithConfidence()) — an approximation of how sure
    the transcription was, not a calibrated pronunciation score. Falls back
    to plain, uncolored $fallback text when $segments is empty (generation
    failed, hasn't run yet, or this is older Evidence saved before this
    feature existed).
--}}
@props(['segments' => [], 'fallback' => null])

@if (count($segments))
    <p {{ $attributes->class(['text-sm text-ink-soft dark:text-ink-soft-dark']) }}>
        @foreach ($segments as $segment)
            <span @class([
                'text-warning-ink' => $segment['confidence'] === 'medium',
                'text-danger-ink' => $segment['confidence'] === 'low',
            ])>{{ $segment['text'] }}</span>{{ ' ' }}
        @endforeach
    </p>

    @if (collect($segments)->contains(fn ($segment) => $segment['confidence'] !== 'high'))
        <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">
            Parts in <span class="text-warning-ink">amber</span>/<span class="text-danger-ink">red</span> were harder to make out — might be worth saying again out loud.
        </p>
    @endif
@elseif ($fallback)
    <p {{ $attributes->class(['text-sm text-ink-soft dark:text-ink-soft-dark']) }}>{{ $fallback }}</p>
@endif
