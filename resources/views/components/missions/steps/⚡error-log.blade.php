<?php

use App\Livewire\Concerns\DictatesAnswers;
use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\AiFailure;
use App\Services\GeminiClient;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Lesson design 12: a waiting screen while the AI finds the learner's top
 * mistakes in everything they said and wrote, then one screen per mistake
 * (wrong → right, the Persian why, and a new sentence of their own, typed
 * or said), then one short line that the patterns come back in Review.
 */
new class extends Component
{
    use DictatesAnswers;
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, array{error: string, correction: string, why: ?string, category: ?string}> */
    public array $mistakes = [];

    /** @var array<int, string> */
    public array $newExamples = [];

    public bool $generated = false;

    public ?string $error = null;

    /**
     * mount() used to call generate() unconditionally, so every page visit
     * — including just revisiting an incomplete step — fired a real Gemini
     * call. It now only loads already-saved mistakes in read-only mode; a
     * fresh review waits for the learner to press the button below, which
     * calls generate() explicitly. A review already generated comes back
     * from the step's draft, so leaving never costs another AI call.
     */
    public function mount(): void
    {
        if (! $this->readOnly) {
            $this->restoreStepDraft();

            return;
        }

        // A preview has no mistakes of its own to show — leave it on the
        // "not reviewed yet" state, which the view words for previews.
        if (! $this->preview) {
            $items = $this->run->errorLogItems;
            $this->mistakes = $items->map(fn ($i) => [
                'error' => $i->error,
                'correction' => $i->correction,
                'why' => $i->why,
                'category' => $i->category,
            ])->all();
            $this->newExamples = $items->pluck('new_example')->all();
            $this->generated = true;
        }
    }

    protected function draftPhase(): string
    {
        return 'error_log';
    }

    protected function draftedProperties(): array
    {
        return ['mistakes', 'generated', 'newExamples'];
    }

    public function updatedNewExamples(): void
    {
        $this->keepStepDraft();
    }

    /**
     * The mic in a mistake's answer box: what was said becomes that
     * mistake's new sentence.
     */
    public function dictateExample(int $index): void
    {
        $text = isset($this->mistakes[$index]) ? $this->dictatedText($index) : null;

        if ($text === null) {
            return;
        }

        $this->newExamples[$index] = $text;
        $this->keepStepDraft();
    }

    public function generate(): void
    {
        $this->error = null;

        try {
            $text = $this->run->allLearnerText();

            if (trim($text) === '') {
                $this->mistakes = [];
                $this->generated = true;

                return;
            }

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $text]],
                systemPrompt: 'You are an English teacher reviewing everything '.$this->run->learner->levelDescription()
                    .' said and wrote during a '
                    .'lesson. Identify 3 to 5 recurring or notable grammar/vocabulary mistakes. For EACH mistake, assign a short '
                    .'category slug (lowercase, hyphenated, 1-3 words, e.g. "third-person-s", "article-usage", '
                    .'"preposition", "verb-tense", "word-order") naming the general grammar/vocabulary pattern the '
                    .'mistake belongs to — so the SAME pattern can be recognised again in a future lesson even '
                    .'though the sentence itself will be different. Also write a "why" field: one short sentence, '
                    .'in PERSIAN (Farsi), explaining the grammar or vocabulary rule behind the mistake — plain '
                    .'Persian, no English words mixed in unless quoting a specific English word or phrase, always in Latin '
                    .'letters inside «», e.g. «study» — never spell an English word in Persian letters. Reply '
                    .'with ONLY a valid JSON array, no markdown fences, no extra text, each item shaped exactly '
                    .'like: {"error": "the mistake as the learner wrote/said it", "correction": "the corrected '
                    .'form", "why": "Persian explanation of the rule", '
                    .'"category": "short-slug"}. '
                    .'If the learner made no real mistakes, reply with an empty JSON array: []',
                profile: GeminiClient::PROFILE_JUDGE,
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->mistakes = collect($data)
                ->map(fn ($item) => $item + ['category' => null, 'why' => null])
                ->all();
            $this->newExamples = array_fill(0, count($data), '');
            $this->generated = true;

            $this->keepStepDraft();
        } catch (Throwable $e) {
            $this->error = AiFailure::message($e, "Couldn't get the error log from the AI Instructor");
        }
    }

    public function save(): void
    {
        if (empty($this->mistakes)) {
            $this->finishWithoutErrors();

            return;
        }

        $incomplete = collect($this->newExamples)->filter(fn ($e) => trim((string) $e) === '')->isNotEmpty();

        if ($incomplete) {
            $this->addError('newExamples', 'Write a new correct sentence for every error before continuing.');

            return;
        }

        foreach ($this->mistakes as $i => $item) {
            ErrorLogItem::create([
                'mission_run_id' => $this->run->id,
                'error' => $item['error'],
                'correction' => $item['correction'],
                'why' => $item['why'] ?? null,
                'new_example' => trim($this->newExamples[$i]),
                'category' => $item['category'] ?? null,
            ]);

            // Only actually starts tracking a spaced-repetition item once
            // this category has recurred across 2+ missions — see
            // User::syncErrorPatternReview().
            if ($item['category'] ?? null) {
                $this->run->learner->syncErrorPatternReview($item['category'], $item['error'], $item['correction']);
            }
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode($this->mistakes),
        ]);

        $this->forgetStepDraft();

        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    /**
     * Offered next to the retry button when the AI review could not be
     * produced (every judge model failed — GeminiClient has already logged
     * why): the learner must never be stuck here, so they can move on. The
     * step is saved with no fixes, like "no mistakes found".
     */
    public function skipForNow(): void
    {
        $this->finishWithoutErrors();
    }

    private function finishWithoutErrors(): void
    {
        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([]),
        ]);

        $this->forgetStepDraft();

        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }
};
?>

