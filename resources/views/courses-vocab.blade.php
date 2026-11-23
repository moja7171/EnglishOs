{{-- Vocab in Use: the course's home. Every unit is open from the start and
     the page never suggests one (no "today", no lock, no order): the map
     of the 90 units, grouped by the book's 16 sections, is the page. A unit
     whose texts are not built yet is dimmed and cannot be opened. --}}
<x-layouts.app>
    <div class="mx-auto max-w-2xl space-y-5 p-4 sm:p-6">
        <header class="flex items-center gap-2">
            <a href="{{ route('courses.index') }}" wire:navigate aria-label="Courses" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <h1 class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">Vocab in Use</h1>
        </header>

        <x-progress-bar>
            <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ round($openedUnits / max(1, $totalUnits) * 100) }}%"></div>
            <x-slot:label>
                <span class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $openedUnits }} of {{ $totalUnits }} units opened</span>
            </x-slot:label>
        </x-progress-bar>

        @if ($dueCards > 0)
            <div class="card-accent flex items-center justify-between gap-3 px-4 py-3" data-testid="vocab-due">
                <div>
                    <p class="font-extrabold text-ink dark:text-ink-dark">{{ $dueCards }} {{ Str::plural('card', $dueCards) }} due</p>
                    <p class="text-xs font-bold text-accent dark:text-accent-dark">about {{ max(1, (int) ceil(min($dueCards, \App\Services\VocabReview::DAILY_CAP) / 10)) }} min</p>
                </div>
                <a href="{{ route('courses.vocab.review') }}" wire:navigate class="rounded-full bg-accent px-5 py-2 text-sm font-bold text-white dark:bg-accent-dark">Review</a>
            </div>
        @endif

        @if ($stats['cards'] > 0)
            <dl class="grid grid-cols-3 gap-2 text-center" data-testid="vocab-stats">
                <div class="card px-2 py-3"><dd class="text-xl font-extrabold text-ink dark:text-ink-dark">{{ $stats['cards'] }}</dd><dt class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">sentences</dt></div>
                <div class="card px-2 py-3"><dd class="text-xl font-extrabold text-ink dark:text-ink-dark">{{ $stats['strong'] }}</dd><dt class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">strong</dt></div>
                <div class="card px-2 py-3"><dd class="text-xl font-extrabold text-ink dark:text-ink-dark">{{ $stats['tricky'] }}</dd><dt class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">tricky</dt></div>
            </dl>
        @endif

        <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Pick any unit. Each one has 3 steps: Listen, Read, Words.</p>

        @foreach ($sections as $section)
            <section wire:key="vocab-section-{{ $section['number'] }}" class="space-y-2">
                <h2 class="px-1 text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $section['name'] }}</h2>
                <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($section['units'] as $unit)
                        <li wire:key="vocab-unit-{{ $unit['number'] }}">
                            @if ($unit['url'])
                                <a href="{{ $unit['url'] }}" wire:navigate @class([
                                    'card flex h-full items-start gap-2.5 p-3 transition-colors hover:border-accent dark:hover:border-accent-dark',
                                    'border-success dark:border-success-dark' => $unit['state'] === 'done',
                                ])>
                                    <span @class([
                                        'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-extrabold',
                                        'bg-success text-white dark:bg-success-dark' => $unit['state'] === 'done',
                                        'bg-accent-soft text-accent dark:bg-accent-soft-dark dark:text-accent-dark' => $unit['state'] === 'open',
                                        'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => $unit['state'] === 'new',
                                    ])>@if ($unit['state'] === 'done') @svg('heroicon-s-check', 'h-4 w-4') @else {{ $unit['number'] }} @endif</span>
                                    <span class="line-clamp-2 min-w-0 flex-1 text-sm leading-tight font-semibold text-ink dark:text-ink-dark">{{ $unit['title'] }}</span>
                                    @if ($unit['review'])
                                        <span @class([
                                            'mt-1 h-2.5 w-2.5 shrink-0 rounded-full',
                                            'bg-accent dark:bg-accent-dark' => $unit['review'] === 'due',
                                            'bg-warning' => $unit['review'] === 'fading',
                                            'bg-success dark:bg-success-dark' => $unit['review'] === 'fresh',
                                        ]) title="{{ $unit['review'] }}" aria-label="Review: {{ $unit['review'] }}"></span>
                                    @endif
                                </a>
                            @else
                                <div class="card-sunken flex h-full items-start gap-2.5 p-3 opacity-60" aria-disabled="true">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface text-xs font-extrabold text-ink-faint dark:bg-surface-dark dark:text-ink-faint-dark">{{ $unit['number'] }}</span>
                                    <span class="line-clamp-2 text-sm leading-tight font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $unit['title'] }}</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        @if ($tricky !== [])
            <section class="card space-y-2 p-4" data-testid="vocab-tricky">
                <h2 class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Tricky words · {{ count($tricky) }}</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($tricky as $phrase)
                        <li class="rounded-full bg-surface-sunken px-3 py-1 text-sm font-bold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">{{ $phrase }}</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-layouts.app>
