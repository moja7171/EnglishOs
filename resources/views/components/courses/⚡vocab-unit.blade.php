<?php

use App\Models\VocabItem;
use App\Models\VocabProgress;
use App\Models\VocabText;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use App\Services\VocabCourse;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public const STEPS = ['listen' => 'Listen', 'read' => 'Read', 'words' => 'Words'];

    #[Locked]
    public int $unitId;

    public string $step = 'listen';

    /**
     * The text (A to E) shown on the Listen and Read steps.
     */
    public string $letter = '';

    /**
     * The words the learner saved for review in this unit, by item id.
     *
     * @var list<int>
     */
    public array $saved = [];

    /**
     * The texts whose audio was really listened to (90% played).
     *
     * @var list<string>
     */
    public array $listened = [];

    public function mount(int $unitId, ?string $step = null): void
    {
        $this->unitId = $unitId;
        $this->step = array_key_exists((string) $step, self::STEPS) ? (string) $step : 'listen';
        $this->letter = (string) $this->texts->first()?->letter;
        $this->listened = $this->run->listened_letters;
        $this->saved = app(VocabCourse::class)->savedItemIds(auth()->user(), $this->unit);

        if ($this->step === 'words' && $this->run->read_done_at === null) {
            $this->run->update(['read_done_at' => now()]);
        }
    }

    #[Computed]
    public function unit(): VocabUnit
    {
        return VocabUnit::findOrFail($this->unitId);
    }

    /**
     * Opening a unit starts its run: that is what the course map counts as "opened".
     */
    #[Computed]
    public function run(): VocabUnitRun
    {
        return app(VocabCourse::class)->runFor(auth()->user(), $this->unit);
    }

    /**
     * @return Collection<int, VocabText>
     */
    #[Computed]
    public function texts(): Collection
    {
        return $this->unit->texts()->with('items')->get();
    }

    #[Computed]
    public function text(): ?VocabText
    {
        return $this->texts->firstWhere('letter', $this->letter) ?? $this->texts->first();
    }

    /**
     * The texts the learner has to hear before moving on: the ones with
     * the book's audio. Without a download host nothing is required.
     *
     * @return list<string>
     */
    #[Computed]
    public function requiredLetters(): array
    {
        return $this->texts
            ->filter(fn (VocabText $text): bool => $text->audioUrl() !== null)
            ->pluck('letter')
            ->all();
    }

    #[Computed]
    public function listenDone(): bool
    {
        return array_diff($this->requiredLetters, $this->listened) === [];
    }

    /**
     * The saved sentences of this unit, one card each, in the book's order.
     *
     * @return Collection<int, array{text: VocabText, sentence: array<string, mixed>, items: Collection<int, VocabItem>}>
     */
    #[Computed]
    public function cards(): Collection
    {
        $texts = $this->texts->keyBy('id');

        return VocabProgress::query()
            ->where('user_id', auth()->id())
            ->whereIn('vocab_text_id', $texts->keys())
            ->get()
            ->sortBy(fn (VocabProgress $card): string => $texts[$card->vocab_text_id]->letter.str_pad((string) $card->sentence_index, 3, '0', STR_PAD_LEFT))
            ->map(function (VocabProgress $card) use ($texts): array {
                $text = $texts[$card->vocab_text_id];

                return [
                    'text' => $text,
                    'sentence' => $text->sentences[$card->sentence_index] ?? ['text' => '', 'start' => 0, 'end' => 0],
                    'items' => $text->items->whereIn('id', $card->saved_item_ids)->values(),
                ];
            })
            ->values();
    }

    public function selectLetter(string $letter): void
    {
        if ($this->texts->contains('letter', $letter)) {
            $this->letter = $letter;
        }
    }

    /**
     * The player calls this once a text's audio has really been listened
     * through (90% played, never skipped to).
     */
    public function markListened(string $letter): void
    {
        if (! $this->texts->contains('letter', $letter) || in_array($letter, $this->listened, true)) {
            return;
        }

        $this->listened[] = $letter;
        $this->run->update(['listened_letters' => $this->listened]);

        if ($this->listenDone && $this->run->listen_done_at === null) {
            $this->run->update(['listen_done_at' => now()]);
        }
    }

    /**
     * Saving a word puts its whole sentence into the course's own review
     * (see App\Services\VocabCourse); tapping again takes it back out.
     */
    public function toggleSaved(int $itemId): void
    {
        $item = VocabItem::query()
            ->whereIn('vocab_text_id', $this->texts->pluck('id'))
            ->findOrFail($itemId);

        $course = app(VocabCourse::class);

        if (in_array($item->id, $this->saved, true)) {
            $course->removeFromReview(auth()->user(), $item);
            $this->saved = array_values(array_diff($this->saved, [$item->id]));

            return;
        }

        $course->saveForReview(auth()->user(), $item);
        $this->saved[] = $item->id;
    }

    public function finish(): void
    {
        $this->run->update([
            'read_done_at' => $this->run->read_done_at ?? now(),
            'finished_at' => $this->run->finished_at ?? now(),
        ]);

        $this->redirectRoute('courses.vocab', navigate: true);
    }

    /**
     * A text (or one sentence of it) as HTML with the book's bold words
     * marked: tappable underlined buttons on the Read step, highlighted
     * words on a card. Built here as one string because any whitespace
     * between the pieces would show up as a space before the punctuation.
     *
     * @param  list<int>|null  $only  Only these items stand out (the saved ones on a card); null = all.
     */
    public function markup(VocabText $text, ?int $from = null, ?int $to = null, ?array $only = null, bool $tappable = true): HtmlString
    {
        $html = '';

        foreach ($text->pieces($from, $to, $only) as $piece) {
            $escaped = e($piece['text']);

            if ($piece['item'] === null) {
                $html .= $escaped;

                continue;
            }

            $saved = in_array($piece['item']->id, $this->saved, true) ? ' rounded bg-accent-soft text-accent dark:bg-accent-soft-dark dark:text-accent-dark' : '';

            $html .= $tappable
                ? '<button type="button" x-on:click="pick('.$piece['item']->id.')" class="cursor-pointer font-bold underline decoration-dotted decoration-2 underline-offset-4'.$saved.'">'.$escaped.'</button>'
                : '<strong class="rounded bg-accent-soft px-0.5 text-accent dark:bg-accent-soft-dark dark:text-accent-dark">'.$escaped.'</strong>';
        }

        return new HtmlString($html);
    }
};
?>

