<?php

use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GeminiClient;
use Livewire\Component;

new class extends Component
{
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

    public bool $loading = false;

    public ?string $error = null;

    public function mount(): void
    {
        if ($this->readOnly) {
            $items = $this->run->errorLogItems;
            $this->mistakes = $items->map(fn ($i) => [
                'error' => $i->error,
                'correction' => $i->correction,
                'category' => $i->category,
                'drills' => $i->drills ?? [],
            ])->all();
            $this->newExamples = $items->pluck('new_example')->all();

            return;
        }

        $this->generate();
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

        if ($expected === null || $given === '') {
            return;
        }

        $this->drillChecked[$mistakeIndex][$drillIndex] = $this->normalizeAnswer($given) === $this->normalizeAnswer($expected);
    }

    private function normalizeAnswer(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }

    public function generate(): void
    {
        $this->error = null;
        $this->loading = true;

        try {
            $text = $this->run->allLearnerText();

            if (trim($text) === '') {
                $this->mistakes = [];

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
                    .'though the sentence itself will be different. Reply with ONLY a valid '
                    .'JSON array, no markdown fences, no extra text, each item shaped exactly like: '
                    .'{"error": "the mistake as the learner wrote/said it", "correction": "the corrected form", '
                    .'"category": "short-slug", '
                    .'"drills": [{"sentence": "...___...", "answer": "..."}, {"sentence": "...___...", "answer": "..."}]}. '
                    .'If the learner made no real mistakes, reply with an empty JSON array: []'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data)) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->mistakes = collect($data)
                ->map(fn ($item) => $item + ['drills' => [], 'category' => null])
                ->all();
            $this->newExamples = array_fill(0, count($data), '');
        } catch (\Throwable $e) {
            $this->error = "Couldn't get the error log from the AI Instructor: {$e->getMessage()}";
        } finally {
            $this->loading = false;
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
                'new_example' => trim($this->newExamples[$i]),
                'drills' => $item['drills'] ?? [],
                'category' => $item['category'] ?? null,
            ]);
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

    @if ($loading)
        <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Reviewing everything you said and wrote…</p>
    @elseif ($error)
        <div class="rounded-xl border border-red-300 p-3 text-sm text-red-600">
            {{ $error }}
            <button
                wire:click="generate"
                class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 dark:border-red-800 dark:hover:bg-red-950"
            >Try again</button>
        </div>
    @elseif (empty($mistakes))
        <div class="rounded-xl border border-line p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
            No recurring mistakes found — nice work!
        </div>
        @unless ($readOnly)
            <button wire:click="save" class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">
                Continue
            </button>
        @endunless
    @else
        <div class="space-y-4" x-data="{ dismissed: {} }">
            @foreach ($mistakes as $i => $item)
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-sm text-red-600 line-through decoration-red-500">{{ $item['error'] }}</p>
                    <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $item['correction'] }}</p>
                    <input
                        type="text"
                        wire:model="newExamples.{{ $i }}"
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
                                            wire:click="checkDrill({{ $i }}, {{ $d }})"
                                            class="cursor-pointer rounded-full border border-line px-2 py-0.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                                        >Check</button>
                                    </p>
                                    <p
                                        x-show="!dismissed['{{ $drillKey }}']"
                                        class="mt-1 text-xs {{ $checked ? 'text-success dark:text-success-dark' : 'text-amber-600' }}"
                                    >
                                        @if ($checked === true)
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

        @error('newExamples')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @unless ($readOnly)
            <button wire:click="save" class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">
                Continue
            </button>
        @endunless
    @endif
</div>
