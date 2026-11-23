<?php

use App\Models\ExtraEpisode;
use App\Models\ExtraWatch;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $episodeId;

    public bool $watched = false;

    public function mount(int $episodeId): void
    {
        $this->episodeId = $episodeId;
        $this->watched = ExtraWatch::query()
            ->where('user_id', auth()->id())
            ->where('extra_episode_id', $episodeId)
            ->exists();
    }

    /**
     * The episode was really watched (the page decides: about 80% played,
     * never skipped to). Only a tick: nothing else depends on it.
     */
    #[Renderless]
    public function markWatched(): void
    {
        ExtraWatch::query()->firstOrCreate(
            ['user_id' => auth()->id(), 'extra_episode_id' => $this->episodeId],
            ['watched_at' => now()],
        );

        $this->watched = true;
    }

    public function episode(): ExtraEpisode
    {
        return ExtraEpisode::findOrFail($this->episodeId);
    }
};
?>

{{-- One Extra English episode: the full video, one CC button for the English
     captions, and a tick once it was really watched. It comes back where the
     learner left it (this phone). Nothing else on purpose: no Persian. --}}
@php($episode = $this->episode())
<div
    class="space-y-4"
    x-data="{ watched: @js($watched) }"
    x-init="$nextTick(() => {
        const video = $el.querySelector('video');
        if (! video || typeof window.eosListenTracker !== 'function') return;
        window.eosListenTracker(video, () => { if (! watched) { watched = true; $wire.markWatched(); } }, 0.8, null, 0.6, 'eos.extra.{{ $episode->number }}.heard');
    })"
>
    <header class="flex items-center gap-2">
        <a href="{{ route('courses.extra') }}" wire:navigate aria-label="Extra English" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <div class="min-w-0 flex-1">
            <p class="text-xs font-extrabold text-accent dark:text-accent-dark">Episode {{ $episode->number }} · {{ $episode->durationLabel() }}</p>
            <h1 class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">{{ $episode->title }}</h1>
        </div>
        <span x-show="watched" x-cloak data-testid="extra-watched" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 text-xs font-extrabold text-success dark:bg-success-soft-dark dark:text-success-dark">
            @svg('heroicon-s-check', 'h-4 w-4') Watched
        </span>
    </header>

    @if ($episode->videoUrl())
        <x-video-player
            :url="$episode->videoUrl()"
            :captions-url="$episode->captionsUrl()"
            :poster="$episode->posterUrl()"
            :title="$episode->title"
            :resume-key="'eos.extra.'.$episode->number.'.position'"
            skip-buttons
        />
    @else
        <p class="card p-4 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark" data-testid="extra-no-video">This video is not available here yet.</p>
    @endif
</div>
