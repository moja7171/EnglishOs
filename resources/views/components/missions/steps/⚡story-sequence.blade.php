<?php

use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\LearnerFeedback;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A numbered picture strip (<x-sequential-picture-story>, built earlier
 * but never wired in — its own docblock said it needed a mission whose
 * grammar point is past narrative; on reflection that was too narrow, a
 * routine sequence narrates just as naturally in Present Simple, which is
 * exactly M01's grammar point) — the learner narrates it in order using
 * sequencing words, right after Grammar in Context teaches the tense
 * those sentences need. Captions are author-only ground truth for the AI
 * feedback prompt below, never shown to the learner — they have to
 * produce the vocabulary themselves, not read it off the picture.
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

    /** @var array<string, mixed>|null A LearnerFeedback::review() result (or an older saved shape). */
    public ?array $feedback = null;

    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        $data = json_decode($this->run->latestEvidence('story_sequence')?->content_ref ?? '{}', true);
        $this->transcript = $data['transcript'] ?? null;
        $this->feedback = $data['feedback'] ?? null;

        $audioEvidence = $this->run->evidence()->where('phase', 'story_sequence')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedAudioUrl = $audioEvidence?->content_ref;
    }

    /**
     * Deliberately no 'caption' key — <x-sequential-picture-story> would
     * render it under the image, and the whole point is the learner
     * produces the narration themselves (see this file's own docblock).
     * The real captions stay in stepContent, read only by
     * transcribeAndReview() below for the AI's ground truth. 'alt' is a
     * separate, neutral scene description (never the caption) so a screen
     * reader user can still tell what's in each picture without being
     * handed the narration.
     *
     * @return list<array{url: string, alt: string|null}>
     */
    public function sequenceImages(): array
    {
        $items = $this->run->mission->stepContent('story_sequence')['sequence_images'] ?? [];
        $client = app(PexelsClient::class);

        return collect($items)
            ->map(fn ($item, $index) => [
                'url' => $client->imageUrlFor($this->run->mission->code.'-story-'.$index, $item['image_query'] ?? ''),
                'alt' => $item['alt_description'] ?? null,
            ])
            ->filter(fn ($item) => $item['url'])
            ->values()
            ->all();
    }

    /**
     * The tense the story is told in, authored per mission next to the
     * pictures: M01's routine is Present Simple (the default, so a mission
     * that says nothing keeps M01's behaviour), M06's finished story is
     * Past Simple. 'not' is the tense slip the AI should look for first.
     *
     * @return array{tense: string, kind: string, example: string, not: string}
     */
    public function narration(): array
    {
        $content = $this->run->mission->stepContent('story_sequence');

        return [
            'tense' => $content['narration_tense'] ?? 'Present Simple',
            'kind' => $content['narration_kind'] ?? 'a routine',
            'example' => $content['narration_example'] ?? 'First, she wakes up...',
            'not' => $content['narration_not'] ?? 'past tense',
        ];
    }

    protected function draftPhase(): string
    {
        return 'story_sequence';
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
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'transcript' => $this->transcript,
                'feedback' => $this->feedback,
            ]),
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'story_sequence',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => Storage::disk('public')->url($path),
        ]);

        $this->forgetStepDraft();
        $this->completed = true;
    }

    /**
     * The shared sentence-by-sentence feedback (see LearnerFeedback), but
     * grounded in the picture captions (the actual story, author-side only
     * — never shown to the learner) and judging sequencing language + the
     * mission's narration tense (see narration()), the two things this
     * exercise is actually for. Silent
     * on failure: the recording's own Evidence is already saved either way.
     */
    private function transcribeAndReview(): void
    {
        try {
            $this->transcript = trim(app(GroqClient::class)->transcribe(Storage::disk('public')->path($this->recordingPath)));
            $this->recordGroqCall();

            if ($this->transcript === '') {
                return;
            }

            $captions = collect($this->run->mission->stepContent('story_sequence')['sequence_images'] ?? [])
                ->pluck('caption')->filter()->implode(' → ');
            $sequencingWords = $this->run->mission->stepContent('story_sequence')['sequencing_words'] ?? [];
            $sequencingContext = $sequencingWords
                ? ' Sequencing words they were encouraged to use: '.implode(', ', $sequencingWords).'.'
                : '';
            $narration = $this->narration();

            $data = app(LearnerFeedback::class)->review(
                $this->run->learner,
                $this->transcript,
                situation: 'the learner narrating a sequence of pictures out loud. Every sentence is theirs.',
                source: 'story_sequence',
                spoken: true,
                extraGuidance: 'The pictures show this real order of events: "'.$captions.'". '
                    .'This is '.$narration['kind'].', so it should be told in '.strtoupper($narration['tense'])
                    .' ("'.$narration['example'].'"), not '.$narration['not'].'.'.$sequencingContext.' Judge whether '
                    .'they covered the events in order and used sequencing words; a tense slip (e.g. '
                    .$narration['not'].' instead of '.strtolower($narration['tense']).') is the first thing to fix.',
                onCallSucceeded: fn () => $this->recordGeminiCall(),
            );

            $this->feedback = $data;

            // Same signal TracksCheckAttempts feeds from every AI-checked
            // sentence step — see MissionRun::aiToneGuidance(). Was missing
            // here (this step had no 'severity' key at all), which made it
            // invisible to mid-run tone adaptation.
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
    $content = $run->mission->stepContent('story_sequence');
    $sequenceImages = $this->sequenceImages();
    $sequencingWords = $content['sequencing_words'] ?? [];
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Picture Story" :title="$content['hook'] ?? null" />
        <x-sequential-picture-story grid :images="$sequenceImages" />
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
{{-- Lesson design 6: the start, then all four pictures at once with the
     sequencing words and ONE recording of the whole story, then the
     feedback on a screen of its own. --}}
<div
    x-data="{ screen: {{ $completed ? 2 : ($recordingPath ? 1 : 0) }} }"
    x-effect="$dispatch('step-progress', { share: (screen + 0.5) / 3 })"
    x-back-close="{ open: screen === 1, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Picture Story" :minutes="$run->mission->stepDuration('story_sequence')" :title="$content['hook'] ?? null" />

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    <x-lesson-screen when="screen === 1">
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Tell it · {{ $this->narration()['tense'] }}</span>

        @if (empty($sequenceImages))
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">We couldn't load the pictures for this story — refresh the page to try again.</p>
        @else
            <x-sequential-picture-story grid :images="$sequenceImages" />
        @endif

        @if (count($sequencingWords))
            <div class="flex flex-wrap justify-center gap-1.5">
                @foreach ($sequencingWords as $word)
                    <span class="rounded-full bg-surface px-3 py-1 text-xs font-bold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">{{ $word }}</span>
                @endforeach
            </div>
        @endif

        <div class="flex justify-center">
            <x-voice-recorder big field="recording" :file="$this->recordingUrl()" file-name="story-sequence.webm" />
        </div>
        @error('recording')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror

        <x-slot:actions>
            <x-continue-button
                on-click="screen = 2; $wire.save()"
                wire-target="save"
                ready-when="{{ $recordingPath ? 'true' : 'false' }}"
            />
        </x-slot:actions>
    </x-lesson-screen>

    <x-feedback-screen when="screen === 2" :feedback="$feedback" :waiting="! $completed">
        @if ($transcript)
            <x-slot:said>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                <p class="text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
            </x-slot:said>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="$wire.proceed()" wire-target="save,proceed" ready-when="{{ $completed ? 'true' : 'false' }}" />
        </x-slot:actions>
    </x-feedback-screen>
</div>
@endif
