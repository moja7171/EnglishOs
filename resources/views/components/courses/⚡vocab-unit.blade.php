<?php

use App\Models\VocabItem;
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
    #[Locked]
    public int $unitId;

    /**
     * What the learner is on: a section letter (A to E), or "words" for the
     * list of all the lesson's new words at the end.
     */
    public string $part = '';

    public function mount(int $unitId, ?string $part = null): void
    {
        $this->unitId = $unitId;
        $letters = $this->texts->pluck('letter');
        $this->part = $part === 'words' || $letters->contains($part) ? (string) $part : (string) $letters->first();

        $this->run;
    }

    #[Computed]
    public function unit(): VocabUnit
    {
        return VocabUnit::findOrFail($this->unitId);
    }

    /**
     * Opening a lesson starts its run: that is what the lesson list counts as "opened".
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
        return $this->texts->firstWhere('letter', $this->part);
    }

    /**
     * Every new word of the lesson, section after section.
     *
     * @return Collection<int, VocabItem>
     */
    #[Computed]
    public function words(): Collection
    {
        return $this->texts->flatMap(fn (VocabText $text) => $text->items)->values();
    }

    /**
     * @return array<int, array{state: string, strength: string|null}>
     */
    #[Computed]
    public function statuses(): array
    {
        return app(VocabCourse::class)->statuses(auth()->user(), $this->words);
    }

    /**
     * The words the learner chose (and the ones they added "anyway" although
     * they are already lesson words): adds them to Review, finishes the
     * lesson and goes back to the list.
     *
     * @param  list<int>  $picked
     * @param  list<int>  $anyway
     */
    public function add(array $picked, array $anyway = []): void
    {
        $course = app(VocabCourse::class);
        $added = $course->addWords(auth()->user(), $this->unit, array_map('intval', $picked), array_map('intval', $anyway));
        $course->finish(auth()->user(), $this->unit);

        if ($added > 0) {
            session()->flash('vocab-added', $added.' '.str('word')->plural($added).' added to Review · Vocab in Use');
        }

        $this->redirectRoute('courses.vocab', navigate: true);
    }

    /**
     * A section's text as HTML with its new words marked as tappable,
     * underlined buttons. Built here as one string because any whitespace
     * between the pieces would show up as a space before the punctuation.
     */
    public function markup(VocabText $text): HtmlString
    {
        $html = '';

        foreach ($text->pieces() as $piece) {
            $escaped = e($piece['text']);

            $html .= $piece['item'] === null
                ? $escaped
                : '<button type="button" x-on:click="pick('.$piece['item']->id.')" class="cursor-pointer rounded-sm font-bold underline decoration-dotted decoration-2 underline-offset-4">'.$escaped.'</button>';
        }

        return new HtmlString($html);
    }
};
?>

@php
    $text = $this->text;
    $letters = $this->texts->pluck('letter');
    $next = $text ? $letters->get($letters->search($text->letter) + 1) : null;
    $audioUrl = $text?->audioUrl();
    $words = $this->words;
    $statuses = $this->statuses;
@endphp

