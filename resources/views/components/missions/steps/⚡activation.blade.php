<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use TracksAiUsage;
    use TracksCheckAttempts;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $sentences = ['', '', '', '', ''];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by sentence index */
    public array $feedback = [];

    /** @var array<int, string> keyed by sentence index — per-input check failure message */
    public array $checkErrors = [];

    public ?UploadedFile $audioFile = null;

    public ?string $savedAudioUrl = null;

    /**
     * The Groq transcript of the recording and a short, non-blocking
     * Persian reflection on it — purely informational, never gates
     * progress (Evidence is already saved by the time these are computed).
     * Null if generation failed or hasn't run yet.
     */
    public ?string $transcript = null;

    /**
     * The same transcript, split into Whisper's own segments and tagged
     * with a rough confidence tier — see GroqClient::transcribeWithConfidence().
     * Purely additive coloring on top of $transcript, which stays the
     * source of truth (used for the AI reflection prompt, vocabulary
     * matching, etc.); empty when generation failed, hasn't run yet, or
     * (for Evidence saved before this feature existed) was never recorded.
     *
     * @var list<array{text: string, confidence: string}>
     */
    public array $segments = [];

    /** @var array{highlight: string, tip: string}|null */
    public ?array $reflection = null;

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows the transcript + reflection recap before the
     * learner actually navigates on, instead of jumping away immediately.
     */
    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $textEvidence = $this->run->evidence()->where('phase', 'activation')->where('type', Evidence::TYPE_TEXT)->latest()->first();
        $data = json_decode($textEvidence?->content_ref ?? '{}', true);
        $this->sentences = array_pad($data['sentences'] ?? [], 5, '');
        $this->transcript = $data['transcript'] ?? null;
        $this->segments = $data['segments'] ?? [];
        $this->reflection = $data['reflection'] ?? null;

        $audioEvidence = $this->run->evidence()->where('phase', 'activation')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedAudioUrl = $audioEvidence?->content_ref;
    }

    public function checkOne(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            $this->checkErrors[$index] = 'Write something first.';

            return;
        }

        $this->runCheck($index, $sentence);
    }

    /**
     * Asks the shared SentenceChecker to judge one personal sentence,
     * storing the verdict tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale verdict attached to different text.
     * See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $sentence): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner wrote a genuine, natural personal sentence about their own '
                    .'daily life.',
                majorCriteria: 'it is just a fragment (not a real sentence), or it is not actually about the '
                    .'learner\'s own daily life',
                context: "a personal sentence about the learner's own daily life",
                text: $sentence,
                extraGuidance: $this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
            $this->trackCheckAttempt($index, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same sentence, the learner can ask the
     * AI to just write the corrected version — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $sentence = trim($this->sentences[$index] ?? '');

        if ($sentence === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $index,
            context: "a personal sentence about the learner's own daily life",
            text: $sentence,
            errorBagKey: $index,
            onCorrected: function (string $corrected) use ($index) {
                $this->sentences[$index] = $corrected;
                $this->feedback[$index] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $this->declineCheckReveal($index);
    }

    public function save(): void
    {
        $this->validate([
            'sentences' => 'array',
            'sentences.*' => 'nullable|string',
            'audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ]);

        $filledSentences = collect($this->sentences)
            ->map(fn ($s, $i) => ['index' => $i, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < 5) {
            $this->addError('sentences', 'Write all 5 personal sentences before continuing.');

            return;
        }

        // Every filled sentence needs a fresh verdict before Continue is
        // allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($filledSentences as $item) {
            $alreadyChecked = ($this->feedback[$item['index']]['checkedText'] ?? null) === $item['text'];

            if (! $alreadyChecked) {
                $this->runCheck($item['index'], $item['text']);
            }
        }

        $hasMajorIssue = $filledSentences->contains(
            fn ($item) => ($this->feedback[$item['index']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        $mission = $this->run->mission;

        $path = $this->audioFile->store('missions/'.strtolower($mission->code).'/evidence', 'public');

        $this->transcribeAndReflect();

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'sentences' => $filledSentences->pluck('text')->values(),
                'transcript' => $this->transcript,
                'segments' => $this->segments,
                'reflection' => $this->reflection,
            ]),
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'activation',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => Storage::disk('public')->url($path),
        ]);

        // Progress is already saved — this only decides what the learner sees
        // next: the transcript + reflection recap, which they dismiss with
        // proceed() below. A failed transcription/reflection never blocks
        // this — it's purely informational, not a requirement.
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    /**
     * Transcribes the recording and asks Gemini for a short, warm, non-
     * blocking reflection in Persian — never a grade, never gates progress.
     * This is the one deliberate exception to the app's English-only AI
     * output rule, at the learner's explicit request (see EOS-009 §8).
     * Failure here is silent by design: $transcript/$reflection just stay
     * null and the recap simply omits them.
     *
     * Also feeds the AI a genuine speaking-pace signal (words per minute,
     * from Whisper's own reported duration — not guessed) and a filler-
     * word count, so the reflection can speak to HOW something was said,
     * not just what — the closest this app gets to real pronunciation/
     * fluency coaching without an actual phonetic-analysis model.
     */
    private function transcribeAndReflect(): void
    {
        try {
            $result = app(GroqClient::class)->transcribeWithConfidence($this->audioFile->getRealPath());
            $this->recordGroqCall();
            $this->transcript = trim($result['text']);
            $this->segments = $result['segments'];

            if ($this->transcript === '') {
                return;
            }

            $vocabularyWords = $this->run->selectedVocabularyWords();
            $vocabularyContext = $vocabularyWords
                ? ' The learner\'s target vocabulary words for this mission were: '
                    .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ')
                    .'. If any of these appear naturally in the transcript, you can mention that warmly.'
                : '';

            $paceContext = $this->paceContext($this->transcript, $result['duration']);

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => "Transcript: \"{$this->transcript}\""]],
                systemPrompt: 'You are a supportive English speaking coach. '.ucfirst($this->run->learner->levelDescription())
                    .' just recorded about 2 '
                    .'minutes of solo speaking in English about their daily life — this is low-pressure fluency '
                    .'practice, not a graded test.'.$vocabularyContext.$paceContext.' Given the transcript, write a short, '
                    .'warm, simple reflection in PERSIAN (Farsi) — never English, and never grade it or use '
                    .'severity labels. Reply with ONLY valid JSON, no markdown fences: {"highlight": "...", '
                    .'"tip": "..."} — "highlight" is one short encouraging sentence in Persian about something '
                    .'they did well; "tip" is one short, gentle, actionable suggestion in Persian for next time. '
                    .'Only mention pace or filler words if the numbers given are genuinely notable (very slow, '
                    .'very fast, or many filler words) — otherwise focus the tip on content/language as usual. '
                    .'Keep both simple, plain Persian, no jargon, no English words mixed in unless quoting a '
                    .'specific English word or phrase they actually said.'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (is_array($data) && isset($data['highlight'], $data['tip'])) {
                $this->reflection = ['highlight' => $data['highlight'], 'tip' => $data['tip']];
            }
        } catch (Throwable) {
            // Silent by design — see method docblock.
        }
    }

    /**
     * A short parenthetical for the reflection prompt above, built from a
     * REAL duration (Whisper's own, not estimated) — skipped entirely for
     * very short clips where words-per-minute would be noise, not signal.
     */
    private function paceContext(string $transcript, float $durationSeconds): string
    {
        if ($durationSeconds < 10.0) {
            return '';
        }

        $wordCount = count(array_filter(preg_split('/\s+/', trim($transcript))));
        $wordsPerMinute = (int) round($wordCount / ($durationSeconds / 60));
        $fillerCount = preg_match_all('/\b(um|uh|erm)\b/i', $transcript);

        return " (For your awareness only: the learner spoke at roughly {$wordsPerMinute} words per minute over "
            .round($durationSeconds).' seconds, with '.$fillerCount.' filler words like "um"/"uh".)';
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
        return "eos-draft:{$this->run->id}:activation:";
    }
};
?>

