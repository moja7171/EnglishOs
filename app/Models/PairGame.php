<?php

namespace App\Models;

use App\Services\StreakRing;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One game from the Games page: two friends, each on their own phone,
 * 5 or 10 minutes, one team score. Taboo is played in timed turns — one
 * explains (the only one who sees the card) while the other guesses —
 * and the two swap after every turn. The explainer taps what happened to
 * each card, so the guesser's phone never shows the word.
 */
#[Fillable(['user_a_id', 'user_b_id', 'started_by_id', 'ended_by_id', 'game', 'minutes', 'rules', 'deck', 'turns', 'stage', 'score', 'joined_at', 'finished_at'])]
class PairGame extends Model
{
    use HasFactory;

    public const GAME_TABOO = 'taboo';

    /**
     * One player is secretly someone or something; the other asks yes/no
     * questions to guess it. One secret per turn.
     */
    public const GAME_WHO = 'who-am-i';

    /** Questions a guesser may ask about one secret. */
    public const MAX_QUESTIONS = 10;

    /** A secret guessed within this many questions earns the bonus. */
    public const QUICK_GUESS_QUESTIONS = 5;

    public const ANSWERS = ['yes', 'no', 'sometimes'];

    /**
     * One talks about a topic for up to a minute, using four of the
     * mission's words that the listener ticks as they hear them; then the
     * listener asks one question about it.
     */
    public const GAME_MINUTE = 'just-a-minute';

    /** After the talk: time for the listener's one question and its answer. */
    public const ASK_SECONDS = 30;

    public const PHASE_TALK = 'talk';

    public const PHASE_ASK = 'ask';

    /**
     * The two tell one story together, a sentence or two each turn, each
     * turn using one word of the mission; the turns pass on by themselves
     * within a story.
     */
    public const GAME_STORY = 'story-chain';

    /**
     * Each player has a role and a secret goal in one scene; they talk,
     * then each guesses the other's goal and says if they reached their own.
     */
    public const GAME_ROLE = 'role-play';

    /** After the talk: time to guess the friend's goal. */
    public const GUESS_SECONDS = 45;

    public const PHASE_GUESS = 'guess';

    /**
     * One says three things about a topic, one of them a lie (marked on
     * their own phone); the other may ask two questions, then picks the lie.
     */
    public const GAME_TRUTHS = 'two-truths';

    /** Statements in one Two Truths turn: two true, one lie. */
    public const STATEMENTS = 3;

    /**
     * Both secretly pick a side of one question and guess the friend's
     * pick; after the reveal both say why.
     */
    public const GAME_RATHER = 'would-you-rather';

    /** Time for both to pick their side and guess the friend's. */
    public const PICK_SECONDS = 30;

    public const PHASE_PICK = 'pick';

    /** @var list<string> */
    public const SIDES = ['a', 'b'];

    /**
     * Every game on the Games page, in the order of its tiles. Only the
     * playable ones can be picked; the others are shown as coming soon.
     *
     * @var list<string>
     */
    public const GAMES = [self::GAME_TABOO, self::GAME_WHO, self::GAME_MINUTE, self::GAME_STORY, self::GAME_ROLE, self::GAME_TRUTHS, self::GAME_RATHER];

    /** @var list<string> */
    public const PLAYABLE = [self::GAME_TABOO, self::GAME_WHO, self::GAME_MINUTE, self::GAME_STORY, self::GAME_ROLE, self::GAME_TRUTHS, self::GAME_RATHER];

    /** @var list<int> */
    public const LENGTHS = [5, 10];

    /**
     * A game's tile on the Games page: its name, one line, what it trains
     * and its photo (public/images/games/<slug>.jpg, from Pexels).
     *
     * @return array{name: string, line: string, tag: string, image: string, playable: bool}
     */
    public static function info(string $game): array
    {
        [$name, $line, $tag] = match ($game) {
            self::GAME_TABOO => ['Taboo', 'Explain it, don’t say it', 'Words'],
            self::GAME_WHO => ['Who am I?', 'Guess with yes/no questions', 'Questions'],
            self::GAME_MINUTE => ['Just a Minute', 'Talk for a minute', 'Speaking'],
            self::GAME_STORY => ['Story Chain', 'One sentence each', 'Speaking'],
            self::GAME_ROLE => ['Role-play', 'Each has a secret goal', 'Speaking'],
            self::GAME_TRUTHS => ['Two Truths', 'Find the lie', 'Questions'],
            self::GAME_RATHER => ['Would You Rather', 'Pick a side, say why', 'Speaking'],
            default => ['Game', '', 'Speaking'],
        };

        return [
            'name' => $name,
            'line' => $line,
            'tag' => $tag,
            'image' => asset('images/games/'.$game.'.jpg'),
            'playable' => in_array($game, self::PLAYABLE, true),
        ];
    }

