{{--
    A spoken answer's transcript as plain text. It used to color the parts
    Whisper was unsure of, but learners read the colors as mistakes, so the
    segments are now only joined back into one line.
--}}
@props(['segments' => [], 'fallback' => null])

@php
    $text = count($segments) ? collect($segments)->pluck('text')->implode(' ') : $fallback;
@endphp

@if (filled($text))
    <p {{ $attributes->class(['text-sm text-ink-soft dark:text-ink-soft-dark']) }}>{{ $text }}</p>
@endif
