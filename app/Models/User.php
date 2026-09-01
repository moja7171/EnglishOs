<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'cefr_level', 'target_band'])]
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
        ];
    }

    /**
     * @return HasMany<MissionRun, $this>
     */
    public function missionRuns(): HasMany
    {
        return $this->hasMany(MissionRun::class, 'learner_id');
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
}
