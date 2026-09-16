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
     * The whole M01-M24 roadmap, keyed by code: planned title, Pexels
     * image_query, and — since 2026-09-16 — the grammar point that
     * mission teaches, with the reason that topic was the right home for
     * it.
     *
     * The grammar column is the curriculum's spine. It exists because the
     * roadmap is topic-driven, so without a map the path could reach M24
     * having never taught the present perfect or a conditional, and the
     * goal is a real, produceable B1 base
     * ([[project_goal_real_b1_not_ielts_prep]]). Two rules shaped it:
     *
     *  - The topic picks the grammar, never the other way round. Each
     *    pairing passes one test — the mission's Final Challenge question
     *    can be written so that answering it honestly REQUIRES that
     *    grammar. 'why' records that argument; if a pairing ever stops
     *    passing it, change the pairing, don't bolt the grammar on.
     *  - Forms build in order, and no point repeats
     *    ([[feedback_content_authoring_conventions]] rule 7). Where the
     *    roadmap revisits a topic (People → Personality → Relationships),
     *    it revisits it at a higher form: present tenses → relative
     *    clauses → present perfect.
     *
     * A mission's own source PDF is NOT authoritative about grammar — the
     * user settled this explicitly; M05 onward the PDFs carry no grammar
     * at all, and M03 already had its PDF's point replaced.
     *
     * Also the single source of truth the missions overview reads for
     * unbuilt slots (⚡overview.blade.php's roadmapPlaceholder()) and
     * MissionSeeder's Pexels cache warmer
     * ([[feedback_never_fetch_pexels_live_on_site]]) — so the plan and
     * what ships can't drift apart. A slot whose mission IS seeded never
     * consults this for display, so a title here may not match what the
     * mission shipped as (M02 shipped as "People I Know").
     *
     * @return array<string, array{title: string, image_query: string, grammar: string, why: string}>
     */
    public static function roadmapCatalog(): array
    {
        return [
            'M01' => [
                'title' => 'My Daily Life', 'image_query' => 'morning routine sunrise coffee',
                'grammar' => 'Present Simple + Adverbs of Frequency',
                'why' => "A routine is the one thing you cannot describe without it: 'I usually get up at seven.'",
            ],
            'M02' => [
                'title' => 'People & Relationships', 'image_query' => 'two friends laughing coffee shop',
                'grammar' => 'Present Simple vs Present Continuous',
                'why' => 'Describing people you know means separating what someone is generally like from what they are doing these days.',
            ],
            'M03' => [
                'title' => 'Work & Study', 'image_query' => 'people working office study',
                'grammar' => 'Modals of Obligation & Ability',
                'why' => "A job is duties and permissions out loud: 'I have to finish it by Friday', 'I can work from home'.",
            ],
            'M04' => [
                'title' => 'Food & Lifestyle', 'image_query' => 'healthy meal fresh vegetables table',
                'grammar' => 'Countable & Uncountable Nouns + Quantifiers',
                'why' => "You cannot talk about what you eat without 'some rice', 'a few eggs', 'not much sugar'.",
            ],
            'M05' => [
                'title' => 'Hobbies & Free Time', 'image_query' => 'hobby painting guitar leisure',
                'grammar' => 'Gerunds vs Infinitives',
                'why' => "Free time is verb patterns: 'I enjoy playing', 'I want to learn', \"I'm good at cooking\".",
            ],
            'M06' => [
                'title' => 'Learning English', 'image_query' => 'open notebook studying language',
                'grammar' => 'Past Simple',
                'why' => "How you started learning English is a story that happened and finished: 'I started at school, we used a red book.'",
            ],
            'M07' => [
                'title' => 'Family', 'image_query' => 'family together home smiling',
                'grammar' => "'used to' + Past Continuous",
                'why' => "Childhood and family life: 'we used to live near the sea', 'while my mother was cooking…'.",
            ],
            'M08' => [
                'title' => 'Friends', 'image_query' => 'friends group laughing outdoors',
                'grammar' => 'Comparatives & Superlatives',
                'why' => "Friends only get described by comparison: 'my oldest friend', 'he's more patient than me'.",
            ],
            'M09' => [
                'title' => 'Personality', 'image_query' => 'thoughtful portrait person',
                'grammar' => 'Defining Relative Clauses',
                'why' => "A personality is a 'someone who…': 'a person who always listens', 'the kind of friend that never judges'.",
            ],
            'M10' => [
                'title' => 'Relationships', 'image_query' => 'couple holding hands walking',
                'grammar' => 'Present Perfect with for / since',
                'why' => "A relationship is measured in duration: \"we've known each other since school\", \"I've never argued with him\".",
            ],
            'M11' => [
                'title' => 'Work', 'image_query' => 'office desk laptop work',
                'grammar' => 'Future: will / going to / present continuous',
                'why' => "Career talk is plans, intentions and arrangements: \"I'm going to apply\", \"I'm meeting my manager on Monday\".",
            ],
            'M12' => [
                'title' => 'Education', 'image_query' => 'university classroom students',
                'grammar' => 'Reported Speech (basic)',
                'why' => "School is what other people said: 'my teacher told me that…', 'they said I had to repeat it.'",
            ],
            'M13' => [
                'title' => 'Technology', 'image_query' => 'laptop smartphone technology desk',
                'grammar' => 'Present Perfect vs Past Simple',
                'why' => "Technology forces the contrast: 'phones have changed a lot' versus 'I bought mine last year.'",
            ],
            'M14' => [
                'title' => 'Money', 'image_query' => 'money coins wallet savings',
                'grammar' => 'First Conditional',
                'why' => "Money is consequences: \"if I save this month, I'll buy it\", \"unless prices drop, I won't.\"",
            ],
            'M15' => [
                'title' => 'Shopping', 'image_query' => 'shopping bags store mall',
                'grammar' => 'The Passive (present & past)',
                'why' => "Products are talked about without an actor: \"it's made in Turkey\", 'it was delivered yesterday.'",
            ],
            'M16' => [
                'title' => 'Travel', 'image_query' => 'airplane travel suitcase passport',
                'grammar' => 'Past Perfect (narrative past)',
                'why' => "A travel story needs an earlier past: 'by the time we arrived, the bus had already left.'",
            ],
            'M17' => [
                'title' => 'Culture', 'image_query' => 'museum art culture',
                'grammar' => "Modals of Deduction (must / might / can't)",
                'why' => "Comparing cultures is careful guessing: \"that must be a local custom\", \"it can't be easy for visitors.\"",
            ],
            'M18' => [
                'title' => 'Environment', 'image_query' => 'nature forest green environment',
                'grammar' => 'Second Conditional',
                'why' => "The environment is the unreal-but-possible: 'if everyone recycled, we would waste less.'",
            ],
            'M19' => [
                'title' => 'Media', 'image_query' => 'newspaper television media',
                'grammar' => 'Non-Defining Relative Clauses',
                'why' => "Media talk adds asides: 'Instagram, which I check every morning, takes an hour of my day.'",
            ],
            'M20' => [
                'title' => 'Opinions', 'image_query' => 'people discussion table talking',
                'grammar' => 'Advice Modals (should / ought to / had better)',
                'why' => "An opinion about what someone else ought to do: 'I think you should…', \"you'd better not…\".",
            ],
            'M21' => [
                'title' => 'Problems & Solutions', 'image_query' => 'lightbulb idea solution',
                'grammar' => 'Purpose & Cause (so that / in order to / because of)',
                'why' => "A solution is explained by its purpose and its cause: 'I wrote it down so that I wouldn't forget.'",
            ],
            'M22' => [
                'title' => 'Decision Making', 'image_query' => 'crossroads decision choice path',
                'grammar' => 'Preference (would rather / prefer / had better)',
                'why' => "Deciding is preferring out loud: \"I'd rather stay than go\", 'I prefer working alone.'",
            ],
            'M23' => [
                'title' => 'Future Plans', 'image_query' => 'calendar planning goals notebook',
                'grammar' => 'Future Continuous + hopes and plans',
                'why' => "A plan set in a future moment: \"this time next year I'll be living abroad\", 'I hope to finish by June.'",
            ],
            'M24' => [
                'title' => 'Debate & Discussion', 'image_query' => 'group discussion meeting table',
                'grammar' => 'Contrast & Concession (although / however / despite)',
                'why' => "A debate is two sides in one sentence: 'although it costs more, it lasts longer.'",
            ],
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

    /** Scaffolding levels, in the order a learner meets them. */
    public const SCAFFOLD_FULL = 'full';

    public const SCAFFOLD_REDUCED = 'reduced';

    public const SCAFFOLD_MINIMAL = 'minimal';

    /**
     * This mission's position in the roadmap ("M03" → 3), or 1 for
     * anything that isn't a roadmap code.
     */
    public function number(): int
    {
        return preg_match('/^M(\d+)$/', $this->code, $m) ? (int) $m[1] : 1;
    }

    /**
     * How much help this mission hands the learner up front. Three even
     * thirds of the roadmap: M01-M08 full, M09-M16 reduced, M17-M24
     * minimal.
     *
     * The design rule this implements is that real difficulty rises while
     * FELT difficulty stays flat
     * ([[project_growth_without_discouragement_stories]] S4). Nobody
     * notices five sentence starters instead of six; everybody braces at
     * "Level 2: harder mode". So this must never be announced, and no UI
     * string anywhere may refer to it — there is a test asserting the
     * level names never reach a rendered page.
     *
     * The hard constraint: this may only ever remove SCAFFOLDING, never
     * change what a step requires to be complete. Grammar in Context still
     * wants 3 sentences at M24 exactly as it did at M01; it just offers
     * four starters to choose from instead of six.
     */
    public function scaffoldLevel(): string
    {
        return match (true) {
            $this->number() <= 8 => self::SCAFFOLD_FULL,
            $this->number() <= 16 => self::SCAFFOLD_REDUCED,
            default => self::SCAFFOLD_MINIMAL,
        };
    }

    /**
     * Trims a list of optional supports (sentence starters, prompts) to
     * this mission's scaffold level — one fewer at reduced, two fewer at
     * minimal — while never going below $keepAtLeast, which callers pass
     * as whatever their step actually REQUIRES. That floor is the whole
     * safety mechanism: it is what makes it impossible for this taper to
     * turn into a raised bar.
     *
     * Keys are preserved (callers index saved answers by position).
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    public function taperScaffolding(array $items, int $keepAtLeast): array
    {
        $drop = match ($this->scaffoldLevel()) {
            self::SCAFFOLD_REDUCED => 1,
            self::SCAFFOLD_MINIMAL => 2,
            default => 0,
        };

        $keep = max($keepAtLeast, count($items) - $drop);

        return array_slice($items, 0, $keep, preserve_keys: true);
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
