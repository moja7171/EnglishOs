{{--
    A game while it is played (Taboo here; Who am I's turn is in who-turn): a dark full screen over the app. Between
    turns the next explainer taps Start; during a turn the explainer sees
    the card and taps what happened, the guesser sees only the clock, the
    first letter once it is shown and the words of this turn. Needs
    $pairGame, $me, $friend, $best from the play page.
--}}
@php
    $turn = $pairGame->currentTurn();
    $turnIndex = $pairGame->currentTurnIndex();
    $explainer = $pairGame->explainer();
    $isExplainer = $explainer->is($me);
    $card = $pairGame->currentCard();
    $seen = $turn ? count($turn['cards']) : 0;
    $skipsLeft = $pairGame->skipsLeft();
    $starters = array_slice(App\Services\TabooDeck::STARTERS, 0, (int) ($pairGame->rules['starters'] ?? 3));
    $lastCard = $turn ? collect($turn['cards'])->last() : null;
    $lastGot = $lastCard && $lastCard['result'] === App\Models\PairGame::RESULT_GOT && now()->getTimestamp() - $lastCard['at'] <= 3 ? $lastCard : null;
    $previousTurn = ! $turn ? collect($pairGame->turns ?? [])->last() : null;
    $hint = $card && ($turn['current']['letter'] ?? false)
        ? collect(mb_str_split($card['word']))->map(fn (string $letter, int $i) => $i === 0 ? $letter : ($letter === ' ' ? ' ' : '_'))->implode(' ')
        : null;
@endphp

<div class="dark fixed inset-0 z-[55] overflow-y-auto bg-ground-dark text-ink-dark">
    <div class="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        <div class="flex items-center justify-between" x-data="{ confirming: false }">
            <button type="button" x-on:click="confirming = true" aria-label="End game" class="-ms-2 inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-full text-ink-soft-dark hover:bg-surface-dark">@svg('heroicon-o-x-mark', 'h-6 w-6')</button>
            <div class="flex items-center gap-2">
                {{-- Shown while this phone is really recording its owner. --}}
                <span x-data="{ rec: false, timer: null, init() { this.timer = setInterval(() => this.rec = !! window.eosGameVoice?.isRecording(), 500) }, destroy() { clearInterval(this.timer) } }" x-show="rec" x-cloak class="flex items-center gap-1.5 rounded-full bg-surface-dark px-2.5 py-1 text-xs font-extrabold text-accent-ink-dark">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-accent-dark"></span>REC
                </span>
                <span class="rounded-full bg-surface-dark px-3 py-1 text-xs font-extrabold text-ink-soft-dark">Turn {{ min($turnIndex + 1, $pairGame->plannedTurns()) }} / {{ $pairGame->plannedTurns() }}</span>
                @if ($best !== null)
                    <span class="rounded-full bg-surface-dark px-3 py-1 text-xs font-extrabold">Best {{ App\Models\PairGame::formatPoints($best) }}</span>
                @endif
            </div>

            <template x-teleport="body">
                <div x-show="confirming" x-cloak class="dark fixed inset-0 z-[70] flex items-end justify-center bg-black/50" x-on:click.self="confirming = false" x-on:keydown.escape.window="confirming = false">
                    <div class="w-full max-w-lg space-y-4 rounded-t-2xl bg-surface-dark p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-center" role="dialog" aria-modal="true" aria-label="End the game">
                        <p class="font-display text-lg font-bold text-ink-dark">End for both of you?</p>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" x-on:click="confirming = false" class="cursor-pointer rounded-full border border-line-dark px-4 py-3 text-sm font-semibold text-ink-soft-dark">Keep playing</button>
                            <button type="button" wire:click="end" x-on:click="confirming = false" wire:loading.attr="disabled" wire:target="end" class="cursor-pointer rounded-full bg-accent-dark px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">End</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        {{-- Team score and the turn's clock. --}}
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-extrabold tracking-widest text-ink-soft-dark">TEAM SCORE</p>
                <p wire:key="score-{{ $pairGame->score }}" class="animate-badge-pop font-display text-5xl leading-none font-extrabold tabular-nums">{{ App\Models\PairGame::formatPoints($pairGame->score) }}</p>
            </div>
            @if ($turn)
                <div
                    wire:key="clock-{{ $turnIndex }}-{{ $pairGame->phase() }}"
                    x-data="{
                        endsAt: {{ $turn['ends_at'] * 1000 }},
                        total: {{ $pairGame->phase() === App\Models\PairGame::PHASE_ASK ? App\Models\PairGame::ASK_SECONDS : $pairGame->turnSeconds() }},
                        left: {{ $pairGame->phase() === App\Models\PairGame::PHASE_ASK ? App\Models\PairGame::ASK_SECONDS : $pairGame->turnSeconds() }},
                        fired: false,
                        warned: false,
                        timer: null,
                        init() { this.tick(); this.timer = setInterval(() => this.tick(), 250); },
                        destroy() { clearInterval(this.timer); },
                        tick() {
                            this.left = Math.max(0, Math.ceil((this.endsAt - Date.now()) / 1000));
                            if (this.left > 0 && this.left <= 10 && ! this.warned) { this.warned = true; navigator.vibrate?.(150); }
                            if (this.left === 0 && ! this.fired) { this.fired = true; navigator.vibrate?.([120, 80, 120]); this.$wire.timeUp({{ $turnIndex }}); }
                        },
                    }"
                    class="relative h-24 w-24"
                    role="timer"
                    x-bind:aria-label="left + ' seconds left'"
                >
                    <svg viewBox="0 0 96 96" class="h-24 w-24 -rotate-90" aria-hidden="true">
                        <circle cx="48" cy="48" r="42" fill="none" stroke-width="8" class="stroke-surface-dark" />
                        <circle cx="48" cy="48" r="42" fill="none" stroke-width="8" stroke-linecap="round" stroke-dasharray="264" x-bind:stroke-dashoffset="264 - 264 * left / total" x-bind:class="left <= 10 ? 'stroke-warning' : 'stroke-accent-dark'" class="transition-[stroke-dashoffset] duration-300" />
                    </svg>
                    <span x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')" x-bind:class="left <= 10 && 'animate-pulse text-warning'" class="absolute inset-0 flex items-center justify-center text-2xl font-extrabold tabular-nums"></span>
                </div>
            @endif
        </div>

        @if (! $turn)
            {{-- Between turns: who explains next, and their Start. --}}
            <div class="flex flex-1 flex-col items-center justify-center gap-5 py-6 text-center">
                @if ($previousTurn && $pairGame->isJustAMinute())
                    @php $lastTalk = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastTalk)
                        <p class="animate-badge-pop rounded-full bg-success-soft-dark px-4 py-1.5 text-sm font-extrabold text-success-dark">{{ count($lastTalk['heard'] ?? []) }} / {{ count($pairGame->deck[$lastTalk['card']]['words'] ?? []) }} words used{{ $lastTalk['result'] === App\Models\PairGame::RESULT_GOT ? ' · question asked' : '' }}</p>
                    @endif
                @elseif ($previousTurn && $pairGame->isOneCardPerTurn())
                    @php $lastSecret = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastSecret)
                        <p @class(['animate-badge-pop rounded-full px-4 py-1.5 text-sm font-extrabold', 'bg-success-soft-dark text-success-dark' => $lastSecret['result'] === App\Models\PairGame::RESULT_GOT, 'bg-surface-dark text-ink-soft-dark' => $lastSecret['result'] !== App\Models\PairGame::RESULT_GOT])>
                            {{ $lastSecret['result'] === App\Models\PairGame::RESULT_GOT ? 'Guessed in '.$lastSecret['questions'].' · +'.App\Models\PairGame::formatPoints($lastSecret['points']) : 'Not guessed' }} — {{ $pairGame->deck[$lastSecret['card']]['word'] ?? '' }}
                        </p>
                    @endif
                @elseif ($previousTurn)
                    @php $lastTurnWords = collect($previousTurn['cards'])->where('result', App\Models\PairGame::RESULT_GOT)->count(); @endphp
                    <p class="animate-badge-pop rounded-full bg-success-soft-dark px-4 py-1.5 text-sm font-extrabold text-success-dark">+{{ $lastTurnWords }} {{ Str::plural('word', $lastTurnWords) }} last turn</p>
                @endif
                <x-user-avatar :user="$explainer" class="h-20 w-20 text-xl" />
                @if ($pairGame->isJustAMinute())
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $isExplainer ? 'Your turn to talk' : $explainer->name.' talks next' }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft-dark">{{ $isExplainer ? $pairGame->turnSeconds().' s · '.$friend->name.' listens for your words' : 'Listen for the four words — then ask one question!' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-50">Start</button>
                    @endif
                @elseif ($isExplainer && $pairGame->isOneCardPerTurn())
                    <div>
                        <p class="font-display text-3xl font-extrabold">You hold the secret</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft-dark">{{ $pairGame->turnSeconds() }} s · {{ $friend->name }} asks, you answer</p>
                    </div>
                    <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-50">Start</button>
                @elseif ($pairGame->isOneCardPerTurn())
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $explainer->name }} holds a secret</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft-dark">Get ready to ask yes/no questions!</p>
                    </div>
                @elseif ($isExplainer)
                    <div>
                        <p class="font-display text-3xl font-extrabold">Your turn to explain</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft-dark">{{ $pairGame->turnSeconds() }} s · {{ $friend->name }} guesses</p>
                    </div>
                    <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-50">Start</button>
                @else
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $explainer->name }} explains</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft-dark">Get ready to guess — say the word out loud!</p>
                    </div>
                @endif
            </div>
        @elseif ($pairGame->isJustAMinute())
            @include('components.games.partials.minute-turn')
        @elseif ($pairGame->isOneCardPerTurn())
            @include('components.games.partials.who-turn')
        @elseif ($isExplainer)
            <div class="flex items-center gap-2.5 rounded-2xl bg-accent-dark px-4 py-3 text-sm font-extrabold tracking-wide text-white">
                @svg('heroicon-o-microphone', 'h-5 w-5')
                YOUR TURN — explain to {{ $friend->name }}
            </div>

            <div wire:key="card-{{ $turnIndex }}-{{ $seen }}" class="animate-review-card-in space-y-4 rounded-3xl bg-surface p-5 text-ink shadow-[0_6px_0_rgba(0,0,0,0.4)]">
                <div class="flex items-center justify-between text-[11px] font-extrabold tracking-wider text-ink-faint uppercase">
                    <span class="truncate">{{ $card['source'] ?? '' }}</span>
                    <span class="shrink-0 rounded-full bg-success-soft px-2 py-0.5 text-success">Card {{ $seen + 1 }}</span>
                </div>
                <div class="text-center">
                    <p class="font-display text-4xl leading-tight font-extrabold break-words">{{ $card['word'] ?? '' }}</p>
                    <p class="mt-1 text-xs font-bold text-ink-faint">Don’t say it · no Persian</p>
                </div>
                @if ($starters !== [])
                    <div class="border-t border-line pt-3">
                        <p class="text-[11px] font-extrabold tracking-wider text-ink-faint">TRY</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($starters as $starter)
                                <span class="rounded-lg bg-surface-sunken px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if (($card['bonus'] ?? []) !== [])
                    <div @class(['rounded-xl border border-dashed px-3 py-2.5', 'border-warning bg-warning-soft text-warning-ink' => ! ($turn['current']['bonus_said'] ?? false), 'border-line bg-surface-sunken text-ink-faint line-through' => $turn['current']['bonus_said'] ?? false])>
                        <p class="text-[11px] font-extrabold tracking-wider">BONUS +1</p>
                        <p class="text-sm font-bold">Don’t say: {{ implode(' · ', $card['bonus']) }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-auto space-y-2.5">
                <div class="grid grid-cols-3 gap-2.5">
                    <button type="button" wire:click="skip({{ $seen }})" wire:loading.attr="disabled" wire:target="skip,got" @disabled($skipsLeft === 0) class="flex h-16 cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-line-dark text-[15px] font-extrabold disabled:cursor-default disabled:opacity-40">
                        Skip
                        <span class="text-[11px] font-semibold text-ink-soft-dark">{{ $skipsLeft === null ? 'free' : $skipsLeft.' left' }}</span>
                    </button>
                    <button type="button" wire:click="got({{ $seen }})" wire:loading.attr="disabled" wire:target="skip,got" class="col-span-2 flex h-16 cursor-pointer items-center justify-center gap-2 rounded-2xl bg-success-dark text-lg font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-60">
                        @svg('heroicon-s-check', 'h-6 w-6') {{ $friend->name }} got it
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button" wire:click="showLetter({{ $seen }})" wire:target="showLetter" @disabled($turn['current']['letter'] ?? false) class="h-11 cursor-pointer text-sm font-bold text-accent-ink-dark disabled:cursor-default disabled:opacity-50">{{ ($turn['current']['letter'] ?? false) ? 'First letter shown' : 'Show first letter (½)' }}</button>
                    @if (($card['bonus'] ?? []) !== [])
                        <button type="button" wire:click="bonusSaid({{ $seen }})" wire:target="bonusSaid" @disabled($turn['current']['bonus_said'] ?? false) class="h-11 cursor-pointer text-sm font-bold text-ink-soft-dark disabled:cursor-default disabled:opacity-50">I said a bonus word</button>
                    @endif
                </div>
            </div>
        @else
            <div class="flex items-center gap-2.5 rounded-2xl bg-surface-dark px-4 py-3 text-sm font-extrabold tracking-wide">
                @svg('heroicon-o-light-bulb', 'h-5 w-5 text-ink-soft-dark')
                LISTEN — {{ $explainer->name }} explains. Say the word!
            </div>

            <div class="flex h-52 flex-col items-center justify-center gap-2.5 rounded-3xl border-2 border-dashed border-line-dark text-center">
                <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-surface-dark">@svg('heroicon-o-lock-closed', 'h-7 w-7')</span>
                <p class="text-sm font-bold text-ink-soft-dark">Only {{ $explainer->name }} sees the card</p>
                @if ($hint)
                    <p wire:key="hint-{{ $seen }}" class="animate-badge-pop font-mono text-lg font-bold tracking-wider text-accent-ink-dark" dir="ltr">{{ $hint }}</p>
                @endif
            </div>

            @if ($turn['cards'] !== [])
                <div>
                    <p class="text-xs font-extrabold tracking-widest text-ink-soft-dark">THIS TURN</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($turn['cards'] as $played)
                            <span wire:key="played-{{ $loop->index }}" @class([
                                'rounded-full px-3 py-1.5 text-sm font-bold',
                                'bg-success-soft-dark text-success-dark' => $played['result'] === App\Models\PairGame::RESULT_GOT,
                                'bg-surface-dark text-ink-soft-dark' => $played['result'] !== App\Models\PairGame::RESULT_GOT,
                            ])>{{ $played['result'] === App\Models\PairGame::RESULT_GOT ? $pairGame->deck[$played['card']]['word'] : 'skipped' }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        @if ($lastGot)
            {{-- A guessed word: a chime on both phones, and "+1 · word" for the guesser for a moment (the explainer already sees the next card). --}}
            <div
                wire:key="got-{{ $turnIndex }}-{{ $seen }}"
                x-data="{ show: true }"
                x-init="window.eosSound?.playSuccess(); navigator.vibrate?.(40); setTimeout(() => show = false, 2000)"
                x-show="show"
                x-transition.opacity.duration.300ms
                @class([
                    'pointer-events-none fixed inset-x-4 bottom-[max(1.5rem,env(safe-area-inset-bottom))] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-success-dark px-4 py-3 text-white shadow-xl',
                    'sr-only' => $isExplainer,
                ])
            >
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/20">@svg('heroicon-s-check', 'h-6 w-6')</span>
                <span class="text-lg font-extrabold">+{{ App\Models\PairGame::formatPoints($lastGot['points']) }} · {{ $pairGame->deck[$lastGot['card']]['word'] ?? '' }}</span>
            </div>
        @endif
    </div>
</div>
