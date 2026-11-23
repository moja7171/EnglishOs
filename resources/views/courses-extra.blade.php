{{-- Extra English: the course's home. 30 episodes, every one open: no order,
     no "next", no watched mark (see App\Models\ExtraEpisode). --}}
<x-layouts.app>
    <div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
        <header class="flex items-center gap-2">
            <a href="{{ route('courses.index') }}" wire:navigate aria-label="Courses" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Extra English</h1>
        </header>

        <ul class="grid grid-cols-2 gap-3">
            @foreach ($episodes as $episode)
                <li wire:key="extra-episode-{{ $episode->number }}">
                    <a href="{{ route('courses.extra.episode', $episode->number) }}" wire:navigate data-testid="extra-episode" class="card block h-full overflow-hidden transition-colors hover:border-accent dark:hover:border-accent-dark">
                        <img src="{{ $episode->posterUrl() }}" alt="" loading="lazy" width="320" height="180" class="aspect-video w-full bg-surface-sunken object-cover dark:bg-surface-sunken-dark">
                        <span class="block space-y-0.5 p-3">
                            <span class="block text-xs font-extrabold text-accent dark:text-accent-dark">{{ $episode->number }}</span>
                            <span class="block text-sm font-extrabold text-ink dark:text-ink-dark">{{ $episode->title }}</span>
                            <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $episode->durationLabel() }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.app>
