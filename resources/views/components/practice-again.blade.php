@props(['missionCode'])

{{-- A finished mission's way back to its extra practice: one row for the
     listening picks and one for the voice practice, each with that
     mission's four days, green once done (see User::listenedDaysOf() and
     User::piPracticedDaysOf()). Shown on the Progress page of the mission
     and on its "done" card in the runner. Every day of a finished mission
     is reached, so none is locked. --}}
@php
    $user = auth()->user();

    $rows = [
        ['label' => 'Listening', 'icon' => 'speaker-wave', 'route' => 'listening.show', 'doneDays' => $user->listenedDaysOf($missionCode)],
        ['label' => 'Voice practice', 'icon' => 'microphone', 'route' => 'pi.practice', 'doneDays' => $user->piPracticedDaysOf($missionCode)],
    ];
@endphp

<section class="card space-y-3 p-4" aria-label="Practice again">
    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Practice again</p>
    @foreach ($rows as $row)
        <div class="flex items-center gap-3">
            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-'.$row['icon'], 'h-4 w-4')
            </span>
            <span class="min-w-0 flex-1 text-sm font-semibold text-ink dark:text-ink-dark">{{ $row['label'] }}</span>
            <div class="flex shrink-0 gap-1.5">
                @foreach (range(1, 4) as $number)
                    @php $isDone = in_array($number, $row['doneDays'], true); @endphp
                    <a
                        href="{{ route($row['route'], [$missionCode, $number]) }}"
                        wire:navigate
                        aria-label="{{ $row['label'] }}, day {{ $number }}{{ $isDone ? ', done' : '' }}"
                        @class([
                            'inline-flex h-9 w-9 items-center justify-center rounded-full border text-sm font-semibold transition-colors',
                            'border-transparent bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $isDone,
                            'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ! $isDone,
                        ])
                    >{{ $number }}</a>
                @endforeach
            </div>
        </div>
    @endforeach
</section>
