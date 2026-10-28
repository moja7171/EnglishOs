{{--
    The Notebook tabs' summary and filter: a bar split by status (tap a
    part to filter by it) and a row of filter chips. Reads and sets
    filter.<tab> (and, for a "learned" part, learned) in the Notebook's
    Alpine state.

    @param string $tab words, mistakes or tips.
    @param list<array{status: string, label: string, count: int, learned?: bool}> $segments The status parts, in bar order.
    @param list<array{0: string, 1: string, 2: int}> $chips Filter key, label and count; the first is "all".
--}}
@props(['tab', 'segments', 'chips'])

@php
    $dotClass = fn (string $status) => match ($status) {
        'known' => 'bg-success dark:bg-success-dark',
        'learning' => 'bg-warning',
        default => 'bg-ink-faint dark:bg-ink-faint-dark',
    };
    $shown = collect($segments)->where('count', '>', 0);
@endphp

<div class="space-y-3">
    @if ($shown->isNotEmpty())
        <div>
            <div class="flex h-2 gap-0.5 overflow-hidden rounded-full">
                @foreach ($shown as $segment)
                    <button
                        type="button"
                        style="flex: {{ $segment['count'] }}"
                        x-on:click="{{ ($segment['learned'] ?? false) ? 'learned = ! learned' : "filter.{$tab} = filter.{$tab} === '{$segment['status']}' ? 'all' : '{$segment['status']}'" }}"
                        aria-label="{{ $segment['label'] }} {{ $segment['count'] }}"
                        class="{{ $dotClass($segment['status']) }} h-full min-w-1 cursor-pointer"
                    ></button>
                @endforeach
            </div>
            <div class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-xs font-bold text-ink-soft dark:text-ink-soft-dark">
                @foreach ($shown as $segment)
                    <span class="inline-flex items-center gap-1.5"><span class="{{ $dotClass($segment['status']) }} h-2 w-2 rounded-full"></span>{{ $segment['count'] }} {{ $segment['label'] }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="-mx-0.5 flex gap-1.5 overflow-x-auto px-0.5 py-0.5 [scrollbar-width:none]">
        @foreach ($chips as [$key, $label, $count])
            <button
                type="button"
                x-on:click="filter.{{ $tab }} = '{{ $key }}'"
                x-bind:aria-pressed="filter.{{ $tab }} === '{{ $key }}'"
                x-bind:class="filter.{{ $tab }} === '{{ $key }}' ? 'bg-ink text-surface dark:bg-ink-dark dark:text-surface-dark' : 'bg-surface text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark'"
                class="shrink-0 cursor-pointer rounded-full px-3 py-1.5 text-sm font-extrabold"
            >{{ $label }} <span class="font-bold opacity-60">{{ $count }}</span></button>
        @endforeach
    </div>
</div>