@if ($readOnly)
    <div class="flex flex-col gap-3 pt-2">
        <x-step-intro kind="My Fixes" />
        @if (! $generated)
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Your own mistakes — fills in once you reach this step.</p>
        @elseif (empty($mistakes))
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">No recurring mistakes found — nice work!</p>
        @endif
        @foreach ($mistakes as $i => $item)
            <div class="flex flex-col gap-1 rounded-2xl bg-surface p-4 dark:bg-surface-dark">
                <p class="text-sm text-danger-ink line-through decoration-danger">{{ $item['error'] }}</p>
                <p class="text-sm font-bold text-success dark:text-success-dark">{{ $item['correction'] }}</p>
                @if (! empty($item['why']))
                    <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $item['why'] }}</p>
                @endif
                @if (filled($newExamples[$i] ?? null))
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $newExamples[$i] }}</p>
                @endif
            </div>
        @endforeach
    </div>
@elseif (! $generated || $error)
    {{-- The waiting screen: the review starts on its own (a review already
         made comes back from the step's draft, so a revisit never calls
         the AI again). --}}
    <div wire:key="error-log-waiting" @if (! $generated && ! $error) x-init="$wire.generate()" @endif x-effect="$dispatch('step-progress', { share: 0.05 })">
        <x-lesson-screen when="true">
            <div class="flex flex-1 flex-col items-center justify-center gap-4 text-center">
                @if ($error)
                    <p class="text-sm text-danger-ink">{{ $error }}</p>
                    <button
                        wire:click="generate"
                        wire:loading.attr="disabled"
                        wire:target="generate"
                        class="cursor-pointer rounded-full border-2 border-line px-5 py-2 text-sm font-bold text-ink transition-colors hover:bg-surface disabled:opacity-50 dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-dark"
                    >
                        <span wire:loading.remove wire:target="generate">Try again</span>
                        <span wire:loading wire:target="generate">Trying again…</span>
                    </button>
                @else
                    <span class="h-16 w-16 animate-pulse rounded-full bg-[radial-gradient(circle_at_35%_35%,var(--color-accent-soft),var(--color-accent))]"></span>
                    <p class="lesson-prompt text-ink dark:text-ink-dark">Finding your top mistakes…</p>
                    <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">From everything you said and wrote</p>
                @endif
            </div>

            @if ($error)
                <x-slot:actions>
                    <button wire:click="skipForNow" wire:loading.attr="disabled" wire:target="skipForNow" class="cursor-pointer text-sm font-bold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">Skip this step for now</button>
                </x-slot:actions>
            @endif
        </x-lesson-screen>
    </div>
