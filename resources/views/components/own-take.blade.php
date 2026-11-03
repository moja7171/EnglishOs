{{--
    A learner's own recording (a take, a saved answer, a partner's voice
    reply): the slim voice-note player — play, seek line, time — in a soft
    pill. The full <x-audio-player> (speed, ±5 s, download) is for lesson
    audio, where scrubbing matters; an 11-second take just needs "tap to play".

    @param string|null $url
--}}
@props(['url'])

@if (! empty($url))
    <div {{ $attributes->class('rounded-full bg-surface px-3 py-2 dark:bg-surface-dark') }}>
        <x-audio-player-compact :url="$url" />
    </div>
@endif
