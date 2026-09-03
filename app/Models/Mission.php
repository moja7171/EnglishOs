<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'title', 'module', 'outcome', 'phases'])]
class Mission extends Model
{
    use HasFactory;

    /**
     * The full curriculum roadmap size (EOS-009 §15, v3.0) — M01-M24.
     * Only some are seeded/playable yet; this is the total the roadmap
     * commits to, used for "Mission N of 24" course-level progress
     * (see User::currentMissionNumber()) and the missions overview's own
     * placeholder slots.
     */
    public const TOTAL_ROADMAP_MISSIONS = 24;

    protected function casts(): array
    {
        return [
            'phases' => 'array',
        ];
    }

    /**
     * @return HasMany<MissionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(MissionRun::class);
    }

    /**
     * The seeded mission immediately before this one in the curriculum
     * sequence ("M03" → "M02"), or null for the first mission or when the
     * previous slot hasn't been seeded yet. Codes are zero-padded "M##"
     * (EOS-009 §15) — see MissionRun::gatingMission() for how this is used
     * to enforce Evidence Before Progress across missions.
     */
    public function previousMission(): ?self
    {
        if (! preg_match('/^M(\d+)$/', $this->code, $matches)) {
            return null;
        }

        $number = (int) $matches[1];

        if ($number <= 1) {
            return null;
        }

        return static::where('code', sprintf('M%02d', $number - 1))->first();
    }

    /**
     * Flattens phases[].steps[] into an ordered list of step keys, in the
     * order a learner must complete them (EOS-009 §7).
     *
     * @return list<string>
     */
    public function stepKeys(): array
    {
        return collect($this->phases ?? [])
            ->flatMap(fn (array $phase) => collect($phase['steps'] ?? [])
                ->map(fn ($step) => is_array($step) ? $step['key'] : $step))
            ->values()
            ->all();
    }

    /**
     * The phase definition (label, mode, steps...) that contains the given
     * step key, or null if the key isn't part of this mission.
     */
    public function phaseFor(string $stepKey): ?array
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if ((is_array($step) ? $step['key'] : $step) === $stepKey) {
                    return $phase;
                }
            }
        }

        return null;
    }

    /**
     * A human-readable label for a step key — its authored 'label' if the
     * seeder gave it one, otherwise the key title-cased as a fallback.
     */
    public function stepLabel(string $stepKey): string
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (is_array($step) && ($step['key'] ?? null) === $stepKey) {
                    return $step['label'] ?? $stepKey;
                }
            }
        }

        return str($stepKey)->replace('_', ' ')->title()->toString();
    }

    /**
     * The full authored step definition for a step key (questions,
     * vocabulary, quick-check items, etc.), or an empty array for a
     * plain-string step that has no content of its own yet.
     */
    public function stepContent(string $stepKey): array
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (is_array($step) && ($step['key'] ?? null) === $stepKey) {
                    return $step;
                }
            }
        }

        return [];
    }

    /**
     * A step's questions/prompts, flattened into one ordered list of plain
     * strings — regardless of which conversation-shaped step authored them
     * (AI Conversation #1's flat `interview_questions`, or AI Conversation
     * #2's `rounds` + one closing `final_prompt`). Lets a Partner Session
     * (see PartnerSession) work the same way for any conversation step,
     * present or future, without caring which shape it was authored in.
     * Empty for a step with no such content.
     *
     * @return list<string>
     */
    public function conversationPrompts(string $stepKey): array
    {
        $content = $this->stepContent($stepKey);

        return match (true) {
            isset($content['interview_questions']) => $content['interview_questions'],
            isset($content['rounds']) => [
                ...$content['rounds'],
                ...(isset($content['final_prompt']) ? [$content['final_prompt']] : []),
            ],
            default => [],
        };
    }

    /**
     * The visual "mood" this mission renders with — the one thing that
     * varies per mission in the app's hybrid design system (shared
     * typography/layout/components everywhere, only the accent hue shifts
     * to match each mission's own subject). Drives the `data-mood`
     * attribute consumed by the mood tokens in resources/css/app.css. New
     * missions default to the app's base identity (M01's "daily-life"
     * coral) until they earn their own entry here — see EOS-009 §8.
     */
    public function moodKey(): string
    {
        return match ($this->code) {
            'M02' => 'connection',
            default => 'daily-life',
        };
    }

    /**
     * A rough authored time estimate for one step, in minutes — lets the
     * learner see how much a day/mission actually costs before starting,
     * instead of an opaque step count. Authored per-step in the seeder
     * (judgment call per step's real content), not derived automatically.
     * 0 for a step with no estimate yet.
     */
    public function stepDuration(string $stepKey): int
    {
        return (int) ($this->stepContent($stepKey)['duration_minutes'] ?? 0);
    }

    /**
     * The whole mission's estimated time, in minutes — the sum of every
     * step's stepDuration().
     */
    public function totalDurationMinutes(): int
    {
        return collect($this->stepKeys())->sum(fn ($key) => $this->stepDuration($key));
    }

    /**
     * Formats a minute count the way a learner would say it out loud —
     * "8 min" under an hour, "1h 50m" (or just "2h" on the nose) once it
     * crosses 60. Shared by every place that shows a duration so they all
     * read the same way.
     */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} min";
        }

        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return $remainder === 0 ? "{$hours}h" : "{$hours}h {$remainder}m";
    }
}
