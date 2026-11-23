<?php

use App\Models\VocabItem;
use App\Models\VocabProgress;
use App\Services\VocabReview;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
        /**
     * The cards of this sitting, by id, in the order they are asked.
     *
     * @var list<int>
     */
    #[Locked]
    public array $queue = [];

    public int $index = 0;

    public int $step = 1;

    /**
     * See: the meanings to pick from, and the saved word they are for.
     *
     * @var list<string>
     */
    public array $options = [];

    #[Locked]
    public int $seeItemId = 0;

    public string $picked = '';

    /**
     * Gap and Recall: what the learner typed.
     */
    public string $typed = '';

    /**
     * Order: the words of the part being built, shuffled, and which of them the learner put down, in order.
     *
     * @var list<string>
     */
    public array $pool = [];

    /**
     * @var list<int>
     */
    public array $built = [];

    public int $part = 0;

    public bool $checked = false;

    /**
     * right, almost (a small slip) or wrong.
     */
    public string $verdict = '';

    /**
     * @var list<string>
     */
    public array $missing = [];

    public int $done = 0;

    public function mount(): void
    {
        $review = app(VocabReview::class);

        $this->queue = $review->due(auth()->user(), $review->allowanceToday(auth()->user()))->pluck('id')->all();
        $this->prepare();
    }

    #[Computed]
    public function card(): ?VocabProgress
    {
        $id = $this->queue[$this->index] ?? null;

        return $id === null
            ? null
            : VocabProgress::query()->where('user_id', auth()->id())->with('text.unit', 'text.items')->find($id);
    }

    /**
     * More cards are waiting than today's cap let in: the "20 more" button.
     */
    #[Computed]
    public function moreWaiting(): int
    {
        return app(VocabReview::class)->due(auth()->user(), 1000, $this->queue)->count();
    }

    /**
     * @return array{text: string, start: int, end: int, audio_start: float|null, audio_end: float|null, fa: string|null}|null
     */
    #[Computed]
    public function sentence(): ?array
    {
        return $this->card ? app(VocabReview::class)->sentence($this->card) : null;
    }

    /**
     * @return Collection<int, VocabItem>
     */
    #[Computed]
    public function savedItems(): Collection
    {
        return $this->card ? app(VocabReview::class)->savedItems($this->card) : collect();
    }

    /**
     * Gap: the sentence around the missing word.
     *
     * @return array{before: string, after: string, answer: string, item: VocabItem}|null
     */
    #[Computed]
    public function gap(): ?array
    {
        return $this->card && $this->step === 2 ? app(VocabReview::class)->gapQuestion($this->card) : null;
    }

    /**
     * The words of the sentence part the learner is putting in order.
     *
     * @return list<string>
     */
    #[Computed]
    public function orderWords(): array
    {
        return $this->card && $this->step === 3
            ? (app(VocabReview::class)->orderParts($this->sentence['text'])[$this->part] ?? [])
            : [];
    }

    #[Computed]
    public function orderParts(): int
    {
        return $this->card && $this->step === 3 ? count(app(VocabReview::class)->orderParts($this->sentence['text'])) : 1;
    }

    /**
     * Sets the screen up for the card now in front: which step, and what
     * that step needs to ask.
     */
    private function prepare(): void
    {
        $this->picked = '';
        $this->typed = '';
        $this->pool = [];
        $this->built = [];
        $this->part = 0;
        $this->checked = false;
        $this->verdict = '';
        $this->missing = [];
        $this->options = [];
        unset($this->card, $this->sentence, $this->savedItems, $this->gap, $this->orderWords, $this->orderParts);

        $card = $this->card;

        if ($card === null) {
            return;
        }

        $review = app(VocabReview::class);
        $this->step = $review->stepFor($card);

        if ($this->step === 1) {
            $question = $review->seeQuestion($card);
            $this->options = $question['options'];
            $this->seeItemId = $question['item']->id;
        }

        if ($this->step === 3) {
            $this->pool = $review->shuffled($this->orderWords);
        }
    }

    public function pick(int $choice): void
    {
        $option = $this->options[$choice] ?? null;

        if ($this->checked || $this->step !== 1 || $option === null) {
            return;
        }

        $this->picked = $option;
        $this->checked = true;
        $this->verdict = $option === app(VocabReview::class)->meaning(VocabItem::query()->findOrFail($this->seeItemId)) ? 'right' : 'wrong';
    }

    /**
     * Gap (the word) and Recall (the whole sentence).
     */
    public function submitTyped(): void
    {
        if ($this->checked || ! in_array($this->step, [2, 4], true) || trim($this->typed) === '') {
            return;
        }

        $review = app(VocabReview::class);

        if ($this->step === 2) {
            $this->verdict = $review->checkWord($this->typed, $this->gap['answer']) ? 'right' : 'wrong';
        } else {
            $result = $review->checkSentence($this->typed, $this->sentence['text'], $this->savedItems->pluck('phrase')->all());
            $this->verdict = $result['verdict'];
            $this->missing = $result['missing'];
        }

        $this->checked = true;
    }

    public function addWord(int $position): void
    {
        if ($this->checked || $this->step !== 3 || ! isset($this->pool[$position]) || in_array($position, $this->built, true)) {
            return;
        }

        $this->built[] = $position;
    }

    public function removeWord(int $place): void
    {
        if ($this->checked || $this->step !== 3) {
            return;
        }

        unset($this->built[$place]);
        $this->built = array_values($this->built);
    }

    public function checkOrder(): void
    {
        if ($this->checked || $this->step !== 3 || count($this->built) !== count($this->pool)) {
            return;
        }

        $words = array_map(fn (int $position): string => $this->pool[$position], $this->built);

        if ($words !== $this->orderWords) {
            $this->checked = true;
            $this->verdict = 'wrong';

            return;
        }

        if ($this->part + 1 < $this->orderParts) {
            $this->part++;
            unset($this->orderWords);
            $this->pool = app(VocabReview::class)->shuffled($this->orderWords);
            $this->built = [];

            return;
        }

        $this->checked = true;
        $this->verdict = 'right';
    }

    /**
     * The learner's own verdict after seeing the answer: Again, Good or
     * Easy. A wrong answer can only be Again.
     */
    public function grade(string $how): void
    {
        $card = $this->card;

        if (! $this->checked || $card === null) {
            return;
        }

        $quality = $this->verdict === 'wrong' ? 1 : match ($how) {
            'easy' => 5,
            'again' => 1,
            default => 4,
        };

        $card->grade($quality);
        $this->done++;
        $this->index++;
        $this->prepare();
    }

    /**
     * "20 more": the next due cards after today's cap.
     */
    public function more(): void
    {
        $more = app(VocabReview::class)->due(auth()->user(), VocabReview::MORE, $this->queue)->pluck('id')->all();

        if ($more === []) {
            return;
        }

        $this->queue = [...$this->queue, ...$more];
        $this->prepare();
    }

    /**
     * The sentence with the saved words marked.
     */
    public function marked(): HtmlString
    {
        $html = '';

        foreach ($this->card->text->pieces($this->sentence['start'], $this->sentence['end'], $this->savedItems->pluck('id')->all()) as $piece) {
            $html .= $piece['item'] === null
                ? e($piece['text'])
                : '<mark class="rounded bg-accent-soft px-0.5 font-bold text-accent dark:bg-accent-soft-dark dark:text-accent-dark">'.e($piece['text']).'</mark>';
        }

        return new HtmlString($html);
    }
};
?>