@php
    $activation = $run->mission->stepContent('activation');
    $vocabularyWords = $run->selectedVocabularyWords();
    $initialFilled = collect($sentences)->map(fn ($s) => trim((string) $s) !== '')->values();
    $draftPrefix = $this->draftPrefix();
    // Same questions from Mission Brief's warm-up, on purpose — a
    // learner who could barely answer them unprepared on Day 1 gets to
    // feel the difference now, with a whole mission of practice behind
    // them (see EOS-009 §8).
    $warmUpQuestions = $run->mission->stepContent('mission_brief')['warm_up_questions'] ?? [];
@endphp

<div class="space-y-6" x-data="{
    filled: {{ $initialFilled->toJson() }},
    dismissed: {},
    activeSection: 0,
    get filledCount() { return this.filled.filter(Boolean).length },
}">
    <x-hook :text="$activation['hook'] ?? null" />

    @if ($completed || ($readOnly && ($transcript || $reflection)))
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Activation complete
                </p>
                @if ($reflection)
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Here's a quick recap of your recording.</p>
                @endif
            </div>

            @if ($reflection)
                <div class="space-y-2 rounded-2xl border border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark" dir="rtl">
                    <p class="text-sm text-ink dark:text-ink-dark">{{ $reflection['highlight'] }}</p>
                    <p class="flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                        @svg('heroicon-o-light-bulb', 'h-4 w-4 shrink-0 mt-0.5')
                        {{ $reflection['tip'] }}
                    </p>
                </div>
            @endif

            @if ($transcript)
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you said</p>
                    <x-confidence-transcript :segments="$segments" :fallback="$transcript" class="mt-1" />
                </div>
            @endif

            @if ($activation['task'] ?? null)
                <div>
                    <x-practice-with-friend
                        :text="$activation['task']"
                        intro="Hey — want to talk about your daily routine together:"
                        label="Talk about this with a friend"
                    />
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
    <div class="mb-2">
        <x-progress-bar>
            <div
                class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                :style="`width: ${(activeSection + 1) / 2 * 100}%`"
            ></div>
            <x-slot:label>
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                    Part <span x-text="activeSection + 1"></span> of 2
                </p>
            </x-slot:label>
        </x-progress-bar>
    </div>

    <div x-show="activeSection === 0" x-cloak>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Write 5 personal sentences</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $activation['task'] ?? '' }}</p>
        @if ($vocabularyWords && ! $readOnly)
            <div class="mt-2">
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Tap a word to drop it into your next sentence:</p>
                <div class="mt-1">
                    <x-vocabulary-chips
                        :words="$vocabularyWords"
                        field="sentences"
                        ref-prefix="sentence_input_"
                        on-insert="filled[idx] = true; dismissed[idx] = true;"
                    />
                </div>
            </div>
        @endif

        @unless ($readOnly)
            <div class="mt-2">
                <x-progress-bar>
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :class="filledCount >= 5 ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                        :style="`width: ${Math.min(filledCount, 5) / 5 * 100}%`"
                    ></div>
                    <x-slot:label>
                        <p
                            class="text-xs font-semibold transition-colors"
                            :class="filledCount >= 5 ? 'text-success dark:text-success-dark' : 'text-ink-soft dark:text-ink-soft-dark'"
                            x-text="`${Math.min(filledCount, 5)} of 5 written`"
                        ></p>
                    </x-slot:label>
                </x-progress-bar>
            </div>
        @endunless

        <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="mt-2 space-y-3">
            @foreach ($sentences as $index => $sentence)
                @php $itemFeedback = $feedback[$index] ?? null; @endphp
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            x-ref="sentence_input_{{ $index }}"
                            wire:model="sentences.{{ $index }}"
                            placeholder="{{ $index + 1 }}."
                            x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}sentences.{{ $index }}', field: 'sentences.{{ $index }}' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="checkOne,revealCorrection,declineReveal,save"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        <x-filled-check show="filled[{{ $index }}]" />
                        @unless ($readOnly)
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,save" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                    @endunless

                    <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                        <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />
                    </div>

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts[$index] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal[$index] ?? false"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,save"
                        />
                    @endunless
                </div>
            @endforeach
        </div>
        @error('sentences')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </div>

    <div x-show="activeSection === 1" x-cloak>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Solo speaking — 2 minutes</p>

        @if ($readOnly)
            <div class="mt-2">
                <x-audio-player :url="$savedAudioUrl" />
            </div>
        @else
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Talk about your daily life without reading. Record when you're ready.</p>

            @if (count($warmUpQuestions))
                <div class="mt-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-xs font-semibold text-ink dark:text-ink-dark">Same questions as Day 1 — how does it feel now?</p>
                    <ul class="mt-2 space-y-1.5">
                        @foreach ($warmUpQuestions as $question)
                            <li class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $question }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-3">
                <x-voice-recorder field="audioFile" :file="$audioFile" file-name="activation-speaking.webm" />
            </div>

            @error('audioFile')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        @endif

        @unless ($readOnly)
            {{-- The recording itself can only be confirmed server-side
                 (an upload needs a real round-trip either way), so that
                 part gates via a plain @if; filledCount stays reactive
                 client-side via ready-when. --}}
            @if ($audioFile)
                <div class="mt-4">
                    <x-continue-button
                        on-click="filled.forEach((_, i) => dismissed[i] = true); $wire.save().then(() => { dismissed = {} })"
                        wire-target="checkOne,revealCorrection,declineReveal,save"
                        loading-label="Checking your sentences and preparing your recap…"
                        ready-when="filledCount >= 5"
                    />
                </div>
            @endif
        @endunless
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSection" :total="2" />
    </div>
    @endunless
</div>