    /**
     * How to play, in three short Persian lines: your part, your friend's
     * part, the points. Shown on the setup page and behind ⓘ in the game.
     *
     * @return list<string>
     */
    public static function guide(string $game): array
    {
        return match ($game) {
            self::GAME_TABOO => [
                'نوبت توست: کلمه رو فقط تو می‌بینی؛ بدون گفتن خودِ کلمه و بدون فارسی توضیحش بده.',
                'دوستت حدس می‌زنه؛ درست گفت، «got it» رو بزن. گیر کردی؟ Skip یا «حرف اول».',
                'هر کلمه +۱ (با حرف اول نصف). از M05: کلمه‌های جایزه رو نگی، +۱. بعد جاها عوض می‌شه.',
            ],
            self::GAME_WHO => [
                'یکی راز رو می‌بینه (شغل، حیوان، غذا…) و فقط Yes / No / Sometimes جواب می‌ده.',
                'اون یکی با سؤال بله/خیر حدس می‌زنه: Am I…? Do I…? Can I…?',
                'حدس درست +۱، با ۵ سؤال یا کمتر +۱ جایزه. ۱۰ سؤال یا ۹۰ ثانیه، بعد جاها عوض.',
            ],
            self::GAME_MINUTE => [
                'گوینده درباره‌ی موضوع حرف می‌زنه و سعی می‌کنه ۴ کلمه‌ی کارت رو به کار ببره.',
                'شنونده هر کلمه رو که شنید روی گوشیش می‌زنه؛ هر کلمه +۱.',
                'بعد شنونده یک سؤال می‌پرسه (+۱) و جاها عوض می‌شه.',
            ],
            self::GAME_STORY => [
                'داستان با جمله‌ی اول شروع می‌شه؛ هر نوبت یکی‌تون ۱ یا ۲ جمله ادامه می‌ده.',
                'کلمه‌ی نوبت رو توی جمله‌ت بیار؛ دوستت «used the word» می‌زنه (+۱).',
                '«Done» داستان رو به دوستت می‌ده؛ نوبت آخر، داستان رو تموم کن.',
            ],
            self::GAME_ROLE => [
                'هر کدوم یک نقش و یک هدف مخفی دارید — صفحه‌ت رو نشون نده. جمله‌های کمکی زیرش هست.',
                '۲ دقیقه با هم حرف بزنید و هر کدوم سعی کنه به هدفش برسه.',
                'بعد هدف دوستت رو از بین ۳ تا حدس بزن (+۱) و صادقانه بگو به هدف خودت رسیدی یا نه (+۱).',
            ],
            self::GAME_TRUTHS => [
                'نوبت توست: درباره‌ی موضوع ۳ جمله بگو — ۲ تا راست، ۱ دروغ — و شماره‌ی دروغ رو روی گوشیت بزن.',
                'دوستت می‌تونه ۲ سؤال بپرسه، بعد دروغ رو از بین ۱، ۲، ۳ انتخاب می‌کنه.',
                'دروغ پیدا شد +۱. بعد جاها عوض می‌شه.',
            ],
            self::GAME_RATHER => [
                'یک سؤال «Would you rather…?» میاد؛ مخفیانه برای خودت A یا B رو بزن و حدس بزن دوستت کدوم رو انتخاب می‌کنه.',
                'بعد جواب‌ها رو می‌بینید و هر کدوم دلیلش رو می‌گه: I’d rather… because…',
                'هر حدس درست +۱. بعد سؤال بعدی.',
            ],
            default => [],
        };
    }

