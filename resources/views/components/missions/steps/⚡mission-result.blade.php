<?php

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\Reflection;
use App\Models\SelfAssessment;
use App\Models\SpeakingPrompt;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
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

    /** Activation's recording — the closest "mid-mission, unscripted speaking" equivalent to pair it against. */
    public ?string $activationRecordingUrl = null;

    /**
     * Opt-in "join Speaking Recall" checklist — same philosophy as
     * TracksVocabularyNotebook (Article 12, Independence: offered, never
     * silently enrolled), kept as plain properties here rather than a
     * shared trait since this is its only caller so far, unlike the
     * vocabulary notebook flow which two different steps already use.
     *
     * @var array<int, bool> keyed by index into speakingPromptCandidates()
     */
    public array $speakingPromptsToTrack = [];

    public bool $trackedSpeakingPrompts = false;

    public function mount(): void
    {
        foreach ($this->skills() as $skill) {
            $this->scores[$skill] = ['before' => null, 'after' => null];
        }

        foreach (array_keys($this->questions()) as $key) {
            $this->reflection[$key] = '';
        }

        $briefScoreEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_SCORE)->latest()->first();
        $this->briefScore = $briefScoreEvidence ? (int) $briefScoreEvidence->content_ref : null;

        $this->warmUpRecordingUrl = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_AUDIO)->latest()->first()?->content_ref;
        $this->activationRecordingUrl = $this->run->evidence()->where('phase', 'activation')->where('type', Evidence::TYPE_AUDIO)->latest()->first()?->content_ref;

        if ($this->readOnly) {
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
     * Picking from real options beats a blank text box — every reflection
     * question is authored with a "type" naming where its options come
     * from, all real data about THIS run rather than a generic fixed
     * list: the same skills self-assessed above, the learner's own
     * Vocabulary Builder picks, or the corrections from their own Error
     * Log this mission. Empty if that source has nothing yet (e.g. no
     * mistakes logged this run) — the question is simply skipped, never a
     * dead end forcing an answer that doesn't exist.
     *
     * @return list<string>
     */
    private function reflectionOptions(string $type): array
    {
        return match ($type) {
            'skills' => $this->skills(),
            'vocabulary' => $this->run->selectedVocabularyWords(),
            'errors' => $this->run->errorLogItems()->pluck('correction')->filter()->unique()->values()->all(),
            default => [],
        };
    }

    public function selectReflectionOption(string $key, string $option): void
    {
        $this->reflection[$key] = $option;
    }

    /**
     * The learner's most-recurring error pattern across missions (see
     * User::topRecurringError(), built for Active Recall's spaced-
     * repetition prompt) — naturally already includes anything logged in
     * THIS mission's own Error Log step, since that step runs before
     * Mission Result. Null until a pattern has recurred across 2+
     * missions, the common case for a learner's first mission or two.
     */
    public function getRecurringErrorProperty(): ?ErrorLogItem
    {
        return $this->run->learner->topRecurringError();
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
     * questions and the AI Conversation interview questions — offered as
     * the starting set for Speaking Recall (see EOS-009 §8). Deduplicated
     * in case a mission happens to reuse a question in both places.
     *
     * @return list<string>
     */
    public function speakingPromptCandidates(): array
    {
        $warmUp = $this->run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [];
        $interview = $this->run->mission->stepContent('ai_conversation_1')['interview_questions'] ?? [];

        return collect($warmUp)->merge($interview)->unique()->values()->all();
    }

    /**
     * firstOrCreate per checked question, same idea as
     * TracksVocabularyNotebook::addWordsToNotebook() — re-adding a
     * question already on the learner's list (from this mission or a
     * past one) never resets its review schedule.
     */
    public function addSpeakingPromptsToRecall(): void
    {
        foreach ($this->speakingPromptCandidates() as $index => $prompt) {
            if (! ($this->speakingPromptsToTrack[$index] ?? false)) {
                continue;
            }

            SpeakingPrompt::firstOrCreate(
                ['learner_id' => $this->run->learner_id, 'prompt' => $prompt],
                [
                    'source_mission_run_id' => $this->run->id,
                    'mission_code' => $this->run->mission->code,
                    'next_review_at' => now(),
                ],
            );
        }

        $this->trackedSpeakingPrompts = true;
    }

    public function getResult(): void
    {
        $this->error = null;

        foreach ($this->scores as $pair) {
            if (! $pair['before'] || ! $pair['after']) {
                $this->addError('scores', 'Rate every skill, before and after, before continuing.');

                return;
            }
        }

        $this->loading = true;

        try {
            $stepKeys = implode(', ', $this->run->mission->stepKeys());

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $this->buildSummary()]],
                systemPrompt: 'You are the AI Instructor deciding whether '.$this->run->learner->levelDescription()
                    .' has completed this '
                    .'mission. Based on the summary, reply with ONLY valid JSON, no markdown fences: '
                    .'{"status": "complete" or "needs_review" or "retry_evidence", "reason": "one short, clear, '
                    .'encouraging sentence explaining the decision to the learner", "weak_step": "if status is '
                    .'not complete, the single step key most worth revisiting — exactly one of: '.$stepKeys
                    .' — otherwise null"}'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['status'], $data['reason'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->status = $data['status'];
            $this->reason = $data['reason'];
            // Never trust the AI's step key blindly — only a real,
            // existing step in this mission is ever offered as a link.
            $weakStep = $data['weak_step'] ?? null;
            $this->weakStep = in_array($weakStep, $this->run->mission->stepKeys(), true) ? $weakStep : null;
            $this->milestoneJustReached = $this->run->learner->streakMilestoneJustReached();
            $this->speakingPromptsToTrack = array_fill(0, count($this->speakingPromptCandidates()), true);
        } catch (Throwable $e) {
            $this->error = "Couldn't get your result from the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->loading = false;
        }
    }

    private function buildSummary(): string
    {
        $parts = [];

        $avgBefore = round(collect($this->scores)->avg('before'), 1);
        $avgAfter = round(collect($this->scores)->avg('after'), 1);
        $parts[] = "Average self-assessment across 5 skills: before {$avgBefore}/5, after {$avgAfter}/5.";

        if ($feedback = $this->run->evidence()->where('phase', 'ai_feedback_1')->latest()->first()) {
            $parts[] = 'AI feedback from the first conversation: '.$feedback->content_ref;
        }

        if ($conv2 = $this->run->evidence()->where('phase', 'ai_conversation_2')->latest()->first()) {
            $data = json_decode($conv2->content_ref, true) ?? [];
            $reqs = $data['requirements'] ?? [];
            $met = collect($reqs)->filter()->count();
            $parts[] = "Final challenge requirements met: {$met}/".count($reqs).'.';
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
                'before' => $pair['before'],
                'after' => $pair['after'],
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
                // Optional — null if the learner never tapped it, never
                // required to reach here.
                'topic_comfort_after' => $this->afterScore,
            ]),
        ]);

        $this->run->update(['status' => $this->status, 'completed_at' => now()]);

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

