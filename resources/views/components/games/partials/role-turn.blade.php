{{--
    A Role-play scene. While talking, each phone shows only its own role,
    secret goal and three sentences to use; either player can end the talk.
    Then each picks the friend's goal out of three and says honestly if
    they reached their own. Needs the arena's $pairGame, $turn, $card, $me,
    $friend.
--}}
@php
    $mine = $pairGame->roleOf($me);
    $guessing = ($turn['current']['phase'] ?? null) === App\Models\PairGame::PHASE_GUESS;
    $sent = isset($turn['current']['guesses'][$me->id]);
@endphp

<div class="flex items-center gap-2.5 rounded-2xl bg-accent-dark px-4 py-3 text-sm font-extrabold tracking-wide text-white">
    @svg('heroicon-o-chat-bubble-left-right', 'h-5 w-5')
    {{ $guessing ? 'TIME TO GUESS' : 'SCENE — '.($card['word'] ?? '') }}
</div>

@if (! $guessing)
    <div class="space-y-3 rounded-3xl bg-surface p-5 text-ink shadow-[0_6px_0_rgba(0,0,0,0.4)]">
        <div>
            <p class="text-[11px] font-extrabold tracking-wider text-ink-faint">YOU ARE</p>
            <p class="font-display text-2xl leading-tight font-extrabold">{{ $mine['role'] ?? '' }}</p>
        </div>
        <div class="rounded-2xl bg-accent-soft p-3">
            <p class="text-[11px] font-extrabold tracking-wider text-accent-ink">YOUR SECRET GOAL</p>
            <p class="text-base font-bold">{{ $mine['goal'] ?? '' }}</p>
        </div>
        <div>
            <p class="text-[11px] font-extrabold tracking-wider text-ink-faint">TRY SAYING</p>
            <ul class="mt-1 space-y-1">
                @foreach ($mine['help'] ?? [] as $line)
                    <li class="rounded-lg bg-surface-sunken px-2.5 py-1.5 text-sm font-semibold">{{ $line }}</li>
                @endforeach
            </ul>
        </div>
        <p class="text-center text-xs font-bold text-ink-faint">🤫 Don’t show your screen</p>
    </div>

    <button type="button" wire:click="endTalk" wire:loading.attr="disabled" wire:target="endTalk" class="mt-auto h-12 w-full cursor-pointer rounded-2xl border-2 border-line-dark text-sm font-extrabold disabled:opacity-60">We’re done — guess</button>
@elseif ($sent)
    <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
        <span class="h-6 w-6 animate-spin rounded-full border-2 border-accent-dark border-t-transparent"></span>
        <p class="text-sm font-semibold text-ink-soft-dark">Waiting for {{ $friend->name }}…</p>
    </div>
@else
    <div x-data="{ option: null, reached: null }" class="flex flex-1 flex-col gap-4">
        <div class="space-y-2">
            <p class="text-sm font-extrabold">What was {{ $friend->name }}’s goal?</p>
            @foreach ($pairGame->guessOptionsFor($me) as $index => $goal)
                <button type="button" wire:key="goal-{{ $index }}" x-on:click="option = {{ $index }}" x-bind:class="option === {{ $index }} ? 'border-accent-dark bg-accent-dark/20' : 'border-line-dark'" class="block w-full cursor-pointer rounded-2xl border-2 px-4 py-3 text-start text-sm font-semibold">{{ $goal }}</button>
            @endforeach
        </div>
        <div class="space-y-2">
            <p class="text-sm font-extrabold">Did you reach your goal?</p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" x-on:click="reached = true" x-bind:class="reached === true ? 'border-success-dark bg-success-dark/20' : 'border-line-dark'" class="h-12 cursor-pointer rounded-2xl border-2 text-sm font-extrabold">Yes</button>
                <button type="button" x-on:click="reached = false" x-bind:class="reached === false ? 'border-accent-dark bg-accent-dark/20' : 'border-line-dark'" class="h-12 cursor-pointer rounded-2xl border-2 text-sm font-extrabold">Not really</button>
            </div>
        </div>
        <button type="button" x-bind:disabled="option === null || reached === null" x-on:click="$wire.guess(option, reached)" class="mt-auto h-14 w-full cursor-pointer rounded-2xl bg-success-dark text-lg font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:cursor-default disabled:opacity-40">Send</button>
    </div>
@endif
