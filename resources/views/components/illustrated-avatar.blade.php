{{--
    A simple flat illustrated avatar — the same vetted "bust" silhouette
    Heroicons' own s-user icon uses (not a hand-drawn path: reusing an
    already-correct shape from a library already in this project, rather
    than freehand bezier curves prone to subtle rendering bugs), with a
    hairstyle variant layered on top from plain circles/ellipses only.
    Same colored-circle container and palette as <x-avatar-initial> —
    prefer <x-user-avatar> when a full User model is on hand.

    @param string $style One of User::avatarStyleOptions()'s keys other
        than "initial" (that one renders as <x-avatar-initial> instead).
    @param string $color One of User::avatarColorPalette()'s keys.
--}}
@props(['style', 'color' => 'accent'])

@php
    // Same literal-classes-only rule as avatar-initial.blade.php — see
    // that file's comment for why this can't be a dynamic string.
    $paletteClasses = match ($color) {
        'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
        'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        'sky' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
        'violet' => 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
        'fuchsia' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950 dark:text-fuchsia-300',
        'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-950 dark:text-slate-300',
        default => 'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark',
    };
@endphp

<span {{ $attributes->class(["inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full $paletteClasses"]) }}>
    <svg viewBox="0 0 24 24" fill="currentColor" class="h-4/5 w-4/5" aria-hidden="true">
        {{-- Hair overlay — plain primitives only (no custom path data),
             painted in the same currentColor as the bust below, so paint
             order never matters: the visible shape is just the union of
             every piece drawn here. --}}
        @switch($style)
            @case('short')
                <ellipse cx="12" cy="2.8" rx="4.9" ry="2.6" />
                @break
            @case('side-part')
                <ellipse cx="10.3" cy="2.6" rx="4.3" ry="2.3" />
                @break
            @case('curly')
                <circle cx="8.3" cy="3.2" r="1.6" />
                <circle cx="10.6" cy="1.9" r="1.7" />
                <circle cx="13.3" cy="1.8" r="1.7" />
                <circle cx="15.5" cy="3.0" r="1.6" />
                @break
            @case('long')
                <ellipse cx="12" cy="2.8" rx="4.9" ry="2.6" />
                <ellipse cx="7.6" cy="9" rx="1.8" ry="5.2" />
                <ellipse cx="16.4" cy="9" rx="1.8" ry="5.2" />
                @break
            @case('bob')
                <ellipse cx="12" cy="2.8" rx="4.9" ry="2.6" />
                <ellipse cx="7.7" cy="7.6" rx="1.7" ry="3.4" />
                <ellipse cx="16.3" cy="7.6" rx="1.7" ry="3.4" />
                @break
            @case('ponytail')
                <ellipse cx="12" cy="2.8" rx="4.9" ry="2.6" />
                <circle cx="16.8" cy="7" r="1.4" />
                @break
        @endswitch

        {{-- The bust — heroicon-s-user's own path, verbatim. --}}
        <path fill-rule="evenodd" clip-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z"/>
    </svg>
</span>
