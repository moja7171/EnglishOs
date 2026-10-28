<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use KeepsStepDraft;
    use PreviewsStep;
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

    /** The kept warm-up recording's path on the public disk, until the step is saved — see updatedWarmUpRecording(). */
    #[Locked]
    public ?string $warmUpRecordingPath = null;

    public ?string $savedWarmUpRecordingUrl = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        $scoreEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_SCORE)->latest()->first();
        $this->score = $scoreEvidence ? (int) $scoreEvidence->content_ref : null;

        $recordingEvidence = $this->run->evidence()->where('phase', 'mission_brief')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedWarmUpRecordingUrl = $recordingEvidence?->content_ref;
    }

    /**
     * The mission's cover image — dual-coding, same principle as every
     * other PexelsClient call. Cached per mission (not per learner), fails
     * soft (null) on no query/no key/any error.
     */
    #[Computed]
    public function heroImageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('mission_brief')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-brief', $query);
    }

    protected function draftPhase(): string
    {
        return 'mission_brief';
    }

    protected function draftedProperties(): array
    {
        return ['score', 'warmUpRecordingPath'];
    }

    public function updatedScore(): void
    {
        $this->keepStepDraft();
    }

    /**
     * The take is stored (and kept in the step's draft) the moment its
     * upload finishes, so leaving before Continue never loses it.
     */
    public function updatedWarmUpRecording(): void
    {
        $this->validate([
            'warmUpRecording' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $this->warmUpRecordingPath = $this->keepDraftRecording($this->warmUpRecording, $this->warmUpRecordingPath);
        $this->warmUpRecording = null;

        $this->keepStepDraft();
    }

    public function warmUpRecordingUrl(): ?string
    {
        return $this->warmUpRecordingPath === null ? null : Storage::disk('public')->url($this->warmUpRecordingPath);
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

        if ($this->warmUpRecordingPath) {
            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'mission_brief',
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => $this->warmUpRecordingUrl(),
            ]);
        }

        $this->forgetStepDraft();

        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }
};
?>

@php
    $brief = $run->mission->stepContent('mission_brief');
    $firstQuestion = $brief['warm_up_questions'][0] ?? null;
    $scoreChoices = [
        1 => ['😣', 'Very hard'],
        2 => ['😕', 'Hard'],
        3 => ['😐', 'Okay'],
        4 => ['🙂', 'Easy'],
        5 => ['😄', 'Very easy'],
    ];
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-5 pt-2">
        <x-step-intro kind="Get Ready" :title="$brief['hook'] ?? null" :image="$this->heroImageUrl" />

        @if ($firstQuestion)
            <p class="text-lg font-bold text-ink dark:text-ink-dark">{{ $firstQuestion }}</p>
        @endif

        @if ($savedWarmUpRecordingUrl)
            <div>
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Your Day 1 answer</p>
                <div class="mt-1"><x-audio-player :url="$savedWarmUpRecordingUrl" /></div>
            </div>
        @endif

        <div class="grid grid-cols-5 gap-1.5">
            @foreach ($scoreChoices as $value => [$face, $label])
                <span @class([
                    'flex flex-col items-center gap-1 rounded-2xl px-1 py-2 text-center text-[11px] font-bold',
                    'bg-accent text-white dark:bg-accent-dark' => $score === $value,
                    'bg-surface text-ink-faint dark:bg-surface-dark dark:text-ink-faint-dark' => $score !== $value,
                ])><span class="text-xl">{{ $face }}</span>{{ $label }}</span>
            @endforeach
        </div>
    </div>
@else
    {{-- Three screens, one thing each: the hook, the first warm-up
         question said out loud (the take comes back at Mission Result,
         next to the Final Talk), and the starting comfort score. --}}
    <div
        x-data="{ screen: {{ $score !== null ? 2 : ($warmUpRecordingPath ? 1 : 0) }} }"
        x-effect="$dispatch('step-progress', { share: (screen + 0.5) / 3 })"
        x-back-close="{ open: screen > 0, close: () => screen-- }"
    >
        <x-lesson-screen when="screen === 0">
            <x-step-intro kind="Get Ready" :minutes="$run->mission->stepDuration('mission_brief')" :title="$brief['hook'] ?? null" :image="$this->heroImageUrl" centered />

            <x-slot:actions>
                <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
            </x-slot:actions>
        </x-lesson-screen>

        <x-lesson-screen when="screen === 1">
            <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Say it out loud</span>
            <p class="text-xl leading-snug font-bold text-ink dark:text-ink-dark">{{ $firstQuestion }}</p>
            <div class="mt-4 flex justify-center">
                <x-voice-recorder big field="warmUpRecording" :file="$this->warmUpRecordingUrl()" file-name="mission-brief-warmup.webm" />
            </div>
            @if ($warmUpRecordingPath)
                <p class="text-center text-sm text-ink-faint dark:text-ink-faint-dark">You'll hear this again at the end.</p>
            @endif

            <x-slot:actions>
                <x-continue-button on-click="screen = 2" wire-target="save" ready-when="{{ $warmUpRecordingPath ? 'true' : 'false' }}" />
                <button type="button" x-on:click="screen = 2" class="cursor-pointer text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
            </x-slot:actions>
        </x-lesson-screen>

        <x-lesson-screen when="screen === 2">
            <p class="text-xl leading-snug font-bold text-ink dark:text-ink-dark">How easy is it to talk about this now?</p>
            <div class="flex flex-col gap-2">
                @foreach ($scoreChoices as $value => [$face, $label])
                    <button
                        type="button"
                        wire:click="$set('score', {{ $value }})"
                        @class([
                            'flex cursor-pointer items-center gap-3 rounded-2xl border-2 px-4 py-3 text-left text-base font-bold transition-colors',
                            'border-accent bg-accent-soft text-accent-ink dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-ink-dark' => $score === $value,
                            'border-transparent bg-surface text-ink dark:bg-surface-dark dark:text-ink-dark' => $score !== $value,
                        ])
                    ><span class="text-2xl">{{ $face }}</span>{{ $label }}</button>
                @endforeach
            </div>
            @error('score')
                <p class="text-sm text-danger-ink">{{ $message }}</p>
            @enderror

            <x-slot:actions>
                <x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Saving…" ready-when="{{ $score !== null ? 'true' : 'false' }}" />
            </x-slot:actions>
        </x-lesson-screen>
    </div>
@endif
