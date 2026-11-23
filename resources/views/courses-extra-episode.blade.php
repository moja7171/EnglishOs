{{-- One Extra English episode: the full video and one CC button for the
     English captions. Nothing else on purpose: no Persian, no resume, no
     "watched" tick. The video can go full screen from its own control bar. --}}
<x-layouts.app>
    <div class="mx-auto max-w-3xl space-y-4 p-4 sm:p-6">
        <header class="flex items-center gap-2">
            <a href="{{ route('courses.extra') }}" wire:navigate aria-label="Extra English" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <div class="min-w-0">
                <p class="text-xs font-extrabold text-accent dark:text-accent-dark">Episode {{ $episode->number }} · {{ $episode->durationLabel() }}</p>
                <h1 class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">{{ $episode->title }}</h1>
            </div>
        </header>

        @if ($episode->videoUrl())
            <x-video-player
                :url="$episode->videoUrl()"
                :captions-url="$episode->captionsUrl()"
                :poster="$episode->posterUrl()"
                :title="$episode->title"
            />
        @else
            <p class="card p-4 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark" data-testid="extra-no-video">This video is not available here yet.</p>
        @endif
    </div>
</x-layouts.app>