@php $draftPrefix = $this->draftPrefix(); @endphp

<div class="space-y-6" x-data="{ activeSection: 0 }">
    <x-hook :text="$run->mission->stepContent('mission_result')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Mission Result</p>
    </div>

    @if (! $status)
        <div class="mb-2">
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    :style="`width: ${(activeSection + 1) / 2 * 100}%`"
                ></div>
                <x-slot:label>
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                        Part <span x-text="activeSection + 1"></span> of 2
                    </p>
                </x-slot:label>
            </x-progress-bar>
        </div>

        <div x-show="activeSection === 0" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Final self-assessment</p>
            <div class="mt-2 space-y-2">
                @foreach ($this->skills() as $skill)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-line p-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                        <span class="w-24">{{ $skill }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">Before</span>
                            <select wire:model="scores.{{ $skill }}.before" class="rounded-lg border border-line bg-transparent px-1 text-ink dark:border-line-dark dark:text-ink-dark">
                                <option value="">–</option>
                                @foreach (range(1, 5) as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                            </select>
                            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">After</span>
                            <select wire:model="scores.{{ $skill }}.after" class="rounded-lg border border-line bg-transparent px-1 text-ink dark:border-line-dark dark:text-ink-dark">
                                <option value="">–</option>
                                @foreach (range(1, 5) as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                            </select>
                        </span>
                    </div>
                @endforeach
            </div>
            @error('scores')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div x-show="activeSection === 1" x-cloak>
            <div class="space-y-4">
                @foreach ($this->questions() as $key => $question)
                    @php
                        $label = is_array($question) ? $question['label'] : $question;
                        $options = is_array($question) ? $this->reflectionOptions($question['type'] ?? '') : [];
                    @endphp
                    @if ($options || ! is_array($question))
                        <div>
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $label }}</p>
                            @if ($options)
                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                    @foreach ($options as $option)
                                        <button
                                            type="button"
                                            @unless ($readOnly)
                                                wire:click="selectReflectionOption('{{ $key }}', {{ \Illuminate\Support\Js::from($option) }})"
                                            @endunless
                                            @class([
                                                'cursor-pointer rounded-full border px-3 py-1.5 text-xs font-semibold transition-colors',
                                                'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => ($reflection[$key] ?? null) === $option,
                                                'border-line text-ink-soft hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ($reflection[$key] ?? null) !== $option,
                                            ])
                                        >{{ $option }}</button>
                                    @endforeach
                                </div>
                            @else
                                <input
                                    type="text"
                                    wire:model="reflection.{{ $key }}"
                                    @unless ($readOnly)
                                        x-draft="{ key: '{{ $draftPrefix }}reflection.{{ $key }}', field: 'reflection.{{ $key }}' }"
                                    @endunless
                                    class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                                >
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>

            @if ($error)
                <p class="mt-2 text-sm text-red-600">{{ $error }}</p>
            @endif

            <button
                wire:click="getResult"
                wire:loading.attr="disabled"
                class="mt-4 cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
            >
                <span wire:loading.remove wire:target="getResult">Get My Result</span>
                <span wire:loading wire:target="getResult">Reviewing your mission…</span>
            </button>
        </div>

        <div class="mt-4">
            <x-substep-nav index-var="activeSection" :total="2" />
        </div>
    @else
        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            @if ($milestoneJustReached)
                <div class="mb-3 rounded-xl border border-accent-soft bg-accent-soft/60 p-3 text-center dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-full bg-accent text-white dark:bg-accent-dark">
                        @svg('heroicon-s-trophy', 'h-5 w-5')
                    </span>
                    <p class="mt-1.5 text-sm font-bold text-accent-ink dark:text-accent-ink-dark">{{ $milestoneJustReached }}-day streak!</p>
                    <p class="text-xs text-ink-soft dark:text-ink-soft-dark">
                        @if ($milestoneJustReached === 7)
                            A full week of practice — real momentum is building.
                        @elseif ($milestoneJustReached === 30)
                            A whole month, consistently — that's a real habit now.
                        @else
                            100 days. That's not a habit anymore — that's just who you are now.
                        @endif
                    </p>
                </div>
            @elseif ($streak = $this->run->learner->currentStreak())
                <p class="mb-2 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">
                    @svg('heroicon-s-fire', 'h-3.5 w-3.5')
                    {{ $streak === 1 ? "You're on a 1-day streak — nice start!" : "You're on a {$streak}-day streak — keep it going!" }}
                </p>
            @endif

            <p class="text-xs font-semibold uppercase tracking-wide
                {{ $status === 'complete' ? 'text-success dark:text-success-dark' : ($status === 'needs_review' ? 'text-amber-600' : 'text-red-600') }}">
                {{ str($status)->replace('_', ' ')->title() }}
            </p>
            <p class="mt-2 text-sm text-ink dark:text-ink-dark">{{ $reason }}</p>

            @if (collect($scores)->every(fn ($pair) => $pair['before'] && $pair['after']))
                <div class="mt-4 space-y-2.5">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Self-assessment</p>
                    @foreach ($scores as $skill => $pair)
                        @php
                            $before = (int) $pair['before'];
                            $after = (int) $pair['after'];
                            $delta = $after - $before;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-xs text-ink-soft dark:text-ink-soft-dark">
                                <span>{{ $skill }}</span>
                                <span class="font-semibold {{ $delta > 0 ? 'text-success dark:text-success-dark' : ($delta < 0 ? 'text-red-600' : 'text-ink-faint dark:text-ink-faint-dark') }}">
                                    {{ $before }} → {{ $after }} ({{ $delta > 0 ? '+' : '' }}{{ $delta }})
                                </span>
                            </div>
                            <div class="mt-1 flex gap-1">
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark" title="Before">
                                    <div class="h-full rounded-full bg-ink-faint dark:bg-ink-faint-dark" style="width: {{ $before / 5 * 100 }}%"></div>
                                </div>
                                <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark" title="After">
                                    <div class="h-full rounded-full bg-accent dark:bg-accent-dark" style="width: {{ $after / 5 * 100 }}%"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($briefScore)
                <div class="mt-4">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Since Day 1</p>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">You started this mission rating your comfort with this topic <span class="font-semibold text-ink dark:text-ink-dark">{{ $briefScore }}/5</span>.</p>

                    @if ($warmUpRecordingUrl || $activationRecordingUrl)
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            @if ($warmUpRecordingUrl)
                                <div class="rounded-xl border border-line p-2.5 dark:border-line-dark">
                                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Day 1 — your warm-up answer</p>
                                    <div class="mt-1"><x-audio-player :url="$warmUpRecordingUrl" /></div>
                                </div>
                            @endif
                            @if ($activationRecordingUrl)
                                <div class="rounded-xl border border-line p-2.5 dark:border-line-dark">
                                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Mid-mission — your Activation recording</p>
                                    <div class="mt-1"><x-audio-player :url="$activationRecordingUrl" /></div>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($readOnly)
                        @if ($afterScore)
                            <p class="mt-2 text-sm text-ink-soft dark:text-ink-soft-dark">You rated it <span class="font-semibold text-ink dark:text-ink-dark">{{ $afterScore }}/5</span> by the end.</p>
                        @endif
                    @else
                        <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">How about now? (optional)</p>
                        <div class="mt-1 flex gap-1.5">
                            @foreach (range(1, 5) as $value)
                                <button
                                    type="button"
                                    wire:click="$set('afterScore', {{ $value }})"
                                    @class([
                                        'h-8 w-8 cursor-pointer rounded-full border text-xs font-semibold transition-colors',
                                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $afterScore === $value,
                                        'border-line text-ink-soft hover:border-ink-faint dark:border-line-dark dark:text-ink-soft-dark' => $afterScore !== $value,
                                    ])
                                >{{ $value }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if (count($this->vocabularyUsage))
                @php $usedCount = collect($this->vocabularyUsage)->where('used', true)->count(); @endphp
                <div class="mt-4">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                        Vocabulary — {{ $usedCount }} of {{ count($this->vocabularyUsage) }} words used
                    </p>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        @foreach ($this->vocabularyUsage as $item)
                            <span class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs
                                {{ $item['used']
                                    ? 'border-success/30 bg-success-soft text-success dark:border-success-dark/30 dark:bg-success-soft-dark dark:text-success-dark'
                                    : 'border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark' }}">
                                @if ($item['used'])
                                    @svg('heroicon-o-check-circle', 'h-3 w-3')
                                @endif
                                {{ $item['word'] }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->recurringError)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
                    <p class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 uppercase dark:text-amber-400">
                        @svg('heroicon-o-arrow-path', 'h-3.5 w-3.5')
                        A pattern to keep an eye on
                    </p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                        <span class="text-red-600 line-through decoration-red-500">{{ $this->recurringError->error }}</span>
                        <span class="text-success dark:text-success-dark">{{ $this->recurringError->correction }}</span>
                    </p>
                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">This has come up across more than one mission — worth extra attention next time.</p>
                </div>
            @endif

            @if (! $readOnly && count($this->speakingPromptCandidates()))
                <div class="mt-4">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Speaking Recall</p>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Want to hear these questions again someday? Pick which ones join your spaced-repetition speaking practice.</p>

                    <div class="mt-2 space-y-2">
                        @foreach ($this->speakingPromptCandidates() as $index => $prompt)
                            <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                                <input
                                    type="checkbox"
                                    wire:model="speakingPromptsToTrack.{{ $index }}"
                                    class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                                >
                                <span class="text-sm text-ink dark:text-ink-dark">{{ $prompt }}</span>
                            </label>
                        @endforeach
                    </div>

                    @if ($trackedSpeakingPrompts)
                        <span class="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-4 w-4') Added to Speaking Recall
                        </span>
                    @else
                        <button
                            type="button"
                            wire:click="addSpeakingPromptsToRecall"
                            class="mt-2 inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >@svg('heroicon-o-microphone', 'h-4 w-4') Add to Speaking Recall</button>
                    @endif
                </div>
            @endif

            @if ($status !== 'complete' && $weakStep)
                <div class="mt-4">
                    <a
                        href="{{ route('missions.show', [$run->mission, $weakStep]) }}"
                        wire:navigate
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >
                        @svg('heroicon-o-arrow-uturn-left', 'h-3.5 w-3.5')
                        Review {{ $run->mission->stepLabel($weakStep) }}
                    </a>
                </div>
            @endif

            @if ($status === 'complete' && ! $readOnly)
                <div class="mt-4">
                    <x-practice-with-friend
                        :text="$run->mission->title"
                        intro="Hey — I just finished my mission:"
                        label="Share this with a friend"
                    />
                </div>
            @endif
        </div>

        @unless ($readOnly)
            <button
                wire:click="finish"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                Finish Mission
            </button>
        @endunless
    @endif
</div>
