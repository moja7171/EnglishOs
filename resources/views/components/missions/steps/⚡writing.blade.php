<?php

use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFeedbackCard;
use App\Services\LearnerFeedback;
use App\Services\PexelsClient;
use Livewire\Component;

/**
 * Writing, step by step (lesson design 10): each of the mission's idea
 * prompts on its own screen (picture + one or two sentences, a running
 * word count), then the sentences joined into one paragraph to polish with
 * connector chips and send, then the shared feedback screen. No mic —
 * this step is for writing.
 */
new class extends Component
{
    use PreviewsStep;
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    public string $text = '';

    /** @var array<string, mixed>|null A LearnerFeedback::review() result (or an older saved shape). */
    public ?array $feedback = null;

    /**
     * True once Continue has saved the essay — the step then shows AI
     * feedback on the writing (<x-learner-feedback>)
     * before the learner dismisses it with proceed() below, instead of
     * jumping away immediately. Progress is already saved by then; a
     * failed feedback call never blocks it, see generateFeedback().
     */
    public bool $completed = false;

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $this->text = $this->run->latestEvidence('writing')?->content_ref ?? '';

        $data = json_decode($this->run->latestEvidence('writing_feedback')?->content_ref ?? '{}', true);

        if (is_array($data) && $data !== []) {
            $this->feedback = $data;
        }
    }

    /**
     * English-only word count (Epic F) — the old preg_split counted ANY
     * whitespace-separated token regardless of language, so Persian text
     * pasted in by mistake still counted toward the target. Only tokens
     * with at least one Latin letter count as a real word now.
     */
    public function getWordCountProperty(): int
    {
        return count(array_filter(
            preg_split('/\s+/', trim($this->text)),
            fn ($token) => preg_match('/[A-Za-z]/', $token) === 1,
        ));
    }

    /**
     * One small inspirational thumbnail per writing prompt — dual-coding,
     * same principle as Vocabulary Builder/Reading Comprehension. Purely
     * decorative, fails soft like every PexelsClient call.
     *
     * @return array<string, string> keyed by prompt label
     */
    public function promptImageUrls(): array
    {
        $prompts = $this->run->mission->stepContent('writing')['prompts'] ?? [];
        $client = app(PexelsClient::class);

        return collect($prompts)
            ->filter(fn ($prompt) => is_array($prompt) && ($prompt['image_query'] ?? null))
            ->mapWithKeys(fn ($prompt) => [
                $prompt['label'] => $client->imageUrlFor(
                    $this->run->mission->code.'-writing-'.$prompt['label'],
                    $prompt['image_query'],
                ),
            ])
            ->filter()
            ->all();
    }

    public function save(): void
    {
        $minWords = $this->run->mission->stepContent('writing')['min_words'] ?? 100;

        if ($this->wordCount < $minWords) {
            $this->addError('text', "Write at least {$minWords} words before continuing.");

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $this->text,
        ]);

        $this->generateFeedback();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    /**
     * The shared sentence-by-sentence feedback (see LearnerFeedback) —
     * 'writing' itself was the only free-text step nobody ever actually
     * read with AI. Stored as its
     * OWN phase ('writing_feedback'), deliberately not a real step key in
     * any mission, so it's just extra data alongside the essay Evidence —
     * never affects step progression, and Error Log's gatherLearnerText()
     * (which reads the 'writing' Evidence as plain text) stays untouched.
     * A failure never blocks the step (the essay's Evidence is already
     * saved) but no longer passes in silence: the step says so and offers
     * retryFeedback().
     */
    private function generateFeedback(): void
    {
        try {
            $vocabularyContext = app(AiFeedbackCard::class)->vocabularyContext($this->run->selectedVocabularyWords());

            $data = app(LearnerFeedback::class)->review(
                $this->run->learner,
                $this->text,
                situation: 'a short piece of writing the learner wrote for this mission. Every sentence is theirs.',
                source: 'writing',
                extraGuidance: trim($vocabularyContext),
                onCallSucceeded: fn () => $this->recordGeminiCall(),
            );

            $this->feedback = $data;

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => 'writing_feedback',
                'type' => Evidence::TYPE_TEXT,
                'content_ref' => json_encode($data),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Asks for the feedback again — after a failed call, or for an essay
     * saved back when a failure showed nothing at all.
     */
    public function retryFeedback(): void
    {
        if ($this->preview || $this->feedback) {
            return;
        }

        $this->text = $this->run->latestEvidence('writing')?->content_ref ?? $this->text;

        if (trim($this->text) === '') {
            return;
        }

        $this->generateFeedback();
    }

    public function proceed(): void
    {
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attribute exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:writing:";
    }
};
?>

@php
    $writing = $run->mission->stepContent('writing');
    $draftPrefix = $this->draftPrefix();
    $prompts = collect($writing['prompts'] ?? [])->map(fn ($prompt) => is_array($prompt) ? ($prompt['label'] ?? '') : $prompt)->filter()->values();
    $promptImages = $this->promptImageUrls();
    $minWords = $writing['min_words'] ?? 100;
    $paragraphScreen = $prompts->count() + 1;
    $feedbackScreen = $paragraphScreen + 1;
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-4 pt-2">
        <x-step-intro kind="Writing" :title="$writing['title'] ?? 'Writing'" />
        @if ($feedback)
            <x-learner-feedback :feedback="$feedback">
                <x-slot:more>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you wrote</p>
                    <p class="text-sm whitespace-pre-line text-ink dark:text-ink-dark">{{ $text }}</p>
                </x-slot:more>
            </x-learner-feedback>
        @else
            @unless ($preview)
                <div class="flex flex-col items-start gap-3 rounded-2xl bg-surface-sunken p-4 dark:bg-surface-sunken-dark" data-writing-feedback-missing>
                    <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">بازخورد این متن نیومد.</p>
                    <button type="button" wire:click="retryFeedback" wire:loading.attr="disabled" wire:target="retryFeedback" class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Get feedback</button>
                </div>
            @endunless
            <p class="text-sm whitespace-pre-line text-ink dark:text-ink-dark">{{ $text }}</p>
        @endif
    </div>
@else
<div
    wire:ignore.self
    x-data="{
        screen: {{ $completed ? $feedbackScreen : 0 }},
        parts: [],
        init() {
            try { this.parts = JSON.parse(localStorage.getItem('{{ $draftPrefix }}parts')) ?? []; } catch (e) {}
            this.$watch('parts', (v) => { try { localStorage.setItem('{{ $draftPrefix }}parts', JSON.stringify(v)) } catch (e) {} });
        },
        words(text) { return (text ?? '').trim().split(/\s+/).filter((t) => /[A-Za-z]/.test(t)).length },
        get partWords() { return this.parts.reduce((sum, part) => sum + this.words(part), 0) },
        toParagraph() {
            if (! ($wire.text ?? '').trim()) $wire.text = this.parts.map((p) => (p ?? '').trim()).filter(Boolean).join(' ');
            this.screen = {{ $paragraphScreen }};
        },
        insert(word) {
            const box = this.$refs.paragraph;
            const text = $wire.text ?? '';
            const at = box && box.selectionStart !== undefined ? box.selectionStart : text.length;
            const before = text.slice(0, at).replace(/\s+$/, '');
            $wire.text = before + (before ? ' ' : '') + word + ' ' + text.slice(at).replace(/^\s+/, '');
            this.$nextTick(() => box?.focus());
        },
    }"
    x-effect="$dispatch('step-progress', { share: (screen + 0.5) / {{ $feedbackScreen + 1 }} })"
    x-back-close.step="{ open: screen > 0 && screen < {{ $feedbackScreen }}, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Writing" :minutes="$run->mission->stepDuration('writing')" :title="$writing['title'] ?? 'Writing'" />
        @if (filled($writing['hook'] ?? null))
            <p class="text-base leading-relaxed text-ink-soft dark:text-ink-soft-dark">{{ $writing['hook'] }}</p>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- One idea per screen: never a blank page. --}}
    @foreach ($prompts as $index => $label)
        <x-lesson-screen when="screen === {{ $index + 1 }}">
            <p class="self-end text-xs font-extrabold text-ink-faint tabular-nums dark:text-ink-faint-dark"><span x-text="partWords">0</span> / {{ $minWords }} words</p>
            <div class="flex items-center gap-3">
                @if ($imageUrl = $promptImages[$label] ?? null)
                    <x-zoomable-image :url="$imageUrl" :alt="$label" class="h-14 w-14 shrink-0 rounded-2xl object-cover" />
                @endif
                <p class="lesson-prompt text-ink dark:text-ink-dark">{{ $label }}</p>
            </div>
            <textarea
                x-model="parts[{{ $index }}]"
                rows="5"
                placeholder="One or two sentences…"
                class="w-full resize-none rounded-2xl border-2 border-line bg-surface px-4 py-3 text-base leading-relaxed text-ink focus:border-accent focus:ring-0 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:focus:border-accent-dark"
            ></textarea>

            <x-slot:actions>
                @if ($index + 1 < $prompts->count())
                    <x-continue-button on-click="screen++" wire-target="save">Next idea</x-continue-button>
                @else
                    <x-continue-button on-click="toParagraph()" wire-target="save" />
                @endif
            </x-slot:actions>
        </x-lesson-screen>
    @endforeach

    <x-lesson-screen when="screen === {{ $paragraphScreen }}">
        <div class="flex items-center justify-between gap-3">
            <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $writing['title'] ?? 'Writing' }}</span>
            <span class="text-xs font-extrabold tabular-nums" :class="words($wire.text) >= {{ $minWords }} ? 'text-success dark:text-success-dark' : 'text-ink-faint dark:text-ink-faint-dark'"><span x-text="words($wire.text)"></span> words<span x-show="words($wire.text) >= {{ $minWords }}"> ✓</span></span>
        </div>
        <textarea
            x-ref="paragraph"
            wire:model="text"
            rows="12"
            placeholder="Your text…"
            x-draft="{ key: '{{ $draftPrefix }}text', field: 'text' }"
            class="w-full flex-1 resize-none rounded-2xl border-2 border-line bg-surface px-4 py-3 text-base leading-relaxed text-ink focus:border-accent focus:ring-0 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:focus:border-accent-dark"
        ></textarea>
        @if (count($writing['try_to_use'] ?? []))
            <div class="flex flex-wrap gap-1.5">
                @foreach ($writing['try_to_use'] as $word)
                    <button type="button" x-on:click="insert(@js($word))" class="cursor-pointer rounded-full border-2 border-dashed border-line px-3 py-1 text-xs font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">+ {{ $word }}</button>
                @endforeach
            </div>
        @endif
        @error('text')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror

        <x-slot:actions>
            <x-continue-button
                on-click="screen = {{ $feedbackScreen }}; $wire.save().then(() => { if (! $wire.completed) screen = {{ $paragraphScreen }} })"
                wire-target="save"
                ready-when="words($wire.text) >= {{ $minWords }}"
                hint="{{ $minWords }} words to send"
            >Send for feedback</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    @if ($completed && ! $feedback)
        <x-lesson-screen when="screen === {{ $feedbackScreen }}">
            <x-step-intro kind="Feedback" />
            <div class="flex flex-col items-start gap-3 rounded-2xl bg-surface-sunken p-4 dark:bg-surface-sunken-dark" data-writing-feedback-missing>
                <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">بازخورد این متن نیومد.</p>
                <button type="button" wire:click="retryFeedback" wire:loading.attr="disabled" wire:target="retryFeedback" class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">
                    <span wire:loading.remove wire:target="retryFeedback">@svg('heroicon-o-arrow-path', 'h-4 w-4')</span>
                    <span wire:loading wire:target="retryFeedback">@svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin')</span>
                    Get feedback
                </button>
            </div>
            <p class="text-sm whitespace-pre-line text-ink dark:text-ink-dark">{{ $text }}</p>

            <x-slot:actions>
                <x-continue-button on-click="$wire.proceed()" wire-target="proceed" />
            </x-slot:actions>
        </x-lesson-screen>
    @else
        <x-feedback-screen when="screen === {{ $feedbackScreen }}" :feedback="$feedback" :waiting="! $completed">
            <x-slot:said>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">What you wrote</p>
                <p class="text-sm whitespace-pre-line text-ink dark:text-ink-dark">{{ $text }}</p>
            </x-slot:said>

            <x-slot:actions>
                <x-continue-button on-click="$wire.proceed()" wire-target="proceed" ready-when="{{ $completed ? 'true' : 'false' }}" />
            </x-slot:actions>
        </x-feedback-screen>
    @endif
</div>
@endif
