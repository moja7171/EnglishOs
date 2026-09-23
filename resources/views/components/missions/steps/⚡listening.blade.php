<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Mission structure redesign, Epic C: day 1's Listening is now exactly 3
 * clean sub-steps — first listen + a short true/false check, second
 * listen + a gap-fill answered by PICKING from a word bank (not typing),
 * and the full transcript + mandatory, leniently AI-graded shadowing. The
 * old gist/expression free-writing (6 AI-checked sentences) is gone
 * entirely — that overlapped with what Vocabulary Builder and Writing
 * already do, and buried Listening's own point (comprehension) under a
 * writing exercise.
 */
new class extends Component
{
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a recap of the target expressions before the
     * learner actually navigates on, instead of jumping away immediately.
     */
    public bool $completed = false;

    /**
     * A one-tap bonus check (see <x-quick-round>), not a required field —
     * null until the learner taps an option (or leaves it alone entirely,
     * which is fine; the round is always skippable). Set client-side by
     * the Quick Round's on-complete Alpine statement.
     */
    public ?bool $detailCorrect = null;

    /** @var array<int, ?string> the phrase the learner picked for each gap, keyed by target_phrases index */
    public array $gapFillSelections = [];

    /** @var array<int, array{severity: string, hint: string}> local verdicts for gapFillSelections, keyed by index */
    public array $gapFillFeedback = [];

    /**
     * The word bank order for the gap-fill sub-step — shuffled once in
     * mount() (not re-shuffled on every render, or the options would jump
     * around under the learner's cursor) so every gap's <select> lists
     * the same target phrases in the same order.
     *
     * @var array<int, string>
     */
    public array $gapFillBankOrder = [];

    /** @var array<int, ?UploadedFile> keyed by shadow_lines index */
    public array $shadowRecordings = [];

    /** @var array<int, string> keyed by shadow_lines index — saved recording URLs, for read-only review */
    public array $savedShadowUrls = [];

    /** @var array<string, array{severity: string, hint: string}> keyed by "shadow_{index}" */
    public array $feedback = [];

    /** @var array<string, string> keyed by field key — per-input check failure message */
    public array $checkErrors = [];

    /**
     * Out of however many lines the mission seeds, only this many need to
     * actually pass — a pool bigger than the requirement (see
     * shadow_lines content) means one persistently-mistranscribed line
     * never blocks the learner; they can just shadow a different one
     * instead of being stuck on a single line forever.
     */
    private const REQUIRED_SHADOWED_LINES = 2;

    public function mount(): void
    {
        $this->gapFillBankOrder = collect($this->targetPhrases())->pluck('phrase')->shuffle()->values()->all();

        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('listening')?->content_ref ?? '{}', true);

        $this->gapFillSelections = $data['gap_fill_selections'] ?? [];
        $this->detailCorrect = $data['detail_correct'] ?? null;

        foreach ($this->run->evidence()->where('phase', 'listening')->where('type', Evidence::TYPE_AUDIO)->get() as $audio) {
            $decoded = json_decode($audio->content_ref, true);

            if (is_array($decoded) && isset($decoded['line_index'], $decoded['url'])) {
                $this->savedShadowUrls[$decoded['line_index']] = $decoded['url'];
            }
        }
    }

    /**
     * The episode's cover image — same dual-coding, fetch-once-cache-
     * forever principle as every other PexelsClient call (see
     * ⚡mission-brief.blade.php's heroImageUrl()). Purely decorative,
     * fails soft (null) on no query/no key/any error.
     */
    public function heroImageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('listening')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-listening', $query);
    }

    private function targetPhrases(): array
    {
        return $this->run->mission->stepContent('listening')['target_phrases'] ?? [];
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function comprehensionCards(): array
    {
        $listening = $this->run->mission->stepContent('listening');

        return collect($listening['comprehension_check'] ?? [])
            ->map(fn ($item) => [
                'prompt' => $item['statement'],
                'options' => ['True', 'False'],
                'correct' => $item['correct'] ? 0 : 1,
                ...(isset($item['difficulty']) ? ['difficulty' => $item['difficulty']] : []),
            ])
            ->all();
    }

    /**
     * Every gap answered from the word bank, correctly — the old free-typed
     * gap-fill was optional bonus practice (a "minor" verdict at worst);
     * now that it's a real sub-step of its own instead of a wrap-up
     * extra, it needs to actually be right before Continue on this
     * sub-step is enabled.
     */
    public function gapFillAllCorrect(): bool
    {
        $phrases = $this->targetPhrases();

        if (! count($phrases)) {
            return true;
        }

        foreach ($phrases as $index => $item) {
            if (($this->gapFillSelections[$index] ?? null) !== $item['phrase']) {
                return false;
            }
        }

        return true;
    }

    public function checkGapFill(int $index): void
    {
        $phrases = $this->targetPhrases();
        $target = $phrases[$index]['phrase'] ?? null;
        $selection = $this->gapFillSelections[$index] ?? null;

        if (! $target || ! $selection) {
            return;
        }

        $this->gapFillFeedback[$index] = $selection === $target
            ? ['severity' => 'none', 'hint' => '']
            : ['severity' => 'minor', 'hint' => 'Not quite — try the word you actually heard in that spot.'];
    }

    public function shadowedCount(): int
    {
        return collect($this->feedback)
            ->filter(fn ($item, $key) => str_starts_with($key, 'shadow_') && $item['severity'] === 'none')
            ->count();
    }

    public function requiredShadowedLines(): int
    {
        return self::REQUIRED_SHADOWED_LINES;
    }

    /**
     * Fires automatically once a shadow recording finishes uploading (see
     * <x-voice-recorder>'s onRecorded) — transcribes it and asks
     * SpokenAnswerChecker's lenient shadowing judgment whether the
     * learner genuinely attempted the line, out loud. See EOS-009 §8 and
     * the mission structure redesign's Epic C notes for why this is
     * lenient rather than a pronunciation-accuracy grade.
     */
    public function checkShadowLine(int $index): void
    {
        $recording = $this->shadowRecordings[$index] ?? null;
        $lines = $this->run->mission->stepContent('listening')['shadow_lines'] ?? [];
        $target = $lines[$index] ?? null;

        if (! $recording || ! $target) {
            return;
        }

        $key = "shadow_{$index}";
        unset($this->checkErrors[$key]);

        try {
            $transcript = trim(app(GroqClient::class)->transcribe($recording->getRealPath()));
            $this->recordGroqCall();

            $data = app(SpokenAnswerChecker::class)->checkShadowing(
                strip_tags(str_replace('**', '', $target)),
                $transcript,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();

            $this->feedback[$key] = $data;
            $this->trackCheckAttempt($key, $data['severity']);
        } catch (Throwable $e) {
            $this->checkErrors[$key] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    public function save(): void
    {
        if (! $this->gapFillAllCorrect()) {
            $this->addError('gapFill', 'Finish the gap-fill correctly before continuing.');

            return;
        }

        if ($this->shadowedCount() < self::REQUIRED_SHADOWED_LINES) {
            $this->addError('shadowRecordings', 'Shadow at least '.self::REQUIRED_SHADOWED_LINES.' lines before continuing.');

            return;
        }

        $mission = $this->run->mission;

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gap_fill_selections' => $this->gapFillSelections,
                // Optional bonus — saved if attempted, never required.
                'detail_correct' => $this->detailCorrect,
            ]),
        ]);

        // One AUDIO Evidence row per shadowed line, same pattern as Video
        // Shadowing — content_ref is JSON here (unlike most other steps'
        // plain-URL audio Evidence) since this step can produce more than
        // one recording; line_index is what lets mount() map each saved
        // file back to its line on review.
        foreach ($this->shadowRecordings as $index => $recording) {
            if (! $recording || ($this->feedback["shadow_{$index}"]['severity'] ?? null) !== 'none') {
                continue;
            }

            $path = $recording->store('missions/'.strtolower($mission->code).'/evidence', 'public');
            $url = Storage::disk('public')->url($path);

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'listening',
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => json_encode(['line_index' => $index, 'url' => $url]),
            ]);

            $this->savedShadowUrls[$index] = $url;
        }

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
        $this->initWordsToTrack();
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->targetPhrases())
            ->map(fn ($item) => ['word' => $item['phrase'], 'meaning' => $item['meaning'] ?? ''])
            ->values()
            ->all();
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:listening:";
    }
};
?>

