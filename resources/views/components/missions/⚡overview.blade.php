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
    <header class="border-b border-neutral-300 pb-4 dark:border-neutral-700">
        <p class="font-mono text-xs tracking-widest text-neutral-500 uppercase">English OS</p>
        <h1 class="text-2xl font-extrabold">Missions</h1>
    </header>

    @forelse ($this->missions as $mission)
        <article class="rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <p class="font-mono text-xs text-neutral-500">{{ $mission->code }} · {{ $mission->module }}</p>
            <h2 class="text-lg font-bold">{{ $mission->title }}</h2>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">{{ $mission->outcome }}</p>
        </article>
    @empty
        <p class="text-sm text-neutral-500">
            No missions seeded yet. Run <code class="font-mono">php artisan db:seed</code>.
        </p>
    @endforelse
</div>