@elseif (empty($mistakes))
    <div wire:key="error-log-none" x-effect="$dispatch('step-progress', { share: 0.9 })">
        <x-lesson-screen when="true">
            <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-o-check', 'h-8 w-8')</span>
                <p class="lesson-prompt text-ink dark:text-ink-dark">No recurring mistakes found — nice work!</p>
            </div>

            <x-slot:actions>
                <x-continue-button on-click="$wire.save()" wire-target="save" />
            </x-slot:actions>
        </x-lesson-screen>
    </div>
@else
    @php $total = count($mistakes); @endphp
    <div
        wire:key="error-log-fixes"
        x-data="{ screen: 0, filled: @js(collect($newExamples)->map(fn ($e) => trim((string) $e) !== '')->all()) }"
        x-effect="$dispatch('step-progress', { share: (screen + 0.5) / {{ $total + 1 }} })"
        x-back-close.step="{ open: screen > 0, close: () => screen-- }"
    >
        @foreach ($mistakes as $i => $item)
            <x-lesson-screen when="screen === {{ $i }}">
                <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Fix {{ $i + 1 }} of {{ $total }}</span>

                <div class="flex flex-col gap-1.5 rounded-3xl bg-surface p-4 dark:bg-surface-dark">
                    <p class="text-base text-danger-ink line-through decoration-danger">{{ $item['error'] }}</p>
                    <p class="text-base font-extrabold text-success dark:text-success-dark">{{ $item['correction'] }}</p>
                    @if (! empty($item['why']))
                        <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $item['why'] }}</p>
                    @endif
                </div>

                <p class="lesson-prompt text-ink dark:text-ink-dark">Now a new sentence of your own, the right way</p>

                <x-type-or-say
                    model="newExamples.{{ $i }}"
                    method="dictateExample"
                    :param="$i"
                    draft-key="error-log-{{ $run->id }}-{{ $i }}"
                    placeholder="Your sentence…"
                    on-input="filled[{{ $i }}] = $el.value.trim() !== ''"
                    :rows="2"
                    :error="$dictationErrors[$i] ?? null"
                />

                <x-slot:actions>
                    <x-continue-button
                        on-click="screen = {{ $i + 1 }}"
                        wire-target="dictateExample"
                        ready-when="filled[{{ $i }}] || {{ filled($newExamples[$i] ?? null) ? 'true' : 'false' }}"
                    />
                </x-slot:actions>
            </x-lesson-screen>
        @endforeach

        <x-lesson-screen when="screen === {{ $total }}">
            <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                <span class="flex h-16 w-16 items-center justify-center rounded-full bg-success text-white dark:bg-success-dark">@svg('heroicon-o-check', 'h-8 w-8')</span>
                <p class="font-fa lesson-prompt text-ink dark:text-ink-dark" dir="rtl">{{ $total }} الگو رو اصلاح کردی؛ توی مرور دوباره میان.</p>
            </div>

            @error('newExamples')
                <p class="text-sm text-danger-ink">{{ $message }}</p>
            @enderror

            <x-slot:actions>
                <x-continue-button on-click="$wire.save()" wire-target="save" />
            </x-slot:actions>
        </x-lesson-screen>
    </div>
@endif
