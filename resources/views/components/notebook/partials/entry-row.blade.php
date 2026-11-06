{{--
    One line in the Mistakes tab: the learner's words struck through, the
    fixed ones in bold, and under them where it came from. A "better way"
    is not a mistake, so it has no strike-through and a sparkle. Tap opens
    its sheet.

    Needs $entry, $show (a JS expression for x-show), $keyPrefix, $tag (the
    small line under the line) and $removeButton.
--}}
<div wire:key="{{ $keyPrefix }}-{{ $entry->id }}" role="button" tabindex="0" x-show="{{ $show }}" x-on:click="if (! edit) { sheet = 'n{{ $entry->id }}' }" x-on:keydown.enter="if (! edit) { sheet = 'n{{ $entry->id }}' }" class="flex w-full cursor-pointer items-center gap-2.5 py-3 text-left">
    <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-accent dark:bg-accent-dark' => $entry->isUnpractised(), 'bg-transparent' => ! $entry->isUnpractised()])></span>
    <span class="min-w-0 flex-1">
        @if ($entry->original)
            <span @class(['line-clamp-2 block text-xs text-ink-faint dark:text-ink-faint-dark', 'line-through' => $entry->isMistake()])>{{ $entry->original }}</span>
        @endif
        <span class="line-clamp-2 block text-sm font-bold text-ink dark:text-ink-dark">{{ $entry->better }}</span>
        @if (filled($tag))
            <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $tag }}</span>
        @endif
    </span>
    @if ($entry->isPhraseSwap())
        <span class="shrink-0 text-sky-700 dark:text-sky-300" title="A better way to say it">@svg('heroicon-o-sparkles', 'h-4 w-4')</span>
    @endif
    @if ($entry->isMistake() && $entry->times_seen > 1)
        <span class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-extrabold text-warning-ink">{{ $entry->times_seen }}×</span>
    @endif
    <button type="button" x-show="edit" x-cloak wire:click="removeEntry({{ $entry->id }})" x-on:click.stop aria-label="Remove" class="{{ $removeButton }}">@svg('heroicon-m-x-mark', 'h-4 w-4')</button>
</div>
