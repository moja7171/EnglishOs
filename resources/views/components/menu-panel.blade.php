{{--
    The panel a header button opens (the account menu, the notifications
    bell). On a phone it is a bottom sheet — a dimmed backdrop, the panel
    pinned to the bottom where a thumb reaches, a grab handle, and a swipe
    down (or a tap on the backdrop) to dismiss. From the sm breakpoint up it
    is the small dropdown under its button that it has always been.

    It reads and writes an Alpine boolean called `open` from the surrounding
    x-data, so it must sit inside the element that owns the button. Pass
    role / aria-label (and any extra classes) as ordinary attributes.

    @param string $width The dropdown's width from sm up, e.g. "sm:w-64".
--}}
@props(['width' => 'sm:w-64'])

<div
    x-show="open"
    x-cloak
    x-on:click="open = false"
    x-transition.opacity.duration.200ms
    aria-hidden="true"
    class="fixed inset-0 z-40 bg-ink/40 sm:hidden"
></div>

<div
    x-show="open"
    x-cloak
    x-swipe-close="open = false"
    x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0 sm:duration-150"
    x-transition:enter-start="translate-y-full sm:translate-y-0 sm:origin-top-right sm:scale-95 sm:opacity-0"
    x-transition:enter-end="translate-y-0 sm:scale-100 sm:opacity-100"
    x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
    x-transition:leave-start="translate-y-0 sm:scale-100 sm:opacity-100"
    x-transition:leave-end="translate-y-full sm:translate-y-0 sm:origin-top-right sm:scale-95 sm:opacity-0"
    {{ $attributes->class([
        'fixed inset-x-0 bottom-0 z-50 max-h-[85dvh] touch-pan-y overflow-y-auto overscroll-contain rounded-t-2xl border border-b-0 border-line bg-surface pb-[env(safe-area-inset-bottom)] text-left shadow-2xl dark:border-line-dark dark:bg-surface-dark',
        'sm:absolute sm:inset-x-auto sm:right-0 sm:bottom-auto sm:z-40 sm:mt-2 sm:max-h-none sm:overflow-hidden sm:rounded-xl sm:border-b sm:pb-0 sm:shadow-lg',
        $width,
    ]) }}
>
    <div class="mx-auto mt-2 mb-1 h-1 w-10 rounded-full bg-line sm:hidden dark:bg-line-dark" aria-hidden="true"></div>
    {{ $slot }}
</div>
