@props(['gatingMission'])

{{-- Shown on the day overview of a mission the learner hasn't unlocked yet:
     they can look through every day and step, just not do any of it. --}}
<div class="rounded-2xl border border-dashed border-accent bg-accent-soft p-4 text-sm dark:border-accent-dark dark:bg-accent-soft-dark" role="status">
    <p class="flex items-center gap-2 font-semibold text-accent-ink dark:text-accent-ink-dark">
        @svg('heroicon-o-eye', 'h-4 w-4 shrink-0')
        Preview only: this mission isn't unlocked yet
    </p>
    <p class="mt-1 text-ink-soft dark:text-ink-soft-dark">
        Look through every day and step. You can't do any of it until
        @if ($gatingMission)
            you finish {{ $gatingMission->code }} · {{ $gatingMission->title }}.
        @else
            the missions before it are finished.
        @endif
    </p>
    @if ($gatingMission)
        <a
            href="{{ route('missions.show', [$gatingMission, 'overview']) }}"
            wire:navigate
            class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-3 py-1.5 text-xs font-bold text-white transition-opacity hover:opacity-85 dark:bg-accent-dark"
        >
            Go to {{ $gatingMission->code }}
            @svg('heroicon-o-chevron-right', 'h-3 w-3')
        </a>
    @endif
</div>
