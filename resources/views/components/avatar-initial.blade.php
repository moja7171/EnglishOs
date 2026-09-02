{{--
    A circular avatar showing a user's first initial (multibyte-safe) —
    used anywhere a real profile photo isn't part of the app, so people
    still read as people instead of a bare name in a list. Prefer
    <x-user-avatar> when a full User model is available (it falls back to
    this automatically) — reach for this directly only when just a name
    is on hand.

    @param string $name
    @param string $color One of User::avatarColorPalette()'s keys.
--}}
@props(['name', 'color' => 'accent'])

@php
    // Literal, not a dynamic "bg-{$color}-100" string — Tailwind's JIT
    // scanner only generates classes it can see written out somewhere,
    // so every option has to exist here as a real string. Falls back to
    // the default palette entry for any unrecognized key (including a
    // stray/legacy value), never a blank/unstyled circle.
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

<span {{ $attributes->class(["inline-flex shrink-0 items-center justify-center rounded-full font-display font-bold $paletteClasses"]) }}>
    {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
</span>