    /**
     * What each phone shows before a game, the first time: this player's
     * part, the friend's part, one example and how points work. 'first' is
     * for whoever starts, 'second' for the friend; a game where both do
     * the same has one 'both' part.
     *
     * @return array{first?: array{title: string, you: string, friend: string}, second?: array{title: string, you: string, friend: string}, both?: array{title: string, you: string, friend: string}, example: array{label: string, line: string}, points: string}|null
     */
    public static function roleGuide(string $game): ?array
    {
        return match ($game) {
            self::GAME_TABOO => [
                'first' => ['title' => 'نوبت اول، تو توضیح می‌دی', 'you' => 'کلمه رو می‌بینی و بدون گفتن خودِ کلمه و بدون فارسی توضیحش می‌دی.', 'friend' => 'گوش می‌ده و حدسش رو بلند می‌گه.'],
                'second' => ['title' => 'نوبت اول، تو حدس می‌زنی', 'you' => 'گوش می‌دی و حدست رو بلند می‌گی.', 'friend' => 'کلمه رو می‌بینه و بدون گفتن خودِ کلمه توضیحش می‌ده.'],
                'example' => ['label' => 'breakfast', 'line' => '"You eat it in the morning."'],
                'points' => 'هر کلمه‌ی درست ۱+. توضیح‌دهنده Got it رو می‌زنه، بعد جاها عوض می‌شه.',
            ],
            self::GAME_WHO => [
                'first' => ['title' => 'نوبت اول، راز دست توئه', 'you' => 'راز رو می‌بینی و فقط Yes، No یا Sometimes رو می‌زنی.', 'friend' => 'با سؤال بله/خیر راز رو حدس می‌زنه.'],
                'second' => ['title' => 'نوبت اول، تو حدس می‌زنی', 'you' => 'با سؤال بله/خیر راز رو حدس می‌زنی.', 'friend' => 'راز رو می‌بینه و Yes، No یا Sometimes جواب می‌ده.'],
                'example' => ['label' => 'a teacher', 'line' => '"Do I work in a school?" Yes'],
                'points' => 'حدس درست ۱+، با ۵ سؤال یا کمتر ۱+ جایزه. دارنده‌ی راز Got it رو می‌زنه.',
            ],
            self::GAME_MINUTE => [
                'first' => ['title' => 'نوبت اول، تو حرف می‌زنی', 'you' => 'درباره‌ی موضوع حرف می‌زنی و سعی می‌کنی ۴ کلمه‌ی کارت رو بگی.', 'friend' => 'هر کلمه رو که شنید می‌زنه و آخر یه سؤال می‌پرسه.'],
                'second' => ['title' => 'نوبت اول، تو گوش می‌دی', 'you' => 'هر کلمه رو که شنیدی می‌زنی و آخر یه سؤال می‌پرسی.', 'friend' => 'درباره‌ی موضوع حرف می‌زنه و سعی می‌کنه ۴ کلمه رو بگه.'],
                'example' => ['label' => 'My weekend · early, coffee, park, friends', 'line' => '"I get up early and drink coffee…"'],
                'points' => 'هر کلمه‌ی شنیده‌شده ۱+، سؤال آخر هم ۱+. بعد جاها عوض می‌شه.',
            ],
            self::GAME_STORY => [
                'first' => ['title' => 'تو داستان رو شروع می‌کنی', 'you' => '۱ یا ۲ جمله می‌گی و کلمه‌ی نوبتت رو میاری، بعد Done.', 'friend' => 'گوش می‌ده، اگه کلمه رو گفتی می‌زنه و داستان رو ادامه می‌ده.'],
                'second' => ['title' => 'دوستت داستان رو شروع می‌کنه', 'you' => 'گوش می‌دی و اگه کلمه رو گفت می‌زنی، بعد ۱ یا ۲ جمله ادامه می‌دی.', 'friend' => '۱ یا ۲ جمله می‌گه و کلمه‌ی نوبتش رو میاره.'],
                'example' => ['label' => 'rain', 'line' => '"Then it started to rain, so we ran home."'],
                'points' => 'هر کلمه‌ی استفاده‌شده ۱+. نوبت آخر، داستان رو تموم کنید.',
            ],
            self::GAME_ROLE => [
                'both' => ['title' => 'هر کدوم یه نقش و یه هدف مخفی دارید', 'you' => 'با نقشت حرف می‌زنی تا به هدفت برسی. صفحه‌ت رو نشون نده.', 'friend' => 'با نقش خودش حرف می‌زنه و دنبال هدف خودشه.'],
                'example' => ['label' => 'A customer and a shop assistant', 'line' => '"Can I change this for a smaller one?"'],
                'points' => 'آخر، هدف دوستت رو حدس بزن (۱+) و صادقانه بگو به هدفت رسیدی یا نه (۱+).',
            ],
            self::GAME_TRUTHS => [
                'first' => ['title' => 'نوبت اول، تو می‌گی', 'you' => '۳ جمله می‌گی: ۲ راست، ۱ دروغ. شماره‌ی دروغ رو روی گوشیت می‌زنی.', 'friend' => '۲ سؤال می‌پرسه و دروغ رو پیدا می‌کنه.'],
                'second' => ['title' => 'نوبت اول، تو دروغ رو پیدا می‌کنی', 'you' => '۲ سؤال می‌پرسی و شماره‌ی دروغ رو می‌زنی.', 'friend' => '۳ جمله می‌گه: ۲ راست، ۱ دروغ.'],
                'example' => ['label' => 'My family', 'line' => '"I have two brothers. My mum is a nurse. I live alone."'],
                'points' => 'دروغ پیدا شد ۱+. بعد جاها عوض می‌شه.',
            ],
            self::GAME_RATHER => [
                'both' => ['title' => 'هر دو مخفیانه انتخاب می‌کنید', 'you' => 'برای خودت A یا B رو می‌زنی و حدس می‌زنی دوستت چی انتخاب می‌کنه.', 'friend' => 'همین کار رو می‌کنه. بعد هر کدوم دلیلش رو می‌گه.'],
                'example' => ['label' => 'Tea or coffee?', 'line' => '"I\'d rather have tea because I don\'t like coffee."'],
                'points' => 'هر حدس درست ۱+.',
            ],
            default => null,
        };
    }

    public const STAGE_WAITING = 'waiting';

    /** Between turns: the next explainer starts their turn when ready. */
    public const STAGE_READY = 'ready';

    public const STAGE_PLAYING = 'playing';

    public const STAGE_DONE = 'done';

    public const STAGE_CANCELLED = 'cancelled';

    public const RESULT_GOT = 'got';

    public const RESULT_SKIP = 'skip';

    /** The card still on screen when the turn's time ran out. */
    public const RESULT_MISSED = 'missed';

    /**
     * Score is kept in half points: a word guessed is 2 (one word), a word
     * guessed after "Show first letter" is 1 (half), and a card whose
     * bonus words were all avoided earns 2 more.
     */
    public const POINTS_WORD = 2;

    public const POINTS_WITH_LETTER = 1;

    public const POINTS_BONUS = 2;

    public const INVITE_MINUTES = 10;

    public const RESUME_HOURS = 2;

    /** A turn's clock gets a little slack for a slow phone before it may be closed. */
    private const CLOCK_SLACK_SECONDS = 2;

    /** @var array<string, mixed> */
    protected $attributes = ['score' => 0];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'deck' => 'array',
            'turns' => 'array',
            'minutes' => 'integer',
            'score' => 'integer',
            'joined_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    /**
     * Every game of this pair, whichever of the two started it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForPair(Builder $query, User $a, User $b): void
    {
        [$lowId, $highId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $query->where('user_a_id', $lowId)->where('user_b_id', $highId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id));
    }

    /**
     * Games that can still be picked up: unfinished, recent, and not an
     * invite that expired before the friend joined.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('finished_at')
            ->where('stage', '!=', self::STAGE_CANCELLED)
            ->where('created_at', '>=', now()->subHours(self::RESUME_HOURS))
            ->where(fn (Builder $query) => $query
                ->where('stage', '!=', self::STAGE_WAITING)
                ->orWhere('created_at', '>', now()->subMinutes(self::INVITE_MINUTES)));
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->id === $this->user_a_id || $user->id === $this->user_b_id;
    }

    public function partnerFor(User $user): User
    {
        return $user->id === $this->user_a_id ? $this->userB : $this->userA;
    }

    public function isWaiting(): bool
    {
        return $this->stage === self::STAGE_WAITING;
    }

    public function isPlaying(): bool
    {
        return $this->stage === self::STAGE_PLAYING;
    }

    public function isFinished(): bool
    {
        return $this->stage === self::STAGE_DONE;
    }

    public function isCancelled(): bool
    {
        return $this->stage === self::STAGE_CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->isWaiting() && $this->created_at->lte(now()->subMinutes(self::INVITE_MINUTES));
    }

    public function inviteEndsAt(): Carbon
    {
        return $this->created_at->copy()->addMinutes(self::INVITE_MINUTES);
    }

    /**
     * The invited friend joins: the game waits for its first explainer —
     * whoever started it — to tap Start.
     */
    public function join(User $user): void
    {
        if (! $this->isWaiting() || $this->isExpired() || $user->id === $this->started_by_id || ! $this->isAccessibleBy($user)) {
            return;
        }

        $this->forceFill(['joined_at' => now(), 'stage' => self::STAGE_READY])->save();
    }

