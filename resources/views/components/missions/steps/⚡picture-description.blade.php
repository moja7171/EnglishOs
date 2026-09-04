<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A real CEFR/IELTS-style "describe this picture" speaking task — the one
 * genuinely visual-description skill (present continuous, prepositions of
 * place, "there is/are") nothing else in M01 practices; Activation and the
 * AI Conversation steps are all personal-routine narration, never an
 * objective scene description. See EOS-009 §8's notification-center-style
 * write-up for the reasoning ("Picture Description Speaking", 2026-09-04).
 */
new class extends Component
{
    use TracksAiUsage;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    public ?UploadedFile $recording = null;

    public ?string $savedAudioUrl = null;

    public ?string $transcript = null;

    /** Real duration (Groq/Whisper's own, not estimated) — null if unavailable. */
    public ?float $durationSeconds = null;

    /** @var array{strength: string, expression: string, correction: string, severity?: string}|null */
    public ?array $feedback = null;

    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('picture_description')?->content_ref ?? '{}', true);
        $this->transcript = $data['transcript'] ?? null;
        $this->feedback = $data['feedback'] ?? null;
        $this->durationSeconds = $data['duration'] ?? null;

        $audioEvidence = $this->run->evidence()->where('phase', 'picture_description')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedAudioUrl = $audioEvidence?->content_ref;
    }

    /** A rough word count from the real transcript — the completion stat badge. */
    public function wordCount(): int
    {
        return $this->transcript ? str_word_count($this->transcript) : 0;
    }

    /**
     * Cached per mission (not per learner), fails soft like every other
     * PexelsClient call — a missing key/network error just means no image,
     * never a blocked step. 'landscape' (not the default 'square') because
     * this is a wide banner that needs to show a whole multi-subject scene,
     * not one centered subject — see PexelsClient::imageUrlFor().
     */
    public function imageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('picture_description')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-picture-description', $query, 'landscape');
    }

    public function save(): void
    {
        $this->validate([
            'recording' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $path = $this->recording->store('missions/'.strtolower($this->run->mission->code).'/evidence', 'public');

        $this->transcribeAndReview();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'picture_description',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'transcript' => $this->transcript,
                'feedback' => $this->feedback,
                'duration' => $this->durationSeconds,
            ]),
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'picture_description',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => Storage::disk('public')->url($path),
        ]);

        $this->completed = true;
    }

    /**
     * Same 3-part feedback shape as Writing/AI Feedback #1, but the
     * judgment criteria are specific to describing a scene rather than
     * narrating a personal routine — present continuous for what's
     * happening right now, "there is/are", prepositions of place. Silent
     * on failure: the recording's own Evidence is already saved either way.
     */
    private function transcribeAndReview(): void
    {
        try {
            $result = app(GroqClient::class)->transcribeWithDuration($this->recording->getRealPath());
            $this->transcript = trim($result['text']);
            $this->durationSeconds = $result['duration'];
            $this->recordGroqCall();

            if ($this->transcript === '') {
                return;
            }

            $vocabularyWords = $this->run->selectedVocabularyWords();
            $vocabularyContext = $vocabularyWords
                ? ' If any of these words appear naturally, you can mention that warmly: '
                    .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ').'.'
                : '';

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript of the learner describing a picture: \"{$this->transcript}\""]],
                systemPrompt: 'You are an encouraging English speaking coach. '.ucfirst($this->run->learner->levelDescription())
                    .' just described a picture out loud — a real IELTS/CEFR-style "describe what you see" task, '
                    .'not personal storytelling. Judge whether they described the SCENE itself: what is happening '
                    .'(present continuous — "a woman is pouring coffee"), where things are (prepositions of place), '
                    .'what is present ("there is/are").'.$vocabularyContext.' Reply with ONLY valid JSON, no markdown '
                    .'fences: {"strength": "one specific thing they described well, one sentence", "expression": '
                    .'"one good word or phrase they actually used", "correction": "one grammar or vocabulary '
                    .'mistake to fix, one sentence, phrased kindly — prefer a present-continuous or preposition-of-'
                    .'place slip if there is one", "severity": "minor or major — how serious this grammar/'
                    .'vocabulary issue is"}'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (is_array($data) && isset($data['strength'], $data['expression'], $data['correction'])) {
                $this->feedback = $data;

                // Same signal TracksCheckAttempts feeds from every AI-checked
                // sentence step — see MissionRun::aiToneGuidance().
                if (($data['severity'] ?? null) === 'major') {
                    $this->run->recordStruggleSignal();
                }
            }
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $content = $run->mission->stepContent('picture_description');
@endphp

