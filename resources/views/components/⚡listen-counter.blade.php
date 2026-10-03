<?php

use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Listens N" chip beside an in-app player. The player's tracker
 * (eosListenTracker in resources/js/app.js) dispatches `listen-completed`
 * once at least 90% of the recording has genuinely played through; this
 * component stores that listen and re-renders the number. Every instance on
 * the page receives the event, so each ignores one meant for another
 * mission or source.
 */
new class extends Component
{
    #[Locked]
    public string $missionCode;

    #[Locked]
    public string $source;

    public int $count = 0;

    public function mount(): void
    {
        $this->count = auth()->user()?->audioListenCount($this->missionCode, $this->source) ?? 0;
    }

    #[On('listen-completed')]
    public function record(string $missionCode, string $source, float $duration = 0): void
    {
        if ($missionCode !== $this->missionCode || $source !== $this->source) {
            return;
        }

        $user = auth()->user();
        $minimumGapSeconds = 0.85 * min(max($duration, 5), 7200);

        $latest = $user->audioListens()
            ->where('mission_code', $missionCode)
            ->where('source', $source)
            ->latest()
            ->first();

        // A real listen takes about as long as the recording itself, so a
        // second one arriving sooner than that is a duplicate or a faked
        // call, not another listen.
        if ($latest && $latest->created_at->diffInSeconds(now(), true) < $minimumGapSeconds) {
            return;
        }

        $user->audioListens()->create(['mission_code' => $missionCode, 'source' => $source]);

        $this->count = $user->audioListenCount($missionCode, $source);
    }
};
?>

<span
    class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-semibold text-ink-soft tabular-nums dark:bg-surface-sunken-dark dark:text-ink-soft-dark"
    title="Counts each time you hear at least 90% of it, from the start."
>
    @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
    Listens: <span data-testid="listen-count">{{ $count }}</span>
</span>
