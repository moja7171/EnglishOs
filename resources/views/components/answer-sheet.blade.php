{{--
    The answer panel at the bottom of a lesson screen once the learner has
    answered: green when it's right, orange when it's nearly right, a short
    bold verdict, a line or two (e.g. the fix), and the screen's Continue.
    It takes the place of the screen's buttons, full width to the screen's
    edges, so the eye goes from the answer straight to what's next.

    @param string $when Raw Alpine expression for when it shows.
    @param string $tone Raw Alpine expression giving 'good' or 'almost',
        or a quoted constant like "'good'".
    @param string $title Raw Alpine expression for the verdict, or a quoted
        constant like "'Right!'".
    @slot actions The Continue button.
--}}
@props(['when', 'tone' => "'good'", 'title' => "'Right!'"])

<div
    x-show="{{ $when }}"
    x-cloak
    x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
    x-transition:enter-start="translate-y-4 opacity-0"
    x-transition:enter-end="translate-y-0 opacity-100"
    x-bind:data-tone="{{ $tone }}"
    role="status"
    {{ $attributes->class('group -mx-4 flex flex-col gap-3 rounded-t-3xl px-4 pt-4 pb-3 sm:-mx-6 sm:px-6 data-[tone=good]:bg-success-soft data-[tone=almost]:bg-accent-soft dark:data-[tone=good]:bg-success-soft-dark dark:data-[tone=almost]:bg-accent-soft-dark') }}
>
    <p class="text-lg font-extrabold group-data-[tone=good]:text-success group-data-[tone=almost]:text-accent-ink dark:group-data-[tone=good]:text-success-dark dark:group-data-[tone=almost]:text-accent-ink-dark" x-text="{{ $title }}"></p>

    @if ($slot->isNotEmpty())
        <div class="text-sm leading-relaxed text-ink dark:text-ink-dark">{{ $slot }}</div>
    @endif

    @isset($actions)
        <div class="flex flex-col items-stretch gap-3">{{ $actions }}</div>
    @endisset
</div>
