<?php

use App\Livewire\Concerns\ChecksShadowLines;
use App\Livewire\Concerns\KeepsShadowDrafts;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Video Shadowing, one task per screen (lesson design 7): watch once with
 * one 🎯 line, the true/false cards one per screen, the video's
 * expressions on one screen (they join My Words by themselves), then each
 * line on its own screen — that bit of the video plays, then the big mic.
 */
new class extends Component
{
    use ChecksShadowLines;
    use KeepsShadowDrafts;
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

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
            ->map(fn ($item) => ['prompt' => $item['statement'], 'options' => ['True', 'False'], 'correct' => $item['correct'] ? 0 : 1, 'label' => 'True or false?'])
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

        // The video's expressions join My Words by themselves (lesson design 7).
        $this->initWordsToTrack();
        $this->addWordsToNotebook();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->run->mission->stepContent('video_shadowing')['target_phrases'] ?? [])
            ->filter(fn ($item) => filled($item['phrase'] ?? null))
            ->map(fn ($item) => ['word' => $item['phrase'], 'meaning' => $item['meaning'] ?? ''])
            ->values()
            ->all();
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
    $draftPrefix = $this->draftPrefix();
    $cards = $readOnly ? [] : $this->comprehensionCards();
    // Screens: 0 start · 1 watch · 2 true/false · 3 expressions · 4.. one line each.
    $quizScreen = count($cards) ? 2 : null;
    $phrasesScreen = count($targetPhrases) ? 3 : null;
    $firstLineScreen = 4;
    $lineCount = count($shadowLines);
    $screenCount = $firstLineScreen + $lineCount;
    $afterWatch = $quizScreen ?? $phrasesScreen ?? $firstLineScreen;
    $afterQuiz = $phrasesScreen ?? $firstLineScreen;
    $shadowedEnough = $this->shadowedCount() >= $this->requiredShadowedLines();
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Video Shadowing" :title="$video['hook'] ?? null" />
        @foreach ($shadowLines as $index => $line)
            <div class="flex flex-col gap-2 rounded-2xl bg-surface p-4 dark:bg-surface-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">“<x-stress-marked-line :text="$line" />”</p>
                @if ($url = $savedShadowUrls[$index] ?? null)
                    <x-audio-player :url="$url" />
                @else
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                @endif
            </div>
        @endforeach
    </div>
