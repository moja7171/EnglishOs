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

    /** @var array{strength: string, expression: string, correction: string}|null */
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

        $audioEvidence = $this->run->evidence()->where('phase', 'picture_description')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedAudioUrl = $audioEvidence?->content_ref;
    }

    /**
     * Cached per mission (not per learner), fails soft like every other
     * PexelsClient call — a missing key/network error just means no image,
     * never a blocked step.
     */
    public function imageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('picture_description')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-picture-description', $query);
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
            $this->transcript = trim(app(GroqClient::class)->transcribe($this->recording->getRealPath()));
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
                    .'place slip if there is one"}'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (is_array($data) && isset($data['strength'], $data['expression'], $data['correction'])) {
                $this->feedback = $data;
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
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Picture Description complete
                </p>
                @if ($feedback)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">A quick look at your description before you move on.</p>
                @endif
            </div>

            @if ($transcript)
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $transcript }}</p>
                </div>
            @endif

            @if ($feedback)
                <div class="space-y-2">
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">One thing you described well</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['strength'] }}</p>
                    </div>
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">A good expression you used</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['expression'] }}</p>
                    </div>
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-amber-600 uppercase">One thing to improve</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['correction'] }}</p>
                    </div>
                </div>
            @endif

            @unless ($readOnly)
                <button
                    wire:click="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    Continue
                </button>
            @endunless
        </div>
    @endif

    @unless ($completed)
        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Describe what you see</p>
            @if ($imageUrl = $this->imageUrl())
                <img src="{{ $imageUrl }}" alt="A picture to describe" class="mt-2 h-52 w-full rounded-2xl object-cover">
            @endif
        </div>

        @if (count($content['guiding_questions'] ?? []))
            <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-xs font-semibold text-ink dark:text-ink-dark">Try to cover:</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($content['guiding_questions'] as $question)
                        <li class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $question }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($readOnly)
            <div>
                <x-audio-player :url="$savedAudioUrl" />
            </div>
        @else
            <div>
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
        @endunless
    @endunless
</div>
