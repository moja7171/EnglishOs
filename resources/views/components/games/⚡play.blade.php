<?php

use App\Livewire\Concerns\ChecksGameVoice;
use App\Models\PairGame;
use App\Notifications\PairGameInvite;
use App\Services\PairGames;
use App\Services\UsageGuides;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    use ChecksGameVoice;

    public PairGame $pairGame;

    /**
     * Opened from an invite's Join (?join=1), the invited friend is in the
     * game with that one tap. The invite has done its job once the game is
     * open on their phone.
     */
    public function mount(PairGame $pairGame): void
    {
        abort_unless($pairGame->isAccessibleBy(auth()->user()), 403);

        if (request()->boolean('join')) {
            $pairGame->joinWithLock(auth()->user());
        }

        auth()->user()->unreadNotifications()
            ->where('type', PairGameInvite::class)
            ->get()
            ->filter(fn ($notification) => ($notification->data['game_id'] ?? null) === $pairGame->id)
            ->each->markAsRead();

        $this->pairGame = $pairGame;
    }

    protected function voiceSetting(): string
    {
        return 'games_voice_feedback';
    }

    public function join(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->join(auth()->user()));
    }

    public function readRoleGuide(): void
    {
        auth()->user()->markGuideRead(UsageGuides::keyForGame($this->pairGame->game));
    }

    public function startTurn(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->startTurn(auth()->user()));
    }

    /**
     * The explainer's taps. $seen is how many cards of this turn their
     * phone had seen, so a double tap or a stale screen changes nothing.
     */
    public function got(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->scoreCard(auth()->user(), PairGame::RESULT_GOT, $seen));
    }

    public function skip(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->scoreCard(auth()->user(), PairGame::RESULT_SKIP, $seen));
    }

    /**
     * Who am I: the secret's holder answers the question just asked.
     * $asked is how many answers their phone had seen.
     */
    public function answer(string $answer, int $asked): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->answerQuestion(auth()->user(), $answer, $asked));
    }

    /**
     * Just a Minute: the listener ticks a target word they heard; the
     * talker stops early; the listener asked their one question.
     */
    public function hear(string $word): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->hearWord(auth()->user(), $word));
    }

    public function doneTalking(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->finishTalking(auth()->user()));
    }

    public function asked(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->questionAsked(auth()->user()));
    }

    /**
     * Story Chain: the listener ticks that the teller used the word; the
     * teller passes the story on.
     */
    public function used(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->wordUsed(auth()->user()));
    }

    public function doneTelling(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->doneTelling(auth()->user()));
    }

    /**
     * Role-play: either player ends the talk; each then sends their guess
     * of the friend's goal and whether they reached their own.
     */
    public function endTalk(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->endTalk(auth()->user()));
    }

    public function guess(int $option, bool $reached): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->submitGuess(auth()->user(), $option, $reached));
    }

    /**
     * Two Truths: the teller marks their lie and says they're done; the
     * listener picks the lie.
     */
    public function markLie(int $statement): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->markLie(auth()->user(), $statement));
    }

    public function doneSaying(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->doneSaying(auth()->user()));
    }

    public function pickLie(int $statement): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->pickLie(auth()->user(), $statement));
    }

    /**
     * Would You Rather: my side and my guess of my friend's; then either
     * of us says we both gave our reasons.
     */
    public function pickSide(string $mine, string $theirs): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->pickSide(auth()->user(), $mine, $theirs));
    }

    public function doneWhy(): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->doneWhy(auth()->user()));
    }

    public function showLetter(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->showLetter(auth()->user(), $seen));
    }

    public function bonusSaid(int $seen): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->bonusSaid(auth()->user(), $seen));
    }

    /**
     * Called by the timer on both phones; only the turn the timer belonged
     * to can be closed, and only once its time is up.
     */
    public function timeUp(int $turn): void
    {
        $this->act(fn (PairGame $pairGame) => $pairGame->timeUp($turn));
    }

    /**
     * Before the friend joined: calls the invite off. During the game:
     * ends it for both.
     */
    public function end(): void
    {
        $wasWaiting = $this->pairGame->isWaiting();

        $this->act(fn (PairGame $pairGame) => $pairGame->isWaiting()
            ? $pairGame->cancel(auth()->user())
            : $pairGame->endEarly(auth()->user()));

        if ($wasWaiting) {
            $this->redirectRoute('games.index', navigate: true);
        }
    }

    /**
     * The same game and length again with the same friend — or the
     * friend's new game, if they were quicker.
     */
    public function playAgain(): void
    {
        abort_unless($this->pairGame->isAccessibleBy(auth()->user()), 403);

        $me = auth()->user();
        $friend = $this->pairGame->partnerFor($me);

        abort_unless($me->canMessageWith($friend), 403);

        $next = app(PairGames::class)->start($me, $friend, $this->pairGame->game, $this->pairGame->minutes, $this->pairGame->rules['days'] ?? null);

        if ($next) {
            $this->redirectRoute('games.play', $next, navigate: true);
        }
    }

    /**
     * Who speaks in a turn: the explainer in Taboo, the one asking the
     * questions in Who am I.
     */
    public function speaksIn(array $turn): bool
    {
        $isHolder = $turn['explainer_id'] === auth()->id();

        if ($this->pairGame->isRolePlay() || $this->pairGame->isWouldYouRather()) {
            return true;
        }

        return $this->pairGame->holderSpeaks() ? $isHolder : ! $isHolder;
    }

    /**
     * Keeps one piece of my voice: a turn I spoke in ("turn-2"). Its
     * label — the words of that turn — is made here, not sent by the phone.
     */
    public function saveVoiceClip(string $part): void
    {
        abort_unless($this->pairGame->isAccessibleBy(auth()->user()), 403);

        $index = preg_match('/^turn-(\d+)$/', $part, $match) ? (int) $match[1] : null;
        $turn = $index === null ? null : ($this->pairGame->refresh()->turns[$index] ?? null);

        if ($turn === null || ! $this->speaksIn($turn)) {
            $this->voiceClip = null;

            return;
        }

        $words = collect($turn['cards'])->map(fn (array $card) => $this->pairGame->deck[$card['card']]['word'] ?? null)->filter()->implode(', ');
        $label = match ($this->pairGame->game) {
            PairGame::GAME_WHO => 'Turn '.($index + 1).' · your questions (the secret: '.$words.')',
            PairGame::GAME_MINUTE => 'Turn '.($index + 1).' · your talk: '.$words,
            PairGame::GAME_TRUTHS => 'Turn '.($index + 1).' · two truths and a lie about: '.$words,
            PairGame::GAME_RATHER => 'Question '.($index + 1).' · '.$words.' — you picked: '.($this->pairGame->deck[$turn['cards'][0]['card'] ?? -1][$turn['cards'][0]['picks'][auth()->id()]['mine'] ?? ''] ?? 'no side'),
            PairGame::GAME_ROLE => 'Scene '.($index + 1).' · '.$words.' — you were: '.($this->pairGame->roleOf(auth()->user(), $this->pairGame->deck[$turn['cards'][0]['card'] ?? -1] ?? null, $index)['role'] ?? ''),
            PairGame::GAME_STORY => 'Turn '.($index + 1).' · your part of the story (word: '.($this->pairGame->deck[$turn['cards'][0]['card'] ?? -1]['word'] ?? '').')',
            default => 'Turn '.($index + 1).' · you explained: '.$words,
        };

        $this->keepVoiceClip($this->pairGame, $part, $label);
    }

    /**
     * Once the game is over and every piece is sent: the sentence-by-
     * sentence feedback on what I said while explaining (once).
     */
    public function checkMyVoice(): void
    {
        if (! $this->pairGame->refresh()->isFinished()) {
            return;
        }

        if ($this->pairGame->isWouldYouRather()) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken reasons in a "Would you rather…?" game with a friend, transcribed by speech-to-text: for each question (given as the question, with the side the learner picked) they said which side they would rather and why. Both voices may be in the transcript; judge only lines that fit the learner\'s side.',
                guidance: 'Judge grammar and word choice, above all "I\'d rather + base verb", "because…" and how the reasons are linked.',
            );

            return;
        }

        if ($this->pairGame->isTwoTruths()) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken turns in a "Two truths and a lie" game, transcribed by speech-to-text: in each turn they said three things about themselves on a topic (given as the question), one of them a lie, then answered up to two questions from their friend. All the text is the learner\'s.',
                guidance: 'Judge grammar and word choice, above all the tense the topic asks for. Never point out which statement might be the lie. The friend\'s questions may appear in the transcript — ignore them.',
            );

            return;
        }

        if ($this->pairGame->isRolePlay()) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken lines in a role-play with a friend, transcribed by speech-to-text: in each scene (given as the question, with the learner\'s role) they talked to reach a secret goal. Both voices may be in the transcript; judge only lines that fit the learner\'s role.',
                guidance: 'Judge grammar, word choice and whether the requests, offers and questions sound natural and polite.',
            );

            return;
        }

        if ($this->pairGame->isStoryChain()) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken parts of a story they told together with a friend in a "Story Chain" game, transcribed by speech-to-text: in each turn they added one or two sentences using a given word (in the question). All the text is the learner\'s.',
                guidance: 'Judge grammar (especially the narrative tenses) and word choice. Short fragments of the friend\'s voice may appear — ignore them.',
            );

            return;
        }

        if ($this->pairGame->isJustAMinute()) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken talks in a "Just a Minute" game, transcribed by speech-to-text: in each turn they talked for up to a minute about a topic (given as the question), trying to use four target words, and then answered one question from their friend. All the text is the learner\'s.',
                guidance: 'Judge grammar, word choice and how the ideas are linked. The friend\'s question may appear in the transcript — ignore it.',
            );

            return;
        }

        if ($this->pairGame->game === PairGame::GAME_WHO) {
            $this->checkVoice(
                $this->pairGame,
                situation: 'These are a learner\'s spoken yes/no questions in a "Who am I?" guessing game, transcribed by speech-to-text: in each turn they asked a friend questions (Am I…? Do I…? Can I…?) to guess a secret job, animal, food, place or thing (the secret is given as the question). All the text is the learner\'s.',
                guidance: 'Judge above all the question forms (word order, do/does/am/is/can, articles). The friend\'s answers ("yes", "no", "sometimes") may appear in the transcript — ignore them.',
            );

            return;
        }

        $this->checkVoice(
            $this->pairGame,
            situation: 'These are a learner\'s spoken explanations in a Taboo game, transcribed by speech-to-text: in each turn they explained words to a friend without saying the word itself (the turn\'s words are given as the question). All the text is the learner\'s.',
            guidance: 'Judge grammar and word choice of the explanations. The friend\'s guesses and short words like "yes", "no", "close" may appear in the transcript — ignore them. Never criticise the learner for not saying the hidden word.',
        );
    }

    /**
     * A game of the same pair started after this one and still open.
     */
    public function nextGame(): ?PairGame
    {
        return PairGame::forPair($this->pairGame->userA, $this->pairGame->userB)->open()->whereKeyNot($this->pairGame->id)->latest()->first();
    }

    /**
     * Both phones act on one game, so every change re-reads it under a row
     * lock first.
     */
    private function act(callable $callback): void
    {
        abort_unless($this->pairGame->isAccessibleBy(auth()->user()), 403);

        DB::transaction(function () use ($callback) {
            $this->pairGame = PairGame::lockForUpdate()->findOrFail($this->pairGame->id);
            $callback($this->pairGame);
        });
    }
};
?>

