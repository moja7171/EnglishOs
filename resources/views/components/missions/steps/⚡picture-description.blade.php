<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFeedbackCard;
use App\Services\LearnerFeedback;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
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
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /** Only the upload slot — see updatedRecording(). */
    public ?UploadedFile $recording = null;

    /** The kept recording's path on the public disk, until the step is saved. */
    #[Locked]
    public ?string $recordingPath = null;

    public ?string $savedAudioUrl = null;

    public ?string $transcript = null;

    /** Real duration (Groq/Whisper's own, not estimated) — null if unavailable. */
    public ?float $durationSeconds = null;

    /** @var array<string, mixed>|null A LearnerFeedback::review() result (or an older saved shape). */
    public ?array $feedback = null;

    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

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

    protected function draftPhase(): string
    {
        return 'picture_description';
    }

    protected function draftedProperties(): array
    {
        return ['recordingPath'];
    }

    /**
     * The take is stored (and kept in the step's draft) the moment its
     * upload finishes, so leaving before Continue never loses it; a new
     * take replaces the old one, file included.
     */
    public function updatedRecording(): void
    {
        $this->validate([
            'recording' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $this->recordingPath = $this->keepDraftRecording($this->recording, $this->recordingPath);
        $this->recording = null;

        $this->keepStepDraft();
    }

    public function recordingUrl(): ?string
    {
        return $this->recordingPath === null ? null : Storage::disk('public')->url($this->recordingPath);
    }

    public function save(): void
    {
        if ($this->recordingPath === null) {
            $this->addError('recording', 'Record your answer first.');

            return;
        }

        $path = $this->recordingPath;

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

        $this->forgetStepDraft();
        $this->completed = true;
    }

    /**
     * The shared sentence-by-sentence feedback (see LearnerFeedback), but the
     * judgment criteria are specific to describing a scene rather than
     * narrating a personal routine — present continuous for what's
     * happening right now, "there is/are", prepositions of place. Silent
     * on failure: the recording's own Evidence is already saved either way.
     */
    private function transcribeAndReview(): void
    {
        try {
            $result = app(GroqClient::class)->transcribeWithDuration(Storage::disk('public')->path($this->recordingPath));
            $this->transcript = trim($result['text']);
            $this->durationSeconds = $result['duration'];
            $this->recordGroqCall();

            if ($this->transcript === '') {
                return;
            }

            $vocabularyContext = app(AiFeedbackCard::class)->vocabularyContext($this->run->selectedVocabularyWords());
            $length = $this->durationSeconds
                ? ' They spoke for '.(int) round($this->durationSeconds).' seconds ('.$this->wordCount().' words).'
                : '';

            $data = app(LearnerFeedback::class)->review(
                $this->run->learner,
                $this->transcript,
                situation: 'the learner describing a picture out loud. Every sentence is theirs.',
                source: 'picture_description',
                spoken: true,
                extraGuidance: 'This is a real IELTS/CEFR-style "describe what you see" task, not personal '
                    .'storytelling. Judge whether they described the SCENE itself: what is happening (present '
                    .'continuous — "a woman is pouring coffee"), where things are (prepositions of place), what is '
                    .'present ("there is/are"); a present-continuous or preposition-of-place slip is the first '
                    .'thing to fix.'.$length.$vocabularyContext,
                onCallSucceeded: fn () => $this->recordGeminiCall(),
            );

            $this->feedback = $data;

            // Same signal TracksCheckAttempts feeds from every AI-checked
            // sentence step — see MissionRun::aiToneGuidance().
            if ($data['severity'] === 'major') {
                $this->run->recordStruggleSignal();
            }
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    public function proceed(): void
    {
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }
};
?>

@php
    $content = $run->mission->stepContent('picture_description');
    $imageUrl = $this->imageUrl();
    $questions = array_values($content['guiding_questions'] ?? []);
    $hotspots = $content['hotspots'] ?? [];
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Describe it" />
        @if ($imageUrl)
            <x-zoomable-image :url="$imageUrl" alt="A picture to describe" class="h-52 w-full rounded-3xl object-cover" />
        @endif
        @if ($savedAudioUrl)
            <x-audio-player :url="$savedAudioUrl" />
        @endif
        @if ($feedback)
            <x-learner-feedback :feedback="$feedback">
                @if ($transcript)
                    <x-slot:more>
                        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                        <p class="text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
                    </x-slot:more>
                @endif
            </x-learner-feedback>
        @elseif ($transcript)
            <p class="text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
        @endif
    </div>
@else
{{-- Lesson design 11: no start screen — the picture itself is the start.
     One guiding question at a time, its numbered dot lit on the picture
     (‹ › or tap a dot to move on), ONE recording for all of them, then
     the shared feedback screen. --}}
<div
    wire:ignore.self x-data="{ screen: {{ $completed ? 1 : 0 }}, question: 0, count: {{ max(count($questions), 1) }} }"
    x-effect="$dispatch('step-progress', { share: screen === 1 ? 0.9 : 0.15 + 0.5 * question / count })"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Describe it" />

        @if ($imageUrl)
            <div class="relative overflow-hidden rounded-3xl">
                <img src="{{ $imageUrl }}" alt="A picture to describe" class="h-56 w-full object-cover">
                <button
                    type="button"
                    x-on:click="$dispatch('zoom-image', { url: @js($imageUrl), alt: 'A picture to describe' })"
                    aria-label="Enlarge picture"
                    class="absolute right-2 bottom-2 inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-black/50 text-white transition-colors hover:bg-black/70"
                >@svg('heroicon-o-arrows-pointing-out', 'h-4 w-4')</button>
                @foreach ($hotspots as $hotspot)
                    @php $qi = (int) ($hotspot['question_index'] ?? 0); @endphp
                    <button
                        type="button"
                        style="left: {{ $hotspot['x'] }}%; top: {{ $hotspot['y'] }}%;"
                        aria-label="Question {{ $qi + 1 }}"
                        class="absolute flex h-7 w-7 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full text-xs font-extrabold shadow-md transition-transform before:absolute before:-inset-2.5 before:content-['']"
                        :class="question === {{ $qi }} ? 'scale-110 bg-accent text-white outline-4 outline-accent/35 dark:bg-accent-dark' : 'bg-white text-ink'"
                        x-on:click="question = {{ $qi }}"
                    >{{ $qi + 1 }}</button>
                @endforeach
            </div>
        @endif

        @if (count($questions))
            <div class="flex items-center gap-1 rounded-2xl bg-surface p-1.5 dark:bg-surface-dark">
                <button type="button" x-on:click="question = (question + count - 1) % count" aria-label="Previous question" class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">@svg('heroicon-o-chevron-left', 'h-5 w-5')</button>
                @foreach ($questions as $qi => $question)
                    <p x-show="question === {{ $qi }}" class="min-w-0 flex-1 text-center text-base leading-snug font-bold text-ink dark:text-ink-dark">{{ $question }}</p>
                @endforeach
                <button type="button" x-on:click="question = (question + 1) % count" aria-label="Next question" class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">@svg('heroicon-o-chevron-right', 'h-5 w-5')</button>
            </div>
        @endif

        <div class="flex justify-center">
            <x-voice-recorder big field="recording" :file="$this->recordingUrl()" file-name="picture-description.webm" />
        </div>
        @error('recording')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror

        <x-slot:actions>
            <x-continue-button
                on-click="screen = 1; $wire.save()"
                wire-target="save"
                ready-when="{{ $recordingPath ? 'true' : 'false' }}"
            />
        </x-slot:actions>
    </x-lesson-screen>

    <x-feedback-screen when="screen === 1" :feedback="$feedback" :waiting="! $completed">
        @if ($transcript)
            <x-slot:said>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                <p class="text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                    @if ($durationSeconds) {{ (int) round($durationSeconds) }}s · @endif{{ $this->wordCount() }} words
                </p>
            </x-slot:said>
        @endif

        <x-slot:actions>
            @if ($completed && ! $transcript)
                <p class="text-center text-sm text-ink-soft dark:text-ink-soft-dark">Your recording was saved, but feedback isn't available this time.</p>
            @endif
            <x-continue-button on-click="$wire.proceed()" wire-target="save,proceed" ready-when="{{ $completed ? 'true' : 'false' }}" />
        </x-slot:actions>
    </x-feedback-screen>
</div>
@endif
