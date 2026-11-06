{{--
    A silent marker that plays one sound and buzz (window.eosFeedback, see
    resources/js/app.js) when it appears on screen. Put it inside the
    result block, which is only drawn once the result is known.

    @param string $kind right, wrong, point, win, lose or done.
--}}
@props(['kind'])

<span {{ $attributes }} class="hidden" aria-hidden="true" x-data x-init="window.eosFeedback?.{{ $kind }}()"></span>