    /**
     * join() under a row lock, for a tap that lands from outside the game
     * (Join on an invite banner or the Friends page).
     */
    public function joinWithLock(User $user): void
    {
        if (! $this->isWaiting() || $user->id === $this->started_by_id) {
            return;
        }

        DB::transaction(function () use ($user) {
            $locked = self::lockForUpdate()->findOrFail($this->id);
            $locked->join($user);
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Seconds of one turn (90 for beginners, 60 later).
     */
    public function turnSeconds(): int
    {
        return (int) ($this->rules['turn_seconds'] ?? 60);
    }

    /**
     * How many turns the game has — always an even number, so both
     * explain equally often.
     */
    public function plannedTurns(): int
    {
        return (int) ($this->rules['turns'] ?? 4);
    }

    /**
     * Skips allowed in one turn, or null when skipping is free.
     */
    public function skipsPerTurn(): ?int
    {
        return isset($this->rules['skips']) ? (int) $this->rules['skips'] : null;
    }

    /**
     * Who explains turn $index: the one who started the game first, then
     * the two take turns.
     */
    public function explainerOf(int $index): User
    {
        $starter = $this->startedBy;

        return $index % 2 === 0 ? $starter : $this->partnerFor($starter);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentTurn(): ?array
    {
        return $this->isPlaying() ? (collect($this->turns ?? [])->last()) : null;
    }

    public function currentTurnIndex(): int
    {
        return max(0, count($this->turns ?? []) - ($this->isPlaying() ? 1 : 0));
    }

    /**
     * Who explains now — or, between turns, who explains next.
     */
    public function explainer(): User
    {
        return $this->explainerOf($this->currentTurnIndex());
    }

    /**
     * The card on the explainer's screen.
     *
     * @return array<string, mixed>|null
     */
    public function currentCard(): ?array
    {
        $turn = $this->currentTurn();

        return $turn === null ? null : ($this->deck[$turn['current']['card']] ?? null);
    }

    /**
     * The next card's place in the deck: the one after the last card shown.
     * The deck starts over if a long game ever runs through it.
     */
    private function nextCardIndex(): int
    {
        $last = -1;

        foreach ($this->turns ?? [] as $turn) {
            foreach ($turn['cards'] as $card) {
                $last = $card['card'];
            }

            if (isset($turn['current'])) {
                $last = $turn['current']['card'];
            }
        }

        return ($last + 1) % max(1, count($this->deck ?? []));
    }

    /**
     * The next explainer starts their turn: the clock runs from now.
     */
    public function startTurn(User $user): void
    {
        if ($this->stage !== self::STAGE_READY || ! $this->explainer()->is($user)) {
            return;
        }

        $this->beginTurn($user);
    }

    private function beginTurn(User $user): void
    {
        $card = $this->nextCardIndex();
        $current = self::freshCurrent($card);

        // Would You Rather starts with both picking; the talk comes after.
        if ($this->isWouldYouRather()) {
            $current['phase'] = self::PHASE_PICK;
        }

        $this->turns = [...($this->turns ?? []), [
            'explainer_id' => $user->id,
            'started_at' => now()->getTimestamp(),
            'ends_at' => now()->addSeconds($this->isWouldYouRather() ? self::PICK_SECONDS : $this->turnSeconds())->getTimestamp(),
            'cards' => [],
            'current' => $current,
        ]];
        $this->stage = self::STAGE_PLAYING;
        $this->save();
    }

    public function turnEndsAt(): ?int
    {
        return $this->currentTurn()['ends_at'] ?? null;
    }

    public function skipsLeft(): ?int
    {
        $limit = $this->skipsPerTurn();
        $turn = $this->currentTurn();

        if ($limit === null || $turn === null) {
            return null;
        }

        return max(0, $limit - collect($turn['cards'])->where('result', self::RESULT_SKIP)->count());
    }

    /**
     * The explainer taps "got it" or Skip for the card on screen. $seen is
     * how many cards of this turn their phone had seen, so a double tap or
     * a stale screen changes nothing.
     */
    public function scoreCard(User $user, string $result, int $seen): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->explainer()->is($user) || count($turn['cards']) !== $seen || now()->getTimestamp() > $turn['ends_at'] + self::CLOCK_SLACK_SECONDS) {
            return;
        }

        if ($result === self::RESULT_SKIP && $this->skipsLeft() === 0) {
            return;
        }

        if (! in_array($result, [self::RESULT_GOT, self::RESULT_SKIP], true) || ($this->isOneCardPerTurn() && $result !== self::RESULT_GOT) || ! in_array($this->game, [self::GAME_TABOO, self::GAME_WHO], true)) {
            return;
        }

        $this->closeCard($result);

        if ($this->isOneCardPerTurn()) {
            $this->endTurn();

            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current'] = self::freshCurrent($this->nextCardIndex());
        $this->turns = $turns;
        $this->save();
    }

    /**
     * @return array{card: int, letter: bool, bonus_said: bool, answers: list<string>}
     */
    private static function freshCurrent(int $card): array
    {
        return ['card' => $card, 'letter' => false, 'bonus_said' => false, 'answers' => [], 'heard' => [], 'phase' => self::PHASE_TALK];
    }

    /**
     * Who am I and Just a Minute have one card per turn: once it is
     * guessed (or talked about and asked about), the turn is over.
     */
    public function isOneCardPerTurn(): bool
    {
        return in_array($this->game, [self::GAME_WHO, self::GAME_MINUTE, self::GAME_STORY, self::GAME_ROLE, self::GAME_TRUTHS, self::GAME_RATHER], true);
    }

    public function isWouldYouRather(): bool
    {
        return $this->game === self::GAME_RATHER;
    }

    /**
     * Would You Rather: $user's own side and their guess of the friend's
     * ('a' or 'b'), once. When both have picked, the picks are shown and
     * each right guess scores.
     */
    public function pickSide(User $user, string $mine, string $theirs): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isWouldYouRather() || ! $this->isAccessibleBy($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_PICK
            || isset($turn['current']['picks'][$user->id]) || ! in_array($mine, self::SIDES, true) || ! in_array($theirs, self::SIDES, true)) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['picks'][$user->id] = ['mine' => $mine, 'guess' => $theirs];
        $this->turns = $turns;

        if (count($turns[array_key_last($turns)]['current']['picks']) >= 2) {
            $this->revealPicks();

            return;
        }

        $this->save();
    }

    /**
     * Would You Rather: the picks are shown on both phones, right guesses
     * score straight away and the two say why.
     */
    private function revealPicks(): void
    {
        $turns = $this->turns;
        $last = array_key_last($turns);
        $turns[$last]['current']['phase'] = self::PHASE_TALK;
        $turns[$last]['ends_at'] = now()->addSeconds($this->turnSeconds())->getTimestamp();
        $this->turns = $turns;
        $this->score += $this->rightGuessPoints($turns[$last]['current']['picks'] ?? []);
        $this->save();
    }

    /**
     * @param  array<int, array{mine: string, guess: string}>  $picks
     */
    private function rightGuessPoints(array $picks): int
    {
        return collect($picks)
            ->filter(fn (array $pick, int $userId) => $pick['guess'] === ($picks[$this->otherPlayerId($userId)]['mine'] ?? null))
            ->count() * self::POINTS_WORD;
    }

    private function otherPlayerId(int $userId): int
    {
        return $userId === $this->user_a_id ? $this->user_b_id : $this->user_a_id;
    }

    /**
     * Would You Rather: either player says they both gave their reasons —
     * on to the next question.
     */
    public function doneWhy(User $user): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isWouldYouRather() || ! $this->isAccessibleBy($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK) {
            return;
        }

        $this->closeTurn();
    }

    public function isTwoTruths(): bool
    {
        return $this->game === self::GAME_TRUTHS;
    }

    /**
     * Two Truths: the teller marks which of their three statements (1–3)
     * is the lie — only their phone knows it. They can change it while
     * telling.
     */
    public function markLie(User $user, int $statement): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isTwoTruths() || ! $this->explainer()->is($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK
            || $statement < 1 || $statement > self::STATEMENTS) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['lie'] = $statement;
        $this->turns = $turns;
        $this->save();
    }

