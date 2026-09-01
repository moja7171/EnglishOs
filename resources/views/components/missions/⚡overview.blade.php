<?php

use App\Models\Mission;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function missions()
    {
        return Mission::orderBy('code')->get();
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

    @forelse ($this->missions as $mission)
        <a href="{{ route('missions.show', $mission) }}"
           data-mood="{{ $mission->moodKey() }}"
           class="block rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-accent dark:border-line-dark dark:bg-surface-dark dark:hover:border-accent-dark">
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $mission->code }} · {{ $mission->module }}</p>
            <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $mission->title }}</h2>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $mission->outcome }}</p>
        </a>
    @empty
        <p class="text-sm text-ink-faint dark:text-ink-faint-dark">
            No missions seeded yet. Run <code class="font-mono">php artisan db:seed</code>.
        </p>
    @endforelse
</div>
