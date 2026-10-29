@php
    $user = auth()->user();

    $tracks = [
        'words' => ['title' => 'Words', 'icon' => 'language'],
        'talk' => ['title' => 'Speaking & grammar', 'icon' => 'chat-bubble-left-right'],
    ];
@endphp

{{-- Daily Review's two paths: the learner's words (ten a day, quick enough
     for a bus) and everything spoken or about grammar (needs a quiet spot
     and the mic). A path nobody has touched today but has something waiting
     gets the accent border. --}}
<x-layouts.app>
    <div class="mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-6">
        <h1 class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">Review</h1>

        <div class="grid gap-3">
            @foreach ($tracks as $track => $card)
                @php
                    $summary = $user->reviewTrackSummary($track);
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
                                {{ $summary['remaining'] }} left today
                            @elseif ($summary['reviewedToday'] > 0)
                                Done for today
                            @else
                                Nothing due
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
    </div>
</x-layouts.app>
