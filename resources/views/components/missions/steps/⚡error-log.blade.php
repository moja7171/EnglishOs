<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
    use TracksAiUsage;

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, array{error: string, correction: string, drills: array<int, array{sentence: string, answer: string}>}> */
    public array $mistakes = [];

    /** @var array<int, string> */
    public array $newExamples = [];

    /** @var array<int, array<int, string>> keyed by mistake index then drill index — the learner's own attempt */
    public array $drillAnswers = [];

    /** @var array<int, array<int, bool>> keyed the same way — whether the last-checked attempt was correct */
    public array $drillChecked = [];

    /** @var array<int, array<int, string>> keyed the same way — set only for an empty attempt, so Check never silently does nothing */
    public array $drillErrors = [];

    public bool $generated = false;

    public ?string $error = null;

    /**
     * mount() used to call generate() unconditionally, so every page visit
     * — including just revisiting an incomplete step — fired a real Gemini
     * call. It now only loads already-saved mistakes in read-only mode; a
     * fresh review waits for the learner to press the button below, which
     * calls generate() explicitly.
     */
    public function mount(): void
    {
        if ($this->readOnly) {
            $items = $this->run->errorLogItems;
            $this->mistakes = $items->map(fn ($i) => [
                'error' => $i->error,
                'correction' => $i->correction,
                'why' => $i->why,
                'category' => $i->category,
                'drills' => $i->drills ?? [],
            ])->all();
            $this->newExamples = $items->pluck('new_example')->all();
            $this->generated = true;
        }
    }

    /**
     * Purely local — every drill's expected answer was already generated
     * alongside its mistake, so there's no AI round-trip needed to check
     * it, same reasoning as Grammar in Context's Quick Check. Optional
     * bonus practice: never blocks save(), never required.
     */
    public function checkDrill(int $mistakeIndex, int $drillIndex): void
    {
        $expected = $this->mistakes[$mistakeIndex]['drills'][$drillIndex]['answer'] ?? null;
        $given = trim($this->drillAnswers[$mistakeIndex][$drillIndex] ?? '');

        if ($expected === null) {
            return;
        }

        if ($given === '') {
            $this->drillErrors[$mistakeIndex][$drillIndex] = 'Write something first.';

            return;
        }

        unset($this->drillErrors[$mistakeIndex][$drillIndex]);
        $this->drillChecked[$mistakeIndex][$drillIndex] = $this->normalizeAnswer($given) === $this->normalizeAnswer($expected);
    }

    private function normalizeAnswer(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
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
                    .'lesson. Identify 3 to 5 recurring or notable grammar/vocabulary mistakes. For EACH mistake, also '
                    .'write 2 short NEW fill-in-the-blank practice sentences that target the exact same error pattern '
                    .'(not the same sentence reworded) — a personal, natural sentence with the key word or form '
                    .'replaced by "___", plus the single word/phrase that correctly fills it. Also assign a short '
                    .'category slug (lowercase, hyphenated, 1-3 words, e.g. "third-person-s", "article-usage", '
                    .'"preposition", "verb-tense", "word-order") naming the general grammar/vocabulary pattern the '
                    .'mistake belongs to — so the SAME pattern can be recognised again in a future lesson even '
                    .'though the sentence itself will be different. Also write a "why" field: one short sentence, '
                    .'in PERSIAN (Farsi), explaining the grammar or vocabulary rule behind the mistake — plain '
                    .'Persian, no English words mixed in unless quoting a specific English word or phrase. Reply '
                    .'with ONLY a valid JSON array, no markdown fences, no extra text, each item shaped exactly '
                    .'like: {"error": "the mistake as the learner wrote/said it", "correction": "the corrected '
                    .'form", "why": "Persian explanation of the rule", '
                    .'"category": "short-slug", '
                    .'"drills": [{"sentence": "...___...", "answer": "..."}, {"sentence": "...___...", "answer": "..."}]}. '
                    .'If the learner made no real mistakes, reply with an empty JSON array: []'
            );
            $this->recordGeminiCall();

            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->mistakes = collect($data)
                ->map(fn ($item) => $item + ['drills' => [], 'category' => null, 'why' => null])
                ->all();
            $this->newExamples = array_fill(0, count($data), '');
            $this->generated = true;
        } catch (Throwable $e) {
            $this->error = "Couldn't get the error log from the AI Instructor: {$e->getMessage()}";
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
                'drills' => $item['drills'] ?? [],
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

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    private function finishWithoutErrors(): void
    {
        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'error_log',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

<div class="space-y-6">
    <x-hook :text="$run->mission->stepContent('error_log')['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Error Log</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Correct your most common mistakes with a new example.</p>
    </div>

    @if ($error)
        <div class="rounded-xl border border-red-300 p-3 text-sm text-red-600">
            {{ $error }}
            <button
                wire:click="generate"
                wire:loading.attr="disabled"
                wire:target="generate"
                class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950"
            >
                <span wire:loading.remove wire:target="generate">Try again</span>
                <span wire:loading wire:target="generate">Trying again…</span>
            </button>
        </div>
    @elseif (! $generated)
        <div class="rounded-xl border border-dashed border-line p-6 text-center dark:border-line-dark">
            <div wire:loading.remove wire:target="generate">
                @svg('heroicon-o-magnifying-glass', 'mx-auto h-6 w-6 text-ink-faint dark:text-ink-faint-dark')
                <p class="mt-2 text-sm text-ink-soft dark:text-ink-soft-dark">Your mistakes haven't been reviewed yet.</p>
                <button
                    wire:click="generate"
                    class="mt-3 cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Review my mistakes</button>
            </div>
            <div wire:loading wire:target="generate">
                <x-ai-thinking label="Reviewing everything you said and wrote…" class="mx-auto max-w-xs" />
            </div>
        </div>
    @elseif (empty($mistakes))
        <div class="rounded-xl border border-line p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
            No recurring mistakes found — nice work!
        </div>
        @unless ($readOnly)
            <x-sticky-bar>
                <button wire:click="save" class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">
                    Continue
                </button>
            </x-sticky-bar>
        @endunless
    @else
        <div class="space-y-4" x-data="{ dismissed: {} }">
            @foreach ($mistakes as $i => $item)
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm text-red-600 line-through decoration-red-500">{{ $item['error'] }}</p>
                    <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $item['correction'] }}</p>
                    @if (! empty($item['why']))
                        <p class="font-fa mt-1 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $item['why'] }}</p>
                    @endif
                    @if (! empty($item['category']))
                        <p class="font-fa mt-1 flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark" dir="rtl">
                            @svg('heroicon-o-arrow-path', 'h-3.5 w-3.5 shrink-0')
                            این الگو رو دوباره توی مرور روزانه می‌بینی
                        </p>
                    @endif
                    <input
                        type="text"
                        wire:model.live="newExamples.{{ $i }}"
                        placeholder="Write a new sentence using the correct form…"
                        @readonly($readOnly)
                        class="mt-2 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                    >

                    @if (count($item['drills'] ?? []))
                        <div class="mt-3 space-y-2 border-t border-line pt-3 dark:border-line-dark">
                            <p class="text-[11px] font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Extra practice — optional</p>
                            @foreach ($item['drills'] as $d => $drill)
                                @php
                                    $drillKey = "drill_{$i}_{$d}";
                                    [$before, $after] = array_pad(explode('___', $drill['sentence'] ?? '', 2), 2, '');
                                    $checked = $drillChecked[$i][$d] ?? null;
                                    $drillError = $drillErrors[$i][$d] ?? null;
                                @endphp
                                <div>
                                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                                        {{ $before }}<input
                                            type="text"
                                            wire:model="drillAnswers.{{ $i }}.{{ $d }}"
                                            placeholder="…"
                                            x-on:input="dismissed['{{ $drillKey }}'] = true"
                                            class="inline w-24 border-b border-line bg-transparent px-1 text-center text-ink focus:border-accent focus:outline-none dark:border-line-dark dark:text-ink-dark dark:focus:border-accent-dark"
                                        >{{ $after }}
                                        <button
                                            type="button"
                                            x-on:click="dismissed['{{ $drillKey }}'] = true; $wire.checkDrill({{ $i }}, {{ $d }}).then(() => { dismissed['{{ $drillKey }}'] = false })"
                                            class="cursor-pointer rounded-full border border-line px-2 py-0.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                                        >Check</button>
                                    </p>
                                    <p
                                        x-show="!dismissed['{{ $drillKey }}']"
                                        class="mt-1 text-xs {{ $checked ? 'text-success dark:text-success-dark' : 'text-amber-600' }}"
                                    >
                                        @if ($drillError)
                                            {{ $drillError }}
                                        @elseif ($checked === true)
                                            @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') Nice — that's it.
                                        @elseif ($checked === false)
                                            Not quite — try again.
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">
            امروز {{ count($mistakes) }} الگوی تکراری رو شناسایی و اصلاح کردی
        </p>

        @error('newExamples')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        {{-- newExamples uses wire:model.live, so this is already known
             server-side on every keystroke — no extra Alpine tracking. --}}
        @if (! $readOnly && collect($newExamples)->every(fn ($e) => trim((string) $e) !== ''))
            <x-sticky-bar>
                <button wire:click="save" class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">
                    Continue
                </button>
            </x-sticky-bar>
        @endif
    @endif
</div>
