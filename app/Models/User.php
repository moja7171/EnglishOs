<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

#[Fillable(['name', 'email', 'password', 'cefr_level', 'target_band', 'avatar_color', 'avatar_path', 'discoverable'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
     * People this user follows — instant, no approval needed. Following
     * alone unlocks seeing the other person's high-level activity (see
     * their controller/view — never the raw Evidence content, just
     * streak/missions-completed); it does NOT by itself unlock messaging,
     * see canMessageWith().
     *
     * @return BelongsToMany<User, $this>
     */
    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'followed_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'followed_id', 'follower_id')
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
     * Both directions of follow exist — the one thing that actually gates
     * a conversation (see canMessageWith()), by explicit product decision:
     * a one-way follow (e.g. following a stranger) must not open a DM.
     */
    public function isMutualWith(User $user): bool
    {
        return $this->isFollowing($user) && $this->isFollowedBy($user);
    }

    public function follow(User $user): void
    {
        if ($user->is($this) || $this->isFollowing($user)) {
            return;
        }

        $this->following()->attach($user->id);
    }

    public function unfollow(User $user): void
    {
        $this->following()->detach($user->id);
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
