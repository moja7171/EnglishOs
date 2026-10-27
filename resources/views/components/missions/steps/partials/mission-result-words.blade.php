{{-- The mission's own words, green when the learner really used them. --}}
<div class="flex flex-col gap-3">
    <p class="text-2xl leading-tight font-extrabold text-ink dark:text-ink-dark">Words you used</p>
    <div class="flex flex-wrap gap-2">
        @foreach ($usage as $item)
            <span @class([
                'rounded-full px-3 py-1.5 text-sm font-bold',
                'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $item['used'],
                'bg-surface text-ink-faint dark:bg-surface-dark dark:text-ink-faint-dark' => ! $item['used'],
            ])>{{ $item['word'] }}@if ($item['used']) ✓@endif</span>
        @endforeach
    </div>
    <p class="text-sm font-bold text-ink-faint dark:text-ink-faint-dark">{{ $usedCount }} of {{ count($usage) }}</p>
</div>
