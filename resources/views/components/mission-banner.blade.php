{{--
    The slim photo banner at the top of a page that belongs to one mission
    (Listening, Daily talk, a day overview, a checkpoint, a mission recap):
    the mission's photo — the same as its Missions card — under a dark
    fade, a way back on the top left, an optional badge on the top right,
    and the page's title. Without a cached photo it falls back to the hero
    gradient.

    @param string $missionCode
    @param string $title
    @param string|null $backHref
    @param string|null $backLabel
    @param string|null $icon Heroicons name shown before the title.
    @param string|null $doneLabel A green ✓ badge on the top right, e.g. "Listened".
    @slot $actions Beside the title, e.g. an ⓘ button.
    @slot $media Extra background layer over the photo, e.g. an ambient video.
    @slot default Under the title: a subtitle line, day pills.
--}}
@props(['missionCode', 'title', 'backHref' => null, 'backLabel' => null, 'icon' => null, 'doneLabel' => null])

@php
    $coverUrl = app(\App\Services\MissionGrid::class)->coverUrl($missionCode);
@endphp

<header data-mission-banner {{ $attributes->class('relative isolate overflow-hidden rounded-3xl bg-linear-to-br from-hero to-hero-2 p-4 text-white') }}>
    @if ($coverUrl)
        <img src="{{ $coverUrl }}" alt="" class="absolute inset-0 -z-30 h-full w-full object-cover">
    @endif
    @isset($media)
        <div class="absolute inset-0 -z-20 overflow-hidden">{{ $media }}</div>
    @endisset
    <div class="absolute inset-0 -z-10 bg-linear-to-t from-black/75 via-black/40 to-black/20"></div>

    <div class="flex min-h-5 items-center justify-between gap-2">
        @if ($backHref)
            <a href="{{ $backHref }}" wire:navigate class="inline-flex min-w-0 items-center gap-1 text-xs font-semibold text-white/85 hover:text-white">
                @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5 shrink-0')
                <span class="truncate">{{ $backLabel }}</span>
            </a>
        @else
            <span class="truncate text-xs font-semibold text-white/85">{{ $backLabel }}</span>
        @endif
        @if ($doneLabel)
            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success px-2 py-0.5 text-[11px] font-bold dark:bg-success-dark">@svg('heroicon-s-check', 'h-3 w-3') {{ $doneLabel }}</span>
        @endif
    </div>
    <div class="mt-6 flex items-center gap-2">
        <h1 class="flex min-w-0 flex-1 items-center gap-2 font-display text-2xl font-extrabold">
            @if ($icon)
                @svg($icon, 'h-6 w-6 shrink-0')
            @endif
            <span class="min-w-0">{{ $title }}</span>
        </h1>
        {{ $actions ?? '' }}
    </div>
    {{ $slot }}
</header>
