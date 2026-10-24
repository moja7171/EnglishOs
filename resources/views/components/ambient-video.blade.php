{{--
    A silent, looping background video clip — mood-setting only, NOT a
    listening-comprehension source (Pexels footage has no usable spoken
    dialogue to test against). Renders nothing at all when $url is null
    (no key configured, fetch failed, or simply not wired up yet) — this
    is purely decorative, never something a step should depend on.
    aria-hidden since a screen reader has nothing to gain from a muted
    ambient loop. See App\Services\PexelsClient::videoUrlFor().

    Light on a phone: whatever is behind it (a photo) shows first, and the
    clip is only fetched once the page is up, then fades in when it really
    plays. Never on Save-Data or with reduced motion asked for.
--}}
@props(['url' => null])

@if ($url && request()->header('Save-Data') !== 'on')
    <video
        {{ $attributes->class(['h-full w-full object-cover opacity-0 transition-opacity duration-700 motion-reduce:hidden']) }}
        data-src="{{ $url }}"
        autoplay
        muted
        loop
        playsinline
        preload="none"
        aria-hidden="true"
        x-data
        x-init="if (! navigator.connection?.saveData && ! matchMedia('(prefers-reduced-motion: reduce)').matches) { $el.src = $el.dataset.src }"
        x-on:playing="$el.classList.remove('opacity-0')"
    ></video>
@endif
