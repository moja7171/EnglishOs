<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\Reflection;
use App\Models\SelfAssessment;
use App\Models\SpeakingPrompt;
use App\Notifications\StreakMilestoneReached;
use App\Services\AiFailure;
use App\Services\GeminiClient;
use Livewire\Component;

/**
 * Lesson design 14: three short questions (how easy the topic feels now —
 * Day 1's question again; the five skills "now" only, "before" comes from
 * the previous mission; one expression to keep), then the celebration,
 * then Day 1 vs today (scores and voices), then the words used and Finish.
 * What became easier / still hard is worked out from the skill scores,
 * and every question this mission asked goes into Speaking Recall.
 */
new class extends Component
{
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<string, array{before: int|null, after: int|null}> */
    public array $scores = [];

    /** @var array<string, string> */
    public array $reflection = [];

    public ?string $status = null;

    public ?string $reason = null;

    /**
     * The one step the AI thinks is most worth a second look when status
     * isn't "complete" — a real, reachable step key (validated against
     * this mission's own stepKeys(), never trusted blindly from the AI),
     * so "Review this step" can link straight to it. Null when the AI
     * didn't name one, or status is "complete".
     */
    public ?string $weakStep = null;

    public bool $loading = false;

    public ?string $error = null;

    /** True when every judge model failed and the result below is the neutral fallback, not an AI decision. */
    public bool $evaluationUnavailable = false;

    /**
     * Set once, inside getResult() — never in readOnly mode, and never
     * recomputed on every render, since streakMilestoneJustReached() has
     * a side effect (marks the milestone celebrated). Reviewing an old,
     * already-graded mission later must never replay — or worse,
     * incorrectly attribute — a celebration for a threshold actually
     * crossed during some other, later mission.
     */
    public ?int $milestoneJustReached = null;

    /**
     * The literal Day 1 comfort score from Mission Brief (never touched
     * since) — see mission-brief.blade.php. Closes the loop on the
     * promise made there ("we'll compare this to your score at the
     * end"), which nothing previously read back.
     */
    public ?int $briefScore = null;

    /**
     * A fresh, optional, ungraded re-ask of the same "how comfortable do
     * you feel about this topic?" question — deliberately separate from
     * the required multi-skill before/after grid above, so answering it
     * never blocks Finish. Null until tapped (live) or if the learner
     * skipped it (readOnly).
     */
    public ?int $afterScore = null;

    /** Mission Brief's optional Day 1 warm-up recording, if the learner made one. */
    public ?string $warmUpRecordingUrl = null;

    /** Final Talk's 3-minute take — "today" next to Day 1's recording. */
    public ?string $finalTalkRecordingUrl = null;

    public function mount(): void
    {
        $previousScores = $this->previousMissionScores();

        foreach ($this->skills() as $skill) {
            $this->scores[$skill] = ['before' => $previousScores[$skill] ?? null, 'after' => null];
        }

        foreach (array_keys($this->questions()) as $key) {
            $this->reflection[$key] = '';
        }

        $briefScoreEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_SCORE)->latest()->first();
        $this->briefScore = $briefScoreEvidence ? (int) $briefScoreEvidence->content_ref : null;

        $this->warmUpRecordingUrl = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_AUDIO)->latest()->first()?->content_ref;
        $this->finalTalkRecordingUrl = $this->run->evidence()->where('phase', 'ai_conversation_2')->where('type', Evidence::TYPE_AUDIO)->latest()->first()?->content_ref;

        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        $data = json_decode($this->run->latestEvidence('mission_result')?->content_ref ?? '{}', true);
        $this->status = $data['status'] ?? null;
        $this->reason = $data['reason'] ?? null;
        $this->weakStep = $data['weak_step'] ?? null;
        $this->afterScore = $data['topic_comfort_after'] ?? null;

        foreach ($this->run->selfAssessments as $assessment) {
            $this->scores[$assessment->skill] = ['before' => $assessment->before, 'after' => $assessment->after];
        }

        if ($reflection = $this->run->reflection) {
            $this->reflection = $reflection->answers;
        }
    }

    private function skills(): array
    {
        return $this->run->mission->stepContent('mission_result')['skills'] ?? [];
    }

    private function questions(): array
    {
        return $this->run->mission->stepContent('mission_result')['reflection_questions'] ?? [];
    }

    /**
     * Each skill's latest "now" score from an earlier mission — this
     * mission's "before", so the learner only rates where they are now.
     *
     * @return array<string, int>
     */
    private function previousMissionScores(): array
    {
        return SelfAssessment::query()
            ->whereHas('missionRun', fn ($query) => $query
                ->where('learner_id', $this->run->learner_id)
                ->where('id', '!=', $this->run->id))
            ->whereNotNull('after')
            ->latest('id')
            ->get()
            ->unique('skill')
            ->mapWithKeys(fn (SelfAssessment $assessment) => [$assessment->skill => (int) $assessment->after])
            ->all();
    }

    /**
     * The learner's own words from this mission — "one expression you want
     * to keep using?".
     *
     * @return list<string>
     */
    public function expressionOptions(): array
    {
        return $this->run->selectedVocabularyWords();
    }

    protected function draftPhase(): string
    {
        return 'mission_result';
    }

    protected function draftedProperties(): array
    {
        return ['scores', 'reflection', 'status', 'reason', 'weakStep', 'evaluationUnavailable', 'milestoneJustReached', 'afterScore'];
    }

    public function updatedScores(): void
    {
        $this->keepStepDraft();
    }

    public function updatedAfterScore(): void
    {
        $this->keepStepDraft();
    }

    public function selectReflectionOption(string $key, string $option): void
    {
        $this->reflection[$key] = $option;

        $this->keepStepDraft();
    }

    /**
     * Which of the learner's own Vocabulary Builder picks actually turned
     * up somewhere in their real output this mission (AI Conversation,
     * Writing, Activation, Active Recall — see MissionRun::allLearnerText())
     * — a plain case-insensitive substring check, deliberately not an AI
     * call: this is just closing the loop on a word list already threaded
     * through the whole mission, not a new judgment worth an API round-trip.
     *
     * @return list<array{word: string, used: bool}>
     */
    public function getVocabularyUsageProperty(): array
    {
        $words = $this->run->selectedVocabularyWords();

        if (! $words) {
            return [];
        }

        $haystack = strtolower($this->run->allLearnerText());

        return collect($words)
            ->map(fn ($word) => ['word' => $word, 'used' => str_contains($haystack, strtolower($word))])
            ->all();
    }

    /**
     * Real questions this mission actually asked — Mission Brief's warm-up
     * questions, the AI Conversation interview questions and the Final
     * Talk rounds this run was given — offered as the starting set for
     * Speaking Recall (see EOS-009 §8). Deduplicated in case a mission
     * happens to reuse a question in more than one place.
     *
     * @return list<string>
     */
    public function speakingPromptCandidates(): array
    {
        $warmUp = $this->run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [];
        $interview = $this->run->mission->stepContent('ai_conversation_1')['interview_questions'] ?? [];

        return collect($warmUp)->merge($interview)->merge($this->run->finalTalkRounds())->unique()->values()->all();
    }

    /**
     * Every question this mission asked goes into Speaking Recall —
     * firstOrCreate, so a question already on the learner's list never
     * has its review schedule reset.
     */
    private function addSpeakingPromptsToRecall(): void
    {
        foreach ($this->speakingPromptCandidates() as $prompt) {
            SpeakingPrompt::firstOrCreate(
                ['learner_id' => $this->run->learner_id, 'prompt' => $prompt],
                [
                    'source_mission_run_id' => $this->run->id,
                    'mission_code' => $this->run->mission->code,
                    'next_review_at' => now(),
                ],
            );
        }
    }

    /**
     * "What became easier" is the skill that rose most since the last
     * mission; "still difficult" the one rated lowest now.
     */
    private function deriveReflection(): void
    {
        $scores = collect($this->scores);

        $gains = $scores
            ->filter(fn ($pair) => $pair['before'] && $pair['after'] && $pair['after'] > $pair['before'])
            ->map(fn ($pair) => $pair['after'] - $pair['before'])
            ->sortDesc();

        $this->reflection['became_easier'] = (string) ($gains->keys()->first() ?? '');
        $this->reflection['still_difficult'] = (string) ($scores->filter(fn ($pair) => $pair['after'])->sortBy('after')->keys()->first() ?? '');
    }

    public function getResult(): void
    {
        $this->error = null;

        foreach ($this->scores as $pair) {
            if (! $pair['after']) {
                $this->addError('scores', 'Rate every skill before continuing.');

                return;
            }
        }

        $this->deriveReflection();
        $this->loading = true;

        try {
            // Only genuinely redoable steps are ever offered to the AI as
            // candidates in the first place — cheaper and cleaner than
            // just rejecting a bad pick after the fact (still done below
            // too, in case the AI ignores this list).
            $redoableStepKeys = implode(', ', array_filter($this->run->mission->stepKeys(), Mission::isStepRedoable(...)));

            // When every judge model has failed (GeminiClient has already
            // logged why) the learner must still be able to finish the
            // mission: 'needs_review' counts as done for progression (see
            // ProgramPlanner) but flags the run for a later look, so nothing
            // is falsely marked 'complete'.
            try {
                $raw = app(GeminiClient::class)->chat(
                    [['role' => 'user', 'text' => $this->buildSummary()]],
                    systemPrompt: 'You are the AI Instructor deciding whether '.$this->run->learner->levelDescription()
                        .' has completed this '
                        .'mission. Based on the summary, reply with ONLY valid JSON, no markdown fences: '
                        .'{"status": "complete" or "needs_review" or "retry_evidence", "reason": "one short, clear, '
                        .'encouraging sentence explaining the decision to the learner", "weak_step": "if status is '
                        .'not complete, the single step key most worth revisiting — exactly one of: '.$redoableStepKeys
                        .' — never a step that is just an automatic AI-generated summary with no learner input, '
                        .'otherwise null"}',
                    profile: GeminiClient::PROFILE_JUDGE,
                );
                $this->recordGeminiCall();

                $data = json_decode(trim($raw), true);

                if (! is_array($data) || ! isset($data['status'], $data['reason'])) {
                    throw new RuntimeException('Unexpected AI response format.');
                }

                $this->status = $data['status'];
                $this->reason = $data['reason'];
                // Never trust the AI's step key blindly — only a real,
                // existing, redoable step in this mission is ever offered as a link.
                $weakStep = $data['weak_step'] ?? null;
                $this->weakStep = (in_array($weakStep, $this->run->mission->stepKeys(), true) && Mission::isStepRedoable($weakStep))
                    ? $weakStep : null;
            } catch (Throwable) {
                $this->evaluationUnavailable = true;
                $this->status = MissionRun::STATUS_NEEDS_REVIEW;
                $this->reason = "We couldn't review this mission just now, so it's saved for a later review. You can carry on.";
                $this->weakStep = null;
            }

            $this->milestoneJustReached = $this->run->learner->streakMilestoneJustReached();

            if ($this->milestoneJustReached !== null) {
                // The page already celebrates this live (the trophy banner
                // below) — the notification is what lets the learner find
                // this moment again later, in the bell's history.
                $this->run->learner->notify(new StreakMilestoneReached($this->milestoneJustReached));
            }
            $this->keepStepDraft();
        } catch (Throwable $e) {
            $this->error = AiFailure::message($e, "Couldn't get your result from the AI Instructor");
        } finally {
            $this->loading = false;
        }
    }

    /**
     * Deliberately excludes the learner's own before/after skill sliders —
     * self-assessment is tone-only (see EOS-009) and must never influence
     * this AI's status decision, only objective Evidence produced during
     * the mission. The scores are still collected, stored, and shown back
     * to the learner (see the "Self-assessment" block in the Blade below);
     * they simply never reach this prompt.
     */
    private function buildSummary(): string
    {
        $parts = [];

        // AI Feedback #1 is now generated automatically as part of AI
        // Conversation #1's own completion recap, not a separate step —
        // its data lives under the 'feedback' key of that phase's TEXT
        // evidence (see ⚡ai-conversation1.blade.php's finishInterview()).
        if ($conv1 = $this->run->evidence()->where('phase', 'ai_conversation_1')->where('type', Evidence::TYPE_TEXT)->latest()->first()) {
            $data = json_decode($conv1->content_ref, true) ?? [];

            if ($feedback = $data['feedback'] ?? null) {
                $parts[] = 'AI feedback from the first conversation: '.json_encode($feedback);
            }
        }

        if ($conv2 = $this->run->evidence()->where('phase', 'ai_conversation_2')->where('type', Evidence::TYPE_TRANSCRIPT)->latest()->first()) {
            $data = json_decode($conv2->content_ref, true) ?? [];
            $reqs = $data['requirements'] ?? [];
            $met = collect($reqs)->filter()->count();
            $parts[] = "Final challenge requirements met: {$met}/".count($reqs).'.';
        }

        // Day 4's other AI-graded speaking step (see
        // ⚡picture-description.blade.php) — added once it became a
        // required step for every mission, not just an optional extra.
        if ($pictureDescription = $this->run->evidence()->where('phase', 'picture_description')->where('type', Evidence::TYPE_TEXT)->latest()->first()) {
            $data = json_decode($pictureDescription->content_ref, true) ?? [];

            if ($feedback = $data['feedback'] ?? null) {
                $parts[] = 'AI feedback from the picture description task: '.json_encode($feedback);
            }
        }

        $parts[] = 'Recurring mistakes identified and corrected: '.$this->run->errorLogItems()->count().'.';
        $parts[] = 'Learner reflection — what became easier: '.($this->reflection['became_easier'] ?? '');
        $parts[] = 'Learner reflection — what is still difficult: '.($this->reflection['still_difficult'] ?? '');

        return implode("\n", $parts);
    }

    public function finish(): void
    {
        if (! $this->status) {
            return;
        }

        foreach ($this->scores as $skill => $pair) {
            SelfAssessment::create([
                'mission_run_id' => $this->run->id,
                'skill' => $skill,
                'before' => $pair['before'] ?? null,
                'after' => $pair['after'] ?? null,
            ]);
        }

        Reflection::create([
            'mission_run_id' => $this->run->id,
            'answers' => $this->reflection,
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'mission_result',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'status' => $this->status,
                'reason' => $this->reason,
                'weak_step' => $this->weakStep,
                'evaluation_unavailable' => $this->evaluationUnavailable,
                // Optional — null if the learner never tapped it, never
                // required to reach here.
                'topic_comfort_after' => $this->afterScore,
            ]),
        ]);

        $this->addSpeakingPromptsToRecall();

        $this->run->update(['status' => $this->status, 'completed_at' => now()]);

        $this->forgetStepDraft();
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:mission_result:";
    }
};
?>

