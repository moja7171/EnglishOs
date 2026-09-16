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

<div class="mx-auto max-w-2xl space-y-6 p-6">
    @php $earlier = $this->earlier(); @endphp
    @if ($completed)
        <div class="space-y-5 rounded-2xl border border-line bg-surface p-6 dark:border-line-dark dark:bg-surface-dark">
            <div class="text-center">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Your voice, {{ $this->mission->code }} in</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Same question you answered before. Listen to both.</p>
            </div>

            @if ($earlier?->audio_url)
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="mb-1.5 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Then</p>
                        <x-audio-player :url="$earlier->audio_url" />
                    </div>
                    <div>
                        <p class="mb-1.5 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Now</p>
                        <x-audio-player :url="$newResult->audio_url" />
                    </div>
                </div>
            @else
                {{-- No earlier recording to play (the placement test was
                     skipped, or predates audio_url) — still show today's,
                     honestly, without pretending a comparison exists. --}}
                <x-audio-player :url="$newResult->audio_url" />
            @endif

            @if ($comparison)
                <div class="rounded-xl bg-success-soft p-4 dark:bg-success-soft-dark">
                    <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">What's different</p>
                    <ul class="mt-1.5 space-y-1 text-sm text-ink dark:text-ink-dark">
                        @foreach ($comparison['observations'] as $observation)
                            <li>{{ $observation }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-2 border-t border-success/20 pt-2 text-sm text-ink-soft dark:border-success-dark/20 dark:text-ink-soft-dark">
                        <span class="font-semibold text-ink dark:text-ink-dark">Next focus:</span> {{ $comparison['focus'] }}
                    </p>
                </div>
            @elseif (! $earlier?->transcript)
                <p class="rounded-xl bg-surface-sunken p-3 text-center text-sm text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                    There's nothing to compare this one to yet — this is the first recording saved for you. Next time, this is where you'll hear the difference.
                </p>
            @else
                {{-- AI comparison failed (relay hiccup) — the recording is
                     still saved; just no commentary right now. Never a
                     bare error here, which would read as a bad result. --}}
                <p class="rounded-xl bg-surface-sunken p-3 text-center text-sm text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                    Both recordings are saved. We couldn't compare them just now — you can always come back and listen from your Progress page.
                </p>
            @endif

            <button
                type="button"
                wire:click="done"
                wire:loading.attr="disabled"
                wire:target="done"
                class="w-full cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
            >Back to today</button>
        </div>
    @else
        <div x-data="{ recorded: false }">
            <header class="border-b border-line pb-4 dark:border-line-dark">
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Your voice, {{ $this->mission->code }} in</p>
                <h1 class="mt-0.5 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Answer it again</h1>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">The exact same question you answered when you started — then you'll hear both side by side.</p>
            </header>

            <div class="mt-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                @php $speaking = $this->test->speaking(); @endphp
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Speak for about {{ $speaking['seconds'] }} seconds</p>
                <p class="mt-1 text-lg font-semibold text-ink dark:text-ink-dark">{{ $speaking['prompt'] }}</p>
                <ul class="mt-2 list-disc space-y-0.5 pl-5 text-sm text-ink-soft dark:text-ink-soft-dark">
                    @foreach ($speaking['bullets'] as $bullet)
                        <li>{{ $bullet }}</li>
                    @endforeach
                </ul>

                <div class="mt-3" x-on:click="recorded = true">
                    <x-voice-recorder field="recording" :file="$recording" file-name="checkpoint.webm" />
                </div>
            </div>

            <x-continue-button
                on-click="$wire.finish()"
                wire-target="finish"
                loading-label="Listening…"
                ready-when="recorded"
                hint="Record your answer to see the comparison"
                class="mt-4"
            />

            <p class="mt-3 text-center text-xs text-ink-faint dark:text-ink-faint-dark">
                <button type="button" wire:click="skip" class="cursor-pointer underline hover:text-ink dark:hover:text-ink-dark">Skip this — take me back to today</button>
            </p>
        </div>
    @endif
</div>
