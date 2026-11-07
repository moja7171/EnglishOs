{{--
    A game while it is played (Taboo here; Who am I's turn is in who-turn): a full screen over the app, in Pair Practice's shell. Between
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

<div class="fixed inset-0 z-[55] overflow-y-auto bg-ground text-ink dark:bg-ground-dark dark:text-ink-dark">
    <div class="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 p-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
        {{-- The same top bar as a Pair Practice round: ✕, one bar per turn (or the
             round's four parts, the game last), the clock and ⓘ. --}}
        @php
            $clockSeconds = match ($pairGame->phase()) {
                App\Models\PairGame::PHASE_ASK => App\Models\PairGame::ASK_SECONDS,
                App\Models\PairGame::PHASE_GUESS => App\Models\PairGame::GUESS_SECONDS,
                App\Models\PairGame::PHASE_PICK => App\Models\PairGame::PICK_SECONDS,
                default => $pairGame->turnSeconds(),
            };
            $doneTurns = count($pairGame->turns ?? []) - ($turn ? 1 : 0);
            $bars = $pairGame->isPractice() ? 4 : $pairGame->plannedTurns();
            $liveBar = $pairGame->isPractice() ? 3 : $doneTurns;
            $gameDone = $pairGame->plannedTurns() > 0 ? $doneTurns / $pairGame->plannedTurns() : 0;
        @endphp
        <header
            wire:key="clock-{{ $turnIndex }}-{{ $pairGame->phase() ?? 'between' }}"
            x-data="{
                confirming: false,
                endsAt: {{ ($turn['ends_at'] ?? 0) * 1000 }},
                total: {{ $clockSeconds }},
                left: {{ $clockSeconds }},
                fired: false,
                warned: false,
                timer: null,
                init() { if (this.endsAt) { this.tick(); this.timer = setInterval(() => this.tick(), 250); } },
                destroy() { clearInterval(this.timer); },
                tick() {
                    this.left = Math.max(0, Math.ceil((this.endsAt - Date.now()) / 1000));
                    if (this.left > 0 && this.left <= 10 && ! this.warned) { this.warned = true; window.eosHaptic?.buzz(150); window.eosSound?.play([[880, 0, 0.09], [880, 0.14, 0.09]], 'triangle'); }
                    if (this.left === 0 && ! this.fired) { this.fired = true; window.eosHaptic?.buzz([120, 80, 120]); window.eosSound?.playLose(); this.$wire.timeUp({{ $turnIndex }}); }
                },
            }"
            class="space-y-3"
        >
            <div class="flex items-center gap-1">
                <button type="button" x-on:click="confirming = true" aria-label="End game" class="-ms-2 inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-x-mark', 'h-6 w-6')</button>
                <div class="flex flex-1 gap-1" role="progressbar" aria-label="{{ $pairGame->isPractice() ? 'Game, part 4 of 4' : 'Turn '.min($turnIndex + 1, $pairGame->plannedTurns()).' of '.$pairGame->plannedTurns() }}">
                    @foreach (range(0, $bars - 1) as $bar)
                        <div wire:key="bar-{{ $bar }}" class="h-2.5 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark">
                            @if ($bar < $liveBar)
                                <div class="h-full w-full rounded-full bg-success dark:bg-success-dark"></div>
                            @elseif ($bar === $liveBar && $pairGame->isPractice())
                                <div class="h-full rounded-full bg-success dark:bg-success-dark" style="width: {{ round($gameDone * 100) }}%"></div>
                            @elseif ($bar === $liveBar && $turn)
                                <div class="h-full rounded-full bg-success transition-all dark:bg-success-dark" x-bind:style="'width: ' + Math.round(100 - 100 * left / total) + '%'"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($turn && ! $pairGame->isAskAnswer())
                    <span
                        role="timer"
                        x-bind:aria-label="left + ' seconds left'"
                        x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')"
                        x-bind:class="left <= 10 ? 'bg-warning-soft text-warning-ink animate-pulse' : 'bg-surface-sunken text-ink dark:bg-surface-sunken-dark dark:text-ink-dark'"
                        class="ms-1 shrink-0 rounded-full px-2.5 py-1 text-sm font-extrabold tabular-nums transition-colors"
                    ></span>
                @endif
                <button type="button" x-on:click="$dispatch('usage-guide-open')" aria-label="How to play" class="-me-2 inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-information-circle', 'h-6 w-6')</button>
            </div>

            <div class="flex items-center justify-between gap-2 text-[13px] font-extrabold text-ink-soft dark:text-ink-soft-dark">
                <span class="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap">{{ $pairGame->isPractice() ? 'Game · '.App\Models\PairGame::info($pairGame->game)['name'] : 'Turn '.min($turnIndex + 1, $pairGame->plannedTurns()).' of '.$pairGame->plannedTurns() }}</span>
                <span class="flex shrink-0 items-center gap-2">
                    {{-- Shown while this phone is really recording its owner. --}}
                    <span x-data="{ rec: false, timer: null, init() { this.timer = setInterval(() => this.rec = !! window.eosGameVoice?.isRecording(), 500) }, destroy() { clearInterval(this.timer) } }" x-show="rec" x-cloak class="flex items-center gap-1.5 text-accent-ink dark:text-accent-ink-dark">
                        <span class="h-2 w-2 animate-pulse rounded-full bg-accent dark:bg-accent-dark"></span>REC
                    </span>
                    <span wire:key="score-{{ $pairGame->score }}" class="animate-badge-pop">Team {{ App\Models\PairGame::formatPoints($pairGame->score) }}{{ $best !== null ? ' · Best '.App\Models\PairGame::formatPoints($best) : '' }}</span>
                </span>
            </div>

            <template x-teleport="body">
                <div x-show="confirming" x-cloak class="fixed inset-0 z-[70] flex items-end justify-center bg-black/50" x-on:click.self="confirming = false" x-on:keydown.escape.window="confirming = false">
                    <div class="w-full max-w-lg space-y-4 rounded-t-2xl bg-surface p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-center dark:bg-surface-dark" role="dialog" aria-modal="true" aria-label="End the game">
                        <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">End for both of you?</p>
                        <div class="grid grid-cols-2 gap-2">
                            <button type="button" x-on:click="confirming = false" class="cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Keep playing</button>
                            <button type="button" wire:click="end" x-on:click="confirming = false" wire:loading.attr="disabled" wire:target="end" class="cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">End</button>
                        </div>
                    </div>
                </div>
            </template>
        </header>

        @if (! $turn)
            {{-- Between turns: who explains next, and their Start. --}}
            <div class="flex flex-1 flex-col items-center justify-center gap-5 py-6 text-center">
                {{-- A one-tap Join skips the waiting screen, so the first Start teaches too. --}}
                @if (($pairGame->turns ?? []) === [])
                    @include('components.games.partials.role-guide')
                @endif
                @if ($previousTurn && $pairGame->isAskAnswer())
                    @php $lastRound = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastRound)
                        <p @class(['animate-badge-pop rounded-full px-4 py-1.5 text-sm font-extrabold', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $lastRound['points'] > 0, 'bg-surface dark:bg-surface-dark text-ink-soft dark:text-ink-soft-dark' => $lastRound['points'] <= 0])>{{ $lastRound['points'] > 0 ? 'Round done · +'.App\Models\PairGame::formatPoints($lastRound['points']) : 'Round done' }}</p>
                    @endif
                @endif
                @if ($previousTurn && $pairGame->isRolePlay())
                    @include('components.games.partials.role-reveal', ['revealTurn' => $previousTurn, 'revealIndex' => count($pairGame->turns) - 1])
                @elseif ($previousTurn && $pairGame->isStoryChain())
                    <p class="animate-badge-pop rounded-full bg-success-soft dark:bg-success-soft-dark px-4 py-1.5 text-sm font-extrabold text-success dark:text-success-dark">Story {{ intdiv(count($pairGame->turns), $pairGame->storyTurns()) }} done!</p>
                @elseif ($previousTurn && $pairGame->isJustAMinute())
                    @php $lastTalk = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastTalk)
                        <p class="animate-badge-pop rounded-full bg-success-soft dark:bg-success-soft-dark px-4 py-1.5 text-sm font-extrabold text-success dark:text-success-dark">{{ count($lastTalk['heard'] ?? []) }} / {{ count($pairGame->deck[$lastTalk['card']]['words'] ?? []) }} words used{{ $lastTalk['result'] === App\Models\PairGame::RESULT_GOT ? ' · question asked' : '' }}</p>
                    @endif
                @elseif ($previousTurn && $pairGame->isWouldYouRather())
                    @php $lastQuestion = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastQuestion)
                        @php $rightGuesses = intdiv($lastQuestion['points'], App\Models\PairGame::POINTS_WORD); @endphp
                        <p @class(['animate-badge-pop rounded-full px-4 py-1.5 text-sm font-extrabold', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $rightGuesses > 0, 'bg-surface dark:bg-surface-dark text-ink-soft dark:text-ink-soft-dark' => $rightGuesses === 0])>{{ $rightGuesses }} / 2 right guesses{{ $rightGuesses > 0 ? ' · +'.$rightGuesses : '' }}</p>
                    @endif
                @elseif ($previousTurn && $pairGame->isTwoTruths())
                    @php $lastTold = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastTold)
                        <p @class(['animate-badge-pop rounded-full px-4 py-1.5 text-sm font-extrabold', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $lastTold['result'] === App\Models\PairGame::RESULT_GOT, 'bg-surface dark:bg-surface-dark text-ink-soft dark:text-ink-soft-dark' => $lastTold['result'] !== App\Models\PairGame::RESULT_GOT])>
                            {{ match (true) {
                                $lastTold['lie'] === null => 'No lie marked',
                                $lastTold['result'] === App\Models\PairGame::RESULT_GOT => 'Lie found! It was #'.$lastTold['lie'].' · +1',
                                $lastTold['guess'] === null => 'Time’s up — the lie was #'.$lastTold['lie'],
                                default => 'Fooled! The lie was #'.$lastTold['lie'],
                            } }}
                        </p>
                    @endif
                @elseif ($previousTurn && $pairGame->isOneCardPerTurn())
                    @php $lastSecret = collect($previousTurn['cards'])->last(); @endphp
                    @if ($lastSecret)
                        <p @class(['animate-badge-pop rounded-full px-4 py-1.5 text-sm font-extrabold', 'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $lastSecret['result'] === App\Models\PairGame::RESULT_GOT, 'bg-surface dark:bg-surface-dark text-ink-soft dark:text-ink-soft-dark' => $lastSecret['result'] !== App\Models\PairGame::RESULT_GOT])>
                            {{ $lastSecret['result'] === App\Models\PairGame::RESULT_GOT ? 'Guessed in '.$lastSecret['questions'].' · +'.App\Models\PairGame::formatPoints($lastSecret['points']) : 'Not guessed' }} — {{ $pairGame->deck[$lastSecret['card']]['word'] ?? '' }}
                        </p>
                    @endif
                @elseif ($previousTurn)
                    @php $lastTurnWords = collect($previousTurn['cards'])->where('result', App\Models\PairGame::RESULT_GOT)->count(); @endphp
                    <p class="animate-badge-pop rounded-full bg-success-soft dark:bg-success-soft-dark px-4 py-1.5 text-sm font-extrabold text-success dark:text-success-dark">+{{ $lastTurnWords }} {{ Str::plural('word', $lastTurnWords) }} last turn</p>
                @endif
                <x-user-avatar :user="$explainer" class="h-20 w-20 text-xl" />
                @if ($pairGame->isAskAnswer())
                    <div>
                        <p class="font-display text-3xl font-extrabold">Round {{ count($pairGame->turns ?? []) + 1 }} of {{ $pairGame->plannedTurns() }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $isExplainer ? 'You ask · '.$friend->name.' answers' : $explainer->name.' asks · you answer' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                    <div class="w-full max-w-sm">
                        @include('components.games.partials.ask-levels', ['levels' => $pairGame->askLevels($me), 'action' => 'setAskLevel'])
                    </div>
                @elseif ($pairGame->isRolePlay())
                    <div>
                        <p class="font-display text-3xl font-extrabold">Scene {{ count($pairGame->turns ?? []) + 1 }} of {{ $pairGame->plannedTurns() }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $isExplainer ? 'You start · 2 min to talk' : $explainer->name.' starts · get ready' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                @elseif ($pairGame->isStoryChain())
                    @php $nextStory = $pairGame->deck[intdiv(count($pairGame->turns ?? []), $pairGame->storyTurns()) * $pairGame->storyTurns()] ?? null; @endphp
                    <div class="space-y-2">
                        <p class="font-display text-3xl font-extrabold">{{ $isExplainer ? 'You start the story' : $explainer->name.' starts the story' }}</p>
                        @if ($nextStory)
                            <p class="mx-auto max-w-sm text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">“{{ $nextStory['line'] }}”</p>
                        @endif
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                @elseif ($pairGame->isWouldYouRather())
                    <div>
                        <p class="font-display text-3xl font-extrabold">Question {{ count($pairGame->turns ?? []) + 1 }} of {{ $pairGame->plannedTurns() }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $isExplainer ? 'You open it · then you say why first' : $explainer->name.' opens it · get ready to pick' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                @elseif ($pairGame->isTwoTruths())
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $isExplainer ? 'Your turn: two truths, one lie' : $explainer->name.' tells next' }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $isExplainer ? $pairGame->turnSeconds().' s · then '.$friend->name.' finds the lie' : 'Listen for the lie — you can ask two questions!' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                @elseif ($pairGame->isJustAMinute())
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $isExplainer ? 'Your turn to talk' : $explainer->name.' talks next' }}</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $isExplainer ? $pairGame->turnSeconds().' s · '.$friend->name.' listens for your words' : 'Listen for the four words — then ask one question!' }}</p>
                    </div>
                    @if ($isExplainer)
                        <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                    @endif
                @elseif ($isExplainer && $pairGame->isOneCardPerTurn())
                    <div>
                        <p class="font-display text-3xl font-extrabold">You hold the secret</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $pairGame->turnSeconds() }} s · {{ $friend->name }} asks, you answer</p>
                    </div>
                    <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                @elseif ($pairGame->isOneCardPerTurn())
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $explainer->name }} holds a secret</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Get ready to ask yes/no questions!</p>
                    </div>
                @elseif ($isExplainer)
                    <div>
                        <p class="font-display text-3xl font-extrabold">Your turn to explain</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $pairGame->turnSeconds() }} s · {{ $friend->name }} guesses</p>
                    </div>
                    <button type="button" wire:click="startTurn" wire:loading.attr="disabled" wire:target="startTurn" class="w-full max-w-xs cursor-pointer rounded-2xl bg-accent dark:bg-accent-dark px-6 py-4 text-xl font-extrabold text-white shadow-sm disabled:opacity-50">Start</button>
                @else
                    <div>
                        <p class="font-display text-3xl font-extrabold">{{ $explainer->name }} explains</p>
                        <p class="mt-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Get ready to guess — say the word out loud!</p>
                    </div>
                @endif
            </div>
        @elseif ($pairGame->isAskAnswer())
            @include('components.games.partials.ask-turn')
        @elseif ($pairGame->isWouldYouRather())
            @include('components.games.partials.rather-turn')
        @elseif ($pairGame->isTwoTruths())
            @include('components.games.partials.truths-turn')
        @elseif ($pairGame->isRolePlay())
            @include('components.games.partials.role-turn')
        @elseif ($pairGame->isStoryChain())
            @include('components.games.partials.story-turn')
        @elseif ($pairGame->isJustAMinute())
            @include('components.games.partials.minute-turn')
        @elseif ($pairGame->isOneCardPerTurn())
            @include('components.games.partials.who-turn')
        @elseif ($isExplainer)
            <div class="-mb-4 flex items-center gap-2.5 rounded-t-2xl bg-accent dark:bg-accent-dark px-4 py-3 text-sm font-extrabold tracking-wide text-white">
                @svg('heroicon-o-microphone', 'h-5 w-5')
                YOUR TURN — explain to {{ $friend->name }}
            </div>

            <div wire:key="card-{{ $turnIndex }}-{{ $seen }}" class="animate-review-card-in space-y-4 rounded-b-2xl bg-surface dark:bg-surface-dark p-5 text-ink dark:text-ink-dark border border-line dark:border-line-dark">
                <div class="flex items-center justify-between text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark uppercase">
                    <span class="truncate">{{ $card['source'] ?? '' }}</span>
                    <span class="shrink-0 rounded-full bg-success-soft dark:bg-success-soft-dark px-2 py-0.5 text-success dark:text-success-dark">Card {{ $seen + 1 }}</span>
                </div>
                <div class="text-center">
                    <p class="font-display text-4xl leading-tight font-extrabold break-words">{{ $card['word'] ?? '' }}</p>
                    <p class="mt-1 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Don’t say it · no Persian</p>
                </div>
                @if ($starters !== [])
                    <div class="border-t border-line dark:border-line-dark pt-3">
                        <p class="text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark">TRY</p>
                        <div class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($starters as $starter)
                                <span class="rounded-lg bg-surface-sunken dark:bg-surface-sunken-dark px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
                @if (($card['bonus'] ?? []) !== [])
                    <div @class(['rounded-xl border border-dashed px-3 py-2.5', 'border-warning bg-warning-soft text-warning-ink' => ! ($turn['current']['bonus_said'] ?? false), 'border-line dark:border-line-dark bg-surface-sunken dark:bg-surface-sunken-dark text-ink-faint dark:text-ink-faint-dark line-through' => $turn['current']['bonus_said'] ?? false])>
                        <p class="text-[11px] font-extrabold tracking-wider">BONUS +1</p>
                        <p class="text-sm font-bold">Don’t say: {{ implode(' · ', $card['bonus']) }}</p>
                    </div>
                @endif
            </div>

            <div class="mt-auto space-y-2.5">
                <div class="grid grid-cols-3 gap-2.5">
                    <button type="button" wire:click="skip({{ $seen }})" wire:loading.attr="disabled" wire:target="skip,got" @disabled($skipsLeft === 0) class="flex h-16 cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-line dark:border-line-dark text-[15px] font-extrabold disabled:cursor-default disabled:opacity-40">
                        Skip
                        <span class="text-[11px] font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $skipsLeft === null ? 'free' : $skipsLeft.' left' }}</span>
                    </button>
                    <button type="button" wire:click="got({{ $seen }})" wire:loading.attr="disabled" wire:target="skip,got" class="col-span-2 flex h-16 cursor-pointer items-center justify-center gap-2 rounded-2xl bg-success dark:bg-success-dark text-lg font-extrabold text-white dark:text-ground-dark shadow-sm disabled:opacity-60">
                        @svg('heroicon-s-check', 'h-6 w-6') {{ $friend->name }} got it
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-2.5">
                    <button type="button" wire:click="showLetter({{ $seen }})" wire:target="showLetter" @disabled($turn['current']['letter'] ?? false) class="h-11 cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark disabled:cursor-default disabled:opacity-50">{{ ($turn['current']['letter'] ?? false) ? 'First letter shown' : 'Show first letter (½)' }}</button>
                    @if (($card['bonus'] ?? []) !== [])
                        <button type="button" wire:click="bonusSaid({{ $seen }})" wire:target="bonusSaid" @disabled($turn['current']['bonus_said'] ?? false) class="h-11 cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark disabled:cursor-default disabled:opacity-50">I said a bonus word</button>
                    @endif
                </div>
            </div>
        @else
            <div class="-mb-4 flex items-center gap-2.5 rounded-t-2xl bg-surface dark:bg-surface-dark px-4 py-3 text-sm font-extrabold tracking-wide">
                @svg('heroicon-o-light-bulb', 'h-5 w-5 text-ink-soft dark:text-ink-soft-dark')
                LISTEN — {{ $explainer->name }} explains. Say the word!
            </div>

            <div class="flex h-52 flex-col items-center justify-center gap-2.5 rounded-b-2xl border-2 border-t-0 border-dashed border-line dark:border-line-dark text-center">
                <span class="inline-flex h-16 w-16 items-center justify-center rounded-full bg-surface dark:bg-surface-dark">@svg('heroicon-o-lock-closed', 'h-7 w-7')</span>
                <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Only {{ $explainer->name }} sees the card</p>
                @if ($hint)
                    <p wire:key="hint-{{ $seen }}" class="animate-badge-pop font-mono text-lg font-bold tracking-wider text-accent-ink dark:text-accent-ink-dark" dir="ltr">{{ $hint }}</p>
                @endif
            </div>

            @if ($turn['cards'] !== [])
                <div>
                    <p class="text-xs font-extrabold tracking-widest text-ink-soft dark:text-ink-soft-dark">THIS TURN</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($turn['cards'] as $played)
                            <span wire:key="played-{{ $loop->index }}" @class([
                                'rounded-full px-3 py-1.5 text-sm font-bold',
                                'bg-success-soft dark:bg-success-soft-dark text-success dark:text-success-dark' => $played['result'] === App\Models\PairGame::RESULT_GOT,
                                'bg-surface dark:bg-surface-dark text-ink-soft dark:text-ink-soft-dark' => $played['result'] !== App\Models\PairGame::RESULT_GOT,
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
                x-init="window.eosSound?.playSuccess(); window.eosHaptic?.buzz(40); setTimeout(() => show = false, 2000)"
                x-show="show"
                x-transition.opacity.duration.300ms
                @class([
                    'pointer-events-none fixed inset-x-4 bottom-[max(1.5rem,env(safe-area-inset-bottom))] z-[60] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-success dark:bg-success-dark px-4 py-3 text-white shadow-xl',
                    'sr-only' => $isExplainer,
                ])
            >
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/20">@svg('heroicon-s-check', 'h-6 w-6')</span>
                <span class="text-lg font-extrabold">+{{ App\Models\PairGame::formatPoints($lastGot['points']) }} · {{ $pairGame->deck[$lastGot['card']]['word'] ?? '' }}</span>
            </div>
        @endif
    </div>
</div>
