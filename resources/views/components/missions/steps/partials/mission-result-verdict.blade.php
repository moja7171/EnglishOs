{{-- Mission Result's celebration: ✓ (or ↻ when it needs a look), the
     streak, the AI's one-line reason, and for a mission that isn't
     complete, the step to redo and a fresh result. --}}
<div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
    @if ($status === 'complete')
        <span class="flex h-24 w-24 items-center justify-center rounded-full bg-success text-white shadow-[0_0_0_10px_var(--color-success-soft)] dark:bg-success-dark">@svg('heroicon-o-check', 'h-12 w-12')</span>
        <h2 class="mt-2 font-display text-3xl font-semibold text-ink dark:text-ink-dark">{{ $run->mission->code }} complete!</h2>
    @else
        <span class="flex h-24 w-24 items-center justify-center rounded-full bg-warning-soft text-warning-ink">@svg('heroicon-o-arrow-path', 'h-12 w-12')</span>
        <h2 class="mt-2 font-display text-3xl font-semibold text-ink dark:text-ink-dark">{{ $status === 'needs_review' ? 'Almost there' : "Let's try that again" }}</h2>
    @endif

    @if ($milestoneJustReached)
        <span class="animate-badge-pop rounded-full bg-accent-soft px-3 py-1 text-sm font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">🏆 {{ $milestoneJustReached }}-day streak!</span>
    @elseif ($streak)
        <span class="rounded-full bg-accent-soft px-3 py-1 text-sm font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">🔥 {{ $streak }}-day streak</span>
    @endif

    @if ($reason)
        <p class="max-w-xs text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">{{ $reason }}</p>
    @endif

    @if ($status !== 'complete')
        <div class="mt-2 flex flex-wrap justify-center gap-2">
            @if ($weakStep)
                <a href="{{ route('missions.show', ['mission' => $run->mission, 'step' => $weakStep, 'retry' => 1]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-bold text-white dark:bg-accent-dark">
                    @svg('heroicon-o-arrow-uturn-left', 'h-4 w-4') Redo {{ $run->mission->stepLabel($weakStep) }}
                </a>
            @endif
            <a href="{{ route('missions.show', ['mission' => $run->mission, 'step' => 'mission_result', 'retry' => 1]) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-full bg-surface px-4 py-2 text-sm font-bold text-ink dark:bg-surface-dark dark:text-ink-dark">
                @svg('heroicon-o-arrow-path', 'h-4 w-4') Get an updated result
            </a>
        </div>
    @endif
</div>
