{{--
    The first screen's top for every mission step: a small chip naming the
    kind of step and its minutes, the step's hook as a big title, and the
    step's picture under it when there is one.

    @param string $kind The step's name, e.g. "Get Ready".
    @param int|null $minutes The step's expected minutes.
    @param string|null $title The step's hook.
    @param string|null $image A picture URL.
--}}
@props(['kind', 'minutes' => null, 'title' => null, 'image' => null])

<div class="flex flex-col gap-3">
    <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">
        {{ $kind }}@if ($minutes) · {{ $minutes }} min @endif
    </span>

    @if (filled($title))
        <h2 class="font-display text-2xl leading-tight font-semibold text-balance text-ink dark:text-ink-dark">{{ $title }}</h2>
    @endif
</div>

@if ($image)
    <x-zoomable-image :url="$image" alt="" class="h-44 w-full rounded-3xl object-cover" />
@endif
