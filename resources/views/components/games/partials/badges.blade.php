{{--
    A player's eight game badges: earned ones in colour, the rest faded
    with how far along they are. Needs $badges (GameBadges::for) and
    $title.
--}}
@php $earnedCount = collect($badges)->where('earned', true)->count(); @endphp
<section class="space-y-2.5" x-data="{ open: null }">
    <div class="flex items-baseline justify-between">
        <h2 class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $title }}</h2>
        <span class="text-xs font-extrabold text-ink-faint tabular-nums dark:text-ink-faint-dark">{{ $earnedCount }} / {{ count($badges) }}</span>
    </div>
    <div class="grid grid-cols-4 gap-2">
        @foreach ($badges as $badge)
            <button type="button" wire:key="badge-{{ $badge['key'] }}" x-on:click="open = open === '{{ $badge['key'] }}' ? null : '{{ $badge['key'] }}'" class="flex cursor-pointer flex-col items-center gap-1 rounded-2xl bg-surface px-1 py-2.5 text-center shadow-sm dark:bg-surface-dark" aria-label="{{ $badge['name'] }}: {{ $badge['line'] }}">
                <span @class([
                    'inline-flex h-11 w-11 items-center justify-center rounded-full',
                    'bg-accent text-white dark:bg-accent-dark' => $badge['earned'],
                    'bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark' => ! $badge['earned'],
                ])>@svg($badge['icon'], 'h-6 w-6')</span>
                <span @class(['line-clamp-2 w-full text-[11px] leading-tight font-extrabold', 'text-ink dark:text-ink-dark' => $badge['earned'], 'text-ink-faint dark:text-ink-faint-dark' => ! $badge['earned']])>{{ $badge['name'] }}</span>
                @unless ($badge['earned'])
                    <span class="text-[10px] font-bold text-ink-faint tabular-nums dark:text-ink-faint-dark">{{ $badge['progress'] }}/{{ $badge['target'] }}</span>
                @endunless
            </button>
        @endforeach
    </div>
    @foreach ($badges as $badge)
        <p x-show="open === '{{ $badge['key'] }}'" x-cloak class="rounded-xl bg-surface-sunken px-3 py-2 text-center text-xs font-semibold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $badge['name'] }} — {{ $badge['line'] }}{{ $badge['earned'] ? ' ✓' : '' }}</p>
    @endforeach
</section>