<div class="space-y-4">
    <x-hook :text="$content['hook'] ?? null" />

    @if ($completed || ($readOnly && ($transcript || $feedback)))
        <div class="space-y-4">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Picture Description complete
                </p>
                @if ($feedback)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">A quick look at your description before you move on.</p>
                @endif
            </div>

            {{-- Magazine-style recap: the image up top, the transcript as a
                 speech-bubble callout overlapping its bottom edge, feedback
                 cards below — reuses AI Feedback #1's report-card visual
                 language (icons, severity tint) rather than a third style. --}}
            @if ($imageUrl = $this->imageUrl())
                <div class="rounded-2xl p-1.5" style="background-image: var(--mood-texture); background-size: var(--mood-texture-size);">
                    <img src="{{ $imageUrl }}" alt="" class="h-44 w-full rounded-xl object-cover">
                </div>
            @endif

            @if ($transcript)
                <div class="relative {{ $imageUrl ?? null ? '-mt-8' : '' }} mx-3 rounded-2xl rounded-tl-sm border border-line bg-surface p-3 shadow-md dark:border-line-dark dark:bg-surface-dark">
                    <p class="text-[10px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
                </div>

                <div class="flex items-center gap-2 px-1">
                    @if ($durationSeconds)
                        <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2.5 py-1 text-xs text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                            @svg('heroicon-o-clock', 'h-3.5 w-3.5') {{ (int) round($durationSeconds) }}s
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2.5 py-1 text-xs text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                        @svg('heroicon-o-pencil-square', 'h-3.5 w-3.5') {{ $this->wordCount() }} words
                    </span>
                </div>
            @endif

            @if ($feedback)
                @php $severity = $feedback['severity'] ?? null; @endphp
                <div class="space-y-3">
                    <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-4 w-4')
                            One thing you described well
                        </p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['strength'] }}</p>
                    </div>
                    <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">
                            @svg('heroicon-o-book-open', 'h-4 w-4')
                            A good expression you used
                        </p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['expression'] }}</p>
                    </div>
                    @if ($severity === 'major')
                        <div class="rounded-xl border-l-4 border-red-500 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950/30">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-red-600 uppercase dark:text-red-400">
                                @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                                One thing to improve
                            </p>
                            <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['correction'] }}</p>
                        </div>
                    @else
                        <div class="rounded-xl border-l-4 border-amber-500 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-amber-600 uppercase dark:text-amber-400">
                                @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                                One thing to improve
                            </p>
                            <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['correction'] }}</p>
                        </div>
                    @endif
                </div>
            @endif

            @unless ($readOnly)
                <button
                    wire:click="proceed"
                    wire:loading.attr="disabled"
                    wire:target="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
                >
                    <span wire:loading.remove wire:target="proceed">Continue</span>
                    <span wire:loading wire:target="proceed">Saving…</span>
                </button>
            @endunless
        </div>
    @endif

    @unless ($completed)
        <div x-data="{ imageFocused: {{ $readOnly ? 'true' : 'false' }}, activeQuestion: null }">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Describe what you see</p>

            {{-- Mood-texture frame (see resources/css/app.css's [data-mood]
                 block) around the image, and numbered hotspot markers tied
                 to the guiding questions below — hover/tap either side to
                 highlight its match. The image starts gently blurred and
                 clears once the learner starts recording (delight, not a
                 puzzle — <x-voice-recorder> exposes no event of its own, so
                 the click that starts recording is the cleanest hook). --}}
            @if ($imageUrl = $this->imageUrl())
                <div class="relative mt-2 rounded-2xl p-1.5" style="background-image: var(--mood-texture); background-size: var(--mood-texture-size);">
                    <div class="relative overflow-hidden rounded-xl">
                        <img
                            src="{{ $imageUrl }}"
                            alt="A picture to describe"
                            class="h-52 w-full object-cover transition-all duration-700 ease-out"
                            :class="imageFocused ? '' : 'scale-105 blur-sm'"
                        >
                        @foreach (($content['hotspots'] ?? []) as $hotspot)
                            @php $qi = $hotspot['question_index'] ?? null; @endphp
                            <button
                                type="button"
                                style="left: {{ $hotspot['x'] }}%; top: {{ $hotspot['y'] }}%;"
                                class="absolute flex h-6 w-6 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-accent text-xs font-bold text-white shadow-md transition-transform hover:scale-110 dark:border-surface-dark dark:bg-accent-dark"
                                :class="activeQuestion === {{ $qi }} ? 'scale-125 ring-2 ring-white dark:ring-surface-dark' : ''"
                                x-on:mouseenter="activeQuestion = {{ $qi }}"
                                x-on:mouseleave="activeQuestion = null"
                                x-on:click="activeQuestion = (activeQuestion === {{ $qi }} ? null : {{ $qi }})"
                            >{{ ($qi ?? 0) + 1 }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (count($content['guiding_questions'] ?? []))
                <div class="mt-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-xs font-semibold text-ink dark:text-ink-dark">Try to cover:</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($content['guiding_questions'] as $qi => $question)
                            <li
                                class="flex items-start gap-2 rounded-lg px-2 py-1 text-sm text-ink-soft transition-colors dark:text-ink-soft-dark"
                                :class="activeQuestion === {{ $qi }} ? 'bg-accent/10 text-ink dark:bg-accent-dark/10 dark:text-ink-dark' : ''"
                                x-on:mouseenter="activeQuestion = {{ $qi }}"
                                x-on:mouseleave="activeQuestion = null"
                            >
                                <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-accent/20 text-[10px] font-bold text-accent-ink dark:bg-accent-dark/20 dark:text-accent-ink-dark">{{ $qi + 1 }}</span>
                                {{ $question }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($readOnly)
                <div class="mt-3">
                    <x-audio-player :url="$savedAudioUrl" />
                </div>
            @else
                <div class="mt-3" x-on:click="imageFocused = true">
                    <x-voice-recorder field="recording" :file="$recording" file-name="picture-description.webm" />
                </div>

                @error('recording')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror

                {{-- The recording can only be confirmed server-side (an upload
                     needs a real round-trip), so this gates via a plain @if,
                     same pattern as Activation's recording substep. --}}
                @if ($recording)
                    <x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Listening and preparing your feedback…" />
                @endif
            @endif
        </div>
    @endunless
</div>
