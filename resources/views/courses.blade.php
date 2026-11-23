{{-- Courses: one big card per course, outside the mission path (see
     App\Services\Courses). A course's due-card count shows on its own card
     only, never in the Review tab. --}}
<x-layouts.app>
    <div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
        <header class="flex items-center gap-2">
            <a href="{{ route('home') }}" wire:navigate aria-label="Home" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <div>
                <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Courses</h1>
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Outside your mission path. Open any time.</p>
            </div>
        </header>

        @foreach ($courses as $course)
            <a href="{{ $course['url'] }}" wire:navigate wire:key="course-{{ $course['key'] }}" class="card block space-y-4 p-5 transition-colors hover:border-accent dark:hover:border-accent-dark">
                <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-accent-soft text-accent dark:bg-accent-soft-dark dark:text-accent-dark">@svg($course['icon'], 'h-6 w-6')</span>
                    @if ($course['dueCards'] > 0)
                        <span class="rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold text-accent dark:bg-accent-soft-dark dark:text-accent-dark">{{ $course['dueCards'] }} {{ Str::plural('card', $course['dueCards']) }} due</span>
                    @endif
                </div>
                <div>
                    <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">{{ $course['name'] }}</p>
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $course['blurb'] }}</p>
                </div>
                @if ($course['progress'] !== null)
                    <x-progress-bar>
                        <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ $course['progress'] }}%"></div>
                        <x-slot:label>
                            <span class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $course['progressLabel'] }}</span>
                        </x-slot:label>
                    </x-progress-bar>
                @else
                    <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $course['progressLabel'] }}</p>
                @endif
            </a>
        @endforeach
    </div>
</x-layouts.app>
