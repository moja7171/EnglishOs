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
     * transcribeAndReview() below for the AI's ground truth.
     *
     * @return list<array{url: string}>
     */
    public function sequenceImages(): array
    {
        $items = $this->run->mission->stepContent('story_sequence')['sequence_images'] ?? [];
        $client = app(PexelsClient::class);

        return collect($items)
            ->map(fn ($item, $index) => [
                'url' => $client->imageUrlFor($this->run->mission->code.'-story-'.$index, $item['image_query'] ?? ''),
            ])
            ->filter(fn ($item) => $item['url'])
            ->values()
            ->all();
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

        $this->completed = true;
    }

    /**
     * Same 3-part feedback shape as every other speaking/writing step, but
     * grounded in the picture captions (the actual story, author-side only
     * — never shown to the learner) and judging sequencing language +
     * Present Simple, the two things this exercise is actually for. Silent
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

            $captions = collect($this->run->mission->stepContent('story_sequence')['sequence_images'] ?? [])
                ->pluck('caption')->filter()->implode(' → ');
            $sequencingWords = $this->run->mission->stepContent('story_sequence')['sequencing_words'] ?? [];
            $sequencingContext = $sequencingWords
                ? ' Sequencing words they were encouraged to use: '.implode(', ', $sequencingWords).'.'
                : '';

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript of the learner narrating the picture sequence: \"{$this->transcript}\""]],
                systemPrompt: 'You are an encouraging English speaking coach. '.ucfirst($this->run->learner->levelDescription())
                    .' just narrated a sequence of pictures showing this real order of events: "'.$captions.'". '
                    .'This is a routine, so it should be told in PRESENT SIMPLE ("First, she wakes up..."), not '
                    .'past tense.'.$sequencingContext.' Judge whether they covered the events in order and used '
                    .'sequencing words. Reply with ONLY valid JSON, no markdown fences: {"strength": "one specific '
                    .'thing they did well, one sentence", "expression": "one good sequencing word or phrase they '
                    .'actually used", "correction": "one grammar or vocabulary mistake to fix, one sentence, '
                    .'phrased kindly — prefer a tense slip (e.g. past instead of present simple) if there is one"}'
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
    $content = $run->mission->stepContent('story_sequence');
    $sequenceImages = $this->sequenceImages();
@endphp

<div class="space-y-4">
    <x-hook :text="$content['hook'] ?? null" />

    @if ($completed || ($readOnly && ($transcript || $feedback)))
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Picture Story complete
                </p>
                @if ($feedback)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">A quick look at your story before you move on.</p>
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
                        <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">One thing you did well</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['strength'] }}</p>
                    </div>
                    <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">A good sequencing word you used</p>
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
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Tell the story, in order</p>
            <p class="mt-1 text-sm text-ink-faint dark:text-ink-faint-dark">What happens first? Then what? Use Present Simple, like you just practiced.</p>
        </div>

        <x-sequential-picture-story :images="$sequenceImages" />

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
                <x-voice-recorder field="recording" :file="$recording" file-name="story-sequence.webm" />
            </div>

            @error('recording')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror

            @if ($recording)
                <x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Listening and preparing your feedback…" />
            @endif
        @endunless
    @endunless
</div>
