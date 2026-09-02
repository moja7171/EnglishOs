<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\Reflection;
use App\Models\SelfAssessment;
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

    public bool $loading = false;

    public ?string $error = null;

    public function mount(): void
    {
        foreach ($this->skills() as $skill) {
            $this->scores[$skill] = ['before' => null, 'after' => null];
        }

        foreach (array_keys($this->questions()) as $key) {
            $this->reflection[$key] = '';
        }

        if ($this->readOnly) {
            $data = json_decode($this->run->latestEvidence('mission_result')?->content_ref ?? '{}', true);
            $this->status = $data['status'] ?? null;
            $this->reason = $data['reason'] ?? null;

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
            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $this->buildSummary()]],
                systemPrompt: 'You are the AI Instructor deciding whether '.$this->run->learner->levelDescription()
                    .' has completed this '
                    .'mission. Based on the summary, reply with ONLY valid JSON, no markdown fences: '
                    .'{"status": "complete" or "needs_review" or "retry_evidence", "reason": "one short, clear, '
                    .'encouraging sentence explaining the decision to the learner"}'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['status'], $data['reason'])) {
                throw new \RuntimeException('Unexpected AI response format.');
            }

            $this->status = $data['status'];
            $this->reason = $data['reason'];
        } catch (\Throwable $e) {
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
            'content_ref' => json_encode(['status' => $this->status, 'reason' => $this->reason]),
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
            <div class="space-y-3">
                @foreach ($this->questions() as $key => $label)
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $label }}</p>
                        <input
                            type="text"
                            wire:model="reflection.{{ $key }}"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}reflection.{{ $key }}', field: 'reflection.{{ $key }}' }"
                            @endunless
                            class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                        >
                    </div>
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