@else
<div
    x-data="{
        screen: 0,
        quizShare: 0,
        clipEnd: null,
        points: {{ \Illuminate\Support\Js::from($this->shadowTimestamps()) }},
        init() {
            this.screen = window.eosDraft.restoreIndex('{{ $draftPrefix }}screen', 0);
            this.$watch('screen', (v) => {
                window.eosDraft.persistIndex('{{ $draftPrefix }}screen', v);
                if (v >= {{ $firstLineScreen }}) this.$nextTick(() => this.playLine(v - {{ $firstLineScreen }}));
            });
        },
        playLine(index) {
            const point = this.points[index];
            const clip = this.$refs.clip;
            if (! point || ! clip) return;
            this.clipEnd = point.end;
            clip.currentTime = point.start;
            clip.play().catch(() => {});
        },
        get share() { return (this.screen + (this.screen === 2 ? this.quizShare : 0.5)) / {{ $screenCount }} },
    }"
    x-effect="$dispatch('step-progress', { share }); if (screen !== 1) $el.querySelectorAll('[data-watch] video').forEach((v) => v.pause()); if (screen < {{ $firstLineScreen }}) $refs.clip?.pause()"
    x-on:quick-round-progress="quizShare = $event.detail.share"
    x-back-close="{ open: screen > 0, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Video Shadowing" :minutes="$run->mission->stepDuration('video_shadowing')" :title="$video['hook'] ?? null" />
        @if (filled($video['source'] ?? null))
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $video['source'] }}</p>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === 1">
        <div data-watch>
            <x-video-player
                text-hidden
                :url="$video['video_url'] ?? ''"
                :listen="$preview ? null : ['mission_code' => $run->mission->code, 'source' => \App\Models\AudioListen::SOURCE_VIDEO_SHADOWING, 'manual_report' => true]"
                :captions-url="$video['captions_url'] ?? null"
                :title="$video['source'] ?? 'Video'"
                :segments="$video['video_segments'] ?? []"
                :resume-key="$preview ? null : $draftPrefix.'position'"
            />
        </div>
        @if (count($targetPhrases))
            <p class="rounded-2xl bg-accent-soft px-4 py-3 text-sm font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">🎯 Listen for “{{ $targetPhrases[0]['phrase'] }}”@if (isset($targetPhrases[1])) and “{{ $targetPhrases[1]['phrase'] }}”@endif.</p>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="screen = {{ $afterWatch }}" wire-target="save" />
        </x-slot:actions>
    </x-lesson-screen>

    @if ($quizScreen)
        <section x-show="screen === {{ $quizScreen }}" x-cloak class="pt-2">
            <x-quick-round
                lesson
                :cards="$cards"
                label="True or false?"
                draft-key="{{ $draftPrefix }}quiz"
                on-complete="if (screen === {{ $quizScreen }}) screen = {{ $afterQuiz }}"
                on-skip="screen = {{ $afterQuiz }}"
            />
        </section>
    @endif

    @if ($phrasesScreen)
        <x-lesson-screen when="screen === {{ $phrasesScreen }}">
            <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">From the video</span>
            <div class="flex flex-col gap-2">
                @foreach ($targetPhrases as $item)
                    <div class="flex flex-col gap-0.5 rounded-2xl bg-surface px-4 py-3 dark:bg-surface-dark">
                        <x-speak-word :word="$item['phrase']" class="text-base font-extrabold text-ink dark:text-ink-dark" />
                        @if (filled($item['meaning'] ?? null))
                            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $item['meaning'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>

            <x-slot:actions>
                <x-continue-button on-click="screen = {{ $firstLineScreen }}" wire-target="save" />
            </x-slot:actions>
        </x-lesson-screen>
    @endif

    {{-- Each line's bit of the video, played as its screen opens. --}}
    <div x-show="screen >= {{ $firstLineScreen }}" x-cloak class="pt-2" wire:ignore>
        <video
            x-ref="clip"
            playsinline
            preload="metadata"
            x-on:timeupdate="if (clipEnd !== null && $el.currentTime >= clipEnd) { $el.pause(); clipEnd = null }"
            class="aspect-video w-full rounded-2xl bg-ink object-cover ring-2 ring-accent dark:ring-accent-dark"
            src="{{ $video['video_url'] ?? '' }}"
        ></video>
    </div>

    @foreach ($shadowLines as $index => $line)
        <x-shadow-line-screen
            when="screen === {{ $firstLineScreen + $index }}"
            :index="$index"
            :line="$line"
            kind="Say it · {{ $index + 1 }} of {{ $lineCount }}"
            hear="playLine({{ $index }})"
            :recording-url="$draftShadowUrls[$index] ?? null"
            file-name="video-shadow-{{ $index }}.webm"
            :feedback="$feedback['shadow_'.$index] ?? null"
            :error="$checkErrors['shadow_'.$index] ?? null"
            :next="$index + 1 < $lineCount ? 'screen++' : '$wire.save()'"
            wire-target="checkShadowLine,save"
        />
        @if ($index + 1 < $lineCount || $shadowedEnough)
            <div x-show="screen === {{ $firstLineScreen + $index }}" x-cloak class="-mt-3 flex justify-center pb-2">
                <button type="button" x-on:click="{{ $index + 1 < $lineCount ? 'screen++' : '$wire.save()' }}" class="cursor-pointer text-sm font-bold text-ink-faint dark:text-ink-faint-dark">{{ $index + 1 < $lineCount ? 'Skip' : 'Finish' }}</button>
            </div>
        @endif
    @endforeach

    @error('shadowRecordings')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror
</div>
@endif
