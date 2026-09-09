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

    /**
     * The whole M01-M24 roadmap's planned title + Pexels image_query,
     * keyed by code — single source of truth shared by the missions
     * overview (renders a themed placeholder for a slot not yet seeded,
     * see ⚡overview.blade.php's roadmapPlaceholder()) and
     * MissionSeeder's Pexels cache warmer (see [[feedback_never_fetch_pexels_live_on_site]]),
     * so the two can never drift apart. A slot whose mission IS already
     * seeded never consults this for display (its own real title/cover
     * renders instead) — this is just the forward-looking plan, so a
     * title here intentionally may not match what a mission ends up
     * actually seeded as (M02 shipped as "People I Know", not "People &
     * Relationships").
     *
     * @return array<string, array{title: string, image_query: string}>
     */
    public static function roadmapCatalog(): array
    {
        return [
            'M01' => ['title' => 'My Daily Life', 'image_query' => 'morning routine sunrise coffee'],
            'M02' => ['title' => 'People & Relationships', 'image_query' => 'two friends laughing coffee shop'],
            'M03' => ['title' => 'Work & Study', 'image_query' => 'people working office study'],
            'M04' => ['title' => 'Food & Lifestyle', 'image_query' => 'healthy meal fresh vegetables table'],
            'M05' => ['title' => 'Hobbies & Free Time', 'image_query' => 'hobby painting guitar leisure'],
            'M06' => ['title' => 'Learning English', 'image_query' => 'open notebook studying language'],
            'M07' => ['title' => 'Family', 'image_query' => 'family together home smiling'],
            'M08' => ['title' => 'Friends', 'image_query' => 'friends group laughing outdoors'],
            'M09' => ['title' => 'Personality', 'image_query' => 'thoughtful portrait person'],
            'M10' => ['title' => 'Relationships', 'image_query' => 'couple holding hands walking'],
            'M11' => ['title' => 'Work', 'image_query' => 'office desk laptop work'],
            'M12' => ['title' => 'Education', 'image_query' => 'university classroom students'],
            'M13' => ['title' => 'Technology', 'image_query' => 'laptop smartphone technology desk'],
            'M14' => ['title' => 'Money', 'image_query' => 'money coins wallet savings'],
            'M15' => ['title' => 'Shopping', 'image_query' => 'shopping bags store mall'],
            'M16' => ['title' => 'Travel', 'image_query' => 'airplane travel suitcase passport'],
            'M17' => ['title' => 'Culture', 'image_query' => 'museum art culture'],
            'M18' => ['title' => 'Environment', 'image_query' => 'nature forest green environment'],
            'M19' => ['title' => 'Media', 'image_query' => 'newspaper television media'],
            'M20' => ['title' => 'Opinions', 'image_query' => 'people discussion table talking'],
            'M21' => ['title' => 'Problems & Solutions', 'image_query' => 'lightbulb idea solution'],
            'M22' => ['title' => 'Decision Making', 'image_query' => 'crossroads decision choice path'],
            'M23' => ['title' => 'Future Plans', 'image_query' => 'calendar planning goals notebook'],
            'M24' => ['title' => 'Debate & Discussion', 'image_query' => 'group discussion meeting table'],
        ];
    }

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
            // Partner Speaking Session's shape: 3 labeled groups of
            // questions (e.g. "Your Friends" / "Personality" / "Deeper"),
            // flattened into one ordered list the same way the other two
            // shapes are — a Partner Session (see PartnerSession) just
            // works through them in order, unaware they were grouped.
            isset($content['round_groups']) => collect($content['round_groups'])->flatMap(fn ($g) => $g['questions'])->all(),
            default => [],
        };
    }

    /**
     * The visual "mood" this mission renders with — the one thing that
     * varies per mission in the app's hybrid design system (shared
     * typography/layout/components everywhere, only the accent hue shifts
     * to match each mission's own subject). Drives the `data-mood`
     * attribute consumed by the mood tokens in resources/css/app.css. Every
     * built mission needs its own entry here (never left on the fallback
     * once real content exists) — see EOS-009 §8. New missions fall back to
     * the app's base identity (M01's "daily-life" coral) only until they're
     * actually built.
     */
    public function moodKey(): string
    {
        return match ($this->code) {
            'M02' => 'connection',
            'M03' => 'focus',
            'M04' => 'nourish',
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
