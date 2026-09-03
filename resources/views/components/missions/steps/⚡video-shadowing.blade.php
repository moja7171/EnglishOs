<?php

use App\Models\Evidence;
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

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a short recap before the learner navigates on,
     * same pattern as Listening/Activation.
     */
    public bool $completed = false;

    /**
     * Self-reported — a YouTube embed has no reliable native "ended"
     * event the way Listening's <audio> element does (see its real
     * listenCount, driven by the "ended" event), so this is an honest
     * checkbox instead of a fabricated automatic watch-count.
     */
    public bool $watchedWithCaptions = false;

    public bool $watchedWithoutCaptions = false;

    /** @var array<int, ?UploadedFile> keyed by shadow_lines index */
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
     */
    private const REQUIRED_SHADOWED_LINES = 2;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('video_shadowing')?->content_ref ?? '{}', true);
        $this->watchedWithCaptions = $data['watched_with_captions'] ?? false;
        $this->watchedWithoutCaptions = $data['watched_without_captions'] ?? false;

        foreach ($this->run->evidence()->where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->get() as $audio) {
            $decoded = json_decode($audio->content_ref, true);

            if (is_array($decoded) && isset($decoded['line_index'], $decoded['url'])) {
                $this->savedShadowUrls[$decoded['line_index']] = $decoded['url'];
            }
        }
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

    public function shadowedCount(): int
    {
        return collect($this->shadowRecordings)->filter()->count();
    }

    /** Exposed for the Blade template — a bare `self::CONST` isn't reachable there. */
    public function requiredShadowedLines(): int
    {
        return self::REQUIRED_SHADOWED_LINES;
    }

    public function save(): void
    {
        if (! $this->watchedWithCaptions || ! $this->watchedWithoutCaptions) {
            $this->addError('watched', 'Watch the video with captions on, then again with captions off, before continuing.');

            return;
        }

        if ($this->shadowedCount() < self::REQUIRED_SHADOWED_LINES) {
            $this->addError('shadowRecordings', 'Shadow at least '.self::REQUIRED_SHADOWED_LINES.' lines before continuing.');

            return;
        }

        $mission = $this->run->mission;

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'watched_with_captions' => $this->watchedWithCaptions,
                'watched_without_captions' => $this->watchedWithoutCaptions,
                'shadowed_line_indices' => collect($this->shadowRecordings)->filter()->keys()->values(),
            ]),
        ]);

        // One AUDIO Evidence row per shadowed line — content_ref is JSON
        // here (unlike most other steps' plain-URL audio Evidence) since
        // this step can produce more than one recording; line_index is
        // what lets mount() map each saved file back to its line on review.
        foreach ($this->shadowRecordings as $index => $recording) {
            if (! $recording) {
                continue;
            }

            $path = $recording->store('missions/'.strtolower($mission->code).'/evidence', 'public');
            $url = Storage::disk('public')->url($path);

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'video_shadowing',
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => json_encode(['line_index' => $index, 'url' => $url]),
            ]);

            $this->savedShadowUrls[$index] = $url;
        }

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
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
    // Two focused sub-steps instead of one long scroll (EOS-009 §8's
    // UI/UX review) — watch/setup first, the actual shadowing (a full
    // voice-recorder per line) second, so the recorders never bury the
    // watch checkboxes and quick check below the fold.
    $totalSubsteps = 2;
@endphp

<div class="space-y-6" x-data="{ activeSubstep: 0 }">
    <x-hook :text="$video['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $video['source'] ?? 'Video' }}</p>
        <div class="mt-2">
            <x-youtube-embed :video-id="$video['video_id'] ?? ''" :title="$video['source'] ?? 'Video'" />
        </div>
        <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Watch once with English captions on (tap CC in the player) — get the gist in your own time. Then watch part of it again with captions off, and see how much you can catch by ear alone.</p>
    </div>

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Video Shadowing complete
            </p>

            <button
                wire:click="proceed"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                Continue
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
            {{-- Sub-step: quick check, watch checkboxes, expressions to notice --}}
            <div x-show="activeSubstep === 0" x-cloak class="space-y-6">
                @unless ($readOnly)
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Quick check</p>
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">True or false — just a warm-up, skip anytime.</p>
                        <div class="mt-2">
                            <x-quick-round :cards="$this->comprehensionCards()" />
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-soft dark:text-ink-soft-dark">
                            <input
                                type="checkbox"
                                wire:model="watchedWithCaptions"
                                class="h-4 w-4 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                            >
                            I watched with captions on
                        </label>
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-ink-soft dark:text-ink-soft-dark">
                            <input
                                type="checkbox"
                                wire:model="watchedWithoutCaptions"
                                class="h-4 w-4 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                            >
                            I watched again with captions off
                        </label>
                    </div>
                    @error('watched')
                        <p class="text-sm text-red-600">{{ $message }}</p>
                    @enderror
                @endunless

                @if (count($targetPhrases))
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Expressions to notice</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($targetPhrases as $item)
                                <span
                                    title="{{ $item['meaning'] }}"
                                    class="rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark"
                                >{{ $item['phrase'] }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Sub-step: the actual shadowing — one full recorder per line --}}
            <div x-show="activeSubstep === 1" x-cloak>
                @if (count($shadowLines))
                    <div>
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Shadow the lines</p>
                        @unless ($readOnly)
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                                Replay just that moment and repeat it out loud until your rhythm matches.
                                Shadow at least {{ $this->requiredShadowedLines() }} of the {{ count($shadowLines) }} lines below
                                ({{ $this->shadowedCount() }} done so far).
                            </p>
                        @endunless

                        <div class="mt-2 space-y-3">
                            @foreach ($shadowLines as $index => $line)
                                <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Line {{ $index + 1 }}</p>
                                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>
                                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Bold words are usually stressed — try to make them a little longer and louder than the rest.</p>

                                    @if ($readOnly)
                                        @if ($url = $savedShadowUrls[$index] ?? null)
                                            <div class="mt-2"><x-audio-player :url="$url" /></div>
                                        @else
                                            <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                                        @endif
                                    @else
                                        <div class="mt-2" wire:key="shadow-recorder-{{ $index }}">
                                            <x-voice-recorder field="shadowRecordings.{{ $index }}" :file="$shadowRecordings[$index] ?? null" file-name="video-shadow-{{ $index }}.webm" />
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @error('shadowRecordings')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                @unless ($readOnly)
                    <div class="mt-4">
                        <x-continue-button
                            on-click="$wire.save()"
                            wire-target="save"
                            loading-label="Saving…"
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