@php
    $me = auth()->user();
    $friend = $pairGame->partnerFor($me);
    $info = PairGame::info($pairGame->game);
    $best = $pairGame->previousBest();
    $poll = match (true) {
        $pairGame->isCancelled() => null,
        $pairGame->isPlaying() => '1s',
        $pairGame->isFinished() => '4s',
        default => '2s',
    };
@endphp

<div class="mx-auto max-w-xl" @if ($poll) wire:poll.{{ $poll }} @endif>
    {{-- Tells this phone's recorder whether its owner is explaining right now. --}}
    @php
        $voiceOn = (bool) $me->{$this->voiceSetting()};
        $voicePart = $pairGame->isPlaying() && $this->speaksIn($pairGame->currentTurn()) ? 'turn-'.$pairGame->currentTurnIndex() : '';
    @endphp
    <span hidden wire:key="voice-{{ $voicePart }}-{{ (int) $voiceOn }}" x-data x-init="window.eosGameVoice?.sync($wire, @js($voiceOn), @js($voicePart), null); @js($voiceOn && ($pairGame->isWaiting() || $pairGame->stage === PairGame::STAGE_READY)) && window.eosGameVoice?.warmUp()"></span>

    @if ($pairGame->isCancelled())
        <div class="space-y-2 p-6 text-center">
            @php $endedBy = $pairGame->endedBy; @endphp
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $endedBy?->is($me) ? 'You' : ($endedBy->name ?? 'Your friend') }} {{ $pairGame->joined_at ? 'ended the game.' : 'cancelled the invite.' }}</p>
            <a href="{{ route('games.index') }}" wire:navigate class="inline-block pt-1 text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Back to Games ›</a>
        </div>
    @elseif ($pairGame->isWaiting())
        @php $isStarter = $pairGame->started_by_id === $me->id; @endphp
        <div class="space-y-4 p-4">
            <a href="{{ route('games.index') }}" wire:navigate aria-label="Back to Games" class="inline-flex h-10 w-10 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>

            @if ($pairGame->isExpired())
                <div class="card p-6 text-center">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">This invite has expired.</p>
                    <a href="{{ route('games.setup', ['game' => $pairGame->game, 'friend' => $friend->id, 'minutes' => $pairGame->minutes]) }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Invite again ›</a>
                </div>
            @else
                <div class="card space-y-4 overflow-hidden p-0 text-center">
                    <img src="{{ $info['image'] }}" alt="" class="h-36 w-full object-cover">
                    <div class="space-y-4 px-6 pb-6">
                        <x-user-avatar :user="$friend" class="mx-auto -mt-12 h-16 w-16 text-lg ring-4 ring-surface dark:ring-surface-dark" />
                        <div>
                            <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $isStarter ? 'Waiting for '.$friend->name.'…' : $friend->name.' wants to play' }}</p>
                            <p class="mt-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $info['name'] }} · {{ $pairGame->minutes }} min{{ $best !== null ? ' · Best '.PairGame::formatPoints($best) : '' }}</p>
                        </div>
                        @unless ($isStarter)
                            <button type="button" wire:click="join" wire:loading.attr="disabled" wire:target="join" class="block w-full cursor-pointer rounded-2xl bg-accent px-4 py-3.5 text-base font-extrabold text-white disabled:opacity-50 dark:bg-accent-dark">Join</button>
                        @endunless
                        @include('components.games.partials.voice-switch')
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not together? Call on any app.</p>
                    </div>
                </div>
                @include('components.games.partials.role-guide')
                <button type="button" wire:click="end" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">Cancel</button>
            @endif
        </div>
    @elseif ($pairGame->isFinished())
        @include('components.games.partials.result')
    @else
        @include('components.games.partials.arena')
    @endif
</div>
