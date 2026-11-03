{{--
    One screen of a mission step, shown one at a time like a lesson in a
    learning app: the step's own Alpine state decides which screen is on
    ($when), the content fills the screen, and the `actions` slot (the
    full-width Continue, a quiet Skip under it) sits at the bottom of it in
    the same place on every screen. Each screen slides in as it appears
    (see .animate-lesson-screen-in in app.css). A screen taller than the phone simply
    scrolls, with its actions at the end — nothing here is pinned. A screen
    that comes on scrolls the page back to the top.

    @param string $when Raw Alpine expression for when this screen shows,
        e.g. "screen === 1".
    @slot actions The screen's buttons, bottom of the screen.
--}}
@props(['when'])

<section
    x-show="{{ $when }}"
    x-cloak
    {{-- A new screen opens at its top: the old screen's scroll offset would leave its first lines under the pinned step header. --}}
    x-data="{ init() { this.$watch(() => {{ $when }}, (shown) => { if (shown && window.scrollY > 0) window.scrollTo({ top: 0 }) }) } }"
    {{ $attributes->class(['animate-lesson-screen-in flex min-h-[calc(100dvh-11rem)] flex-col gap-5 pt-2']) }}
>
    <div class="flex flex-1 flex-col gap-5">
        {{ $slot }}
    </div>

    @isset($actions)
        <div class="flex flex-col items-stretch gap-3 pb-2">
            {{ $actions }}
        </div>
    @endisset
</section>
