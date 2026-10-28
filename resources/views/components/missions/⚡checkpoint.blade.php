<?php

use App\Models\Mission;
use App\Models\PlacementTest as PlacementTestResult;
use App\Services\PlacementTest;
use App\Services\ProgramPlanner;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * "Your voice, N months in" — S3 of
 * [[project_growth_without_discouragement_stories]]. Reachable only from
 * a checkpoint mission's (M06/M12/M18/M24) consolidation day, and only
 * once per checkpoint mission (see ProgramPlanner::checkpointAvailable()).
 *
 * Deliberately NOT a test in the UI's own language anywhere — same
 * spoken prompt as the placement test, redone, then played back next to
 * the very first recording. The comparison is the reward; the CEFR
 * letter is secondary and small. Never gates anything: the consolidation
 * day already closes via its own "Log it & finish today" (see
 * ⚡overview.blade.php), and this page's own skip link returns there
 * with nothing recorded against the learner at all.
 */
new class extends Component
{
    use WithFileUploads;

    public Mission $mission;

    public $recording;

    public bool $completed = false;

    public ?array $comparison = null;

    public ?PlacementTestResult $newResult = null;

    /**
     * The most recent placement attempt's ID, captured once in mount() —
     * BEFORE this page can possibly create a new one — rather than
     * re-queried live. A live "latest placement test" query evaluated
     * after finish() has already saved the new checkpoint row would
     * return that SAME new row (now the latest by id), comparing a
     * recording against itself. A plain public property is dehydrated
     * and rehydrated by Livewire like any other, so this stays correct
     * across the whole page lifecycle without depending on WHEN it
     * happens to be read.
     */
    public ?int $earlierId = null;

    #[Computed]
    public function test(): PlacementTest
    {
        return app(PlacementTest::class);
    }

    /** The row $earlierId points at — null only if the learner skipped the placement test entirely. */
    public function earlier(): ?PlacementTestResult
    {
        return $this->earlierId ? PlacementTestResult::find($this->earlierId) : null;
    }

    public function mount(Mission $mission): void
    {
        $this->mission = $mission;
        $this->earlierId = auth()->user()->latestPlacementTest()?->id;
    }

    /**
     * Transcribes and grades the new recording exactly like the
     * placement test's own spoken part, saves it as a new
     * PlacementTestResult row (kind=checkpoint), and — when there's an
     * earlier recording to compare against — asks the AI what actually
     * changed between the two transcripts. Every AI step fails soft: a
     * relay outage still saves the new recording, just without the
     * qualitative comparison (see PlacementTest::gradeSpeakingRecording()
     * and ::compareTranscripts()).
     */
    public function finish(): void
    {
        $this->validate(['recording' => 'required|file|max:20480']);

        $path = $this->recording->store('placement', 'public');
        $audioUrl = Storage::disk('public')->url($path);

        [$transcript, $spokenLevel] = $this->test->gradeSpeakingRecording($this->recording->getRealPath());

        $earlier = $this->earlier();
        $recognitionLevel = $earlier?->recognition_level ?? 'A2';
        $level = $this->test->levelForCheckpoint($recognitionLevel, $spokenLevel);

        $this->newResult = PlacementTestResult::create([
            'learner_id' => auth()->id(),
            'kind' => PlacementTestResult::KIND_CHECKPOINT,
            'checkpoint_mission_code' => $this->mission->code,
            'level' => $level,
            'recognition_level' => $recognitionLevel,
            'spoken_level' => $spokenLevel,
            'transcript' => $transcript,
            'audio_url' => $audioUrl,
            'detail' => ['provisional' => $spokenLevel === null],
        ]);

        if ($transcript !== null && $earlier?->transcript) {
            $this->comparison = $this->test->compareTranscripts($earlier->transcript, $transcript);
        }

        // A real level change replaces the standing self-assessment the
        // same way the initial test does — but ONLY a real change. Never
        // silently drop a learner back down if this one recording had an
        // off day; that would be exactly the sting this whole epic
        // exists to avoid.
        if ($spokenLevel !== null && $this->rank($level) > $this->rank(auth()->user()->cefr_level)) {
            auth()->user()->forceFill(['cefr_level' => $level])->save();
        }

        $this->completed = true;
    }

    private function rank(?string $level): int
    {
        return ['A1' => 1, 'A2' => 2, 'A2+' => 2, 'B1' => 3, 'B2' => 4, 'C1' => 5][$level] ?? 0;
    }

    public function skip(): void
    {
        $this->redirect(route('home'), navigate: true);
    }

    public function done(): void
    {
        $this->redirect(route('home'), navigate: true);
    }
};
?>

