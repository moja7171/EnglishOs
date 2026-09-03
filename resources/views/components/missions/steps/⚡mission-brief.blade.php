<?php

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    public ?int $score = null;

    /**
     * A short, optional, ungraded recording of one warm-up question — a
     * real "Day 1" artifact to look back on later (see Mission Result's
     * recap), not just a mood number. Never required, never graded, and
     * never blocks Continue — same philosophy as Listening's shadowing
     * practice.
     */
    public ?UploadedFile $warmUpRecording = null;

    public ?string $savedWarmUpRecordingUrl = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $scoreEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_SCORE)->latest()->first();
        $this->score = $scoreEvidence ? (int) $scoreEvidence->content_ref : null;

        $recordingEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedWarmUpRecordingUrl = $recordingEvidence?->content_ref;
    }

    public function save(): void
    {
        $this->validate([
            'score' => 'required|integer|min:1|max:5',
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'mission_brief',
            'type' => Evidence::TYPE_SCORE,
            'content_ref' => (string) $this->score,
        ]);

        if ($this->warmUpRecording) {
            $path = $this->warmUpRecording->store('missions/'.strtolower($this->run->mission->code).'/evidence', 'public');

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => Storage::disk('public')->url($path),
            ]);
        }

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $brief = $run->mission->stepContent('mission_brief');
    $phases = $run->mission->phases ?? [];
    $totalSteps = $run->mission->stepKeys();
@endphp

<div class="space-y-6">
    <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <p class="font-display text-sm font-semibold text-ink dark:text-ink-dark">{{ $run->mission->outcome }}</p>
    </div>

    {{-- Roadmap: a short, visible journey rather than an open-ended form --}}
    <div class="flex flex-wrap items-center gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
        @foreach ($phases as $phase)
            <span class="rounded-full border border-line px-2.5 py-1 dark:border-line-dark">
                {{ $phase['label'] ?? ucfirst($phase['phase'] ?? '') }}
            </span>
            @if (! $loop->last)
                <span>@svg('heroicon-o-chevron-right', 'inline h-3 w-3')</span>
            @endif
        @endforeach
        <span class="ml-1">· {{ count($totalSteps) }} short steps · ~{{ Mission::formatDuration($run->mission->totalDurationMinutes()) }} total</span>
    </div>

    <x-hook :text="$brief['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Before you start</p>
        <p class="mt-1 text-sm text-ink-faint dark:text-ink-faint-dark">Answer out loud, with no preparation.</p>
        <ul class="mt-3 space-y-2">
            @foreach ($brief['warm_up_questions'] ?? [] as $question)
                <li class="rounded-xl border border-line px-3 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                    {{ $question }}
                </li>
            @endforeach
        </ul>

        @if ($readOnly)
            @if ($savedWarmUpRecordingUrl)
                <div class="mt-3">
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your Day 1 answer</p>
                    <div class="mt-1">
                        <x-audio-player :url="$savedWarmUpRecordingUrl" />
                    </div>
                </div>
            @endif
        @else
            <div class="mt-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Optional — record yourself answering one</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Never graded, never required — just something real to look back on later in this mission.</p>
                <div class="mt-2">
                    <x-voice-recorder field="warmUpRecording" :file="$warmUpRecording" file-name="mission-brief-warmup.webm" />
                </div>
            </div>
        @endif
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Starting score</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
            How comfortable am I talking about this topic right now?
        </p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">We'll compare this to your score at the end of the mission.</p>
        <div class="mt-2 flex gap-2">
            @foreach (range(1, 5) as $value)
                <button
                    type="button"
                    @disabled($readOnly)
                    wire:click="$set('score', {{ $value }})"
                    @class([
                        'h-10 w-10 cursor-pointer rounded-full border text-sm font-semibold transition-colors',
                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $score === $value,
                        'border-line text-ink-soft hover:border-ink-faint dark:border-line-dark dark:text-ink-soft-dark' => $score !== $value,
                    ])
                >{{ $value }}</button>
            @endforeach
        </div>
        @error('score')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    @unless ($readOnly)
        <x-sticky-bar>
            <button
                wire:click="save"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                Continue
            </button>
        </x-sticky-bar>
    @endunless
</div>
