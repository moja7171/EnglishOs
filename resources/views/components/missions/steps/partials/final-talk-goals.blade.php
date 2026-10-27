{{-- Final Talk's 3-minute goals: empty circles until the talk is checked, then ticked or not. --}}
<div class="flex flex-col gap-1.5">
    @foreach ($this->requirements as $requirement)
        @php $met = $checklist[$requirement] ?? false; @endphp
        <div class="flex items-center gap-2.5 rounded-xl bg-surface px-3 py-2 text-sm font-semibold text-ink dark:bg-surface-dark dark:text-ink-dark">
            @if ($met)
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-o-check', 'h-3 w-3')</span>
            @else
                <span class="h-5 w-5 shrink-0 rounded-full border-2 border-line dark:border-line-dark"></span>
            @endif
            <span>{{ $requirement }}</span>
        </div>
    @endforeach
    @if ($checklist && $checklistNote)
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $checklistNote }}</p>
    @endif
</div>
