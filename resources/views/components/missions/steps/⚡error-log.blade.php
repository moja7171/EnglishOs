<?php

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    /** @var array<int, array{error: string, correction: string}> */
    public array $mistakes = [];

    /** @var array<int, string> */
    public array $newExamples = [];

    public bool $loading = false;

    public ?string $error = null;

    public function mount(): void
    {
        $this->generate();
    }

    public function generate(): void
    {
        $this->error = null;
        $this->loading = true;

        try {
            $text = $this->gatherLearnerText();

            if (trim($text) === '') {
                $this->mistakes = [];

                return;
            }

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $text]],
                systemPrompt: 'You are an English teacher reviewing everything a B1 learner said and wrote during a '
                    .'lesson. Identify 3 to 5 recurring or notable grammar/vocabulary mistakes. Reply with ONLY a valid '
                    .'JSON array, no markdown fences, no extra text, each item shaped exactly like: '
                    .'{"error": "the mistake as the learner wrote/said it", "correction": "the corrected form"}. '
                    .'If the learner made no real mistakes, reply with an empty JSON array: []'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->mistakes = $data;
            $this->newExamples = array_fill(0, count($data), '');
        } catch (\Throwable $e) {
            $this->error = "Couldn't get the error log from the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->loading = false;
        }
    }

    public function save(): void
    {
        if (empty($this->mistakes)) {
            $this->finishWithoutErrors();

            return;
        }

        $incomplete = collect($this->newExamples)->filter(fn ($e) => trim((string) $e) === '')->isNotEmpty();

        if ($incomplete) {
            $this->addError('newExamples', 'Write a new correct sentence for every error before continuing.');

            return;
        }

        foreach ($this->mistakes as $i => $item) {
            ErrorLogItem::create([
                'mission_run_id' => $this->run->id,
                'error' => $item['error'],
                'correction' => $item['correction'],
                'new_example' => trim($this->newExamples[$i]),
            ]);
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode($this->mistakes),
        ]);

        $this->redirect(route('missions.show', $this->run->mission));
    }

    private function finishWithoutErrors(): void
    {
        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission));
    }

    private function gatherLearnerText(): string
    {
        $pieces = [];

        if ($conv1 = $this->run->evidence()->where('phase', 'ai_conversation_1')->latest()->first()) {
            $turns = json_decode($conv1->content_ref, true) ?? [];
            $pieces[] = collect($turns)->pluck('answer')->implode(' ');
        }

        if ($conv2 = $this->run->evidence()->where('phase', 'ai_conversation_2')->latest()->first()) {
            $data = json_decode($conv2->content_ref, true) ?? [];
            $pieces[] = collect($data['rounds'] ?? [])->pluck('answer')->implode(' ');
            $pieces[] = $data['final_transcript'] ?? '';
        }

        if ($writing = $this->run->evidence()->where('phase', 'writing')->latest()->first()) {
            $pieces[] = $writing->content_ref;
        }

        return implode("\n\n", array_filter($pieces));
    }
};
?>

<div class="space-y-6">
    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Error Log</p>
        <p class="text-xs text-neutral-500">Correct your most common mistakes with a new example.</p>
    </div>

    @if ($loading)
        <p class="text-sm text-neutral-500">Reviewing everything you said and wrote…</p>
    @elseif ($error)
        <div class="rounded border border-red-300 p-3 text-sm text-red-600">
            {{ $error }}
            <button wire:click="generate" class="mt-2 block underline">Try again</button>
        </div>
    @elseif (empty($mistakes))
        <div class="rounded border border-neutral-300 p-3 text-sm dark:border-neutral-700">
            No recurring mistakes found — nice work!
        </div>
        <button wire:click="save" class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">
            Continue
        </button>
    @else
        <div class="space-y-4">
            @foreach ($mistakes as $i => $item)
                <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                    <p class="text-sm text-red-600 line-through decoration-red-500">{{ $item['error'] }}</p>
                    <p class="mt-1 text-sm text-green-600">{{ $item['correction'] }}</p>
                    <input
                        type="text"
                        wire:model="newExamples.{{ $i }}"
                        placeholder="Write a new sentence using the correct form…"
                        class="mt-2 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
                    >
                </div>
            @endforeach
        </div>

        @error('newExamples')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        <button wire:click="save" class="rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900">
            Continue
        </button>
    @endif
</div>
