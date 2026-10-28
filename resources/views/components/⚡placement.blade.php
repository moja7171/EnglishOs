<?php

use App\Models\PlacementTest as PlacementTestResult;
use App\Services\PlacementTest;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The placement test a learner takes right after registering — three
 * parts, about 8-10 minutes: vocabulary recognition, grammar in context,
 * and one real spoken answer. See App\Services\PlacementTest for the
 * content, the scoring, and (importantly) what the result does NOT
 * change: not the curriculum, not the order, not the pace.
 *
 * Runs like a lesson: a start screen, then one question per screen under
 * one bar for the whole test (a tap moves on, nothing is marked right or
 * wrong, "I don't know" counts as wrong), the spoken answer, the result.
 *
 * Skippable on purpose. A gate in front of the very first lesson costs
 * more learners than an unknown starting level does; skipping just keeps
 * the default level and offers the test again from Home.
 */
new class extends Component
{
    use WithFileUploads;

    /** @var array<int, int> item index => chosen option */
    public array $vocabularyAnswers = [];

    /** @var array<int, int> item index => chosen option */
    public array $grammarAnswers = [];

    public $recording;

    public bool $completed = false;

    /** @var array{level: string, recognitionLevel: string, spokenLevel: ?string, aboveRange: bool, bands: array<string, array{correct: int, total: int}>, provisional: bool, parts: array{vocabulary: array{level: string, share: float}, grammar: array{level: string, share: float}}}|null */
    public ?array $result = null;

    public ?string $transcript = null;

    #[Computed]
    public function test(): PlacementTest
    {
        return app(PlacementTest::class);
    }

    /**
     * Grades the spoken answer and writes the result. The recording is
     * transcribed and read for CEFR level; if either AI call fails the
     * result is still saved, marked provisional, from the two objective
     * parts alone — a relay outage must not cost the learner their test.
     */
    public function finish(): void
    {
        $this->validate([
            'vocabularyAnswers' => 'array',
            'grammarAnswers' => 'array',
            'recording' => 'nullable|file|max:20480',
        ]);

        $spokenLevel = null;
        $audioUrl = null;

        if ($this->recording) {
            $path = $this->recording->store('placement', 'public');
            $audioUrl = Storage::disk('public')->url($path);
            [$this->transcript, $spokenLevel] = $this->test->gradeSpeakingRecording($this->recording->getRealPath());
        }

        $this->result = $this->test->score($this->vocabularyAnswers, $this->grammarAnswers, $spokenLevel)
            + ['parts' => $this->test->partLevels($this->vocabularyAnswers, $this->grammarAnswers)];

        PlacementTestResult::create([
            'learner_id' => auth()->id(),
            'kind' => PlacementTestResult::KIND_INITIAL,
            'level' => $this->result['level'],
            'recognition_level' => $this->result['recognitionLevel'],
            'spoken_level' => $this->result['spokenLevel'],
            'transcript' => $this->transcript,
            'audio_url' => $audioUrl,
            'detail' => [
                'bands' => $this->result['bands'],
                'provisional' => $this->result['provisional'],
                'aboveRange' => $this->result['aboveRange'],
                'vocabularyAnswers' => $this->vocabularyAnswers,
                'grammarAnswers' => $this->grammarAnswers,
            ],
        ]);

        // The real level replaces the registration form's self-assessment
        // — every AI prompt in the app reads it through
        // User::levelDescription().
        auth()->user()->forceFill(['cefr_level' => $this->result['level']])->save();

        $this->completed = true;
    }

    public function skip(): void
    {
        $this->redirect(route('home'), navigate: true);
    }

    public function start(): void
    {
        $this->redirect(route('home'), navigate: true);
    }
};
?>

@php
    $vocabulary = $this->test->vocabulary();
    $grammar = $this->test->grammar();
    $speaking = $this->test->speaking();
    $questions = [
        ...collect($vocabulary)->map(fn (array $item, int $index) => ['part' => 'vocabulary', 'index' => $index, 'label' => 'Words', 'text' => $item['word'], 'options' => $item['options']]),
        ...collect($grammar)->map(fn (array $item, int $index) => ['part' => 'grammar', 'index' => $index, 'label' => 'Grammar', 'text' => $item['prompt'], 'options' => $item['options']]),
    ];
    $total = count($questions) + 1;
    $speakingAt = count($questions);
    $option = 'w-full cursor-pointer rounded-2xl border-2 border-line bg-surface px-4 py-3.5 text-start text-base font-semibold text-ink transition-colors hover:border-accent active:bg-accent-soft dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:hover:border-accent-dark';
    $cta = 'block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:bg-line disabled:text-ink-faint disabled:shadow-none dark:bg-accent-dark';
    $chip = 'self-start rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark';
@endphp

