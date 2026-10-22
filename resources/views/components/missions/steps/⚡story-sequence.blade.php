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
@endphp

<div class="space-y-4">
    <x-hook :text="$content['hook'] ?? null" />

    @if ($completed || ($readOnly && ($transcript || $feedback)))
        <div class="space-y-4 card p-4">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Picture Story complete
                </p>
                @if ($feedback)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">A quick look at your story before you move on.</p>
                @endif
            </div>

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
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
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
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Tell the story, in order</p>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Tell the story in order, using {{ $this->narration()['tense'] }}.</p>
        </div>

        <x-sequential-picture-story :images="$sequenceImages" />

        @if (empty($sequenceImages))
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">We couldn't load the pictures for this story — refresh the page to try again.</p>
        @endif

        @if (count($content['sequencing_words'] ?? []))
            <div class="flex flex-wrap gap-1.5">
                @foreach ($content['sequencing_words'] as $word)
                    <span class="rounded-full border border-line px-2 py-0.5 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $word }}</span>
                @endforeach
            </div>
        @endif

        @if ($readOnly)
            <div>
                <x-audio-player :url="$savedAudioUrl" />
            </div>
        @else
            <div>
                <x-voice-recorder field="recording" :file="$this->recordingUrl()" file-name="story-sequence.webm" />
            </div>

            @error('recording')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror

            <div class="mt-4">
                <x-continue-button
                    on-click="$wire.save()"
                    wire-target="save"
                    loading-label="Listening and preparing your feedback…"
                    ready-when="{{ $recordingPath ? 'true' : 'false' }}"
                    hint="Record your story to continue"
                />
            </div>
        @endunless
    @endunless
</div>