<div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6" data-testid="vocab-unit">
    <header class="flex items-center gap-2">
        @if ($part === 'words')
            <a href="{{ route('courses.vocab.unit', [$this->unit->number, $letters->last()]) }}" wire:navigate aria-label="Back to the last section" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        @else
            <a href="{{ route('courses.vocab') }}" wire:navigate aria-label="Back to the lessons" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        @endif
        <div class="min-w-0 flex-1">
            <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Unit {{ $this->unit->number }} · {{ $this->unit->title }}@if ($text) · Section {{ $text->letter }} of {{ $letters->count() }}@endif</p>
            <p class="font-display text-2xl leading-tight font-semibold text-ink dark:text-ink-dark">@if ($part === 'words') {{ $words->count() }} new {{ Str::plural('word', $words->count()) }} @else {{ $text->title }} @endif</p>
        </div>
    </header>

    @if ($part !== 'words' && $text)
        <section
            class="space-y-4"
            wire:key="vocab-section-{{ $text->letter }}"
            x-data="{
                items: {{ \Illuminate\Support\Js::from($text->items->mapWithKeys(fn ($item) => [$item->id => ['id' => $item->id, 'phrase' => $item->phrase, 'ipa' => $item->ipa, 'fa' => $item->phrase_fa, 'gloss' => $item->gloss, 'start' => $item->audio_start, 'end' => $item->audio_end]])) }},
                sel: null,
                open: false,
                stop: null,
                pick(id) { this.sel = this.items[id]; this.open = true; },
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
            @if ($audioUrl)
                <x-audio-player :url="$audioUrl" />
                <audio x-ref="hear" src="{{ $audioUrl }}" preload="none"></audio>
            @endif

            @php $persianLines = collect($text->sentences)->pluck('fa')->filter()->values(); @endphp
            @if ($persianLines->isNotEmpty())
                <div class="flex justify-end"><x-fa-toggle /></div>
            @endif

            <div class="card px-4 py-4 text-lg leading-9 whitespace-pre-line text-ink dark:text-ink-dark">{!! $this->markup($text) !!}</div>

            @if ($persianLines->isNotEmpty())
                <div x-show="$store.fa.on" x-cloak dir="rtl" class="font-fa space-y-1 text-sm leading-8 text-ink-soft dark:text-ink-soft-dark" data-testid="vocab-section-fa">
                    @foreach ($persianLines as $line)
                        <p>{{ $line }}</p>
                    @endforeach
                </div>
            @endif

            @if ($text->items->contains(fn ($item) => $item->isInText()))
                <p class="text-center text-sm text-ink-faint dark:text-ink-faint-dark">Tap an underlined word</p>
            @endif

            <div class="flex items-center justify-between gap-3 pt-2">
                <div class="flex gap-1.5" aria-hidden="true">
                    @foreach ($letters as $dot)
                        <span @class(['h-2 w-2 rounded-full', 'bg-ink dark:bg-ink-dark' => $dot === $text->letter, 'bg-line dark:bg-line-dark' => $dot !== $text->letter])></span>
                    @endforeach
                </div>
                <a href="{{ route('courses.vocab.unit', [$this->unit->number, $next ?? 'words']) }}" wire:navigate class="rounded-2xl bg-accent px-5 py-2.5 text-sm font-bold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">{{ $next ? 'Next section ›' : 'Words ›' }}</a>
            </div>

            <x-bottom-sheet show="open" label="Word">
                <template x-if="sel">
                    <div class="space-y-3">
                        <p dir="rtl" class="font-fa text-3xl leading-snug font-bold text-ink dark:text-ink-dark" x-show="sel.fa" x-text="sel.fa"></p>
                        <div>
                            <p class="font-display text-xl font-semibold text-ink-soft dark:text-ink-soft-dark" x-text="sel.phrase"></p>
                            <p class="text-sm text-ink-faint dark:text-ink-faint-dark" x-show="sel.ipa" x-text="'/' + sel.ipa + '/'"></p>
                        </div>
                        <p class="text-base text-ink-soft dark:text-ink-soft-dark" x-show="sel.gloss" x-text="sel.gloss"></p>
                        @if ($audioUrl)
                            <button type="button" x-on:click="hear()" x-show="sel.start !== null" aria-label="Hear it" class="inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-speaker-wave', 'h-5 w-5')</button>
                        @endif
                    </div>
                </template>
            </x-bottom-sheet>
        </section>
    @elseif ($part === 'words')
        <section
            class="space-y-3 pb-24"
            wire:key="vocab-words"
            x-data="{
                picked: [],
                anyway: [],
                free: {{ \Illuminate\Support\Js::from($words->filter(fn ($item) => $statuses[$item->id]['state'] === 'new')->pluck('id')->values()) }},
                toggle(id) { this.picked = this.picked.includes(id) ? this.picked.filter((x) => x !== id) : [...this.picked, id]; },
                addAnyway(id) { if (! this.anyway.includes(id)) this.anyway.push(id); if (! this.picked.includes(id)) this.picked.push(id); },
                selectAll() { this.picked = [...new Set([...this.picked, ...this.free])]; },
            }"
        >
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Choose the ones to review.</p>

            <ul class="card divide-y divide-line overflow-hidden dark:divide-line-dark">
                @foreach ($words as $item)
                    @php $status = $statuses[$item->id]; @endphp
                    <li wire:key="vocab-pick-{{ $item->id }}">
                        @if ($status['state'] === 'added')
                            <div class="flex items-center gap-3 px-3.5 py-2.5" data-testid="vocab-word-added">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-success text-white dark:bg-success-dark">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                                <span class="min-w-0 flex-1"><span class="font-extrabold text-ink dark:text-ink-dark">{{ $item->phrase }}</span> @if ($item->ipa)<span class="text-xs text-ink-faint dark:text-ink-faint-dark">/{{ $item->ipa }}/</span>@endif<span dir="rtl" class="font-fa block text-sm text-ink-soft dark:text-ink-soft-dark">{{ $item->phrase_fa }}</span></span>
                            </div>
                        @elseif ($status['state'] === 'in_words')
                            <div class="flex items-center gap-3 px-3.5 py-2.5" data-testid="vocab-word-in-words">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border-2 border-dashed" x-bind:class="picked.includes({{ $item->id }}) ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : 'border-line bg-surface-sunken text-ink-faint dark:border-line-dark dark:bg-surface-sunken-dark'">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                                <span class="min-w-0 flex-1">
                                    <span class="font-extrabold text-ink dark:text-ink-dark">{{ $item->phrase }}</span> @if ($item->ipa)<span class="text-xs text-ink-faint dark:text-ink-faint-dark">/{{ $item->ipa }}/</span>@endif
                                    <span dir="rtl" class="font-fa block text-sm text-ink-soft dark:text-ink-soft-dark">{{ $item->phrase_fa }}</span>
                                    <span class="mt-0.5 flex items-center gap-2">
                                        <span class="rounded-full bg-success-soft px-2 py-0.5 text-[11px] font-extrabold text-success dark:bg-success-soft-dark dark:text-success-dark">In your Words · {{ $status['strength'] }}</span>
                                        <button type="button" x-show="! picked.includes({{ $item->id }})" x-on:click="addAnyway({{ $item->id }})" class="cursor-pointer text-[11px] font-extrabold text-accent-ink underline dark:text-accent-ink-dark">Add anyway</button>
                                    </span>
                                </span>
                            </div>
                        @else
                            <button type="button" x-on:click="toggle({{ $item->id }})" x-bind:aria-pressed="picked.includes({{ $item->id }})" class="flex w-full cursor-pointer items-center gap-3 px-3.5 py-2.5 text-start transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-lg border-2" x-bind:class="picked.includes({{ $item->id }}) ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : 'border-line text-transparent dark:border-line-dark'">@svg('heroicon-s-check', 'h-3.5 w-3.5')</span>
                                <span class="min-w-0 flex-1"><span class="font-extrabold text-ink dark:text-ink-dark">{{ $item->phrase }}</span> @if ($item->ipa)<span class="text-xs text-ink-faint dark:text-ink-faint-dark">/{{ $item->ipa }}/</span>@endif<span dir="rtl" class="font-fa block text-sm text-ink-soft dark:text-ink-soft-dark">{{ $item->phrase_fa }}</span></span>
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>

            <div class="fixed inset-x-0 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-30 mx-auto flex max-w-2xl gap-2 border-t border-line bg-surface px-4 py-3 dark:border-line-dark dark:bg-surface-dark">
                <button type="button" x-on:click="selectAll()" class="cursor-pointer rounded-2xl border-2 border-line px-4 py-3 text-sm font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Select all</button>
                <button type="button" x-on:click="$wire.add(picked, anyway)" wire:loading.attr="disabled" wire:target="add" class="flex-1 cursor-pointer rounded-2xl bg-accent py-3 text-sm font-bold text-white dark:bg-accent-dark" x-text="picked.length ? 'Add ' + picked.length + ' to review' : 'Finish'"></button>
            </div>
        </section>
    @endif
</div>