<div class="mx-auto max-w-xl p-4 sm:p-6">
    @if ($completed)
        @php
            $level = $result['level'];
            $bars = [
                'Words' => [$result['parts']['vocabulary']['share'], $result['parts']['vocabulary']['level']],
                'Grammar' => [$result['parts']['grammar']['share'], $result['parts']['grammar']['level']],
                'Speaking' => [['below A1' => 0.15, 'A1' => 0.35, 'A2' => 0.65, 'B1' => 0.9, 'above B1' => 1][$result['spokenLevel']] ?? 0, $result['spokenLevel'] ?? '—'],
            ];
        @endphp
        <section data-step-header class="flex min-h-[calc(100dvh-9rem)] flex-col items-center justify-center gap-4 text-center">
            <p class="text-sm font-bold text-ink-faint dark:text-ink-faint-dark">You're starting at</p>
            <p class="font-display text-7xl leading-none font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $level }}</p>

            <div class="w-full max-w-xs space-y-2.5 pt-2">
                @foreach ($bars as $label => [$share, $barLevel])
                    <div wire:key="bar-{{ $label }}" class="flex items-center gap-3 text-sm font-bold text-ink dark:text-ink-dark">
                        <span class="w-20 text-start">{{ $label }}</span>
                        <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark"><span class="block h-full rounded-full bg-success dark:bg-success-dark" style="width: {{ round($share * 100) }}%"></span></span>
                        <span class="w-14 text-end text-xs">{{ $barLevel }}</span>
                    </div>
                @endforeach
            </div>

            @if ($result['aboveRange'])
                <p class="max-w-xs text-xs text-ink-faint dark:text-ink-faint-dark">You're past B1 — much of this will feel easy.</p>
            @endif
            <p dir="rtl" class="font-fa max-w-xs text-sm leading-7 text-ink-soft dark:text-ink-soft-dark">{{ $result['provisional'] ? 'صدات الان بررسی نشد؛ سطح از دو بخش اول اومده.' : 'صدات ذخیره شد؛ آخر دوره دوباره می‌شنویش.' }}</p>

            <button type="button" wire:click="start" wire:loading.attr="disabled" wire:target="start" class="{{ $cta }} mt-2 max-w-xs">Start M01</button>
        </section>
    @else
        <div x-data="{ screen: 'start', q: 0, recorded: false }">
            {{-- Start --}}
            <section x-show="screen === 'start'" class="flex min-h-[calc(100dvh-11rem)] flex-col gap-4 pt-4">
                <div class="flex flex-1 flex-col gap-3">
                    <span class="{{ $chip }}">~8 min</span>
                    <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Where are you starting?</h1>
                    <p class="text-base text-ink-soft dark:text-ink-soft-dark">So the lessons fit you.</p>
                    <div class="mt-2 space-y-2">
                        @foreach ([['📖', 'Words', count($vocabulary)], ['✏️', 'Grammar', count($grammar)], ['🎙', 'Speaking', '1 min']] as [$icon, $label, $size])
                            <div wire:key="part-{{ $label }}" class="card flex items-center gap-3 rounded-2xl p-3 text-base font-bold text-ink dark:text-ink-dark">
                                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent-soft dark:bg-accent-soft-dark">{{ $icon }}</span>
                                <span class="flex-1">{{ $label }}</span>
                                <span class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $size }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="space-y-3 pb-2">
                    <button type="button" x-on:click="screen = 'test'" class="{{ $cta }}">Start</button>
                    <button type="button" wire:click="skip" class="block w-full cursor-pointer py-1 text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
                </div>
            </section>

            {{-- The test: one bar for every question and the spoken answer --}}
            <div x-show="screen === 'test'" x-cloak data-step-header>
                {{-- Answers wait on the page until Finish; wire:ignore keeps the
                     bar where Alpine put it when the recording upload re-renders. --}}
                <div wire:ignore>
                    <x-lesson-header close-click="screen = 'start'" progress-expr="q / {{ $total }}">
                        <span class="shrink-0 text-sm font-extrabold text-ink-soft tabular-nums dark:text-ink-soft-dark" x-text="(q + 1) + '/{{ $total }}'"></span>
                    </x-lesson-header>
                </div>

                @foreach ($questions as $number => $question)
                    <section wire:key="question-{{ $number }}" x-show="q === {{ $number }}" x-cloak class="flex min-h-[calc(100dvh-12rem)] flex-col gap-4 pt-6">
                        <span class="{{ $chip }}">{{ $question['label'] }}</span>
                        @if ($question['part'] === 'vocabulary')
                            <p class="font-display text-4xl font-extrabold text-ink dark:text-ink-dark">{{ $question['text'] }}</p>
                            <p class="text-sm font-bold text-ink-faint dark:text-ink-faint-dark">What does it mean?</p>
                        @else
                            <p class="text-2xl leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $question['text'] }}</p>
                        @endif
                        <div class="space-y-2.5">
                            @foreach ($question['options'] as $choice => $text)
                                <button
                                    type="button"
                                    wire:key="question-{{ $number }}-{{ $choice }}"
                                    x-on:click="$wire.$set('{{ $question['part'] === 'vocabulary' ? 'vocabularyAnswers' : 'grammarAnswers' }}.{{ $question['index'] }}', {{ $choice }}, false); q++"
                                    class="{{ $option }}"
                                >{{ $text }}</button>
                            @endforeach
                        </div>
                        <div class="flex-1"></div>
                        <button type="button" x-on:click="q++" class="cursor-pointer py-2 text-sm font-bold text-ink-faint dark:text-ink-faint-dark">I don't know</button>
                    </section>
                @endforeach

                <section x-show="q === {{ $speakingAt }}" x-cloak class="flex min-h-[calc(100dvh-12rem)] flex-col gap-4 pt-6">
                    <span class="{{ $chip }}">Speaking · ~{{ $speaking['seconds'] }}s</span>
                    <p class="text-2xl leading-snug font-extrabold text-ink dark:text-ink-dark">{{ $speaking['prompt'] }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($speaking['bullets'] as $bullet)
                            <span wire:key="bullet-{{ $loop->index }}" class="rounded-full bg-surface px-3 py-1 text-xs font-bold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">{{ $bullet }}</span>
                        @endforeach
                    </div>
                    <div class="flex flex-1 items-center justify-center">
                        <x-voice-recorder big field="recording" :file="$recording" file-name="placement.webm" />
                    </div>
                    <x-continue-button on-click="$wire.finish()" wire-target="finish" loading-label="Listening to your answer…" ready-when="{{ $recording ? 'true' : 'false' }}">See my level</x-continue-button>
                </section>
            </div>
        </div>
    @endif
</div>