@php
    $listening = $run->mission->stepContent('listening');
    $targetPhrases = $listening['target_phrases'] ?? [];
    $transcript = $listening['transcript'] ?? [];
    $detailQuestion = $listening['detail_question'] ?? null;
    $shadowLines = $listening['shadow_lines'] ?? [];
    // Two listens before the transcript unlocks for most of the roadmap,
    // three from M09 on — a scaffolding taper, never a bar: the transcript
    // is only ever a reference inside sub-step 3, and the audio can be
    // replayed freely either way. See Mission::scaffoldLevel().
    $listensRequired = $this->run->mission->scaffoldLevel() === App\Models\Mission::SCAFFOLD_FULL ? 2 : 3;

    $detailCard = $detailQuestion ? [[
        'prompt' => $detailQuestion['question'],
        'options' => $detailQuestion['options'],
        'correct' => $detailQuestion['correct'],
    ]] : [];

    $comprehensionCards = ! $readOnly ? $this->comprehensionCards() : [];
    $hasComprehensionCheck = count($comprehensionCards) > 0;
    $comprehensionIndex = $hasComprehensionCheck ? 0 : null;
    $gapFillIndex = $hasComprehensionCheck ? 1 : 0;
    $shadowIndex = $gapFillIndex + 1;
    $totalSubsteps = $shadowIndex + 1;
    $nextDisabledExpr = "(activeSubstep === {$gapFillIndex} && ".($this->gapFillAllCorrect() ? 'false' : 'true').')';