@php
    $earlier = $this->earlier();
    $speaking = $this->test->speaking();
    $missionsIn = $this->mission->number();
    $cta = 'block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark';
    $voiceLabel = 'text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark';
@endphp

{{-- Two lesson-style screens: say the first question again, then hear both
     voices. No score, no gate — ✕ and Skip both go back to today with
     nothing recorded. --}}
<div class="mx-auto max-w-xl p-4 sm:p-6" data-step-header>
    @if ($completed)
        <section class="flex min-h-[calc(100dvh-9rem)] flex-col gap-5 pt-8">
            <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">You, then and now</h1>

            <div class="space-y-3">
                @if ($earlier?->audio_url)
                    <div>
                        <p class="{{ $voiceLabel }} mb-1.5">Placement test</p>
                        <x-audio-player :url="$earlier->audio_url" />
                    </div>
                @endif
                <div>
                    <p class="{{ $voiceLabel }} mb-1.5">Today</p>
                    <x-audio-player :url="$newResult->audio_url" />
                </div>
            </div>

            @if ($comparison)
                <div class="rounded-2xl bg-success-soft p-4 dark:bg-success-soft-dark">
                    <p class="text-xs font-extrabold tracking-wide text-success uppercase dark:text-success-dark">What's different</p>
                    <ul class="mt-1.5 space-y-1 text-sm leading-6 text-ink dark:text-ink-dark">
                        @foreach (array_slice($comparison['observations'], 0, 3) as $observation)
                            <li wire:key="observation-{{ $loop->index }}">• {{ $observation }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 border-t border-success/20 pt-2 text-sm text-ink-soft dark:border-success-dark/20 dark:text-ink-soft-dark">
                        <span class="font-bold text-ink dark:text-ink-dark">Next focus:</span> {{ $comparison['focus'] }}
                    </p>
                </div>
            @elseif (! $earlier?->transcript)
                <p class="rounded-2xl bg-surface-sunken p-3 text-center text-sm text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">There's nothing to compare this one to yet — next time you'll hear the difference.</p>
            @else
                {{-- The AI comparison failed (relay hiccup): the recording is
                     saved, just no commentary — never a bare error, which
                     would read as a bad result. --}}
                <p class="rounded-2xl bg-surface-sunken p-3 text-center text-sm text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Both recordings are saved. We couldn't compare them just now.</p>
            @endif

            <div class="flex-1"></div>
            <button type="button" wire:click="done" wire:loading.attr="disabled" wire:target="done" class="{{ $cta }}">Done</button>
        </section>
    @else
        <div x-data="{ recorded: false }">
            <x-lesson-header :close="route('home')" :progress="0.4" />

            <section class="flex min-h-[calc(100dvh-10rem)] flex-col gap-4 pt-6">
                <span class="self-start rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Your voice, {{ $missionsIn }} missions in · ~60s</span>
                <p class="text-2xl leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $speaking['prompt'] }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($speaking['bullets'] as $bullet)
                        <span wire:key="bullet-{{ $loop->index }}" class="rounded-full bg-surface px-3 py-1 text-xs font-bold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">{{ $bullet }}</span>
                    @endforeach
                </div>

                <div class="flex flex-1 items-center justify-center" x-on:click="recorded = true">
                    <x-voice-recorder big field="recording" :file="$recording" file-name="checkpoint.webm" />
                </div>

                <div class="space-y-3 pb-2">
                    <x-continue-button on-click="$wire.finish()" wire-target="finish" loading-label="Listening…" ready-when="recorded" />
                    <button type="button" wire:click="skip" class="block w-full cursor-pointer py-1 text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
                </div>
            </section>
        </div>
    @endif
</div>
