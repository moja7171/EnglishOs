<?php

use App\Livewire\Concerns\ChecksShadowLines;
use App\Livewire\Concerns\KeepsShadowDrafts;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use ChecksShadowLines;
    use KeepsShadowDrafts;
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a short recap before the learner navigates on,
     * same pattern as Listening/Activation.
     */
    public bool $completed = false;

    /** @var array<int, ?UploadedFile> keyed by shadow_lines index — only the upload slot, see KeepsShadowDrafts */
    public array $shadowRecordings = [];

    /** @var array<int, string> keyed by shadow_lines index — saved recording URLs, for read-only review */
    public array $savedShadowUrls = [];

    /**
     * This step's whole point is pronunciation practice — earlier this
     * required only 1 line plus two AI-checked comprehension sentences,
     * which made it structurally identical to Listening's gist/expression
     * shape (see EOS-009 §7 v3.16 note). Dropping the AI-checked writing
     * entirely and requiring more real shadowing instead keeps the two
     * steps genuinely different in form, not just in source material.
     * Each mission seeds a pool of lines to choose from (6), so the learner
     * records the ones they like — at least this many.
     */
    private const REQUIRED_SHADOWED_LINES = 3;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreShadowDraftsWithFeedback();

            return;
        }

        foreach ($this->run->evidence()->where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->get() as $audio) {
            $decoded = json_decode($audio->content_ref, true);

            if (is_array($decoded) && isset($decoded['line_index'], $decoded['url'])) {
                $this->savedShadowUrls[$decoded['line_index']] = $decoded['url'];
            }
        }
    }

    /**
     * @return list<string>
     */
    public function shadowLines(): array
    {
        return $this->run->mission->stepContent('video_shadowing')['shadow_lines'] ?? [];
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int}>
     */
    public function comprehensionCards(): array
    {
        $items = $this->run->mission->stepContent('video_shadowing')['comprehension_check'] ?? [];

        return collect($items)
            ->map(fn ($item) => ['prompt' => $item['statement'], 'options' => ['True', 'False'], 'correct' => $item['correct'] ? 0 : 1])
            ->all();
    }

    /**
     * Real caption-derived pause points, parallel to shadow_lines by
     * index — see missions:cache-shadow-timestamps.
     *
     * @return list<array{start: float, end: float}|null>
     */
    public function shadowTimestamps(): array
    {
        return $this->run->mission->stepContent('video_shadowing')['shadow_timestamps'] ?? [];
    }

    /**
     * Recorded lines, minus any the AI heard as a different sentence (see
     * ChecksShadowLines) — a line not checked yet still counts.
     */
    public function shadowedCount(): int
    {
        return collect($this->draftShadowUrls)
            ->keys()
            ->reject(fn (int $index) => ($this->feedback["shadow_{$index}"]['severity'] ?? null) === 'major')
            ->count();
    }

    /** Exposed for the Blade template — a bare `self::CONST` isn't reachable there. */
    public function requiredShadowedLines(): int
    {
        return self::REQUIRED_SHADOWED_LINES;
    }

    public function save(): void
    {
        if ($this->shadowedCount() < self::REQUIRED_SHADOWED_LINES) {
            $this->addError('shadowRecordings', 'Shadow at least '.self::REQUIRED_SHADOWED_LINES.' lines before continuing.');

            return;
        }

        $shadowedLineIndices = collect($this->draftShadowUrls)->keys()->sort()->values();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => $this->phaseKey(),
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'shadowed_line_indices' => $shadowedLineIndices,
            ]),
        ]);

        $this->saveShadowEvidence();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    public function proceed(): void
    {
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    protected function phaseKey(): string
    {
        return 'video_shadowing';
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:video_shadowing:";
    }
};
?>

@php
    $video = $run->mission->stepContent('video_shadowing');
    $shadowLines = $video['shadow_lines'] ?? [];
    $targetPhrases = $video['target_phrases'] ?? [];
    $listenCount = $run->learner->audioListenCount($run->mission->code, \App\Models\AudioListen::SOURCE_VIDEO_SHADOWING);
    // Two focused sub-steps instead of one long scroll (EOS-009 §8's
    // UI/UX review) — the first watch (just understand it) and the quick
    // check, then the second watch where the video pauses at each line
    // and the learner shadows it. Shadowing is never offered on the first
    // watch and is not a setting: the player only pauses on part 2.
    $totalSubsteps = 2;
    $draftPrefix = $this->draftPrefix();
@endphp

<div
    class="space-y-6"
    x-data="{
        activeSubstep: 0,
        init() {
            this.activeSubstep = window.eosDraft.restoreIndex('{{ $draftPrefix }}activeSubstep', 0);
            this.$watch('activeSubstep', (v) => window.eosDraft.persistIndex('{{ $draftPrefix }}activeSubstep', v));
        },
    }"