@php
    $steps = ['listen' => 'Listen', 'read' => 'Read', 'words' => 'Words'];
    $stepNumber = array_search($step, array_keys($steps), true) + 1;
    $text = $this->text;
    $audioUrl = $text?->audioUrl();
    $hasFa = $text !== null && collect($text->sentences)->contains(fn ($line) => ! empty($line['fa']));
    $words = $this->texts->flatMap(fn ($t) => $t->items)->mapWithKeys(fn ($item) => [$item->id => [
        'id' => $item->id,
        'phrase' => $item->phrase,
        'ipa' => $item->ipa,
        'gloss' => $item->gloss,
        'fa' => $item->phrase_fa,
        'start' => $item->audio_start,
        'end' => $item->audio_end,
    ]]);
@endphp

<div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6" data-testid="vocab-unit">
    <header class="space-y-3">
        <div class="flex items-center gap-2">
            <a href="{{ route('courses.vocab') }}" wire:navigate aria-label="Back to Vocab in Use" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <div class="min-w-0 flex-1">
                <p class="truncate font-display text-xl font-semibold text-ink dark:text-ink-dark">Unit {{ $this->unit->number }} · {{ $this->unit->title }}</p>
                <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Step {{ $stepNumber }} of 3 · {{ $steps[$step] }}</p>
            </div>
        </div>
        <div class="flex gap-1.5" aria-hidden="true">
            @foreach (array_keys($steps) as $key)
                <span @class(['h-1.5 flex-1 rounded-full', 'bg-accent dark:bg-accent-dark' => $loop->iteration <= $stepNumber, 'bg-line dark:bg-line-dark' => $loop->iteration > $stepNumber])></span>
            @endforeach
        </div>
    </header>

    @if ($step !== 'words' && $this->texts->count() > 1)
        <nav class="flex gap-2" aria-label="Texts of this unit">
            @foreach ($this->texts as $tab)
                <button type="button" wire:click="selectLetter('{{ $tab->letter }}')" wire:key="vocab-tab-{{ $tab->letter }}" @class([
                    'inline-flex h-9 min-w-9 cursor-pointer items-center justify-center gap-1 rounded-full border px-3 text-sm font-extrabold transition-colors',
                    'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $tab->letter === $text->letter,
                    'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => $tab->letter !== $text->letter,
                ])>
                    {{ $tab->letter }}
                    @if (in_array($tab->letter, $listened, true) && $step === 'listen')
                        @svg('heroicon-s-check', 'h-3.5 w-3.5')
                    @endif
                </button>
            @endforeach
        </nav>
    @endif

    @if ($step === 'listen')
        <section class="space-y-4" wire:key="vocab-listen-{{ $text->letter }}">
            <p class="font-bold text-ink dark:text-ink-dark">{{ $text->letter }} · {{ $text->title }}</p>

            @if ($audioUrl)
                <x-audio-player
                    :url="$audioUrl"
                    :segments="$text->playerSegments()"
                    :on-listened="'$wire.markListened(\''.$text->letter.'\')'"
                />
            @else
                <p class="card-sunken px-4 py-3 text-sm text-ink-soft dark:text-ink-soft-dark">The audio is not available here.</p>
            @endif

            @if ($text->items->isNotEmpty())
                <ul class="card divide-y divide-line px-4 py-1 dark:divide-line-dark">
                    @foreach ($text->items as $item)
                        <li wire:key="vocab-word-{{ $item->id }}" class="flex items-baseline justify-between gap-3 py-2.5">
                            <span class="font-bold text-ink dark:text-ink-dark">{{ $item->phrase }}</span>
                            @if ($item->phrase_fa)
                                <span x-data="{ shown: false }" x-on:click="shown = ! shown" x-bind:class="shown || 'blur-sm'" class="font-fa min-w-0 flex-1 cursor-pointer text-end text-sm text-ink-soft transition-[filter] dark:text-ink-soft-dark" dir="rtl">{{ $item->phrase_fa }}</span>
                            @endif
                            @if ($item->ipa)
                                <span class="shrink-0 text-sm text-ink-faint dark:text-ink-faint-dark">/{{ $item->ipa }}/</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->listenDone)
                <a href="{{ route('courses.vocab.unit', [$this->unit->number, 'read']) }}" wire:navigate class="block w-full rounded-2xl bg-accent py-3.5 text-center text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-colors hover:opacity-90 dark:bg-accent-dark">Continue</a>
            @else
                <button type="button" disabled class="w-full cursor-not-allowed rounded-2xl bg-line py-3.5 text-base font-bold text-ink-faint dark:bg-line-dark dark:text-ink-faint-dark">Listen to 90% to continue</button>
            @endif
        </section>
    @elseif ($step === 'read')
        <section
            class="space-y-4"
            wire:key="vocab-read-{{ $text->letter }}"
            x-data="{
                words: {{ \Illuminate\Support\Js::from($words) }},
                sel: null,
                open: false,
                fa: false,
                stop: null,
                pick(id) { this.sel = this.words[id]; this.open = true; },
                hear() {
                    const w = this.sel, a = this.$refs.hear;
                    if (! w || ! a || w.start === null) return;
                    if (this.stop) a.removeEventListener('timeupdate', this.stop);
                    a.pause();
                    a.currentTime = w.start;
                    this.stop = () => { if (a.currentTime >= w.end) { a.pause(); a.removeEventListener('timeupdate', this.stop); } };
                    a.addEventListener('timeupdate', this.stop);
                    a.play();
                },
            }"
        >
            <div class="flex items-center justify-between gap-3">
                <p class="font-bold text-ink dark:text-ink-dark">{{ $text->letter }} · {{ $text->title }}</p>
                <span class="flex items-center gap-2">
                    @if ($hasFa)
                        <button type="button" x-on:click="fa = ! fa" x-bind:aria-pressed="fa" x-bind:class="fa ? 'border-accent bg-accent-soft text-accent dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-dark' : 'border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark'" class="font-fa cursor-pointer rounded-full border px-3 py-1 text-xs font-bold transition-colors">فا</button>
                    @endif
                    <span class="rounded-full bg-surface-sunken px-3 py-1 text-xs font-extrabold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark" data-testid="vocab-saved-count">{{ count($saved) }} saved</span>
                </span>
            </div>

            @if ($audioUrl)
                <audio x-ref="hear" src="{{ $audioUrl }}" preload="none"></audio>
            @endif

            @if ($text->hasSentences())
                <p class="card px-4 py-4 text-lg leading-9 whitespace-pre-line text-ink dark:text-ink-dark">{!! $this->markup($text) !!}</p>
                @if ($hasFa)
                    <div x-show="fa" x-cloak dir="rtl" class="font-fa space-y-1 text-sm leading-8 text-ink-soft dark:text-ink-soft-dark">
                        @foreach ($text->sentences as $line)
                            @if (! empty($line['fa']))
                                <p>{{ $line['fa'] }}</p>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($text->items->isNotEmpty())
                    <p class="text-center text-sm text-ink-faint dark:text-ink-faint-dark">Tap an underlined word</p>
                @endif
            @elseif ($text->rows)
                <ul class="card divide-y divide-line px-4 py-1 text-sm text-ink dark:divide-line-dark dark:text-ink-dark">
                    @foreach ($text->rows as $row)
                        <li class="py-2">{{ $row }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="flex items-center justify-between gap-3 pt-2">
                <div class="flex gap-1.5" aria-hidden="true">
                    @foreach ($this->texts as $dot)
                        <span @class(['h-2 w-2 rounded-full', 'bg-accent dark:bg-accent-dark' => $dot->letter === $text->letter, 'bg-line dark:bg-line-dark' => $dot->letter !== $text->letter])></span>
                    @endforeach
                </div>
                @php $next = $this->texts->first(fn ($candidate) => $candidate->letter > $text->letter); @endphp
                @if ($next)
                    <button type="button" wire:click="selectLetter('{{ $next->letter }}')" class="cursor-pointer rounded-2xl bg-accent px-5 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">Next section ›</button>
                @else
                    <a href="{{ route('courses.vocab.unit', [$this->unit->number, 'words']) }}" wire:navigate class="rounded-2xl bg-accent px-5 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">Words ›</a>
                @endif
            </div>

            <x-bottom-sheet show="open" label="Word">
                <template x-if="sel">
                    <div class="space-y-3">
                        <div>
                            <p dir="rtl" class="font-fa text-3xl leading-snug font-bold text-ink dark:text-ink-dark" x-show="sel.fa" x-text="sel.fa"></p>
                            <p class="font-display text-xl font-semibold text-ink-soft dark:text-ink-soft-dark" x-text="sel.phrase"></p>
                            <p class="text-sm text-ink-faint dark:text-ink-faint-dark" x-show="sel.ipa" x-text="'/' + sel.ipa + '/'"></p>
                        </div>
                        <p class="text-base text-ink-soft dark:text-ink-soft-dark" x-show="sel.gloss" x-text="sel.gloss"></p>
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Saves the whole sentence for review</p>
                        <div class="flex items-center gap-2">
                            @if ($audioUrl)
                                <button type="button" x-on:click="hear()" x-show="sel.start !== null" aria-label="Hear it" class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</button>
                            @endif
                            <button
                                type="button"
                                x-on:click="$wire.toggleSaved(sel.id)"
                                x-bind:class="$wire.saved.includes(sel.id) ? 'bg-success text-white dark:bg-success-dark' : 'bg-accent text-white dark:bg-accent-dark'"
                                class="flex-1 cursor-pointer rounded-2xl py-3 text-sm font-bold transition-colors hover:opacity-90"
                                x-text="$wire.saved.includes(sel.id) ? '✓ Saved for review' : '+ Save for review'"
                            ></button>
                        </div>
                    </div>
                </template>
            </x-bottom-sheet>
        </section>
    @else
        <section
            class="space-y-4"
            wire:key="vocab-words"
            x-data="{
                stop: null,
                hear(ref, start, end) {
                    const a = this.$refs[ref];
                    if (! a || start === null) return;
                    if (this.stop) a.removeEventListener('timeupdate', this.stop);
                    a.pause();
                    a.currentTime = start;
                    this.stop = () => { if (a.currentTime >= end) { a.pause(); a.removeEventListener('timeupdate', this.stop); } };
                    a.addEventListener('timeupdate', this.stop);
                    a.play();
                },
            }"
        >
            @foreach ($this->texts as $audioText)
                @if ($audioText->audioUrl())
                    <audio x-ref="audio-{{ $audioText->letter }}" src="{{ $audioText->audioUrl() }}" preload="none"></audio>
                @endif
            @endforeach

            @forelse ($this->cards as $card)
                <article wire:key="vocab-card-{{ $card['text']->id }}-{{ $loop->index }}" class="card space-y-3 p-4">
                    <p class="text-lg leading-8 text-ink dark:text-ink-dark">
                        {!! $this->markup($card['text'], $card['sentence']['start'], $card['sentence']['end'], $card['items']->pluck('id')->all(), false) !!}
                    </p>
                    <ul class="space-y-1 text-sm">
                        @foreach ($card['items'] as $item)
                            <li class="text-ink-soft dark:text-ink-soft-dark"><span class="font-bold text-ink dark:text-ink-dark">{{ $item->phrase }}</span>@if ($item->gloss) · {{ $item->gloss }}@endif @if ($item->phrase_fa)<span x-data="{ shown: false }" x-on:click="shown = ! shown" x-bind:class="shown || 'blur-sm'" class="font-fa cursor-pointer transition-[filter]" dir="rtl">{{ $item->phrase_fa }}</span>@endif</li>
                        @endforeach
                    </ul>
                    @if (! empty($card['sentence']['fa']))
                        <p x-data="{ shown: false }" x-on:click="shown = ! shown" x-bind:class="shown || 'blur-sm'" dir="rtl" class="font-fa cursor-pointer text-sm leading-8 text-ink-soft transition-[filter] dark:text-ink-soft-dark">{{ $card['sentence']['fa'] }}</p>
                    @endif
                    @if ($card['text']->audioUrl() && isset($card['sentence']['audio_start']))
                        <button type="button" x-on:click="hear('audio-{{ $card['text']->letter }}', {{ $card['sentence']['audio_start'] }}, {{ $card['sentence']['audio_end'] }})" aria-label="Hear the sentence" class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</button>
                    @endif
                </article>
            @empty
                <div class="card-sunken space-y-3 px-4 py-6 text-center">
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">No words saved yet.</p>
                    <a href="{{ route('courses.vocab.unit', [$this->unit->number, 'read']) }}" wire:navigate class="inline-block text-sm font-bold text-accent dark:text-accent-dark">‹ Back to Read</a>
                </div>
            @endforelse

            <button type="button" wire:click="finish" wire:loading.attr="disabled" wire:target="finish" class="w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-colors hover:opacity-90 dark:bg-accent-dark">Finish unit</button>
        </section>
    @endif
</div>
