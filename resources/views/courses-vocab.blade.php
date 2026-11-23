{{-- Vocab in Use: the course's home is the list of its lessons, in the book's
     order and grouped by its 16 sections. Every lesson is open from the start
     and the page never suggests one (no "today", no lock). A lesson whose
     texts are not built yet is dimmed and cannot be opened. --}}
<x-layouts.app>
    <div
        class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6"
        x-data="{
            q: '',
            match(rows) {
                const q = this.q.trim().toLowerCase();
                return q === '' || rows.some((row) => String(row[0]) === q || row[1].toLowerCase().includes(q));
            },
        }"
    >
        <header class="flex items-center gap-2">
            <a href="{{ route('courses.index') }}" wire:navigate aria-label="Courses" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <h1 class="min-w-0 flex-1 font-display text-3xl font-semibold text-ink dark:text-ink-dark">Vocab in Use</h1>
            <span class="rounded-full bg-surface-sunken px-3 py-1 text-xs font-extrabold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $openedUnits }} of {{ $totalUnits }} opened</span>
        </header>

        <x-progress-bar>
            <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round($openedUnits / max(1, $totalUnits) * 100) }}%"></div>
        </x-progress-bar>

        @if (session('vocab-added'))
            <p class="rounded-2xl bg-success-soft px-4 py-3 text-sm font-bold text-success dark:bg-success-soft-dark dark:text-success-dark" data-testid="vocab-added">{{ session('vocab-added') }}</p>
        @endif

        @if ($dueCards > 0)
            <a href="{{ route('review.track', 'vocab') }}" wire:navigate data-testid="vocab-due" class="card flex items-center gap-3 p-3.5 transition-colors hover:border-accent dark:hover:border-accent-dark">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent dark:bg-accent-soft-dark dark:text-accent-dark">@svg('heroicon-s-bolt', 'h-5 w-5')</span>
                <span class="min-w-0 flex-1">
                    <span class="block font-extrabold text-ink dark:text-ink-dark">{{ $dueCards }} {{ Str::plural('word', $dueCards) }} to review</span>
                    <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Open in Review · Vocab in Use</span>
                </span>
                @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            </a>
        @endif

        <label class="card flex items-center gap-2 px-3.5 py-2.5 text-ink-faint dark:text-ink-faint-dark">
            @svg('heroicon-o-magnifying-glass', 'h-4 w-4 shrink-0')
            <input id="vocab-search" type="search" x-model="q" placeholder="Search a lesson" autocomplete="off" class="min-w-0 flex-1 bg-transparent text-base text-ink placeholder:text-ink-faint focus:outline-none dark:text-ink-dark dark:placeholder:text-ink-faint-dark">
        </label>

        @foreach ($sections as $section)
            <section wire:key="vocab-section-{{ $section['number'] }}" class="space-y-1.5" x-show="match({{ Illuminate\Support\Js::from(array_map(fn (array $unit) => [$unit['number'], $unit['title']], $section['units'])) }})">
                <div class="flex items-baseline justify-between gap-2 px-1">
                    <h2 class="text-xs font-extrabold tracking-wide text-ink-soft uppercase dark:text-ink-soft-dark">{{ $section['name'] }}</h2>
                    <span class="text-[11px] font-bold text-ink-faint dark:text-ink-faint-dark">Lessons {{ $section['units'][0]['number'] }}–{{ end($section['units'])['number'] }}</span>
                </div>
                <ul class="card divide-y divide-line overflow-hidden dark:divide-line-dark">
                    @foreach ($section['units'] as $unit)
                        @php
                            $done = $unit['state'] === 'done';
                            $partial = ! $done && $unit['added'] > 0;
                        @endphp
                        <li wire:key="vocab-unit-{{ $unit['number'] }}" x-show="match({{ Illuminate\Support\Js::from([[$unit['number'], $unit['title']]]) }})">
                            @if ($unit['url'])
                                <a href="{{ $unit['url'] }}" wire:navigate class="flex items-center gap-3 px-3.5 py-2.5 transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                                    <span @class([
                                        'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-extrabold',
                                        'bg-success text-white dark:bg-success-dark' => $done,
                                        'bg-accent-soft text-accent ring-2 ring-accent dark:bg-accent-soft-dark dark:text-accent-dark dark:ring-accent-dark' => ! $done && $unit['state'] === 'open',
                                        'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => ! $done && $unit['state'] === 'new',
                                    ])>@if ($done) @svg('heroicon-s-check', 'h-4 w-4') @else {{ $unit['number'] }} @endif</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm leading-tight font-bold text-ink dark:text-ink-dark">{{ $unit['title'] }}</span>
                                        @if ($partial && $unit['total'] > 0)
                                            <span class="mt-1.5 block h-[3px] overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark"><span class="block h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round(min(1, $unit['added'] / $unit['total']) * 100) }}%"></span></span>
                                        @endif
                                    </span>
                                    @if ($done || $partial)
                                        <span @class(['shrink-0 text-xs font-bold', 'text-success dark:text-success-dark' => $done, 'text-accent-ink dark:text-accent-ink-dark' => ! $done])>{{ $done ? $unit['added'].' '.Str::plural('word', $unit['added']) : $unit['added'].' / '.$unit['total'] }}</span>
                                    @endif
                                </a>
                            @else
                                <div class="flex items-center gap-3 px-3.5 py-2.5 opacity-50" aria-disabled="true">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-extrabold text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">{{ $unit['number'] }}</span>
                                    <span class="min-w-0 flex-1 text-sm leading-tight font-bold text-ink-faint dark:text-ink-faint-dark">{{ $unit['title'] }}</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-layouts.app>
