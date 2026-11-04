{{--
    A Would You Rather question once both picked: each one's side and
    whether the friend guessed it. Needs $pairGame, $me, $friend,
    $revealCard (the question), $revealPicks (user id => mine/guess) and
    $revealIndex.
--}}
@if ($revealCard)
    <div class="w-full space-y-2 text-start">
        <p class="text-center text-sm font-extrabold text-ink-dark">{{ $revealCard['word'] }}</p>
        <div class="grid grid-cols-2 gap-2">
            @foreach ([$me, $friend] as $player)
                @php
                    $side = $revealPicks[$player->id]['mine'] ?? null;
                    $otherGuess = $revealPicks[$pairGame->partnerFor($player)->id]['guess'] ?? null;
                    $guessedRight = $side !== null && $otherGuess === $side;
                @endphp
                <div wire:key="rather-{{ $revealIndex }}-{{ $player->id }}" class="rounded-2xl bg-surface-dark p-3">
                    <p class="text-xs font-bold text-ink-soft-dark">{{ $player->is($me) ? 'You' : $player->name }}</p>
                    <p class="text-sm font-bold text-ink-dark">{{ $side ? ucfirst($revealCard[$side]) : 'No pick' }}</p>
                    <span @class(['mt-1.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-extrabold', 'bg-success-soft-dark text-success-dark' => $guessedRight, 'bg-ground-dark text-ink-soft-dark' => ! $guessedRight])>{{ $player->is($me) ? $friend->name : 'You' }} {{ $guessedRight ? 'guessed it ✓' : 'didn’t guess it' }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
