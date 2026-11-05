{{--
    After a Role-play scene: both secret goals, each one's guess and
    whether each reached their goal. Needs $pairGame, $me, $friend,
    $revealTurn (a finished turn) and $revealIndex (its place).
--}}
@php
    $played = $revealTurn['cards'][0] ?? null;
    $sceneCard = $played ? ($pairGame->deck[$played['card']] ?? null) : null;
@endphp
@if ($sceneCard)
    <div class="w-full space-y-2 text-start">
        <p class="text-center text-xs font-extrabold tracking-widest text-ink-soft dark:text-ink-soft-dark">{{ strtoupper($sceneCard['word']) }}</p>
        @foreach ([$me, $friend] as $player)
            @php
                $role = $pairGame->roleOf($player, $sceneCard, $revealIndex);
                $guess = $played['guesses'][$pairGame->partnerFor($player)->id] ?? null;
                $friendOptions = $pairGame->guessOptionsFor($pairGame->partnerFor($player), $sceneCard, $revealIndex);
                $guessedRight = $guess !== null && ($friendOptions[$guess['option']] ?? null) === ($role['goal'] ?? null);
                $reached = $played['guesses'][$player->id]['reached'] ?? null;
            @endphp
            <div wire:key="reveal-{{ $revealIndex }}-{{ $player->id }}" class="rounded-2xl bg-surface dark:bg-surface-dark p-3">
                <p class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $player->is($me) ? 'Your' : $player->name.'’s' }} goal · {{ $role['role'] ?? '' }}</p>
                <p class="text-sm font-bold text-ink dark:text-ink-dark">{{ $role['goal'] ?? '' }}</p>
                <div class="mt-1.5 flex flex-wrap gap-1.5 text-[11px] font-extrabold">
                    <span @class(['rounded-full px-2 py-0.5', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $reached, 'bg-ground-dark text-ink-soft dark:text-ink-soft-dark' => ! $reached])>{{ $reached ? 'Reached ✓' : 'Not reached' }}</span>
                    <span @class(['rounded-full px-2 py-0.5', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $guessedRight, 'bg-ground-dark text-ink-soft dark:text-ink-soft-dark' => ! $guessedRight])>{{ $player->is($me) ? $friend->name : 'You' }} {{ $guessedRight ? 'guessed it ✓' : 'didn’t guess it' }}</span>
                </div>
            </div>
        @endforeach
    </div>
@endif