@php
    $comfortChoices = [
        1 => ['😣', 'Very hard'],
        2 => ['😕', 'Hard'],
        3 => ['😐', 'Okay'],
        4 => ['🙂', 'Easy'],
        5 => ['😄', 'Very easy'],
    ];
    $streak = $this->run->learner->currentStreak();
    $usage = $this->vocabularyUsage;
    $usedCount = collect($usage)->where('used', true)->count();
    $allRated = collect($scores)->every(fn ($pair) => (bool) $pair['after']);
    $chip = 'inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark';
    $bigQuestion = 'lesson-prompt text-ink dark:text-ink-dark';
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-5 pt-2" @if ($preview) inert @endif>
        <x-step-intro kind="Mission Result" :title="$status ? null : ($run->mission->stepContent('mission_result')['hook'] ?? null)" />
        @if ($status)
            @include('components.missions.steps.partials.mission-result-verdict')
            @include('components.missions.steps.partials.mission-result-then-now')
            @if (count($usage))
                @include('components.missions.steps.partials.mission-result-words')
            @endif
        @endif
    </div>
@else
<div
    x-data="{ screen: {{ $status ? 3 : 0 }} }"
    x-effect="$dispatch('step-progress', { share: (screen + 0.5) / 6 })"
    x-back-close="{ open: screen > 0 && screen < 3, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <span class="{{ $chip }}">Mission Result</span>
        <p class="{{ $bigQuestion }}">How easy is it to talk about this now?</p>
        <div class="flex flex-col gap-2">
            @foreach ($comfortChoices as $value => [$emoji, $label])
                <button
                    type="button"
                    wire:click="$set('afterScore', {{ $value }})"
                    @class([
                        'flex cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 py-3 text-left text-base font-bold text-ink transition-colors dark:text-ink-dark',
                        'border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark' => $afterScore === $value,
                        'border-transparent bg-surface dark:bg-surface-dark' => $afterScore !== $value,
                    ])
                ><span class="text-xl">{{ $emoji }}</span>{{ $label }}</button>
            @endforeach
        </div>

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="afterScore" ready-when="{{ $afterScore ? 'true' : 'false' }}" />
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === 1">
        <p class="{{ $bigQuestion }}">How good are you now?</p>
        <div class="flex flex-col gap-2">
            @foreach ($scores as $skill => $pair)
                <div class="flex items-center justify-between gap-3 rounded-2xl bg-surface px-4 py-2.5 dark:bg-surface-dark">
                    <span class="text-base font-bold text-ink dark:text-ink-dark">{{ $skill }}</span>
                    <span class="flex gap-1">
                        @foreach (range(1, 5) as $n)
                            <button
                                type="button"
                                wire:click="$set('scores.{{ $skill }}.after', {{ $n }})"
                                aria-label="{{ $skill }} {{ $n }} of 5"
                                class="flex h-8 w-8 cursor-pointer items-center justify-center"
                            ><span @class([
                                'h-5 w-5 rounded-full transition-colors',
                                'bg-accent dark:bg-accent-dark' => ($pair['after'] ?? 0) >= $n,
                                'bg-line dark:bg-line-dark' => ($pair['after'] ?? 0) < $n,
                            ])></span></button>
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>
        @error('scores')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror

        <x-slot:actions>
            <x-continue-button on-click="screen = 2" wire-target="scores" ready-when="{{ $allRated ? 'true' : 'false' }}" />
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === 2">
        @if (count($this->expressionOptions()))
            <p class="{{ $bigQuestion }}">One expression you want to keep using?</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->expressionOptions() as $option)
                    <button
                        type="button"
                        wire:click="selectReflectionOption('expression_to_keep', {{ \Illuminate\Support\Js::from($option) }})"
                        @class([
                            'cursor-pointer rounded-full border-2 px-3.5 py-2 text-sm font-bold transition-colors',
                            'border-accent bg-accent-soft text-accent-ink dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ($reflection['expression_to_keep'] ?? null) === $option,
                            'border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark' => ($reflection['expression_to_keep'] ?? null) !== $option,
                        ])
                    >{{ $option }}</button>
                @endforeach
            </div>
        @else
            <p class="{{ $bigQuestion }}">Ready to see your result?</p>
        @endif
        @if ($error)
            <p class="text-sm text-danger-ink">{{ $error }}</p>
        @endif

        <x-slot:actions>
            <x-continue-button
                on-click="$wire.getResult().then(() => { if ($wire.status) screen = 3 })"
                wire-target="getResult"
                loading-label="Reviewing your mission…"
            >See my result</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    @if ($status)
        <x-lesson-screen when="screen === 3">
            @if ($status === 'complete' || $milestoneJustReached)
                <div x-init="window.eosConfetti?.burst()"></div>
            @endif
            @include('components.missions.steps.partials.mission-result-verdict')

            <x-slot:actions>
                <x-continue-button on-click="screen = 4" wire-target="finish" />
            </x-slot:actions>
        </x-lesson-screen>

        <x-lesson-screen when="screen === 4">
            @include('components.missions.steps.partials.mission-result-then-now')

            <x-slot:actions>
                <x-continue-button on-click="screen = 5" wire-target="finish" />
            </x-slot:actions>
        </x-lesson-screen>

        <x-lesson-screen when="screen === 5">
            @if (count($usage))
                @include('components.missions.steps.partials.mission-result-words')
            @else
                <p class="{{ $bigQuestion }}">That's the mission done.</p>
            @endif

            <x-slot:actions>
                <x-continue-button on-click="$wire.finish()" wire-target="finish" loading-label="Finishing…">Finish mission</x-continue-button>
            </x-slot:actions>
        </x-lesson-screen>
    @endif
</div>
@endif
