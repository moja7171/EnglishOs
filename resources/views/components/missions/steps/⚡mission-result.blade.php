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
    }

    private function skills(): array
    {
        return $this->run->mission->stepContent('mission_result')['skills'] ?? [];
    }

    private function questions(): array
    {
        return $this->run->mission->stepContent('mission_result')['reflection_questions'] ?? [];
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
                systemPrompt: 'You are the AI Instructor deciding whether a B1 English learner has completed this '
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

        $this->redirect(route('missions.show', $this->run->mission));
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Mission Result</p>
    </div>

    @if (! $status)
        <div>
            <p class="text-sm font-semibold">Final self-assessment</p>
            <div class="mt-2 space-y-2">
                @foreach ($this->skills() as $skill)
                    <div class="flex items-center justify-between gap-3 rounded border border-neutral-300 p-2 text-sm dark:border-neutral-700">
                        <span class="w-24">{{ $skill }}</span>
                        <span class="flex items-center gap-2">
                            <span class="text-xs text-neutral-500">Before</span>
                            <select wire:model="scores.{{ $skill }}.before" class="rounded border border-neutral-300 bg-transparent px-1 dark:border-neutral-700">
                                <option value="">–</option>
                                @foreach (range(1, 5) as $n) <option value="{{ $n }}">{{ $n }}</option> @endforeach
                            </select>
                            <span class="text-xs text-neutral-500">After</span>
                            <select wire:model="scores.{{ $skill }}.after" class="rounded border border-neutral-300 bg-transparent px-1 dark:border-neutral-700">
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

        <div class="space-y-3">
            @foreach ($this->questions() as $key => $label)
                <div>
                    <p class="text-sm font-semibold">{{ $label }}</p>
                    <input
                        type="text"
                        wire:model="reflection.{{ $key }}"
                        class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                </div>
            @endforeach
        </div>

        @if ($error)
            <p class="text-sm text-red-600">{{ $error }}</p>
        @endif

        <button
            wire:click="getResult"
            wire:loading.attr="disabled"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            <span wire:loading.remove wire:target="getResult">Get My Result</span>
            <span wire:loading wire:target="getResult">Reviewing your mission…</span>
        </button>
    @else
        <div class="rounded-lg border-2 border-neutral-900 p-4 dark:border-white">
            <p class="text-xs font-semibold uppercase tracking-wide
                {{ $status === 'complete' ? 'text-green-600' : ($status === 'needs_review' ? 'text-amber-600' : 'text-red-600') }}">
                {{ str($status)->replace('_', ' ')->title() }}
            </p>
            <p class="mt-2 text-sm">{{ $reason }}</p>
        </div>

        <button
            wire:click="finish"
            class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
        >
            Finish Mission
        </button>
    @endif
</div>
