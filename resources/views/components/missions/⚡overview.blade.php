<?php

use App\Models\Mission;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** The consolidation day's "one sentence you said to Pi" — see logConsolidationSpeaking(). */
    public string $piSentence = '';

    /**
     * The 120-day program's answer to "what do I do today" — the top of
     * this page. See App\Services\ProgramPlanner for the whole model
     * (24 missions × 5 days, progress-based, never calendar-enforced).
     */
    #[Computed]
    public function program(): array
    {
        return app(ProgramPlanner::class)->plan(auth()->user());
    }

    /**
     * Closes the consolidation day: one real sentence from the learner's
     * conversation with Pi becomes Evidence on that mission's run (so it
     * counts as an active streak day like anything else), and tomorrow's
     * "today" becomes the next mission's day 1.
     */
    public function logConsolidationSpeaking(): void
    {
        $this->validate(['piSentence' => 'required|string|min:8|max:500']);

        $today = $this->program['today'];

        if ($today['kind'] !== 'consolidation' || ! $today['run']) {
            return;
        }

        app(ProgramPlanner::class)->logConsolidationSpeaking($today['run'], trim($this->piSentence));

        $this->piSentence = '';
        unset($this->program);
    }

    /**
     * Words, speaking prompts, recurring grammar-mistake patterns, and
     * taught grammar points combined — one nudge into Daily Review
     * instead of a separate card per system (see review/⚡index.blade.php).
     * The dedicated pages (My Words, Speaking Recall) stay reachable from
     * the nav for anyone who wants to focus on just one.
     */
    #[Computed]
    public function dueReviewCount(): int
    {
        return auth()->user()->vocabularyWords()->where('next_review_at', '<=', now())->count()
            + auth()->user()->speakingPrompts()->where('next_review_at', '<=', now())->count()
            + auth()->user()->errorPatternReviews()->where('next_review_at', '<=', now())->count()
            + auth()->user()->grammarPoints()->where('next_review_at', '<=', now())->count();
    }

    /**
     * A dense glance-able strip atop the home page linking to the full
     * /progress page — that page's own progressStats()/averageMemoryFreshness()
     * data existed before but was buried in Profile Settings (tab 3 of 5),
     * nowhere a learner would check daily. Only the 3 numbers worth a
     * glance live here; everything else stays on the full page.
     *
     * @return array{streak: int, missionsCompleted: int, freshness: ?int}
     */
    #[Computed]
    public function progressSummary(): array
    {
        $user = auth()->user();

        return [
            'streak' => $user->currentStreak(),
            'missionsCompleted' => $user->missionsCompletedCount(),
            'freshness' => $user->averageMemoryFreshness(),
        ];
    }

    /**
     * "Mission N of 24" — a course-level counter, deliberately separate
     * from any mission's own Day 1-4 numbering (see
     * User::currentMissionNumber()'s own docblock for why those two
     * never get merged into one continuous count).
     *
     * @return array{current: int, total: int}
     */
    #[Computed]
    public function courseProgress(): array
    {
        return [
            'current' => auth()->user()->currentMissionNumber(),
            'total' => Mission::TOTAL_ROADMAP_MISSIONS,
        ];
    }

    #[Computed]
    public function justBenefitedFromGrace(): bool
    {
        return auth()->user()->justBenefitedFromGrace();
    }

    /**
     * True when there's a real streak worth protecting AND today hasn't
     * been logged yet — the one moment a reminder is genuinely actionable
     * (not shown once currentStreak() is already 0, since there's nothing
     * left to protect; see justLostStreak() for that case instead).
     */
    #[Computed]
    public function needsTodayReminder(): bool
    {
        $user = auth()->user();

        return $user->currentStreak() > 0 && ! ($user->activeDates()->first()?->isToday() ?? false);
    }

    #[Computed]
    public function justLostStreak(): bool
    {
        return auth()->user()->justLostStreak();
    }

    /**
     * The full curriculum is 24 missions (EOS-009 §15 roadmap, v3.0); only
     * the ones actually seeded so far are playable. Every slot 1-24 is
     * shown so the whole path is visible from day one — seeded missions as
     * real clickable cards, the rest as locked placeholders — rather than
     * the list just trailing off after whatever happens to exist yet.
     *
     * Evidence Before Progress (EOS-001 Article 3) also gates mission-to-
     * mission: a seeded mission whose predecessor's MissionRun isn't at
     * least 'complete' or 'needs_review' — never started, still
     * 'in_progress', or 'retry_evidence' — renders as gated instead of
     * clickable. A learner who already has ANY run of their own for that
     * mission is exempt, so this never retroactively locks progress made
     * before the gate existed (or while an admin's Evidence-gating bypass
     * was on — see User::bypassesEvidenceGating() — entirely below).
     *
     * @return list<array{code: string, mission: ?Mission, blockedBy: ?Mission}>
     */
    /**
     * The mission's cover image, reused from Mission Brief's own cache
     * (see ⚡mission-brief.blade.php's heroImageUrl()) — this reads the
     * exact same cache key so it never triggers a fresh Pexels fetch,
     * it's a zero-cost reuse of whatever's already cached (or null on a
     * cache miss, which simply renders no thumbnail).
     */
    public function missionCoverUrl(Mission $mission): ?string
    {
        return app(PexelsClient::class)->cachedImageUrl($mission->code.'-brief');
    }

    #[Computed]
    public function missionSlots(): array
    {
        $seeded = Mission::orderBy('code')->get()->keyBy('code');
        $learner = auth()->user();

        return collect(range(1, Mission::TOTAL_ROADMAP_MISSIONS))
            ->map(fn ($n) => sprintf('M%02d', $n))
            ->map(fn ($code) => ['code' => $code, 'mission' => $seeded->get($code)])
            ->map(fn ($slot) => $slot + [
                'blockedBy' => $slot['mission'] ? MissionRun::gatingMission($learner, $slot['mission']) : null,
            ])
            ->all();
    }

    /**
     * The full 24-mission roadmap's real title + a thematic Pexels query,
     * for the "coming soon" placeholder cards below — the user's own
     * planning list (EOS-009 §15), not yet built means not yet a real
     * Mission row — see Mission::roadmapCatalog(), the single source of
     * truth this and MissionSeeder's Pexels cache warmer both read from.
     *
     * @return array<string, array{title: string, image_query: string}>
     */
    private function roadmap(): array
    {
        return Mission::roadmapCatalog();
    }

    /**
     * Fetched once per code, cached forever (same PexelsClient
     * fetch-once-cache-forever pattern as every other image_query in the
     * app) — a "-roadmap" suffix on the cache key keeps this permanently
     * distinct from that same mission's real "-brief" cover once it's
     * actually built, so seeding it later can never collide with or be
     * shadowed by this placeholder's cached file.
     */
    public function roadmapPlaceholder(string $code): ?array
    {
        $entry = $this->roadmap()[$code] ?? null;

        if (! $entry) {
            return null;
        }

        return $entry + [
            'image_url' => app(PexelsClient::class)->imageUrlFor("{$code}-roadmap", $entry['image_query']),
        ];
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    @php $program = $this->program; $today = $program['today']; @endphp

    <header class="border-b border-line pb-4 dark:border-line-dark">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Missions</h1>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Your 120-day program · <a href="{{ route('program.guide') }}" wire:navigate class="underline hover:text-ink dark:hover:text-ink-dark">how it works</a></p>
            </div>
            @if ($program['started'] && $today['kind'] !== 'finished')
                @php $delta = $program['daysDelta']; @endphp
                <span @class([
                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold',
                    'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $delta >= 0,
                    'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300' => $delta < 0 && $delta >= -7,
                    'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' => $delta < -7,
                ])>
                    @if ($delta > 0) @svg('heroicon-s-bolt', 'h-3 w-3') {{ $delta }} {{ Str::plural('day', $delta) }} ahead
                    @elseif ($delta === 0) @svg('heroicon-s-check', 'h-3 w-3') On track
                    @else @svg('heroicon-o-clock', 'h-3 w-3') {{ abs($delta) }} {{ Str::plural('day', abs($delta)) }} behind
                    @endif
                </span>
            @endif
        </div>
        <div class="mt-3">
            <div class="flex items-center justify-between text-xs">
                <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">Day {{ $program['programDay'] }} of {{ $program['totalDays'] }} · Mission {{ $this->courseProgress['current'] }} of {{ $this->courseProgress['total'] }}</span>
                <span class="text-ink-faint dark:text-ink-faint-dark">{{ round($program['programDay'] / $program['totalDays'] * 100) }}%</span>
            </div>
            <div class="mt-1">
                <x-progress-bar>
                    <div
                        class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                        style="width: {{ $program['programDay'] / $program['totalDays'] * 100 }}%"
                    ></div>
                </x-progress-bar>
            </div>
        </div>
    </header>

    {{-- Today — the one thing this page must answer (see ProgramPlanner). --}}
    <section class="rounded-2xl border-2 border-accent/40 bg-surface p-4 dark:border-accent-dark/40 dark:bg-surface-dark">
        @if ($today['kind'] === 'mission_day')
            <div class="flex items-center justify-between gap-3">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today · Day {{ $today['dayNumber'] }} of {{ $today['mission']->title }}</p>
                <span class="text-xs text-ink-faint dark:text-ink-faint-dark">~{{ \App\Models\Mission::formatDuration($today['estimatedMinutes']) }}</span>
            </div>
            @if ($today['dayLabel'])
                <p class="mt-0.5 text-sm font-semibold text-ink dark:text-ink-dark">{{ $today['dayLabel'] }}</p>
            @endif
            <ul class="mt-3 space-y-1.5">
                @foreach ($today['steps'] as $step)
                    <li>
                        <a
                            href="{{ route('missions.show', [$today['mission'], $step['key']]) }}"
                            wire:navigate
                            @class([
                                'flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark',
                                'text-ink-faint line-through dark:text-ink-faint-dark' => $step['done'],
                                'font-semibold text-ink dark:text-ink-dark' => $step['current'],
                                'text-ink-soft dark:text-ink-soft-dark' => ! $step['done'] && ! $step['current'],
                            ])
                        >
                            @if ($step['done'])
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-3 w-3')</span>
                            @elseif ($step['current'])
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-accent text-white dark:bg-accent-dark">@svg('heroicon-s-play', 'h-2.5 w-2.5')</span>
                            @else
                                <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line dark:border-line-dark"></span>
                            @endif
                            <span class="flex-1">{{ $step['label'] }}</span>
                            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $step['minutes'] }} min</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @php $currentStep = collect($today['steps'])->firstWhere('current', true); @endphp
            <a
                href="{{ route('missions.show', [$today['mission'], $currentStep['key'] ?? null]) }}"
                wire:navigate
                class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >{{ $currentStep ? 'Continue' : 'Open mission' }} @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>

        @elseif ($today['kind'] === 'consolidation')
            @php $speaking = $today['speaking']; @endphp
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today · Consolidation day (~25 min)</p>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $today['mission']->title }} is done. Before the next mission, make it stick.</p>

            <ol class="mt-3 space-y-3">
                <li class="flex gap-3">
                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">1</span>
                    <div class="flex-1 text-sm">
                        <a href="{{ route('review.index') }}" wire:navigate class="font-semibold text-ink underline decoration-line underline-offset-2 hover:decoration-ink dark:text-ink-dark">Daily Review</a>
                        <span class="text-ink-faint dark:text-ink-faint-dark">— {{ $this->dueReviewCount }} {{ Str::plural('item', $this->dueReviewCount) }} due · ~10 min</span>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">2</span>
                    <div
                        class="flex-1 text-sm"
                        x-data="{ copied: false, copy() { navigator.clipboard?.writeText($refs.prompt.value).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000) }) } }"
                    >
                        <p class="font-semibold text-ink dark:text-ink-dark">Speak with Pi for 10-15 minutes <span class="font-normal text-ink-faint dark:text-ink-faint-dark">— out loud, about "{{ $speaking['title'] }}"</span></p>
                        <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">Open <a href="https://pi.ai" target="_blank" rel="noopener" class="underline">pi.ai</a> (or any voice assistant), paste this to start, then just talk:</p>
                        <textarea x-ref="prompt" readonly rows="4" class="mt-1.5 w-full rounded-xl border border-line bg-surface-sunken p-2.5 font-mono text-[11px] leading-relaxed text-ink-soft dark:border-line-dark dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $speaking['prompt'] }}</textarea>
                        <button type="button" x-on:click="copy()" class="mt-1 inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                            <span x-show="!copied">@svg('heroicon-o-clipboard', 'h-3.5 w-3.5') Copy prompt</span>
                            <span x-show="copied" x-cloak>@svg('heroicon-s-check', 'h-3.5 w-3.5') Copied</span>
                        </button>

                        <form wire:submit="logConsolidationSpeaking" class="mt-3">
                            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">When you're done: one sentence you said to Pi</label>
                            <input
                                type="text"
                                wire:model="piSentence"
                                placeholder="e.g. I have to finish a report every Friday."
                                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1.5 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                            >
                            @error('piSentence')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="logConsolidationSpeaking"
                                class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
                            >
                                <span wire:loading.remove wire:target="logConsolidationSpeaking">Log it & finish today</span>
                                <span wire:loading wire:target="logConsolidationSpeaking">Saving…</span>
                            </button>
                        </form>
                    </div>
                </li>
                <li class="flex gap-3">
                    <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-surface-sunken text-xs font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">3</span>
                    <div class="flex-1 text-sm">
                        <a href="{{ route('missions.show', [$today['mission'], 'error_log']) }}" wire:navigate class="font-semibold text-ink underline decoration-line underline-offset-2 hover:decoration-ink dark:text-ink-dark">Re-read your Error Log</a>
                        <span class="text-ink-faint dark:text-ink-faint-dark">— 2 min, just a glance</span>
                    </div>
                </li>
            </ol>

            @if ($today['nextMission'])
                <p class="mt-4 text-xs text-ink-faint dark:text-ink-faint-dark">
                    In a hurry? <a href="{{ route('missions.show', $today['nextMission']) }}" wire:navigate class="underline hover:text-ink dark:hover:text-ink-dark">Start {{ $today['nextMission']->code }}: {{ $today['nextMission']->title }}</a> — the consolidation day is a suggestion, not a lock.
                </p>
            @endif

        @elseif ($today['kind'] === 'start_next')
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Today · Start a new mission</p>
            @if ($today['nextMission'])
                <p class="mt-0.5 text-sm font-semibold text-ink dark:text-ink-dark">{{ $today['nextMission']->code }}: {{ $today['nextMission']->title }}</p>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Day 1 · ~{{ \App\Models\Mission::formatDuration(collect($today['nextMission']->phases[0]['steps'] ?? [])->sum(fn ($s) => (int) ($s['duration_minutes'] ?? 0))) }}</p>
                <a
                    href="{{ route('missions.show', $today['nextMission']) }}"
                    wire:navigate
                    class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Start {{ $today['nextMission']->code }} @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</a>
            @else
                <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $today['nextMissionCode'] }} isn't built yet — until it is, keep the streak alive with a <a href="{{ route('review.index') }}" wire:navigate class="underline">Review</a> session and a chat with Pi.</p>
            @endif

        @else
            <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">120 days · done</p>
            <p class="mt-0.5 text-sm font-semibold text-ink dark:text-ink-dark">All 24 missions complete. Keep the streak with Daily Review — and keep talking.</p>
        @endif
    </section>

    <a
        href="{{ route('progress.index') }}"
        wire:navigate
        class="flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-3.5 transition-colors hover:border-accent dark:border-line-dark dark:bg-surface-dark dark:hover:border-accent-dark"
    >
        <div class="flex flex-1 flex-wrap items-center gap-x-4 gap-y-1 text-xs">
            <span class="inline-flex items-center gap-1 font-semibold text-accent-ink dark:text-accent-ink-dark">
                <x-streak-flame :streak="$this->progressSummary['streak']" /> {{ $this->progressSummary['streak'] }}
            </span>
            <span class="inline-flex items-center gap-1 font-semibold text-ink dark:text-ink-dark">
                @svg('heroicon-o-check-badge', 'h-3.5 w-3.5') {{ $this->progressSummary['missionsCompleted'] }} {{ Str::plural('mission', $this->progressSummary['missionsCompleted']) }}
            </span>
            @if (($freshness = $this->progressSummary['freshness']) !== null)
                @php
                    $freshnessColor = $freshness >= 66 ? 'text-success dark:text-success-dark' : ($freshness >= 33 ? 'text-amber-600' : 'text-red-600');
                @endphp
                <span class="inline-flex items-center gap-1 font-semibold {{ $freshnessColor }}">
                    @svg('heroicon-o-bolt', 'h-3.5 w-3.5') {{ $freshness }}% fresh
                </span>
            @endif
        </div>
        <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
            My Progress
            @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')
        </span>
    </a>

    @if ($this->justBenefitedFromGrace)
        <div class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-s-fire', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">You missed a day, but your streak is safe!</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">One skipped day never breaks it — keep going.</span>
            </span>
        </div>
    @elseif ($this->justLostStreak)
        <div class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-trophy', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Fresh start — your best run was {{ auth()->user()->longestStreak() }} {{ Str::plural('day', auth()->user()->longestStreak()) }}.</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Let's see if today can be the start of a new one.</span>
            </span>
        </div>
    @elseif ($this->needsTodayReminder)
        <div
            class="flex items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950"
            x-data="{
                remaining: '',
                updateRemaining() {
                    const now = new Date();
                    const midnight = new Date(now);
                    midnight.setHours(24, 0, 0, 0);
                    const diff = midnight - now;
                    const h = Math.floor(diff / 3600000);
                    const m = Math.floor((diff % 3600000) / 60000);
                    this.remaining = `${h}h ${m}m`;
                },
            }"
            x-init="updateRemaining(); setInterval(() => updateRemaining(), 60000)"
        >
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-400">
                <x-streak-flame :streak="auth()->user()->currentStreak()" size="h-4 w-4" />
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">Practice today to keep your {{ auth()->user()->currentStreak() }}-day streak!</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark"><span x-text="remaining"></span> left today</span>
            </span>
        </div>
    @endif

    @if ($this->dueReviewCount)
        <a
            href="{{ route('review.index') }}"
            wire:navigate
            class="flex items-center gap-3 rounded-2xl border border-accent-soft bg-accent-soft/40 p-4 transition-colors hover:bg-accent-soft dark:border-accent-soft-dark dark:bg-accent-soft-dark/40 dark:hover:bg-accent-soft-dark"
        >
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-bolt', 'h-4 w-4')
            </span>
            <span class="flex-1">
                <span class="block text-sm font-semibold text-ink dark:text-ink-dark">{{ $this->dueReviewCount }} {{ Str::plural('item', $this->dueReviewCount) }} ready for Daily Review</span>
                <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">Words, speaking, and grammar — a couple of minutes keeps them all fresh.</span>
            </span>
            @svg('heroicon-o-chevron-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark shrink-0')
        </a>
    @endif

    @foreach ($this->missionSlots as $slot)
        @if (! $slot['mission'])
            @php $placeholder = $this->roadmapPlaceholder($slot['code']); @endphp
            <div class="flex items-center gap-3.5 rounded-2xl border border-line bg-surface-sunken p-4 opacity-60 dark:border-line-dark dark:bg-surface-sunken-dark">
                @if ($placeholder && $placeholder['image_url'])
                    <img src="{{ $placeholder['image_url'] }}" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover grayscale">
                @endif
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $slot['code'] }}</p>
                    <p class="font-display text-lg font-bold text-ink-faint dark:text-ink-faint-dark">{{ $placeholder['title'] ?? 'Coming soon' }}</p>
                    <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Coming soon</p>
                </div>
                <span class="shrink-0 text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-lock-closed', 'h-4 w-4')</span>
            </div>
        @elseif ($slot['blockedBy'])
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $slot['mission']->code }} · {{ $slot['mission']->module }}</p>
                    <p class="font-display text-lg font-bold text-ink-faint dark:text-ink-faint-dark">{{ $slot['mission']->title }}</p>
                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Finish {{ $slot['blockedBy']->code }} first to unlock this one.</p>
                </div>
                <a
                    href="{{ route('missions.show', $slot['blockedBy']) }}"
                    wire:navigate
                    title="Go to {{ $slot['blockedBy']->code }}"
                    class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-dark dark:hover:text-ink-dark"
                >@svg('heroicon-o-lock-closed', 'h-4 w-4')</a>
            </div>
        @else
            @php $coverUrl = $this->missionCoverUrl($slot['mission']); @endphp
            <a href="{{ route('missions.show', $slot['mission']) }}"
               data-mood="{{ $slot['mission']->moodKey() }}"
               class="flex items-center gap-3.5 rounded-2xl border border-line bg-surface p-4 transition-colors hover:border-accent dark:border-line-dark dark:bg-surface-dark dark:hover:border-accent-dark">
                @if ($coverUrl)
                    <img src="{{ $coverUrl }}" alt="" class="h-14 w-14 shrink-0 rounded-xl object-cover">
                @endif
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $slot['mission']->code }} · {{ $slot['mission']->module }}</p>
                    <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $slot['mission']->title }}</h2>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $slot['mission']->outcome }}</p>
                </div>
            </a>
        @endif
    @endforeach
</div>
