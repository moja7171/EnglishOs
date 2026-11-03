{{--
    The end of a game: one team score (and a new record when it beats the
    pair's best for this game and length), how many words each explained
    (Taboo) or secrets each guessed (Who am I), the words nobody got —
    shown here only, never saved — and "Your sentences".
    Needs $pairGame, $me, $friend, $best from the play page.
--}}
@php
    $newRecord = $pairGame->isNewRecord();
    $missed = $pairGame->missedWords();
    $next = App\Models\PairGame::forPair($pairGame->userA, $pairGame->userB)->open()->whereKeyNot($pairGame->id)->latest()->first();
@endphp

<div class="space-y-4 p-4">
    <div class="dark relative overflow-hidden rounded-3xl bg-surface-dark px-5 py-6 text-center text-ink-dark">
        @if ($newRecord)
            {{-- A few confetti bits, plain shapes. --}}
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                @foreach ([['7%', '10%', 'bg-accent-dark', 20], ['90%', '14%', 'bg-success-dark', -25], ['14%', '30%', 'bg-warning', 40], ['84%', '32%', 'bg-accent-dark', -30], ['26%', '6%', 'bg-success-dark', 15], ['70%', '7%', 'bg-warning', -15]] as [$left, $top, $color, $turn])
                    <span class="animate-badge-pop absolute h-3.5 w-2 rounded-sm {{ $color }}" style="left: {{ $left }}; top: {{ $top }}; transform: rotate({{ $turn }}deg)"></span>
                @endforeach
            </div>
        @endif
        <p @class(['relative text-xs font-extrabold tracking-widest', 'text-accent-ink-dark' => $newRecord, 'text-ink-soft-dark' => ! $newRecord])>{{ $newRecord ? 'NEW RECORD!' : 'TEAM SCORE' }}</p>
        <p class="animate-trophy-pop relative font-display text-7xl leading-none font-extrabold tabular-nums">{{ App\Models\PairGame::formatPoints($pairGame->score) }}</p>
        <p class="relative mt-1 text-sm font-semibold text-ink-soft-dark">
            points together{{ $best !== null ? ' · best '.($newRecord ? 'was ' : '').App\Models\PairGame::formatPoints($best) : '' }}
        </p>
        <div class="relative mt-4 grid grid-cols-2 gap-2 text-start">
            @foreach ([$me, $friend] as $player)
                <div wire:key="explained-{{ $player->id }}" class="rounded-2xl bg-ground-dark/60 p-3">
                    <p class="truncate text-xs font-bold text-ink-soft-dark">{{ $player->is($me) ? 'You' : $player->name }} {{ $pairGame->isOneCardPerTurn() ? 'guessed' : 'explained' }}</p>
                    <p class="text-xl font-extrabold tabular-nums">{{ $pairGame->creditFor($player) }}</p>
                </div>
            @endforeach
        </div>
        @if ($pairGame->endedBy)
            <p class="relative mt-3 text-xs text-ink-soft-dark">{{ $pairGame->endedBy->is($me) ? 'You' : $pairGame->endedBy->name }} ended the game.</p>
        @endif
    </div>

    @if ($missed !== [])
        <div>
            <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">{{ $pairGame->isOneCardPerTurn() ? 'Not guessed' : 'Missed words' }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($missed as $word)
                    <span wire:key="missed-{{ $loop->index }}" class="rounded-full bg-surface px-3 py-1.5 text-sm font-bold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark">{{ $word }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @php $voice = App\Models\VoiceFeedback::existingFor($me, $pairGame); @endphp
    @if ($voice)
        <section class="space-y-2.5">
            <h2 class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">Your sentences</h2>
            @if (! $voice->isDone())
                <div
                    wire:key="voice-checking-{{ $voice->id }}"
                    x-data
                    x-init="window.eosGameVoice ? window.eosGameVoice.whenIdle(() => $wire.checkMyVoice()) : $wire.checkMyVoice()"
                    class="flex items-center gap-3 rounded-2xl bg-surface px-4 py-3 shadow-sm dark:bg-surface-dark"
                >
                    <span class="h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-accent border-t-transparent dark:border-accent-dark dark:border-t-transparent"></span>
                    <span class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Checking your sentences…</span>
                </div>
            @elseif ($voice->status === App\Models\VoiceFeedback::STATUS_READY)
                <x-learner-feedback :feedback="$voice->feedback" :answers="$voice->answers()" />
            @elseif ($voice->status === App\Models\VoiceFeedback::STATUS_EMPTY)
                <p class="rounded-2xl bg-surface px-4 py-3 text-sm font-semibold text-ink-soft shadow-sm dark:bg-surface-dark dark:text-ink-soft-dark">Not enough was recorded to check this time.</p>
            @else
                <p class="rounded-2xl bg-surface px-4 py-3 text-sm font-semibold text-ink-soft shadow-sm dark:bg-surface-dark dark:text-ink-soft-dark">{{ $voice->error ?: 'Couldn’t check your sentences this time.' }}</p>
            @endif
        </section>
    @endif

    <div class="space-y-2.5 pt-2">
        <button type="button" wire:click="playAgain" wire:loading.attr="disabled" wire:target="playAgain" class="block w-full cursor-pointer rounded-2xl bg-accent px-5 py-4 text-lg font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] disabled:opacity-50 dark:bg-accent-dark">
            {{ $next && $next->started_by_id !== $me->id ? 'Join '.$friend->name.'’s next game' : 'Play again' }}
        </button>
        <a href="{{ route('games.index') }}" wire:navigate class="block w-full rounded-2xl bg-surface px-5 py-3.5 text-center text-base font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark">Another game</a>
    </div>
</div>
