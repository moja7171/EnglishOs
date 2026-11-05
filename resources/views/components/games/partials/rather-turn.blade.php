{{--
    A Would You Rather question. While picking, each phone shows the two
    sides: tap your own, then guess your friend's, then Send (neither sees
    the other's until both sent). After the reveal both say why — the
    turn's opener first — and either taps "We both said why". Needs the
    arena's $pairGame, $turn, $card, $explainer, $isExplainer, $me, $friend.
--}}
@php
    $picking = ($turn['current']['phase'] ?? null) === App\Models\PairGame::PHASE_PICK;
    $sent = isset($turn['current']['picks'][$me->id]);
@endphp

<div @class(['flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-extrabold tracking-wide', 'bg-accent dark:bg-accent-dark text-white' => $picking, 'bg-surface dark:bg-surface-dark' => ! $picking])>
    @svg($picking ? 'heroicon-o-hand-raised' : 'heroicon-o-chat-bubble-left-right', 'h-5 w-5')
    {{ $picking ? 'PICK A SIDE — secretly' : 'SAY WHY — '.($isExplainer ? 'you first' : $explainer->name.' first') }}
</div>

@if ($picking && $sent)
    <div class="rounded-2xl bg-surface dark:bg-surface-dark p-5 text-center text-ink dark:text-ink-dark border border-line dark:border-line-dark">
        <p class="font-display text-2xl leading-tight font-extrabold">{{ $card['word'] ?? '' }}</p>
    </div>
    <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
        <span class="h-6 w-6 animate-spin rounded-full border-2 border-accent dark:border-accent-dark border-t-transparent"></span>
        <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Waiting for {{ $friend->name }}…</p>
    </div>
@elseif ($picking)
    <div x-data="{ mine: null, theirs: null }" class="flex flex-1 flex-col gap-4">
        <div class="space-y-2.5 rounded-2xl bg-surface dark:bg-surface-dark p-5 text-ink dark:text-ink-dark border border-line dark:border-line-dark">
            <p class="text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark">WOULD YOU RATHER…</p>
            <p class="text-xs font-extrabold text-ink-soft dark:text-ink-soft-dark">You?</p>
            @foreach (App\Models\PairGame::SIDES as $side)
                <button type="button" wire:key="mine-{{ $side }}" x-on:click="mine = '{{ $side }}'" x-bind:class="mine === '{{ $side }}' ? 'border-accent bg-accent text-white' : 'border-line dark:border-line-dark text-ink dark:text-ink-dark'" class="block w-full cursor-pointer rounded-2xl border-2 px-4 py-3 text-start text-base font-bold">{{ ucfirst($card[$side] ?? '') }}</button>
            @endforeach
        </div>
        <div class="space-y-2">
            <p class="text-sm font-extrabold">And {{ $friend->name }}? Guess!</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach (App\Models\PairGame::SIDES as $side)
                    <button type="button" wire:key="theirs-{{ $side }}" x-on:click="theirs = '{{ $side }}'" x-bind:class="theirs === '{{ $side }}' ? 'border-success dark:border-success-dark bg-success/20 dark:bg-success-dark/20' : 'border-line dark:border-line-dark'" class="min-h-12 cursor-pointer rounded-2xl border-2 px-3 py-2 text-sm font-bold">{{ ucfirst($card[$side] ?? '') }}</button>
                @endforeach
            </div>
        </div>
        <button type="button" x-bind:disabled="mine === null || theirs === null" x-on:click="$wire.pickSide(mine, theirs)" class="mt-auto h-14 w-full cursor-pointer rounded-2xl bg-success dark:bg-success-dark text-lg font-extrabold text-white dark:text-ground-dark shadow-sm disabled:cursor-default disabled:opacity-40">Send</button>
    </div>
@else
    @include('components.games.partials.rather-reveal', ['revealCard' => $card, 'revealPicks' => $turn['current']['picks'] ?? [], 'revealIndex' => $pairGame->currentTurnIndex()])

    <div class="mt-auto space-y-2.5">
        <p class="text-[11px] font-extrabold tracking-wider text-ink-soft dark:text-ink-soft-dark">TRY</p>
        <div class="flex flex-wrap gap-1.5">
            @foreach (['I’d rather… because…', 'For me, … is better.', 'I wouldn’t like to… because…'] as $starter)
                <span class="rounded-lg bg-surface dark:bg-surface-dark px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
            @endforeach
        </div>
        <button type="button" wire:click="doneWhy" wire:loading.attr="disabled" wire:target="doneWhy" class="h-12 w-full cursor-pointer rounded-2xl border-2 border-line dark:border-line-dark text-sm font-extrabold disabled:opacity-60">We both said why — next</button>
    </div>
@endif
