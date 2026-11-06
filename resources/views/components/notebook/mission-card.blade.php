{{--
    One mission's card in the Notebook (the Words tab, and the Mistakes tab
    grouped by mission): its photo or colour with the code, the title, how
    many are due, a count line, a thin status bar and a chevron that folds
    the rows (the slot). It lives inside the Notebook's Alpine state:
    shows(), filtering() and isOpen() decide what is on show, and while a
    search or filter is on the count line says how many were found.

    @param string $tab The Notebook tab the rows belong to (words, mistakes).
    @param string $groupKey Key of the fold state, unique on the page.
    @param bool $first Open at first (the latest mission).
    @param string $code The mission code, empty for the card of lines with no mission.
    @param string $title
    @param string|null $cover The mission's photo.
    @param string $countText Shown when nothing is searched, e.g. "6 words · 2 yours".
    @param int $due
    @param array{known: int, learning: int, new: int} $counts For the status bar.
    @param iterable $metas The search/filter data of the rows, in order.
    @slot default The rows.
--}}
@props(['tab', 'groupKey', 'first' => false, 'code' => '', 'title', 'cover' => null, 'countText', 'due' => 0, 'counts', 'metas'])

@php
    $firstJs = $first ? 'true' : 'false';
    $total = max(1, $counts['known'] + $counts['learning'] + $counts['new']);
@endphp
<section aria-label="{{ $title }}" class="overflow-hidden rounded-3xl bg-surface dark:bg-surface-dark" x-data="{ metas: {{ \Illuminate\Support\Js::from($metas) }} }" x-show="metas.some(meta => shows('{{ $tab }}', meta))">
    <button type="button" x-on:click="open['{{ $groupKey }}'] = ! isOpen('{{ $tab }}', '{{ $groupKey }}', {{ $firstJs }})" x-bind:aria-expanded="isOpen('{{ $tab }}', '{{ $groupKey }}', {{ $firstJs }})" class="flex w-full cursor-pointer items-center gap-3 p-3 text-left">
        <span class="relative flex h-14 w-14 shrink-0 items-end overflow-hidden rounded-2xl bg-linear-to-br from-hero to-dusk p-1.5 text-[13px] font-extrabold text-white">
            @if ($cover)
                <img src="{{ $cover }}" alt="" class="absolute inset-0 h-full w-full object-cover">
                <span class="absolute inset-0 bg-black/30"></span>
            @endif
            <span class="relative drop-shadow">{{ $code ?: '+' }}</span>
        </span>
        <span class="flex min-w-0 flex-1 flex-col gap-1.5">
            <span class="flex items-center gap-2">
                <span class="min-w-0 truncate text-base font-extrabold text-ink dark:text-ink-dark">{{ $title }}</span>
                @if ($due > 0)
                    <span class="shrink-0 rounded-full bg-accent-soft px-2 py-0.5 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $due }} due</span>
                @endif
            </span>
            <span class="text-[13px] font-semibold text-ink-soft dark:text-ink-soft-dark" x-text="filtering('{{ $tab }}') ? metas.filter(meta => shows('{{ $tab }}', meta)).length + ' found' : @js($countText)">{{ $countText }}</span>
            <span class="flex h-1.5 gap-0.5 overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark" role="img" aria-label="{{ $counts['known'] }} known, {{ $counts['learning'] }} learning, {{ $counts['new'] }} new">
                @foreach (['known' => 'bg-success dark:bg-success-dark', 'learning' => 'bg-warning', 'new' => 'bg-ink-faint dark:bg-ink-faint-dark'] as $status => $color)
                    @if ($counts[$status] > 0)
                        <span class="{{ $color }} block h-full" style="width: {{ $counts[$status] / $total * 100 }}%"></span>
                    @endif
                @endforeach
            </span>
        </span>
        <span class="shrink-0 text-ink-faint transition-transform dark:text-ink-faint-dark" x-bind:class="isOpen('{{ $tab }}', '{{ $groupKey }}', {{ $firstJs }}) ? 'rotate-180' : ''">@svg('heroicon-m-chevron-down', 'h-5 w-5')</span>
    </button>
    <div x-show="isOpen('{{ $tab }}', '{{ $groupKey }}', {{ $firstJs }})" @unless ($first) x-cloak @endunless class="divide-y divide-line border-t border-line px-4 dark:divide-line-dark dark:border-line-dark">
        {{ $slot }}
    </div>
</section>
