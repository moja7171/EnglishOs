<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Models\Evidence;
use App\Models\MissionRun;
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

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a short recap before the learner navigates on,
     * same pattern as Listening/Activation.
     */
    public bool $completed = false;

    public string $noticedSentence = '';

    public string $expressionSentence = '';

    /** @var array<string, array{severity: string, hint: string, checkedText: string}> keyed by field key */
    public array $feedback = [];

    /** @var array<string, string> keyed by field key — per-input check failure message */
    public array $checkErrors = [];

    /**
     * Self-reported — a YouTube embed has no reliable native "ended"
     * event the way Listening's <audio> element does (see its real
     * listenCount, driven by the "ended" event), so this is an honest
     * checkbox instead of a fabricated automatic watch-count.
     */
    public bool $watchedWithCaptions = false;

    public bool $watchedWithoutCaptions = false;

    public ?int $activeShadowLine = null;

    public ?UploadedFile $shadowRecording = null;

    public ?string $savedShadowUrl = null;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('video_shadowing')?->content_ref ?? '{}', true);

        $this->noticedSentence = $data['noticed_sentence'] ?? '';
        $this->expressionSentence = $data['expression_sentence'] ?? '';
        $this->watchedWithCaptions = $data['watched_with_captions'] ?? false;
        $this->watchedWithoutCaptions = $data['watched_without_captions'] ?? false;
        $this->activeShadowLine = $data['shadow_line_index'] ?? null;

        $audioEvidence = $this->run->evidence()->where('phase', 'video_shadowing')->where('type', Evidence::TYPE_AUDIO)->latest()->first();
        $this->savedShadowUrl = $audioEvidence?->content_ref;
    }

    public function checkNoticed(int $index = 0): void
    {
        $text = trim($this->noticedSentence);

        if ($text === '') {
            $this->checkErrors['noticed'] = 'Write something first.';

            return;
        }

        $this->runCheck('noticed', $this->noticedContext(), $text);
    }

    public function checkExpression(int $index = 0): void
    {
        $text = trim($this->expressionSentence);

        if ($text === '') {
            $this->checkErrors['expression'] = 'Write something first.';

            return;
        }

        $this->runCheck('expression', $this->expressionContext(), $text);
    }

    /**
     * A faithful summary of the real video (seeded per mission) so the AI
     * check can catch an answer that is fluent English but unrelated to
     * what was actually shown, not just judge grammar in isolation.
     */
    private function topicSummary(): ?string
    {
        return $this->run->mission->stepContent('video_shadowing')['topic_summary'] ?? null;
    }

    private function noticedContext(): string
    {
        $context = 'a complete English sentence describing one thing the learner noticed in a short B1-level video';

        return $this->topicSummary() ? "{$context}. The video was about: {$this->topicSummary()}" : $context;
    }

    private function expressionContext(): string
    {
        $context = 'a personal sentence using an expression the learner noticed in a short B1-level video';

        return $this->topicSummary() ? "{$context}. The video was about: {$this->topicSummary()}" : $context;
    }

    /**
     * Same shared SentenceChecker pattern as Listening/Reading
     * Comprehension — see EOS-009 §8 "الگوی چک جمله".
     */
    private function runCheck(string $key, string $context, string $text): void
    {
        unset($this->checkErrors[$key]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether what the learner wrote is a genuine, natural, complete English '
                    .'sentence about the SAME GENERAL TOPIC as the video (not just a bare word or fragment, '
                    .'and not about a completely different topic). This is a coarse topic check only — do NOT '
                    .'fact-check specific details against the topic summary (who did what, exact wording, '
                    .'etc.); the summary is background, not a source to grade accuracy against.',
                majorCriteria: 'it is just a bare word or fragment (not a real sentence), it is about a '
                    .'completely different topic than the video',
                context: $context,
                text: $text,
                extraGuidance: 'Treat anything on-topic and correctly formed as "none", even if a small detail '
                    .'is debatable — never claim the learner\'s facts are wrong, since you were only given a '
                    .'short summary, not the full video.'.$this->run->aiToneGuidance(),
            );
            $this->recordGeminiCall();

            $this->feedback[$key] = $data + ['checkedText' => $text];
            $this->trackCheckAttempt($key, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$key] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$key] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same field, the learner can ask the AI
     * to just write the corrected sentence — see TracksCheckAttempts.
     */
    public function revealNoticed(int $index = 0): void
    {
        $text = trim($this->noticedSentence);

        if ($text === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: 'noticed',
            context: $this->noticedContext(),
            text: $text,
            errorBagKey: 'noticed',
            onCorrected: function (string $corrected) {
                $this->noticedSentence = $corrected;
                $this->feedback['noticed'] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineNoticed(int $index = 0): void
    {
        $this->declineCheckReveal('noticed');
    }

    public function revealExpression(int $index = 0): void
    {
        $text = trim($this->expressionSentence);

        if ($text === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: 'expression',
            context: $this->expressionContext(),
            text: $text,
            errorBagKey: 'expression',
            onCorrected: function (string $corrected) {
                $this->expressionSentence = $corrected;
                $this->feedback['expression'] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineExpression(int $index = 0): void
    {
        $this->declineCheckReveal('expression');
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

    /**
     * Selecting a new line to shadow clears any previous recording so an
     * old take is never mistaken for a take of the newly-picked line —
     * same rule as Listening's selectShadowLine().
     */
    public function selectShadowLine(int $index): void
    {
        $this->activeShadowLine = $index;
        $this->shadowRecording = null;
    }

    public function save(): void
    {
        if (! $this->watchedWithCaptions || ! $this->watchedWithoutCaptions) {
            $this->addError('watched', 'Watch the video with captions on, then again with captions off, before continuing.');

            return;
        }

        $this->validate([
            'shadowRecording' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480'],
        ], [
            'shadowRecording.required' => 'Pick a line to shadow and record yourself saying it before continuing.',
        ]);

        $entries = [
            'noticed' => ['context' => $this->noticedContext(), 'text' => trim($this->noticedSentence)],
            'expression' => ['context' => $this->expressionContext(), 'text' => trim($this->expressionSentence)],
        ];

        if (collect($entries)->contains(fn ($entry) => $entry['text'] === '')) {
            $this->addError('sentences', 'Write both sentences before continuing.');

            return;
        }

        // Every field needs a fresh Gemini verdict before Continue is
        // allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($entries as $key => $entry) {
            $alreadyChecked = ($this->feedback[$key]['checkedText'] ?? null) === $entry['text'];

            if (! $alreadyChecked) {
                $this->runCheck($key, $entry['context'], $entry['text']);
            }
        }

        $hasMajorIssue = collect($entries)->keys()->contains(
            fn ($key) => ($this->feedback[$key]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        $mission = $this->run->mission;
        $path = $this->shadowRecording->store('missions/'.strtolower($mission->code).'/evidence', 'public');

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'noticed_sentence' => trim($this->noticedSentence),
                'expression_sentence' => trim($this->expressionSentence),
                'watched_with_captions' => $this->watchedWithCaptions,
                'watched_without_captions' => $this->watchedWithoutCaptions,
                'shadow_line_index' => $this->activeShadowLine,
            ]),
        ]);

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'video_shadowing',
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => Storage::disk('public')->url($path),
        ]);

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
        $this->savedShadowUrl = Storage::disk('public')->url($path);
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
    $draftPrefix = $this->draftPrefix();
    $checkTargets = 'checkNoticed,checkExpression,revealNoticed,declineNoticed,revealExpression,declineExpression,save';
@endphp

<div class="space-y-6">
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

            @if ($savedShadowUrl)
                <div>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your shadowing recording</p>
                    <div class="mt-1"><x-audio-player :url="$savedShadowUrl" /></div>
                </div>
            @endif

            <button
                wire:click="proceed"
                class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >
                Continue
            </button>
        </div>
    @else
        <div wire:loading.class="pointer-events-none" wire:target="{{ $checkTargets }}" class="space-y-6">
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

            <div class="space-y-3">
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm text-ink dark:text-ink-dark">Write one sentence about something you noticed in the video.</p>
                    <div class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="noticedSentence"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}noticedSentence', field: 'noticedSentence' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="{{ $checkTargets }}"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        @unless ($readOnly)
                            <x-check-button method="checkNoticed" :index="0" key-prefix="noticed_" wire-target="{{ $checkTargets }}" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkNoticed, revealNoticed" class="mt-2" />
                    @endunless

                    <x-severity-feedback :feedback="$feedback['noticed'] ?? null" :error="$checkErrors['noticed'] ?? null" />

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts['noticed'] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal['noticed'] ?? false"
                            reveal-method="revealNoticed"
                            decline-method="declineNoticed"
                            :index="0"
                            wire-target="{{ $checkTargets }}"
                        />
                    @endunless
                </div>

                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm text-ink dark:text-ink-dark">Write a sentence using one of the expressions above.</p>
                    <div class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="expressionSentence"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}expressionSentence', field: 'expressionSentence' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="{{ $checkTargets }}"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        @unless ($readOnly)
                            <x-check-button method="checkExpression" :index="0" key-prefix="expression_" wire-target="{{ $checkTargets }}" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkExpression, revealExpression" class="mt-2" />
                    @endunless

                    <x-severity-feedback :feedback="$feedback['expression'] ?? null" :error="$checkErrors['expression'] ?? null" />

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="($checkAttempts['expression'] ?? 0) === 2" />
                        <x-reveal-offer
                            :show="$offerReveal['expression'] ?? false"
                            reveal-method="revealExpression"
                            decline-method="declineExpression"
                            :index="0"
                            wire-target="{{ $checkTargets }}"
                        />
                    @endunless
                </div>
            </div>
            @error('sentences')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            @if (count($shadowLines))
                <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Shadow a line</p>
                    @unless ($readOnly)
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Pick a real line, replay just that moment, and repeat it out loud until your rhythm matches.</p>
                    @endunless

                    @unless ($readOnly)
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($shadowLines as $index => $line)
                                <button
                                    type="button"
                                    wire:click="selectShadowLine({{ $index }})"
                                    @class([
                                        'cursor-pointer rounded-full border px-2.5 py-1 text-xs transition-colors',
                                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $activeShadowLine === $index,
                                        'border-line text-ink-soft hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark' => $activeShadowLine !== $index,
                                    ])
                                >Line {{ $index + 1 }}</button>
                            @endforeach
                        </div>
                    @endunless

                    @if ($activeShadowLine !== null)
                        <p class="mt-3 text-xs text-ink-faint dark:text-ink-faint-dark">Bold words are usually stressed — try to make them a little longer and louder than the rest.</p>
                        <p class="mt-1 text-sm text-ink dark:text-ink-dark">"<x-stress-marked-line :text="$shadowLines[$activeShadowLine]" />"</p>
                        @if ($readOnly)
                            <div class="mt-2">
                                <x-audio-player :url="$savedShadowUrl" />
                            </div>
                        @else
                            <div class="mt-2" wire:key="shadow-recorder-{{ $activeShadowLine }}">
                                <x-voice-recorder field="shadowRecording" :file="$shadowRecording" file-name="video-shadow.webm" />
                            </div>
                        @endif
                    @endif
                </div>
            @endif
            @error('shadowRecording')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror

            @unless ($readOnly)
                <x-continue-button
                    on-click="$wire.save()"
                    wire-target="{{ $checkTargets }}"
                    loading-label="Checking your work…"
                />
            @endunless
        </div>
    @endif
</div>
