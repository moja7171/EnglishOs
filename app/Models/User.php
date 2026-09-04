<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\FollowRequestAccepted;
use App\Notifications\FollowRequestReceived;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'cefr_level', 'target_band', 'avatar_color', 'avatar_path', 'avatar_style', 'gender', 'discoverable', 'celebrated_streak_milestone', 'weekly_goal_days', 'pinned_highlight'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Explicit, not left to the migration's DB column default — Eloquent
     * doesn't re-fetch a Postgres row's own defaults after insert(), so a
     * freshly created instance would otherwise read this as null in the
     * very same request instead of 0, breaking
     * streakMilestoneJustReached()'s "< $milestone" comparison. Same class
     * of gap already found and fixed once on VocabularyWord.
     */
    protected $attributes = [
        'celebrated_streak_milestone' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'discoverable' => 'boolean',
        ];
    }

    /**
     * Plain-language self-assessment options instead of raw CEFR codes —
     * a true beginner has no idea what "B1" means, but recognizes "I can
     * introduce myself and ask simple questions." Shared by registration
     * and the profile edit form so the two never drift apart. See
     * levelDescription() for where the stored value actually gets used.
     *
     * @return array<string, string>
     */
    public static function levelOptions(): array
    {
        return [
            'A1' => 'Beginner — I know a few basic words and phrases',
            'A2' => 'Elementary — I can introduce myself and ask simple questions',
            'A2+' => 'Pre-Intermediate — I can handle familiar topics, but I hesitate a lot',
            'B1' => 'Intermediate — I can talk about daily life and familiar topics',
            'B2' => 'Upper-Intermediate — I can discuss most topics fairly fluently',
            'C1' => 'Advanced — I can express myself fluently on almost anything',
        ];
    }

    /**
     * @return list<string>
     */
    public static function targetBandOptions(): array
    {
        return ['5.0', '5.5', '6.0', '6.5', '7.0', '7.5', '8.0', '8.5', '9.0'];
    }

    /**
     * "unspecified" is a real, first-class choice here, not a stand-in for
     * a missing value — it's what keeps a learner on the plain
     * color+initial avatar (see defaultAvatarStyleForGender()) rather than
     * ever forcing a gendered illustration on someone who'd rather not say.
     *
     * @return array<string, string>
     */
    public static function genderOptions(): array
    {
        return [
            'male' => 'Male',
            'female' => 'Female',
            'unspecified' => 'Prefer not to say',
        ];
    }

    /**
     * Every illustrated avatar style a learner can pick — "initial" (the
     * plain color+letter circle) is always available regardless of
     * gender, same as every color in avatarColorPalette(). Labels are
     * deliberately neutral (not "men's style 1") since any learner can
     * freely pick any style; gender only decides the one auto-suggested
     * the first time it's set (see defaultAvatarStyleForGender()).
     *
     * @return array<string, string>
     */
    public static function avatarStyleOptions(): array
    {
        return [
            'initial' => 'Initial',
            'bust' => 'Classic',
            'short' => 'Cap',
            'side-part' => 'Side part',
            'curly' => 'Curly',
            'long' => 'Long',
            'bob' => 'Bob',
            'ponytail' => 'Ponytail',
        ];
    }

    /**
     * A starting point, never a lock-in — Profile only applies this when
     * gender changes AND the learner hasn't already picked a real avatar
     * style or photo (see Profile::updateBasicInfo()), so it can never
     * silently override a deliberate choice.
     */
    public static function defaultAvatarStyleForGender(string $gender): string
    {
        return match ($gender) {
            'male' => 'short',
            'female' => 'long',
            default => 'initial',
        };
    }

    /**
     * A fixed set of accent colors a learner can pick for their avatar —
     * literal Tailwind class strings (never built from a dynamic
     * "bg-{$key}-100"), because Tailwind's JIT scanner only generates the
     * utility classes it can see written out somewhere in a source file.
     * "accent" reuses the app's own semantic token (its own light/dark
     * pair); every other key is a plain Tailwind hue since there's no
     * semantic token for an arbitrary color.
     *
     * @return array<string, string>
     */
    public static function avatarColorPalette(): array
    {
        return [
            'accent' => 'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark',
            'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
            'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
            'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
            'sky' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300',
            'violet' => 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
            'fuchsia' => 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950 dark:text-fuchsia-300',
            'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-950 dark:text-slate-300',
        ];
    }

    /**
     * The public URL of this learner's uploaded photo, or null when
     * they're on a color+initial avatar — always the 'public' disk (see
     * the avatar_path migration comment), never the private 'local' disk
     * every other upload in this app uses, because an avatar is meant to
     * be visible to anyone who can already see this learner's name.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    /**
     * @return HasMany<MissionRun, $this>
     */
    public function missionRuns(): HasMany
    {
        return $this->hasMany(MissionRun::class, 'learner_id');
    }

    public function missionsCompletedCount(): int
    {
        return $this->missionRuns()->where('status', MissionRun::STATUS_COMPLETE)->count();
    }

    /**
     * Non-competitive "this week" framing for the Friends Board (see
     * friends/⚡board.blade.php) — a plain count, never a rank.
     */
    public function missionsCompletedThisWeekCount(): int
    {
        return $this->missionRuns()
            ->where('status', MissionRun::STATUS_COMPLETE)
            ->where('completed_at', '>=', now()->startOfWeek())
            ->count();
    }

    /**
     * How far into the 24-mission roadmap (Mission::TOTAL_ROADMAP_MISSIONS,
     * EOS-009 §15) this learner has reached — the highest mission NUMBER
     * they have any run for at all (complete, in progress, needs review,
     * or retry), regardless of calendar pace. Deliberately separate from
     * "day" numbering: each mission's own Day 1-4 stays local to that
     * mission (see MissionRun::dayProgress()) — a single continuous day
     * count across the whole roadmap would conflate two different things
     * (calendar-based streak vs. content-based mission day) and produce
     * a number in the high 90s by the end of the roadmap, which reads as
     * a slog rather than a series of manageable sprints. Defaults to 1 —
     * a learner who hasn't started anything yet is still "on Mission 1".
     */
    public function currentMissionNumber(): int
    {
        $numbers = $this->missionRuns()
            ->with('mission')
            ->get()
            ->pluck('mission.code')
            ->filter()
            ->map(fn (string $code) => (int) preg_replace('/\D/', '', $code));

        return max(1, $numbers->max() ?? 1);
    }

    /**
     * The mission this learner is actively working on right now, if any —
     * feeds the Friends Board's per-friend progress ring/journey map.
     * "Latest" by started_at since a learner could in principle have more
     * than one in_progress run across different missions.
     */
    public function latestInProgressMissionRun(): ?MissionRun
    {
        return $this->missionRuns()
            ->where('status', MissionRun::STATUS_IN_PROGRESS)
            ->latest('started_at')
            ->first();
    }

    /**
     * @return HasMany<VocabularyWord, $this>
     */
    public function vocabularyWords(): HasMany
    {
        return $this->hasMany(VocabularyWord::class, 'learner_id');
    }

    /**
     * @return HasMany<SpeakingPrompt, $this>
     */
    public function speakingPrompts(): HasMany
    {
        return $this->hasMany(SpeakingPrompt::class, 'learner_id');
    }

    /**
     * @return HasMany<ErrorPatternReview, $this>
     */
    public function errorPatternReviews(): HasMany
    {
        return $this->hasMany(ErrorPatternReview::class, 'learner_id');
    }

    /**
     * @return HasMany<GrammarPoint, $this>
     */
    public function grammarPoints(): HasMany
    {
        return $this->hasMany(GrammarPoint::class, 'learner_id');
    }

    /**
     * Every actively-tracked spaced-repetition item (repetitions > 0 —
     * see HasSpacedRepetition::needsWrittenReview()) across all four
     * review systems, with its current freshness() — sorted so the most
     * decayed item comes first, since that's the one worth surfacing.
     * Feeds the "My Progress" page's memory-freshness section.
     *
     * @return list<array{type: string, label: string, freshness: int}>
     */
    public function memoryFreshnessItems(): array
    {
        $words = $this->vocabularyWords()->where('repetitions', '>', 0)->get()
            ->map(fn (VocabularyWord $item) => ['type' => 'Word', 'label' => $item->word, 'freshness' => $item->freshness()]);

        $prompts = $this->speakingPrompts()->where('repetitions', '>', 0)->get()
            ->map(fn (SpeakingPrompt $item) => ['type' => 'Speaking', 'label' => $item->prompt, 'freshness' => $item->freshness()]);

        // Labeled "Grammar" (a recurring MISTAKE pattern, not a taught rule)
        // — kept distinct from GrammarPoint's "Grammar point" label below,
        // since the two track genuinely different things.
        $errors = $this->errorPatternReviews()->where('repetitions', '>', 0)->get()
            ->map(fn (ErrorPatternReview $item) => ['type' => 'Grammar', 'label' => $item->last_correction, 'freshness' => $item->freshness()]);

        $grammarPoints = $this->grammarPoints()->where('repetitions', '>', 0)->get()
            ->map(fn (GrammarPoint $item) => ['type' => 'Grammar point', 'label' => $item->focus, 'freshness' => $item->freshness()]);

        return $words->concat($prompts)->concat($errors)->concat($grammarPoints)
            ->sortBy('freshness')
            ->values()
            ->all();
    }

    /**
     * The average freshness() across every item memoryFreshnessItems()
     * returns — null (not 0) when nothing has been reviewed even once
     * yet, so callers can tell "no data" apart from "fully decayed".
     */
    public function averageMemoryFreshness(): ?int
    {
        $items = collect($this->memoryFreshnessItems());

        return $items->isEmpty() ? null : (int) round($items->avg('freshness'));
    }

    /**
     * Called once per newly-logged mistake (see Error Log's save()). A
     * category only starts being tracked here once recurringErrorCategories()
     * says it has actually recurred across 2+ missions — a single one-off
     * mistake never creates a review item. Every further recurrence
     * refreshes the example (grounding the next prompt in the CURRENT
     * wrong/correct pair, not a stale one) and makes it immediately due —
     * this is just "surface fresh practice material", not itself a graded
     * review event, so it deliberately does NOT touch the SM-2 fields
     * (ease_factor/interval_days/repetitions); those only move via
     * ErrorPatternReview::review(), called once the learner actually
     * completes the practice sentence (see Active Recall's
     * saveRecurringPractice()).
     */
    public function syncErrorPatternReview(string $category, string $error, string $correction): void
    {
        if (! $this->recurringErrorCategories()->contains($category)) {
            return;
        }

        ErrorPatternReview::updateOrCreate(
            ['learner_id' => $this->id, 'category' => $category],
            ['last_error' => $error, 'last_correction' => $correction, 'next_review_at' => now()],
        );
    }

    /**
     * Called once per completed Grammar in Context step (see that step's
     * save()) — unlike syncErrorPatternReview(), enrolled unconditionally
     * every time, since a mission only ever teaches its grammar focus
     * once (there's no "has this recurred" signal to gate on the way
     * error categories have). Re-teaching the same focus in a later
     * mission just refreshes the example/reminder and makes it
     * immediately due again, same idea as the error-pattern sync.
     */
    public function syncGrammarPoint(string $focus, string $exampleSentence, string $ruleReminder, string $missionCode, ?int $sourceMissionRunId = null): void
    {
        GrammarPoint::updateOrCreate(
            ['learner_id' => $this->id, 'focus' => $focus],
            [
                'example_sentence' => $exampleSentence,
                'rule_reminder' => $ruleReminder,
                'mission_code' => $missionCode,
                'source_mission_run_id' => $sourceMissionRunId,
                'next_review_at' => now(),
            ],
        );
    }

    /**
     * Every distinct word/phrase this learner has ever picked in
     * Vocabulary Builder, across every mission — reuses
     * MissionRun::selectedVocabularyWords() per run rather than
     * re-decoding the Evidence JSON itself, so there's exactly one place
     * that knows that shape. Feeds Profile's "My Progress" tab — a plain
     * count of words picked, distinct from vocabularyWords() above,
     * which is the actual spaced-repetition tracking table.
     *
     * @return Collection<int, string>
     */
    public function vocabularyWordsSelected(): Collection
    {
        return $this->missionRuns()
            ->get()
            ->flatMap(fn (MissionRun $run) => $run->selectedVocabularyWords())
            ->unique()
            ->values();
    }

    /**
     * A natural-language description of this learner's self-reported CEFR
     * level, meant to be dropped directly into an AI systemPrompt (see
     * SentenceChecker and every step's GeminiClient::chat() call) so
     * grading/conversation difficulty actually calibrates to the learner,
     * instead of a hardcoded "B1" assumption everywhere. Falls back to the
     * app's original B1 baseline for a level this map doesn't recognise
     * (including the column's own default before onboarding sets it).
     */
    public function levelDescription(): string
    {
        return match ($this->cefr_level) {
            'A1' => 'an absolute beginner (A1) English learner who is just starting out',
            'A2' => 'an elementary (A2) English learner who knows basic phrases and simple sentences',
            'A2+' => 'a pre-intermediate (A2+) English learner who can handle familiar topics but still hesitates with anything more complex',
            'B2' => 'a B2 (upper-intermediate) English learner who is fairly fluent already',
            'C1' => 'a C1 (advanced) English learner who is highly fluent',
            default => 'a B1 (intermediate) English learner',
        };
    }

    /**
     * The calendar dates (across every Mission, not just one) on which
     * this learner recorded at least one real Evidence row — the unit the
     * learning streak is built from. Deliberately keyed off real Evidence,
     * the same "did they actually do something" signal the rest of the
     * app already trusts (Article 4, Evidence Before Progress), not just
     * opening the app. No new counter column: computed fresh each time
     * so it can never drift from what Evidence actually says happened.
     *
     * @return Collection<int, Carbon> most recent date first
     */
    public function activeDates(): Collection
    {
        return Evidence::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'evidences.mission_run_id')
            ->where('mission_runs.learner_id', $this->id)
            ->selectRaw('DISTINCT DATE(evidences.created_at) as day')
            ->orderByDesc('day')
            ->pluck('day')
            ->map(fn ($day) => Carbon::parse($day));
    }

    /**
     * The learner's current streak of active days, forgiving a single
     * skipped day (not two or more in a row) so a normal day off doesn't
     * punish them — the app's motivation system stays encouraging rather
     * than guilt-driven, in keeping with Article 12 (Independence): a
     * streak here is a nudge to come back, not a dependency trap. 0 if
     * they've missed 2+ days in a row, including never having started.
     */
    public function currentStreak(): int
    {
        $dates = $this->activeDates();

        if ($dates->isEmpty() || abs($dates->first()->diffInDays(now()->startOfDay())) > 2) {
            return 0;
        }

        return $this->streakChains($dates)[0];
    }

    /**
     * The longest streak of active days this learner has ever had (same
     * single-day-grace rule as currentStreak()), even if that streak has
     * since ended — a lasting record of their best run, not just "now."
     */
    public function longestStreak(): int
    {
        $chains = $this->streakChains($this->activeDates());

        return $chains === [] ? 0 : max($chains);
    }

    /**
     * Splits a descending list of active dates into "chains" — runs of
     * days that stay unbroken because any gap between consecutive active
     * dates is at most 2 (i.e. at most one day skipped in between). A gap
     * of 3+ days ends a chain and starts a new one. Each chain's length is
     * the number of active days it contains (skipped days aren't counted,
     * just forgiven).
     *
     * @param  Collection<int, Carbon>  $dates  descending, as from activeDates()
     * @return list<int> chain lengths, most recent chain first
     */
    private function streakChains(Collection $dates): array
    {
        if ($dates->isEmpty()) {
            return [];
        }

        $chains = [];
        $chainLength = 1;
        $previous = $dates->first();

        foreach ($dates->skip(1) as $date) {
            if (abs($previous->diffInDays($date)) <= 2) {
                $chainLength++;
            } else {
                $chains[] = $chainLength;
                $chainLength = 1;
            }

            $previous = $date;
        }

        $chains[] = $chainLength;

        return $chains;
    }

    /**
     * A fixed grid of the last N calendar weeks (Sunday-start, so it lines
     * up cleanly as columns of 7 in a CSS grid with grid-flow-col), each
     * day flagged active/not from the same real-Evidence signal
     * currentStreak() trusts. Powers Profile's "My progress" heatmap.
     *
     * @return list<array{date: string, label: string, active: bool, future: bool}>
     */
    public function activityCalendar(int $weeks = 12): array
    {
        $active = $this->activeDates()->map(fn (Carbon $date) => $date->toDateString())->flip();
        $start = now()->startOfWeek(Carbon::SUNDAY)->subWeeks($weeks - 1);

        return collect(range(0, $weeks * 7 - 1))
            ->map(function (int $offset) use ($start, $active) {
                $date = $start->copy()->addDays($offset);

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->format('M j, Y'),
                    'active' => $active->has($date->toDateString()),
                    'future' => $date->isFuture(),
                ];
            })
            ->all();
    }

    /**
     * A real single-month calendar grid (Sunday-start, padded with the
     * adjacent months' days so every week row has 7 cells) — the "look
     * back at one specific month" counterpart to activityCalendar()'s
     * 12-week strip. Powers <x-month-calendar> on the Progress page.
     *
     * @return list<array{date: string, day: int, active: bool, future: bool, inMonth: bool, isToday: bool}>
     */
    public function activityForMonth(int $year, int $month): array
    {
        $active = $this->activeDates()->map(fn (Carbon $date) => $date->toDateString())->flip();
        $firstOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $gridStart = $firstOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $days = [];
        $cursor = $gridStart->copy();

        while ($cursor->lte($gridEnd)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'active' => $active->has($cursor->toDateString()),
                'future' => $cursor->isFuture(),
                'inMonth' => $cursor->month === $month,
                'isToday' => $cursor->isToday(),
            ];
            $cursor = $cursor->copy()->addDay();
        }

        return $days;
    }

    /**
     * How many days this calendar week (Sunday-start, same boundary as
     * activityCalendar()) already have real activity — what
     * weekly_goal_days is measured against.
     */
    public function activeDaysThisWeek(): int
    {
        $startOfWeek = now()->startOfWeek(Carbon::SUNDAY);

        return $this->activeDates()->filter(fn (Carbon $date) => $date->gte($startOfWeek))->count();
    }

    /**
     * True right after today's activity was the thing that carried the
     * streak across yesterday's gap — i.e. currentStreak()'s built-in
     * one-day forgiveness (see streakChains()) just actually applied,
     * rather than the learner having simply practiced every day. Lets the
     * UI say so explicitly instead of the forgiveness being an invisible
     * mechanic the learner never finds out about.
     */
    public function justBenefitedFromGrace(): bool
    {
        $dates = $this->activeDates();

        if ($dates->count() < 2 || ! $dates->first()->isToday()) {
            return false;
        }

        return (int) abs($dates->first()->diffInDays($dates->get(1))) === 2;
    }

    /**
     * True the moment a streak has actually broken (2+ days missed, not
     * just the one-day grace) but there's a real best run on record worth
     * pointing back to — the encouraging "let's beat your record" moment
     * instead of a silent reset to zero.
     */
    public function justLostStreak(): bool
    {
        return $this->currentStreak() === 0 && $this->longestStreak() > 0;
    }

    private const STREAK_MILESTONES = [100, 30, 7];

    /**
     * Returns the milestone (100/30/7) the moment currentStreak() first
     * reaches it, marking it celebrated so the same milestone is never
     * returned again — without celebrated_streak_milestone, this would
     * replay on every single page view for as long as the streak stays at
     * or above that milestone, since currentStreak() is computed fresh
     * every time rather than stored. Highest milestone first, so hitting
     * several in one jump (e.g. a backfilled/edited streak) only
     * celebrates the highest one reached.
     */
    public function streakMilestoneJustReached(): ?int
    {
        $streak = $this->currentStreak();

        foreach (self::STREAK_MILESTONES as $milestone) {
            if ($streak >= $milestone && $this->celebrated_streak_milestone < $milestone) {
                $this->update(['celebrated_streak_milestone' => $milestone]);

                return $milestone;
            }
        }

        return null;
    }

    /**
     * The next streak badge (7/30/100) still ahead of the CURRENT streak
     * — null once every tier is earned. Powers <x-milestone-path>'s "X
     * days to go" framing, a nearer-term goal than just showing badges
     * already earned.
     */
    public function nextStreakMilestone(): ?int
    {
        $streak = $this->currentStreak();

        return collect(self::STREAK_MILESTONES)->sort()->first(fn (int $milestone) => $milestone > $streak);
    }

    public function daysUntilNextMilestone(): ?int
    {
        $next = $this->nextStreakMilestone();

        return $next === null ? null : $next - $this->currentStreak();
    }

    /**
     * Grammar/vocabulary error categories (see ErrorLogItem::$category)
     * that this learner has been flagged for in 2 or more DIFFERENT
     * mission runs — the same slip showing up twice within one Error Log
     * batch doesn't count, this is specifically "keeps coming back across
     * lessons". Ordered by how many distinct runs it's shown up in, most
     * first. Naturally empty until a learner has completed 2+ missions
     * (or the AI simply never assigned a category), since there is
     * nothing to compare across yet.
     *
     * @return Collection<int, string>
     */
    public function recurringErrorCategories(): Collection
    {
        return ErrorLogItem::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'error_log_items.mission_run_id')
            ->where('mission_runs.learner_id', $this->id)
            ->whereNotNull('error_log_items.category')
            ->selectRaw('error_log_items.category as category, COUNT(DISTINCT error_log_items.mission_run_id) as run_count')
            ->groupBy('error_log_items.category')
            ->havingRaw('COUNT(DISTINCT error_log_items.mission_run_id) >= 2')
            ->orderByDesc('run_count')
            ->pluck('category');
    }

    /**
     * The single most recent example of this learner's most-recurring
     * error pattern — a concrete sentence/correction pair (not just an
     * abstract category name) to ground a fresh spaced-repetition prompt
     * in a later mission's Active Recall step. Null if nothing recurs yet
     * (see recurringErrorCategories()).
     */
    public function topRecurringError(): ?ErrorLogItem
    {
        $category = $this->recurringErrorCategories()->first();

        if ($category === null) {
            return null;
        }

        return ErrorLogItem::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'error_log_items.mission_run_id')
            ->where('mission_runs.learner_id', $this->id)
            ->where('error_log_items.category', $category)
            ->orderByDesc('error_log_items.created_at')
            ->orderByDesc('error_log_items.id')
            ->select('error_log_items.*')
            ->first();
    }

    /**
     * A lightweight "is this actually improving" signal for the top
     * recurring error category — deliberately NOT a full chart, since
     * most learners simply don't have enough dated data points yet for
     * one to look like anything but noise (or an empty state). Compares
     * how often the category showed up across the learner's 2 MOST
     * RECENT mission runs against its all-time total.
     *
     * @return array{category: string, totalCount: int, recentCount: int}|null
     */
    public function topRecurringErrorTrend(): ?array
    {
        $category = $this->recurringErrorCategories()->first();

        if ($category === null) {
            return null;
        }

        $totalCount = ErrorLogItem::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'error_log_items.mission_run_id')
            ->where('mission_runs.learner_id', $this->id)
            ->where('error_log_items.category', $category)
            ->count();

        $recentRunIds = $this->missionRuns()
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->limit(2)
            ->pluck('id');

        $recentCount = ErrorLogItem::query()
            ->whereIn('mission_run_id', $recentRunIds)
            ->where('category', $category)
            ->count();

        return ['category' => $category, 'totalCount' => $totalCount, 'recentCount' => $recentCount];
    }

    /**
     * Average "after" self-assessment score per skill (0-5) across every
     * completed mission — feeds <x-skill-radar> on the Progress page.
     * Deliberately excludes the "before" scores (this is a snapshot of
     * where the learner stands now, not a before/after comparison).
     *
     * @return array<string, float>
     */
    public function skillAverages(): array
    {
        return SelfAssessment::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'self_assessments.mission_run_id')
            ->where('mission_runs.learner_id', $this->id)
            // Mission Result writes a SelfAssessment row whenever the AI
            // verdict comes back at all — 'needs_review' and
            // 'retry_evidence' included, not just 'complete' — so without
            // this filter a mission that DIDN'T pass still fed the radar,
            // producing a populated "Skills" chart next to a genuine
            // "0 missions completed" stat. This is the one query that
            // actually needs the completed-only filter; missionsCompletedCount()
            // already had it right.
            ->where('mission_runs.status', MissionRun::STATUS_COMPLETE)
            ->whereNotNull('self_assessments.after')
            ->selectRaw('self_assessments.skill as skill, AVG(self_assessments.after) as avg_after')
            ->groupBy('self_assessments.skill')
            ->pluck('avg_after', 'skill')
            ->map(fn ($value) => round((float) $value, 1))
            ->all();
    }

    /**
     * Total real minutes practiced — summed duration_minutes for every
     * step that actually has recorded Evidence, across every mission run
     * this learner has ever started (not just completed ones). A
     * satisfying "time invested" number for the Progress page.
     */
    public function totalPracticeMinutes(): int
    {
        return $this->missionRuns()
            ->get()
            ->sum(function (MissionRun $run) {
                $recordedPhases = $run->evidence()->pluck('phase')->unique();

                return collect($run->mission->stepKeys())
                    ->filter(fn (string $key) => $recordedPhases->contains($key))
                    ->sum(fn (string $key) => $run->mission->stepDuration($key));
            });
    }

    /**
     * New vocabulary words added per week over the last N weeks — feeds a
     * simple growth bar chart on the Progress page. Sunday-start weeks,
     * same boundary as activityCalendar()/activeDaysThisWeek().
     *
     * @return list<array{label: string, count: int}>
     */
    public function vocabularyGrowthByWeek(int $weeks = 8): array
    {
        $start = now()->startOfWeek(Carbon::SUNDAY)->subWeeks($weeks - 1);

        $counted = $this->vocabularyWords()
            ->where('created_at', '>=', $start)
            ->get()
            ->groupBy(fn (VocabularyWord $word) => $word->created_at->startOfWeek(Carbon::SUNDAY)->toDateString());

        return collect(range(0, $weeks - 1))
            ->map(function (int $offset) use ($start, $counted) {
                $weekStart = $start->copy()->addWeeks($offset);

                return [
                    'label' => $weekStart->format('M j'),
                    'count' => $counted->get($weekStart->toDateString(), collect())->count(),
                ];
            })
            ->all();
    }

    /**
     * People this user has an ACCEPTED, confirmed follow of — a fresh
     * follow() request sits as 'pending' (see pendingFollowRequests())
     * until the other side accepts it, so it does NOT show up here yet.
     * Following alone unlocks seeing the other person's high-level
     * activity (see their controller/view — never the raw Evidence
     * content, just streak/missions-completed); it does NOT by itself
     * unlock messaging, see canMessageWith().
     *
     * @return BelongsToMany<User, $this>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followed_id')
            ->wherePivot('status', Follow::STATUS_ACCEPTED)
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followed_id', 'follower_id')
            ->wherePivot('status', Follow::STATUS_ACCEPTED)
            ->withTimestamps();
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->whereKey($user->id)->exists();
    }

    public function isFollowedBy(User $user): bool
    {
        return $user->isFollowing($this);
    }

    /**
     * A follow() already sent to $user that's still awaiting their
     * accept/reject — lets the UI show "Requested" instead of "Follow"
     * without letting a second click queue up a duplicate request.
     */
    public function hasPendingRequestTo(User $user): bool
    {
        return Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $user->id)
            ->where('status', Follow::STATUS_PENDING)
            ->exists();
    }

    /**
     * Incoming requests to follow THIS user, newest first — surfaced on
     * the Friends page with Accept/Reject actions, and counted for the
     * notification badge on the Friends nav icon (see
     * pendingFollowRequestsCount()).
     *
     * @return Collection<int, User>
     */
    public function pendingFollowRequests(): Collection
    {
        return $this->followerRelationRows()
            ->where('status', Follow::STATUS_PENDING)
            ->with('follower')
            ->latest()
            ->get()
            ->pluck('follower');
    }

    public function pendingFollowRequestsCount(): int
    {
        return $this->followerRelationRows()->where('status', Follow::STATUS_PENDING)->count();
    }

    /**
     * @return Builder<Follow>
     */
    private function followerRelationRows(): Builder
    {
        return Follow::query()->where('followed_id', $this->id);
    }

    /**
     * Both directions of follow exist — the one thing that actually gates
     * a conversation (see canMessageWith()), by explicit product decision:
     * a one-way follow (e.g. following a stranger) must not open a DM.
     * In practice this is automatic once a request is accepted (see
     * acceptFollowRequest(), which creates both directions at once) —
     * this stays useful for any older, pre-request one-directional data.
     */
    public function isMutualWith(User $user): bool
    {
        return $this->isFollowing($user) && $this->isFollowedBy($user);
    }

    /**
     * Sends a follow request — sits 'pending' until $user accepts or
     * rejects it (see acceptFollowRequest()/rejectFollowRequest()). A
     * no-op if a request or an accepted follow already exists in this
     * direction, so re-clicking "Follow"/"Requested" never queues a
     * duplicate row.
     */
    public function follow(User $user): void
    {
        if ($user->is($this)) {
            return;
        }

        $exists = Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $user->id)
            ->exists();

        if ($exists) {
            return;
        }

        Follow::create([
            'follower_id' => $this->id,
            'followed_id' => $user->id,
            'status' => Follow::STATUS_PENDING,
        ]);

        $user->notify(new FollowRequestReceived($this));
    }

    /**
     * Removes any follow row this user has toward $user, pending or
     * accepted — doubles as both "cancel my pending request" and "unfollow
     * someone I already follow". $user's own follow of this user (if any)
     * is untouched.
     */
    public function unfollow(User $user): void
    {
        Follow::query()
            ->where('follower_id', $this->id)
            ->where('followed_id', $user->id)
            ->delete();
    }

    /**
     * Accepting makes it mutual right away — both directions become
     * 'accepted' in one step, per product decision: there's no such thing
     * as a one-way *accepted* follow born from a request, only the
     * request itself is ever one-directional. If $follower had somehow
     * also already sent (or been sent) a request in the other direction,
     * that row is normalized to accepted too rather than left stale.
     */
    public function acceptFollowRequest(User $follower): void
    {
        Follow::query()
            ->where('follower_id', $follower->id)
            ->where('followed_id', $this->id)
            ->where('status', Follow::STATUS_PENDING)
            ->update(['status' => Follow::STATUS_ACCEPTED]);

        $reverse = Follow::firstOrNew(['follower_id' => $this->id, 'followed_id' => $follower->id]);
        $reverse->status = Follow::STATUS_ACCEPTED;
        $reverse->save();

        $follower->notify(new FollowRequestAccepted($this));
    }

    /**
     * Declines a pending request — the row is simply removed, leaving
     * $follower free to send another one later if they want to.
     */
    public function rejectFollowRequest(User $follower): void
    {
        Follow::query()
            ->where('follower_id', $follower->id)
            ->where('followed_id', $this->id)
            ->where('status', Follow::STATUS_PENDING)
            ->delete();
    }

    public function hasBlocked(User $user): bool
    {
        return FriendBlock::query()
            ->where('blocker_id', $this->id)
            ->where('blocked_id', $user->id)
            ->exists();
    }

    public function isBlockedBy(User $user): bool
    {
        return $user->hasBlocked($this);
    }

    /**
     * The single gate a conversation thread actually checks: mutual
     * follow, and neither side has blocked the other. A block silently
     * closes the door in both directions — the blocked user is never told
     * why, they just stop being able to reach the other person.
     */
    public function canMessageWith(User $user): bool
    {
        if ($user->is($this)) {
            return false;
        }

        return $this->isMutualWith($user)
            && ! $this->hasBlocked($user)
            && ! $this->isBlockedBy($user);
    }

    /**
     * Friends this user can actually message right now — mutual follow,
     * no block either direction, the same set canMessageWith() would
     * accept for any of them. Used to build a friend-picker (e.g. "practice
     * this mission question with a friend") without checking every
     * candidate one by one.
     *
     * @return Collection<int, User>
     */
    public function mutualFriends(): Collection
    {
        $blockedIds = FriendBlock::where('blocker_id', $this->id)->pluck('blocked_id')
            ->merge(FriendBlock::where('blocked_id', $this->id)->pluck('blocker_id'));

        return $this->following()
            ->whereNotIn('users.id', $blockedIds)
            ->get()
            ->filter(fn (User $candidate) => $this->isFollowedBy($candidate))
            ->values();
    }

    /**
     * How many of this learner's real (mutual) friends have already
     * practiced today — deliberately just a headcount, never a ranked
     * leaderboard: visible companionship without turning the streak into
     * a competition (Article 12, Independence).
     */
    public function mutualFriendsActiveTodayCount(): int
    {
        return $this->mutualFriends()
            ->filter(fn (User $friend) => $friend->activeDates()->first()?->isToday())
            ->count();
    }

    /**
     * Every message between this user and $user, oldest first — there is
     * no separate "conversation" entity, two users can only ever have one
     * thread (see direct_messages migration), so this just queries it
     * directly rather than looking one up.
     *
     * @return Builder<DirectMessage>
     */
    public function conversationWith(User $user)
    {
        return DirectMessage::query()
            ->where(function ($query) use ($user) {
                $query->where('sender_id', $this->id)->where('recipient_id', $user->id);
            })
            ->orWhere(function ($query) use ($user) {
                $query->where('sender_id', $user->id)->where('recipient_id', $this->id);
            })
            ->orderBy('created_at');
    }
}
