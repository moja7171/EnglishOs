{{--
    A circular avatar showing a user's first initial (multibyte-safe) —
    used anywhere a real profile photo isn't part of the app, so people
    still read as people instead of a bare name in a list.

    @param string $name
--}}
@props(['name'])

<span {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full bg-accent-soft font-display font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark']) }}>
    {{ mb_strtoupper(mb_substr($name, 0, 1)) }}
</span>
