{{--
    A bottom sheet: the panel that slides up over a dimmed page for
    details, choices and help (a notebook line, the prompt Pi gets, a
    friend's board card, an example answer). One shape everywhere: grab
    handle, rounded top, the page dimmed behind it, closed by a tap on the
    dim, a swipe down (phones), Esc, or the phone's Back button (see Alpine
    directives 'swipe-close' and 'back-close').

    $show names a boolean in the surrounding x-data, which opens it and
    which it sets back to false when it closes — e.g. x-data="{ ideas:
    false }" with a button x-on:click="ideas = true" and
    <x-bottom-sheet show="ideas" label="Ideas">. Sits under the dictionary
    sheet (z-70), so a word tapped inside a sheet still opens its meaning
    on top, and over the full-screen players (z-50).

    @param string $show Alpine boolean the sheet reads and closes.
    @param string $label The dialog's accessible name.
    @slot title Optional bold first line.
--}}
@props(['show', 'label'])

<div
    x-on:keydown.escape.window="if ({{ $show }}) { {{ $show }} = false }"
    x-back-close="{ open: {{ $show }}, close: () => {{ $show }} = false }"
>
    <div
        x-show="{{ $show }}"
        x-cloak
        x-on:click="{{ $show }} = false"
        x-transition.opacity.duration.200ms
        aria-hidden="true"
        class="fixed inset-0 z-[55] bg-ink/40"
    ></div>

    <div
        x-show="{{ $show }}"
        x-cloak
        x-swipe-close="{{ $show }} = false"
        x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $label }}"
        class="fixed inset-x-0 bottom-0 z-[56] mx-auto max-h-[85dvh] w-full max-w-lg touch-pan-y overflow-y-auto overscroll-contain rounded-t-3xl border border-b-0 border-line bg-surface pb-[env(safe-area-inset-bottom)] text-left shadow-2xl dark:border-line-dark dark:bg-surface-dark"
    >
        <div class="mx-auto mt-2 mb-1 h-1 w-10 rounded-full bg-line dark:bg-line-dark" aria-hidden="true"></div>

        <div {{ $attributes->class('flex flex-col gap-4 px-4 pt-2 pb-5 sm:px-5') }}>
            @isset($title)
                <p class="text-base font-extrabold text-ink dark:text-ink-dark">{{ $title }}</p>
            @endisset

            {{ $slot }}
        </div>
    </div>
</div>