@php
    $card = $this->card;
    $steps = [1 => 'See', 2 => 'Gap', 3 => 'Order', 4 => 'Recall'];
    $total = count($queue);
    $audioUrl = $card?->text->audioUrl();
    $sentence = $this->sentence;
    $stepLabel = match ($step) {
        1 => 'Read it, then pick the meaning',
        2 => 'Fill the gap',
        3 => 'Put the words in order'.($this->orderParts > 1 ? ' · Part '.($part + 1).' of '.$this->orderParts : ''),
        default => 'Type the sentence',
    };
@endphp

<div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6" data-testid="vocab-review">
    <header class="flex items-center gap-2">
        <a href="{{ route('courses.vocab') }}" wire:navigate aria-label="Back to Vocab in Use" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <div class="min-w-0 flex-1">
            <p class="font-display text-xl font-semibold text-ink dark:text-ink-dark">Vocab in Use review</p>
            @if ($card)
                <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Sentence {{ $index + 1 }} of {{ $total }} · Unit {{ $card->text->unit->number }} {{ $card->text->letter }}</p>
            @endif
        </div>
    </header>

    @if ($card)
        <x-progress-bar>
            <div class="h-full rounded-full bg-accent transition-all dark:bg-accent-dark" style="width: {{ round($index / max(1, $total) * 100) }}%"></div>
        </x-progress-bar>

        <div class="flex items-center gap-3">
            <div class="flex gap-1.5" aria-label="Step {{ $step }} of 4">
                @foreach ($steps as $number => $label)
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-extrabold whitespace-nowrap',
                        'bg-accent text-white dark:bg-accent-dark' => $number === $step,
                        'bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark' => $number !== $step,
                    ])>{{ $label }}</span>
                @endforeach
            </div>
        </div>

        <section
            class="space-y-4"
            wire:key="vocab-review-{{ $card->id }}-{{ $index }}"
            x-data="{
                stop: null,
                hear() {
                    const a = this.$refs.hear;
                    if (! a) return;
                    const start = {{ $sentence['audio_start'] ?? 'null' }}, end = {{ $sentence['audio_end'] ?? 'null' }};
                    if (start === null) return;
                    if (this.stop) a.removeEventListener('timeupdate', this.stop);
                    a.pause();
                    a.currentTime = start;
                    this.stop = () => { if (a.currentTime >= end) { a.pause(); a.removeEventListener('timeupdate', this.stop); } };
                    a.addEventListener('timeupdate', this.stop);
                    a.play();
                },
            }"
        >
            @if ($audioUrl)
                <audio x-ref="hear" src="{{ $audioUrl }}" preload="none"></audio>
            @endif

            <div class="card space-y-3 p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                        {{ $stepLabel }}
                    </p>
                    @if ($audioUrl && isset($sentence['audio_start']) && ($step !== 4 || ! $checked))
                        <button type="button" x-on:click="hear()" aria-label="Hear the sentence" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</button>
                    @endif
                </div>

                @if ($step === 1)
                    <p class="text-lg leading-8 text-ink dark:text-ink-dark">{!! $this->marked() !!}</p>
                    @php $seeItem = $this->savedItems->firstWhere('id', $seeItemId); @endphp
                    <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">What does “{{ $seeItem?->phrase }}” mean?</p>
                    @if ($sentence['fa'])
                        <p x-data="{ shown: false }" x-on:click="shown = ! shown" x-bind:class="shown || 'blur-sm'" dir="rtl" class="font-fa cursor-pointer text-sm leading-8 text-ink-soft transition-[filter] dark:text-ink-soft-dark">{{ $sentence['fa'] }}</p>
                    @endif
                @elseif ($step === 2)
                    <p class="text-lg leading-9 text-ink dark:text-ink-dark">{{ $this->gap['before'] }}<span class="mx-0.5 inline-block min-w-20 border-b-2 border-ink-faint align-bottom dark:border-ink-faint-dark">@if ($checked)<span @class(['font-bold', 'text-success dark:text-success-dark' => $verdict === 'right', 'text-danger' => $verdict === 'wrong'])>{{ $this->gap['answer'] }}</span>@else&nbsp;@endif</span>{{ $this->gap['after'] }}</p>
                    @if ($sentence['fa'])
                        <p dir="rtl" class="font-fa text-sm leading-8 text-ink-soft dark:text-ink-soft-dark">{{ $sentence['fa'] }}</p>
                    @endif
                    @if ($this->gap['item']->gloss)
                        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $this->gap['item']->gloss }}</p>
                    @endif
                @elseif ($step === 3)
                    @if ($sentence['fa'])
                        <p dir="rtl" class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark">{{ $sentence['fa'] }}</p>
                    @endif
                    <div class="flex min-h-12 flex-wrap gap-2 rounded-2xl border border-dashed border-line p-2 dark:border-line-dark">
                        @forelse ($built as $place => $position)
                            <button type="button" wire:click="removeWord({{ $place }})" wire:key="built-{{ $place }}-{{ $position }}" @class([
                                'cursor-pointer rounded-full border px-3 py-1.5 text-sm font-bold',
                                'border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark' => ! $checked,
                                'border-success bg-success-soft text-success dark:border-success-dark dark:bg-success-soft-dark dark:text-success-dark' => $checked && $verdict === 'right',
                                'border-danger text-danger' => $checked && $verdict === 'wrong',
                            ])>{{ $pool[$position] }}</button>
                        @empty
                            <span class="self-center px-1 text-sm text-ink-faint dark:text-ink-faint-dark">Tap the words below</span>
                        @endforelse
                    </div>
                @else
                    @if ($sentence['fa'])
                        <p dir="rtl" class="font-fa text-lg leading-9 font-bold text-ink dark:text-ink-dark">{{ $sentence['fa'] }}</p>
                    @endif
                    <ul class="space-y-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                        @foreach ($this->savedItems as $item)
                            <li><span class="font-bold text-ink dark:text-ink-dark">{{ $item->phrase }}</span>@if ($item->gloss) · {{ $item->gloss }}@endif</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- The answer: a choice, a word, the words in order, or the whole sentence. --}}
            @if ($step === 1)
                <div class="space-y-2">
                    @foreach ($options as $option)
                        <button type="button" wire:click="pick({{ $loop->index }})" wire:key="option-{{ $loop->index }}" @disabled($checked) dir="auto" class="choice font-fa" data-state="{{ ! $checked ? 'idle' : ($option === app(\App\Services\VocabReview::class)->meaning($this->savedItems->firstWhere('id', $seeItemId)) ? 'correct' : ($option === $picked ? 'wrong' : 'muted')) }}">{{ $option }}</button>
                    @endforeach
                </div>
            @elseif ($step === 2)
                <form wire:submit="submitTyped" class="flex gap-2">
                    <input type="text" wire:model="typed" @disabled($checked) autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="The missing word" placeholder="{{ mb_substr($this->gap['answer'], 0, 1) }}{{ str_repeat(' _', max(0, mb_strlen($this->gap['answer']) - 1)) }}" class="min-w-0 flex-1 rounded-2xl border border-line bg-surface px-4 py-3 text-base text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">
                    @unless ($checked)
                        <button type="submit" class="cursor-pointer rounded-2xl bg-accent px-5 text-sm font-bold text-white dark:bg-accent-dark">Check</button>
                    @endunless
                </form>
            @elseif ($step === 3)
                <div class="flex flex-wrap gap-2">
                    @foreach ($pool as $position => $word)
                        <button type="button" wire:click="addWord({{ $position }})" wire:key="pool-{{ $position }}" @disabled($checked || in_array($position, $built, true)) @class([
                            'cursor-pointer rounded-full border border-line px-3 py-1.5 text-sm font-bold text-ink disabled:cursor-default disabled:opacity-30 dark:border-line-dark dark:text-ink-dark',
                        ])>{{ $word }}</button>
                    @endforeach
                </div>
                @unless ($checked)
                    <button type="button" wire:click="checkOrder" @disabled(count($built) !== count($pool)) class="w-full cursor-pointer rounded-2xl bg-accent py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:bg-line disabled:text-ink-faint dark:bg-accent-dark dark:disabled:bg-line-dark dark:disabled:text-ink-faint-dark">Check</button>
                @endunless
            @else
                <form wire:submit="submitTyped" class="space-y-2">
                    <textarea wire:model="typed" @disabled($checked) rows="3" autocomplete="off" autocapitalize="sentences" spellcheck="false" aria-label="The sentence" class="w-full rounded-2xl border border-line bg-surface px-4 py-3 text-base text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"></textarea>
                    @unless ($checked)
                        <button type="submit" class="w-full cursor-pointer rounded-2xl bg-accent py-3 text-sm font-bold text-white dark:bg-accent-dark">Check</button>
                        <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Small slips count as right. Only a changed meaning is wrong.</p>
                    @endunless
                </form>
            @endif

            @if ($checked)
                <div @class([
                    'rounded-2xl px-4 py-3 text-sm',
                    'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $verdict === 'right',
                    'bg-warning-soft text-warning-ink' => $verdict === 'almost',
                    'bg-danger-soft text-danger-ink' => $verdict === 'wrong',
                ]) data-testid="vocab-verdict">
                    @if ($verdict === 'right')
                        <p class="font-bold">Correct</p>
                    @elseif ($verdict === 'almost')
                        <p><b>Almost.</b> @if ($missing !== []) Missing: {{ implode(' ', $missing) }} @else Fine. @endif</p>
                    @else
                        <p class="font-bold">Not quite</p>
                    @endif
                    @if ($verdict !== 'right')
                        <p class="mt-1 text-ink dark:text-ink-dark">{{ $sentence['text'] }}</p>
                    @endif
                </div>

                <div class="grid gap-2 {{ $verdict === 'wrong' ? 'grid-cols-1' : 'grid-cols-2' }}">
                    @if ($verdict === 'wrong')
                        <button type="button" wire:click="grade('again')" class="cursor-pointer rounded-2xl bg-accent py-3 text-sm font-bold text-white dark:bg-accent-dark">Next · again in {{ $card->nextIntervalLabel(1) }}</button>
                    @else
                        <button type="button" wire:click="grade('good')" class="cursor-pointer rounded-2xl bg-accent py-3 text-sm font-bold text-white dark:bg-accent-dark">Good · {{ $card->nextIntervalLabel(4) }}</button>
                        <button type="button" wire:click="grade('easy')" class="cursor-pointer rounded-2xl border border-line py-3 text-sm font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Easy · {{ $card->nextIntervalLabel(5) }}</button>
                    @endif
                </div>
            @endif
        </section>
    @else
        <div class="card space-y-3 px-4 py-8 text-center" data-testid="vocab-review-done">
            <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">{{ $done > 0 ? 'Done' : 'Nothing due' }}</p>
            @if ($done > 0)
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $done }} {{ Str::plural('sentence', $done) }} reviewed</p>
            @endif
            @if ($this->moreWaiting > 0)
                <button type="button" wire:click="more" class="cursor-pointer rounded-2xl bg-accent px-6 py-3 text-sm font-bold text-white dark:bg-accent-dark">{{ min(\App\Services\VocabReview::MORE, $this->moreWaiting) }} more</button>
            @endif
            <a href="{{ route('courses.vocab') }}" wire:navigate class="block text-sm font-bold text-accent dark:text-accent-dark">‹ Back to Vocab in Use</a>
        </div>
    @endif
</div>
