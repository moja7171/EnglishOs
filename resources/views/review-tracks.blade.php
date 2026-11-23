@php
    $user = auth()->user();

    $tracks = [
        'words' => ['title' => 'Words', 'icon' => 'language'],
        'vocab' => ['title' => 'Vocab in Use', 'icon' => 'academic-cap'],
        'talk' => ['title' => 'Mistakes & reminders', 'icon' => 'book-open'],
    ];

    // A path counts for today when it has something left or was worked on; the ring slice needs all of them done.
    $summaries = collect($tracks)->map(fn (array $card, string $track): array => $user->reviewTrackSummary($track));
    $active = $summaries->filter(fn (array $summary): bool => $summary['remaining'] > 0 || $summary['reviewedToday'] > 0);
    $finished = $active->filter(fn (array $summary): bool => $summary['remaining'] === 0)->count();
@endphp

{{-- Daily Review's two paths: the learner's words (ten a day, quick enough
     for a bus) and the mistakes and grammar reminders to read (no mic, no answers). A path nobody has touched today but has something waiting
     gets the accent border. --}}
<x-layouts.app :refresh-on-return="true">
    <div class="mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-6">
        <div class="flex items-center justify-between gap-2">
            <h1 class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">Review</h1>
            <div class="flex items-center gap-2">
                @if ($active->count() > 1)
                    <span class="rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark" data-testid="review-progress">{{ $finished }} of {{ $active->count() }} done today</span>
                @endif
                <x-usage-guide-button />
            </div>
        </div>
        <livewire:usage-guide guide="review" />

        <div class="grid gap-3">
            @foreach ($tracks as $track => $card)
                @php
                    $summary = $summaries[$track];
                    $total = $summary['reviewedToday'] + $summary['remaining'];
                    $isNext = $summary['remaining'] > 0 && $summary['reviewedToday'] === 0;
                @endphp
                <a
                    href="{{ route('review.track', $track) }}"
                    wire:navigate
                    wire:key="review-track-{{ $track }}"
                    @class([
                        'flex items-center gap-4 rounded-[1.75rem] border-2 bg-surface p-5 transition-colors hover:bg-surface-sunken dark:bg-surface-dark dark:hover:bg-surface-sunken-dark',
                        'border-accent dark:border-accent-dark' => $isNext,
                        'border-transparent' => ! $isNext,
                    ])
                >
                    <x-progress-ring :percent="$total > 0 ? round($summary['reviewedToday'] / $total * 100) : 0" :size="56" :stroke-width="5">
                        @svg('heroicon-o-'.$card['icon'], 'h-6 w-6 text-ink-soft dark:text-ink-soft-dark')
                    </x-progress-ring>
                    <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="text-lg font-extrabold text-ink dark:text-ink-dark">{{ $card['title'] }}</span>
                        <span class="text-sm text-ink-soft dark:text-ink-soft-dark">
                            @if ($summary['remaining'] > 0)
                                {{ $summary['remaining'] }} {{ $track === 'talk' ? 'to read today' : 'left today' }}
                            @elseif ($summary['reviewedToday'] > 0)
                                {{ $track === 'talk' ? 'All read today' : 'Done for today' }}
                            @else
                                {{ $track === 'talk' ? 'Nothing to read yet' : 'Nothing due' }}
                            @endif
                        </span>
                        @if ($summary['waiting'] > 0)
                            <span class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $summary['waiting'] }} more waiting</span>
                        @endif
                    </span>
                    @svg('heroicon-o-chevron-right', 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                </a>
            @endforeach
        </div>

        @if ($active->count() > 1)
            <p class="rounded-2xl bg-accent-soft px-4 py-3 text-sm font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Finish every path to complete today's review ring.</p>
        @endif
    </div>
</x-layouts.app>
