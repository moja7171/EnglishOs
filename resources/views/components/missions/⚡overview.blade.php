<?php

use App\Models\Mission;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * The full curriculum is 24 missions (EOS-009 §15 roadmap, v3.0); only
     * the ones actually seeded so far are playable. Every slot 1-24 is
     * shown so the whole path is visible from day one — seeded missions as
     * real clickable cards, the rest as locked placeholders — rather than
     * the list just trailing off after whatever happens to exist yet.
     *
     * @return list<Mission|string> a Mission where seeded, otherwise its
     *   not-yet-seeded code (e.g. "M07")
     */
    #[Computed]
    public function missionSlots(): array
    {
        $seeded = Mission::orderBy('code')->get()->keyBy('code');

        return collect(range(1, 24))
            ->map(fn ($n) => sprintf('M%02d', $n))
            ->map(fn ($code) => $seeded->get($code) ?? $code)
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <p class="inline-flex items-center gap-1.5 text-xs font-bold tracking-widest text-ink-faint uppercase dark:text-ink-faint-dark">
            <span class="h-1.5 w-1.5 rounded-full bg-accent dark:bg-accent-dark"></span>
            English OS
        </p>
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Missions</h1>
    </header>

    @foreach ($this->missionSlots as $slot)
        @if ($slot instanceof Mission)
            <a href="{{ route('missions.show', $slot) }}"
               data-mood="{{ $slot->moodKey() }}"
               class="block rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-accent dark:border-line-dark dark:bg-surface-dark dark:hover:border-accent-dark">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $slot->code }} · {{ $slot->module }}</p>
                <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $slot->title }}</h2>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $slot->outcome }}</p>
            </a>
        @else
            <div class="flex items-center justify-between rounded-2xl border border-line bg-surface-sunken p-4 opacity-60 dark:border-line-dark dark:bg-surface-sunken-dark">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $slot }}</p>
                    <p class="font-display text-lg font-bold text-ink-faint dark:text-ink-faint-dark">Coming soon</p>
                </div>
                <span class="shrink-0 text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-lock-closed', 'h-4 w-4')</span>
            </div>
        @endif
    @endforeach
</div>