>
    <x-hook :text="$video['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $video['source'] ?? 'Video' }}</p>
        <div class="mt-2">
            <x-video-player
                text-hidden
                :url="$video['video_url'] ?? ''"
                :listen="$preview ? null : ['mission_code' => $run->mission->code, 'source' => \App\Models\AudioListen::SOURCE_VIDEO_SHADOWING, 'manual_report' => true]"
                :captions-url="$video['captions_url'] ?? null"
                :title="$video['source'] ?? 'Video'"
                :segments="$video['video_segments'] ?? []"
                :shadow-lines="$readOnly ? [] : $shadowLines"
                :shadow-timestamps="$readOnly ? [] : $this->shadowTimestamps()"
                shadow-active="activeSubstep === 1"
                :resume-key="$readOnly || $preview ? null : $draftPrefix.'position'"
            />
        </div>
    </div>

    @if ($completed)
        <div class="space-y-4 card p-4">
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Video Shadowing complete
            </p>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">You've watched this video {{ $listenCount }} {{ Str::plural('time', $listenCount) }} so far.</p>

            <button
                wire:click="proceed"
                wire:loading.attr="disabled"
                wire:target="proceed"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="proceed">Continue</span>
                <span wire:loading wire:target="proceed">Please wait…</span>
            </button>
        </div>
    @else
        <div class="mb-2">
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    :style="`width: ${(activeSubstep + 1) / {{ $totalSubsteps }} * 100}%`"
                ></div>
                <x-slot:label>
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                        Part <span x-text="activeSubstep + 1"></span> of {{ $totalSubsteps }}
                    </p>
                </x-slot:label>
            </x-progress-bar>
        </div>

        <div wire:loading.class="pointer-events-none" wire:target="save">
            {{-- Sub-step 1, the first watch: just understand the video —
                 a light check on it, then extra vocabulary. Nothing here
                 is required, and the player does not pause for shadowing. --}}
            <div x-show="activeSubstep === 0" x-cloak class="space-y-6">
                @unless ($readOnly)
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">First watch: just follow along.</p>

                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                        <p class="text-xs text-ink-soft dark:text-ink-soft-dark">True or false — just a warm-up, skip anytime.</p>
                        <div class="mt-2">
                            <x-quick-round :cards="$this->comprehensionCards()" />
                        </div>
                    </div>
                @endunless

                @if (count($targetPhrases))
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Expressions to notice</p>
                        <ul class="mt-2 divide-y divide-line card-sunken px-4 dark:divide-line-dark">
                            @foreach ($targetPhrases as $item)
                                <li class="py-2.5">
                                    <p x-lookup class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $item['phrase'] }}</p>
                                    @if (filled($item['meaning'] ?? null))
                                        <p class="text-xs text-ink-soft dark:text-ink-soft-dark">{{ $item['meaning'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Sub-step 2, the second watch: the video restarts and pauses
                 at each line (see <x-video-player>'s shadow-active); one
                 full recorder per line below. --}}
            <div x-show="activeSubstep === 1" x-cloak>
                @if (count($shadowLines))
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Shadow the lines</p>
                        @unless ($readOnly)
                            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                                It pauses after each line — say it, then record any {{ $this->requiredShadowedLines() }} of {{ count($shadowLines) }}.
                            </p>
                            <p class="mt-2 flex items-start gap-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">
                                @svg('heroicon-o-light-bulb', 'mt-px h-3.5 w-3.5 shrink-0')
                                Bold = stressed.
                            </p>
                        @endunless

                        <div class="mt-3 space-y-3">
                            @foreach ($shadowLines as $index => $line)
                                <div class="card-sunken p-4">
                                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Line {{ $index + 1 }}</p>
                                    <p x-lookup class="mt-1 text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

                                    @if ($readOnly)
                                        @if ($url = $savedShadowUrls[$index] ?? null)
                                            <div class="mt-2"><x-audio-player :url="$url" /></div>
                                        @else
                                            <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                                        @endif
                                    @else
                                        <div class="mt-2" wire:key="shadow-recorder-{{ $index }}">
                                            <x-voice-recorder
                                                field="shadowRecordings.{{ $index }}"
                                                :file="$draftShadowUrls[$index] ?? null"
                                                file-name="video-shadow-{{ $index }}.webm"
                                                on-recorded="checkShadowLine"
                                                :on-recorded-param="$index"
                                            />
                                        </div>
                                        <x-shadow-line-check :index="$index" :feedback="$feedback['shadow_'.$index] ?? null" :error="$checkErrors['shadow_'.$index] ?? null" />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @error('shadowRecordings')
                            <p class="mt-2 text-sm text-danger-ink">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- Always on screen, disabled until done. shadowedCount()
                     only advances once an upload genuinely completes, so
                     readiness is known server-side and goes into
                     ready-when as a literal. Having watched is never a
                     requirement — the recordings are the evidence. --}}
                @unless ($readOnly)
                    @php
                        $shadowedEnough = $this->shadowedCount() >= $this->requiredShadowedLines();
                    @endphp
                    <div class="mt-4">
                        <x-continue-button
                            on-click="$wire.save()"
                            wire-target="save"
                            loading-label="Saving…"
                            ready-when="{{ $shadowedEnough ? 'true' : 'false' }}"
                            hint="Record {{ $this->requiredShadowedLines() }} shadowed {{ Str::plural('line', $this->requiredShadowedLines()) }} to continue"
                        />
                    </div>
                @endunless
            </div>
        </div>

        <div class="mt-4">
            <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" />
        </div>
    @endif
</div>