    /**
     * Two Truths: the teller said their three things (and marked the lie)
     * — the listener now asks and picks.
     */
    public function doneSaying(User $user): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isTwoTruths() || ! $this->explainer()->is($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK
            || ! isset($turn['current']['lie'])) {
            return;
        }

        $this->startGuessing();
    }

    private function startGuessing(): void
    {
        $turns = $this->turns;
        $last = array_key_last($turns);
        $turns[$last]['current']['phase'] = self::PHASE_GUESS;
        $turns[$last]['ends_at'] = now()->addSeconds(self::GUESS_SECONDS)->getTimestamp();
        $this->turns = $turns;
        $this->save();
    }

    /**
     * Two Truths: the listener picks the statement they think is the lie
     * — found, one point — and the turn is over.
     */
    public function pickLie(User $user, int $statement): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isTwoTruths() || $this->explainer()->is($user) || ! $this->isAccessibleBy($user)
            || ($turn['current']['phase'] ?? null) !== self::PHASE_GUESS || $statement < 1 || $statement > self::STATEMENTS) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['guess'] = $statement;
        $this->turns = $turns;

        $this->closeCard($statement === ($turn['current']['lie'] ?? null) ? self::RESULT_GOT : self::RESULT_MISSED);
        $this->endTurn();
    }

    public function isRolePlay(): bool
    {
        return $this->game === self::GAME_ROLE;
    }

    /**
     * Role-play: $user's side of the scene on screen — the turn's holder
     * plays 'a', the other 'b'.
     *
     * @return array{role: string, goal: string, help: list<string>}|null
     */
    public function roleOf(User $user, ?array $card = null, ?int $turnIndex = null): ?array
    {
        $card ??= $this->currentCard();
        $holder = $this->explainerOf($turnIndex ?? $this->currentTurnIndex());

        return $card[$holder->is($user) ? 'a' : 'b'] ?? null;
    }

    /**
     * Role-play: the three goals $user picks from when guessing the
     * friend's goal (the real one and two that fit the scene too).
     *
     * @return list<string>
     */
    public function guessOptionsFor(User $user, ?array $card = null, ?int $turnIndex = null): array
    {
        $card ??= $this->currentCard();
        $holder = $this->explainerOf($turnIndex ?? $this->currentTurnIndex());

        return $card[$holder->is($user) ? 'options_b' : 'options_a'] ?? [];
    }

    /**
     * Role-play: either player ends the talk — now both guess.
     */
    public function endTalk(User $user): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isRolePlay() || ! $this->isAccessibleBy($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK) {
            return;
        }

        $this->startGuessing();
    }

    /**
     * Role-play: $user's guess of the friend's goal (an index into their
     * options) and whether they honestly reached their own. Once both have
     * answered, the scene is over.
     */
    public function submitGuess(User $user, int $option, bool $reached): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isRolePlay() || ! $this->isAccessibleBy($user) || ($turn['current']['phase'] ?? null) !== self::PHASE_GUESS
            || isset($turn['current']['guesses'][$user->id]) || ! isset($this->guessOptionsFor($user)[$option])) {
            return;
        }

        $turns = $this->turns;
        $last = array_key_last($turns);
        $turns[$last]['current']['guesses'][$user->id] = ['option' => $option, 'reached' => $reached];
        $this->turns = $turns;

        if (count($turns[$last]['current']['guesses']) >= 2) {
            $this->closeCard(self::RESULT_GOT);
            $this->endTurn();

            return;
        }

        $this->save();
    }

    public function isStoryChain(): bool
    {
        return $this->game === self::GAME_STORY;
    }

    /** Turns in one story. */
    public function storyTurns(): int
    {
        return (int) ($this->rules['story_turns'] ?? 8);
    }

    /**
     * Story Chain: the listener ticks that the teller used this turn's
     * word — one point, once.
     */
    public function wordUsed(User $user): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isStoryChain() || $this->explainer()->is($user) || ! $this->isAccessibleBy($user)
            || ($turn['current']['used'] ?? false) || now()->getTimestamp() > $turn['ends_at'] + self::CLOCK_SLACK_SECONDS) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['used'] = true;
        $this->turns = $turns;
        $this->score += self::POINTS_WORD;
        $this->save();
    }

    /**
     * Story Chain: the teller is done with their sentence or two — the
     * story passes to the other player straight away.
     */
    public function doneTelling(User $user): void
    {
        if ($this->currentTurn() === null || ! $this->isStoryChain() || ! $this->explainer()->is($user)) {
            return;
        }

        $this->closeTurn();
    }

    public function isJustAMinute(): bool
    {
        return $this->game === self::GAME_MINUTE;
    }

    /**
     * Who talks in a turn of $user's: the card holder in Taboo (explains)
     * and Just a Minute (talks), the other player in Who am I (asks).
     */
    public function holderSpeaks(): bool
    {
        return $this->game !== self::GAME_WHO;
    }

    /**
     * Just a Minute: the listener ticks a target word as they hear it —
     * one point each, once.
     */
    public function hearWord(User $user, string $word): void
    {
        $turn = $this->currentTurn();
        $card = $this->currentCard();

        if ($turn === null || ! $this->isJustAMinute() || $this->explainer()->is($user) || ! $this->isAccessibleBy($user)
            || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK || ! in_array($word, $card['words'] ?? [], true)
            || in_array($word, $turn['current']['heard'] ?? [], true) || now()->getTimestamp() > $turn['ends_at'] + self::CLOCK_SLACK_SECONDS) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['heard'][] = $word;
        $this->turns = $turns;
        $this->score += self::POINTS_WORD;
        $this->save();
    }

    /**
     * Just a Minute: the talker stops before the minute is up, or the
     * minute ran out — the listener now asks their one question.
     */
    public function startQuestion(): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isJustAMinute() || ($turn['current']['phase'] ?? null) !== self::PHASE_TALK) {
            return;
        }

        $turns = $this->turns;
        $last = array_key_last($turns);
        $turns[$last]['current']['phase'] = self::PHASE_ASK;
        $turns[$last]['ends_at'] = now()->addSeconds(self::ASK_SECONDS)->getTimestamp();
        $this->turns = $turns;
        $this->save();
    }

    public function finishTalking(User $user): void
    {
        if ($this->explainer()->is($user)) {
            $this->startQuestion();
        }
    }

    /**
     * Just a Minute: the listener asked their question (and heard the
     * answer) — one more point, and the turn is over.
     */
    public function questionAsked(User $user): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->isJustAMinute() || $this->explainer()->is($user) || ! $this->isAccessibleBy($user)
            || ($turn['current']['phase'] ?? null) !== self::PHASE_ASK) {
            return;
        }

        $this->closeCard(self::RESULT_GOT);
        $this->endTurn();
    }

    public function phase(): ?string
    {
        return $this->currentTurn()['current']['phase'] ?? null;
    }

    /**
     * Who am I: the one holding the secret answers the guesser's question
     * — yes, no or sometimes. The guesser's phone shows the answer; after
     * MAX_QUESTIONS the secret counts as missed and the turn is over.
     */
    public function answerQuestion(User $user, string $answer, int $asked): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || $this->game !== self::GAME_WHO || ! $this->explainer()->is($user) || ! in_array($answer, self::ANSWERS, true)
            || count($turn['current']['answers'] ?? []) !== $asked || now()->getTimestamp() > $turn['ends_at'] + self::CLOCK_SLACK_SECONDS) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current']['answers'][] = $answer;
        $this->turns = $turns;

        if (count($turns[array_key_last($turns)]['current']['answers']) >= self::MAX_QUESTIONS) {
            $this->closeTurn();

            return;
        }

        $this->save();
    }

    public function questionsAsked(): int
    {
        return count($this->currentTurn()['current']['answers'] ?? []);
    }

    /**
     * Moves the card on screen into the turn's cards with $result and adds
     * its points.
     */
    private function closeCard(string $result): void
    {
        $turns = $this->turns;
        $last = array_key_last($turns);
        $current = $turns[$last]['current'];
        $card = $this->deck[$current['card']] ?? [];
        $points = 0;

        $alreadyCounted = 0;

        if ($this->isRolePlay()) {
            $points = collect($current['guesses'] ?? [])->map(function (array $guess, int $userId) use ($card) {
                $guesser = $this->user_a_id === $userId ? $this->userA : $this->userB;
                $options = $this->guessOptionsFor($guesser, $card, count($this->turns) - 1);
                $friendGoal = $this->roleOf($this->partnerFor($guesser), $card, count($this->turns) - 1)['goal'] ?? null;

                return (($options[$guess['option']] ?? null) === $friendGoal ? self::POINTS_WORD : 0) + ($guess['reached'] ? self::POINTS_WORD : 0);
            })->sum();
        } elseif ($this->isWouldYouRather()) {
            // Right guesses were counted at the reveal; a question nobody
            // finished picking scores nothing.
            $revealed = ($current['phase'] ?? null) === self::PHASE_TALK;
            $points = $alreadyCounted = $revealed ? $this->rightGuessPoints($current['picks'] ?? []) : 0;
            $result = $revealed ? self::RESULT_GOT : self::RESULT_MISSED;
        } elseif ($this->isStoryChain()) {
            $result = ($current['used'] ?? false) ? self::RESULT_GOT : self::RESULT_MISSED;
            $points = $alreadyCounted = $result === self::RESULT_GOT ? self::POINTS_WORD : 0;
        } elseif ($this->isJustAMinute() || $this->isTwoTruths()) {
            $points = $result === self::RESULT_GOT ? self::POINTS_WORD : 0;
        } elseif ($result === self::RESULT_GOT && $this->isOneCardPerTurn()) {
            $points = self::POINTS_WORD + (count($current['answers'] ?? []) <= self::QUICK_GUESS_QUESTIONS ? self::POINTS_BONUS : 0);
        } elseif ($result === self::RESULT_GOT) {
            $points = $current['letter'] ? self::POINTS_WITH_LETTER : self::POINTS_WORD;

            if (($card['bonus'] ?? []) !== [] && ! $current['bonus_said']) {
                $points += self::POINTS_BONUS;
            }
        }

        $turns[$last]['cards'][] = [
            'card' => $current['card'],
            'result' => $result,
            'letter' => $current['letter'],
            'bonus_said' => $current['bonus_said'],
            'questions' => count($current['answers'] ?? []),
            'heard' => $current['heard'] ?? [],
            'guesses' => $current['guesses'] ?? [],
            'lie' => $current['lie'] ?? null,
            'picks' => $current['picks'] ?? [],
            'guess' => $current['guess'] ?? null,
            'points' => $points,
            'at' => now()->getTimestamp(),
        ];

        $this->turns = $turns;
        $this->score += $points - $alreadyCounted;
    }

    /**
     * "Show first letter": the guesser's phone shows the word's first
     * letter and its length; a word guessed after it is worth half.
     */
    public function showLetter(User $user, int $seen): void
    {
        $this->markCurrent($user, $seen, 'letter');
    }

    /**
     * "I said a bonus word" — honest, from the explainer: no bonus point
     * for this card.
     */
    public function bonusSaid(User $user, int $seen): void
    {
        $this->markCurrent($user, $seen, 'bonus_said');
    }

    private function markCurrent(User $user, int $seen, string $flag): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->explainer()->is($user) || count($turn['cards']) !== $seen) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current'][$flag] = true;
        $this->turns = $turns;
        $this->save();
    }

    /**
     * Called by the timer on both phones: the turn $index ends once its
     * time is up. The card left on screen counts as missed; after the last
     * turn the game is over.
     */
    public function timeUp(int $index): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || $this->currentTurnIndex() !== $index || now()->getTimestamp() + self::CLOCK_SLACK_SECONDS < $turn['ends_at']) {
            return;
        }

        if ($this->isJustAMinute() && ($turn['current']['phase'] ?? null) === self::PHASE_TALK) {
            $this->startQuestion();

            return;
        }

        // Would You Rather: the picks so far are shown once the pick time is
        // up (a question nobody picked just ends).
        if ($this->isWouldYouRather() && ($turn['current']['phase'] ?? null) === self::PHASE_PICK) {
            if (($turn['current']['picks'] ?? []) === []) {
                $this->closeTurn();
            } else {
                $this->revealPicks();
            }

            return;
        }

        // Role-play always goes on to the guesses; Two Truths only once the
        // teller has marked their lie (without one there is nothing to find).
        if (($turn['current']['phase'] ?? null) === self::PHASE_TALK && ($this->isRolePlay() || ($this->isTwoTruths() && isset($turn['current']['lie'])))) {
            $this->startGuessing();

            return;
        }

        $this->closeTurn();
    }

    private function closeTurn(): void
    {
        $this->closeCard($this->isRolePlay() ? self::RESULT_GOT : self::RESULT_MISSED);
        $this->endTurn();
    }

    /**
     * The turn is over (its card already closed): the next one waits for
     * its explainer, or the game ends after the last.
     */
    private function endTurn(): void
    {
        $turns = $this->turns;
        unset($turns[array_key_last($turns)]['current']);
        $this->turns = $turns;

        if (count($turns) >= $this->plannedTurns()) {
            $this->finish();

            return;
        }

        $this->stage = self::STAGE_READY;

        // A story goes on by itself to the other teller; only a new story
        // waits for its Start.
        if ($this->isStoryChain() && count($turns) % $this->storyTurns() !== 0) {
            $this->beginTurn($this->explainerOf(count($turns)));

            return;
        }

        $this->save();
    }

    public function finish(): void
    {
        if ($this->isFinished()) {
            return;
        }

        $this->forceFill(['stage' => self::STAGE_DONE, 'finished_at' => now()])->save();

        // A finished game is a slice of both players' day, like a pair practice.
        foreach ([$this->userA, $this->userB] as $player) {
            app(StreakRing::class)->record($player, 'game');
        }
    }

    /**
     * Calls off an invite before the friend joined. Either of the two can.
     */
    public function cancel(User $by): void
    {
        if (! $this->isWaiting()) {
            return;
        }

        $this->forceFill(['stage' => self::STAGE_CANCELLED, 'ended_by_id' => $by->id])->save();
    }

    /**
     * One of the two taps End: a game with at least one finished turn ends
     * with its score; one ended before that doesn't count.
     */
    public function endEarly(User $by): void
    {
        if ($this->isFinished() || $this->isCancelled() || $this->isWaiting()) {
            return;
        }

        $this->ended_by_id = $by->id;

        if ($this->isPlaying()) {
            $turns = $this->turns;
            unset($turns[array_key_last($turns)]['current']);
            $this->turns = $turns;
        }

        if (collect($this->turns ?? [])->sum(fn (array $turn) => count($turn['cards'])) === 0) {
            $this->forceFill(['stage' => self::STAGE_CANCELLED])->save();

            return;
        }

        $this->finish();
    }

    /**
     * Half points as shown: 9, 9½.
     */
    public static function formatPoints(int $halves): string
    {
        $whole = intdiv($halves, 2);

        return match (true) {
            $halves % 2 === 0 => (string) $whole,
            $whole === 0 => '½',
            default => $whole.'½',
        };
    }

    /**
     * The pair's best score in this game and length before this game —
     * null when they have never finished one.
     */
    public function previousBest(): ?int
    {
        $best = self::forPair($this->userA, $this->userB)
            ->where('game', $this->game)
            ->where('minutes', $this->minutes)
            ->where('stage', self::STAGE_DONE)
            ->whereKeyNot($this->id)
            ->when($this->finished_at, fn (Builder $query) => $query->where('finished_at', '<', $this->finished_at))
            ->max('score');

        return $best === null ? null : (int) $best;
    }

    public function isNewRecord(): bool
    {
        $best = $this->previousBest();

        return $this->isFinished() && $this->score > 0 && ($best === null || $this->score > $best);
    }

    /**
     * Every card played, with its word: what the result screen lists.
     *
     * @return list<array{word: string, result: string, explainer_id: int, points: int}>
     */
    public function playedCards(): array
    {
        return collect($this->turns ?? [])
            ->flatMap(fn (array $turn) => collect($turn['cards'])->map(fn (array $card) => [
                'word' => $this->deck[$card['card']]['word'] ?? '',
                'result' => $card['result'],
                'explainer_id' => $turn['explainer_id'],
                'points' => $card['points'],
            ]))
            ->values()
            ->all();
    }

    /**
     * Words guessed while $user explained.
     */
    public function guessedWhileExplaining(User $user): int
    {
        return collect($this->playedCards())->where('explainer_id', $user->id)->where('result', self::RESULT_GOT)->count();
    }

    /**
     * What the result screen counts for each player: words they explained
     * that were guessed (Taboo), or secrets they guessed (Who am I).
     */
    public function creditFor(User $user): int
    {
        if ($this->isStoryChain()) {
            return collect($this->playedCards())->where('explainer_id', $user->id)->where('result', self::RESULT_GOT)->count();
        }

        if ($this->isWouldYouRather()) {
            return collect($this->turns ?? [])->sum(fn (array $turn) => collect($turn['cards'])->sum(function (array $card) use ($user) {
                $picks = $card['picks'] ?? [];

                return isset($picks[$user->id]) && $picks[$user->id]['guess'] === ($picks[$this->otherPlayerId($user->id)]['mine'] ?? null) ? 1 : 0;
            }));
        }

        if ($this->isRolePlay()) {
            return collect($this->turns ?? [])->sum(fn (array $turn) => collect($turn['cards'])->sum(fn (array $card) => ($card['guesses'][$user->id]['reached'] ?? false) ? 1 : 0));
        }

        if ($this->isJustAMinute()) {
            return collect($this->turns ?? [])
                ->where('explainer_id', $user->id)
                ->sum(fn (array $turn) => collect($turn['cards'])->sum(fn (array $card) => count($card['heard'] ?? [])));
        }

        if (! $this->isOneCardPerTurn()) {
            return $this->guessedWhileExplaining($user);
        }

        return collect($this->playedCards())->where('explainer_id', '!=', $user->id)->where('result', self::RESULT_GOT)->count();
    }

    /**
     * Words skipped or left when the time ran out (Just a Minute: target
     * words never heard) — shown at the end only, never saved anywhere.
     *
     * @return list<string>
     */
    public function missedWords(): array
    {
        if ($this->isRolePlay() || $this->isTwoTruths() || $this->isWouldYouRather()) {
            return [];
        }

        if ($this->isJustAMinute()) {
            return collect($this->turns ?? [])
                ->flatMap(fn (array $turn) => collect($turn['cards'])->flatMap(fn (array $card) => array_diff($this->deck[$card['card']]['words'] ?? [], $card['heard'] ?? [])))
                ->unique()
                ->values()
                ->all();
        }

        return collect($this->playedCards())
            ->where('result', '!=', self::RESULT_GOT)
            ->pluck('word')
            ->unique()
            ->values()
            ->all();
    }
}