@endphp

{{-- Server-rendered values stay OUT of the x-data expression and come in
     through data-* attributes instead: Livewire's morph rewrites x-data on
     every re-render, and Alpine rebuilds the whole scope from scratch
     whenever that string actually changed — which would silently reset
     activeSubstep to 0. Attribute changes on data-* have no such effect. --}}
<div
    class="space-y-6"
    data-listens-required="{{ $listensRequired }}"
    x-data="{
        activeSubstep: 0,
        listenCount: 0,
        showTranscript: false,
        listensRequired: 1,
        get transcriptUnlocked() { return this.listenCount >= this.listensRequired },
        init() {
            this.listensRequired = Number(this.$el.dataset.listensRequired);
        },
    }"
    x-on:audio-ended="listenCount++"
>
    @if ($imageUrl = $this->heroImageUrl())
        <img src="{{ $imageUrl }}" alt="" class="h-32 w-full rounded-2xl object-cover">
    @endif

    <x-hook :text="$listening['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" on-ended="$dispatch('audio-ended')" />
        </div>
    </div>

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Listening complete
                </p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Here's the language from today's episode — pick which ones join your spaced-repetition notebook.</p>
            </div>
            <div class="space-y-2">
                @foreach ($targetPhrases as $index => $item)
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                        <input
                            type="checkbox"
                            wire:model="wordsToTrack.{{ $index }}"
                            class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                        >
                        <span>
                            <span class="block text-sm font-bold text-ink dark:text-ink-dark">{{ $item['phrase'] }}</span>
                            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">{{ $item['meaning'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($trackedWords)
                    <span class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-o-check-circle', 'h-4 w-4') Added to My Words
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="addWordsToNotebook"
                        wire:loading.attr="disabled"
                        wire:target="addWordsToNotebook"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="addWordsToNotebook" class="inline-flex items-center gap-1 whitespace-nowrap">@svg('heroicon-o-book-open', 'h-4 w-4') Add to My Words</span>
                        <span wire:loading wire:target="addWordsToNotebook">Adding…</span>
                    </button>
                @endif

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
        </div>
    @else
    <div wire:loading.class="pointer-events-none" wire:target="checkGapFill,checkShadowLine,save">
        <div class="mb-4">
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

        @if ($hasComprehensionCheck)
            {{-- Sub-step 1: first listen + a short true/false check. --}}
            <div x-show="activeSubstep === {{ $comprehensionIndex }}" x-cloak>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">First listening — quick check</p>
                <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Listen once, then a few quick true/false taps about what you heard.</p>
                <div class="mt-2">
                    <x-quick-round :cards="$comprehensionCards" />
                </div>
            </div>
        @endif

        {{-- Sub-step 2: second listen + gap-fill, answered by picking from a word bank. --}}
        <div x-show="activeSubstep === {{ $gapFillIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Second listening — fill the gaps</p>
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Listen again, then pick the word you heard in each gap from the word bank below.</p>

            @if (count($targetPhrases))
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($this->gapFillBankOrder as $bankPhrase)
                        <span class="rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $bankPhrase }}</span>
                    @endforeach
                </div>

                <div class="mt-3 space-y-3">
                    @foreach ($targetPhrases as $index => $item)
                        @php $gapFeedback = $gapFillFeedback[$index] ?? null; @endphp
                        <div>
                            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                                {{ $item['gap_before'] ?? '' }}
                                <select
                                    wire:model.live="gapFillSelections.{{ $index }}"
                                    @disabled($readOnly)
                                    wire:change="checkGapFill({{ $index }})"
                                    class="inline rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                                >
                                    <option value="">…</option>
                                    @foreach ($this->gapFillBankOrder as $bankPhrase)
                                        <option value="{{ $bankPhrase }}">{{ $bankPhrase }}</option>
                                    @endforeach
                                </select>
                                {{ $item['gap_after'] ?? '' }}
                            </p>
                            @if ($gapFeedback)
                                <p class="mt-1 text-xs {{ $gapFeedback['severity'] === 'none' ? 'text-success dark:text-success-dark' : 'text-amber-600' }}">
                                    @if ($gapFeedback['severity'] === 'none')
                                        @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') That's it.
                                    @else
                                        {{ $gapFeedback['hint'] }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @error('gapFill')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endif
        </div>

        {{-- Sub-step 3: full transcript + mandatory shadowing. --}}
        <div x-show="activeSubstep === {{ $shadowIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">The full conversation</p>

            @if (count($transcript))
                <div>
                    @if ($readOnly)
                        <button
                            type="button"
                            x-on:click="showTranscript = !showTranscript"
                            class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
                        >
                            <span x-show="!showTranscript">Show transcript</span>
                            <span x-show="showTranscript" x-cloak>Hide transcript</span>
                        </button>
                    @else
                        <p x-show="!transcriptUnlocked" class="flex items-center gap-1.5 text-xs text-ink-soft dark:text-ink-soft-dark">
                            @svg('heroicon-o-lock-closed', 'h-3.5 w-3.5 shrink-0')
                            <span x-text="`Listen ${Math.min(listenCount, {{ $listensRequired }})}/{{ $listensRequired }} times to unlock the transcript.`"></span>
                        </p>
                        <div x-show="transcriptUnlocked" x-cloak x-transition.opacity.duration.300ms>
                            <p class="flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5')
                                Transcript unlocked
                            </p>
                            <button
                                type="button"
                                x-on:click="showTranscript = !showTranscript"
                                class="mt-1 inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
                            >
                                <span x-show="!showTranscript">Show transcript</span>
                                <span x-show="showTranscript" x-cloak>Hide transcript</span>
                            </button>
                        </div>
                    @endif

                    <div
                        x-show="showTranscript && {{ $readOnly ? 'true' : 'transcriptUnlocked' }}"
                        x-cloak
                        class="mt-2 max-h-72 space-y-2 overflow-y-auto rounded-2xl border border-line bg-surface-sunken p-4 text-sm dark:border-line-dark dark:bg-surface-sunken-dark"
                    >
                        @foreach ($transcript as $turn)
                            <p>
                                <span class="font-semibold text-ink dark:text-ink-dark">{{ $turn['speaker'] }}:</span>
                                <span class="text-ink-soft dark:text-ink-soft-dark">{{ $turn['text'] }}</span>
                            </p>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (count($shadowLines))
                <div class="mt-4 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Shadow the lines</p>
                    <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Bold words are usually stressed — try to make them a little longer and louder than the rest.</p>
                    @unless ($readOnly)
                        <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">
                            Replay a line and repeat it out loud, then record yourself. Shadow at least {{ $this->requiredShadowedLines() }} of the {{ count($shadowLines) }} lines below
                            ({{ $this->shadowedCount() }} done so far) — a line that keeps mishearing you? Just try a different one.
                        </p>
                    @endunless

                    <div class="mt-2 space-y-3">
                        @foreach ($shadowLines as $index => $line)
                            @php $shadowKey = "shadow_{$index}"; $shadowFeedback = $feedback[$shadowKey] ?? null; @endphp
                            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Line {{ $index + 1 }}</p>
                                <p class="mt-1 text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$line" />"</p>

                                @if ($readOnly)
                                    @if ($url = $savedShadowUrls[$index] ?? null)
                                        <div class="mt-2"><x-audio-player :url="$url" /></div>
                                    @else
                                        <p class="mt-2 text-xs text-ink-faint dark:text-ink-faint-dark">Not shadowed.</p>
                                    @endif
                                @else
                                    <div class="mt-2" wire:key="listening-shadow-recorder-{{ $index }}">
                                        <x-voice-recorder
                                            field="shadowRecordings.{{ $index }}"
                                            :file="$shadowRecordings[$index] ?? null"
                                            file-name="listening-shadow-{{ $index }}.webm"
                                            on-recorded="checkShadowLine"
                                            :on-recorded-param="$index"
                                        />
                                    </div>
                                    <x-ai-thinking wire:loading wire:target="checkShadowLine({{ $index }})" class="mt-2" label="Listening to your recording…" />
                                    @if ($shadowFeedback)
                                        <p class="mt-2 text-xs {{ $shadowFeedback['severity'] === 'none' ? 'text-success dark:text-success-dark' : 'text-amber-600' }}">
                                            @if ($shadowFeedback['severity'] === 'none')
                                                @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') Nice — that counts.
                                            @else
                                                {{ $shadowFeedback['hint'] }}
                                            @endif
                                        </p>
                                    @endif
                                    @if ($checkErrors[$shadowKey] ?? null)
                                        <p class="mt-2 text-xs text-red-600">{{ $checkErrors[$shadowKey] }}</p>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @error('shadowRecordings')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endif

            @if ($detailQuestion)
                <div class="mt-4">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Bonus — a detail</p>
                    @if ($readOnly)
                        @if ($detailCorrect !== null)
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                                You {{ $detailCorrect ? 'got' : "didn't quite get" }} this one: {{ $detailQuestion['question'] }}
                            </p>
                        @endif
                    @else
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Optional — one real detail from the conversation. Doesn't affect Continue.</p>
                        <div class="mt-2">
                            <x-quick-round :cards="$detailCard" on-complete="$wire.set('detailCorrect', correctCount === 1)" />
                        </div>
                    @endif
                </div>
            @endif

            @if ($listening['topic_summary'] ?? null)
                <div class="mt-4">
                    <x-practice-with-friend
                        :text="$listening['topic_summary']"
                        intro="Hey — want to discuss this listening topic with me:"
                        label="Discuss this with a friend"
                    />
                </div>
            @endif

            @unless ($readOnly)
                @php $shadowedEnough = $this->shadowedCount() >= $this->requiredShadowedLines(); @endphp
                <div class="mt-4">
                    <x-continue-button
                        on-click="$wire.save()"
                        wire-target="checkGapFill,checkShadowLine,save"
                        loading-label="Saving…"
                        ready-when="{{ $shadowedEnough ? 'true' : 'false' }}"
                        hint="Shadow {{ $this->requiredShadowedLines() }} lines to continue"
                    />
                </div>
            @endunless
        </div>
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" :next-disabled="$nextDisabledExpr" />
    </div>
    @endif
</div>
