{{--
    A silent, looping background video clip — mood-setting only, NOT a
    listening-comprehension source (Pexels footage has no usable spoken
    dialogue to test against). Renders nothing at all when $url is null
    (no key configured, fetch failed, or simply not wired up yet) — this
    is purely decorative, never something a step should depend on.
    aria-hidden since a screen reader has nothing to gain from a muted
    ambient loop. See App\Services\PexelsClient::videoUrlFor().
--}}
@props(['url' => null])

@if ($url)
    <video
        {{ $attributes->class(['h-full w-full object-cover']) }}
        src="{{ $url }}"
        autoplay
        muted
        loop
        playsinline
        aria-hidden="true"
    ></video>
@endif
