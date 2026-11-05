{{--
    A Story Chain turn. Both see the story's photo, its first line and
    this turn's word; the teller adds a sentence or two with it and taps
    Done (or the time runs out) and the story passes on; the listener ticks
    "used the word". The last turn of a story ends it. Needs the arena's
    $pairGame, $turn, $card, $explainer, $isExplainer, $friend, $turnIndex.
--}}
@php
    $part = $turnIndex % $pairGame->storyTurns() + 1;
    $isLast = $part === $pairGame->storyTurns();
    $used = $turn['current']['used'] ?? false;
@endphp

<div @class(['flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-extrabold tracking-wide', 'bg-accent dark:bg-accent-dark text-white' => $isExplainer, 'bg-surface dark:bg-surface-dark' => ! $isExplainer])>
    @svg($isExplainer ? 'heroicon-o-microphone' : 'heroicon-o-speaker-wave', 'h-5 w-5')
    {{ $isExplainer ? ($isLast ? 'YOUR TURN — end the story!' : 'YOUR TURN — add 1–2 sentences') : 'LISTEN — '.$explainer->name.' goes on with the story' }}
</div>

<div class="overflow-hidden rounded-2xl bg-surface dark:bg-surface-dark text-ink dark:text-ink-dark border border-line dark:border-line-dark">
    @if (filled($card['image'] ?? null))
        <img src="{{ $card['image'] }}" alt="" class="h-28 w-full object-cover">
    @endif
    <div class="space-y-3 p-5">
        <div class="flex items-center justify-between text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark uppercase">
            <span class="truncate">{{ $card['source'] ?? '' }}</span>
            <span class="shrink-0 rounded-full bg-success-soft dark:bg-success-soft-dark px-2 py-0.5 text-success dark:text-success-dark">Part {{ $part }} / {{ $pairGame->storyTurns() }}</span>
        </div>
        <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark italic">“{{ $card['line'] ?? '' }}”</p>
        <div class="text-center">
            <p class="text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark">USE THIS WORD</p>
            <p wire:key="story-word-{{ $turnIndex }}" @class(['animate-badge-pop mt-1 inline-flex items-center gap-1.5 rounded-xl px-4 py-2 font-display text-3xl font-extrabold', 'bg-success text-white' => $used, 'bg-surface-sunken dark:bg-surface-sunken-dark' => ! $used])>
                @if ($used) @svg('heroicon-s-check', 'h-6 w-6') @endif {{ $card['word'] ?? '' }}
            </p>
        </div>
    </div>
</div>

<div class="mt-auto space-y-2.5">
    @if ($isExplainer)
        <button type="button" wire:click="doneTelling" wire:loading.attr="disabled" wire:target="doneTelling" class="h-14 w-full cursor-pointer rounded-2xl bg-surface dark:bg-surface-dark text-base font-extrabold disabled:opacity-60">{{ $isLast ? 'The end' : 'Done — your turn, '.$friend->name }}</button>
    @elseif (! $used)
        <button type="button" wire:click="used" wire:loading.attr="disabled" wire:target="used" class="flex h-16 w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-success dark:bg-success-dark text-lg font-extrabold text-white dark:text-ground-dark shadow-sm disabled:opacity-60">
            @svg('heroicon-s-check', 'h-6 w-6') {{ $explainer->name }} used the word
        </button>
    @else
        <p class="text-center text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Next, it’s your turn — get ready!</p>
    @endif
</div>
