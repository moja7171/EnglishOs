{{--
    The sheet for one word: its meanings, every sentence to learn it from
    (the learner's own, the AI's, the lesson's) with the word picked out,
    "Another sentence", a place to write one of their own that the AI
    checks, and how far along the word is.

    Needs $word, $courseFa, $statusDot, $removeSheetButton.
--}}
@php
    $meaningFa = $word->meaning_fa ?: ($courseFa[mb_strtolower($word->word)] ?? null);
    $mission = $word->sourceMissionRun?->mission;
    $sourceLabels = ['own' => 'Your sentence', 'ai' => 'Made for you ✦', 'lesson' => 'From the lesson'];
@endphp
<div
    x-show="sheet === 'w{{ $word->id }}'"
    wire:key="word-sheet-{{ $word->id }}"
    x-data="{
        writing: '',
        result: null,
        checking: false,
        making: false,
        noneMade: false,
        async another() {
            this.making = true;
            this.noneMade = false;
            const made = await $wire.makeSentence({{ $word->id }});
            this.making = false;
            this.noneMade = made === null;
        },
        async check() {
            if (this.writing.trim() === '') { return; }
            this.checking = true;
            this.result = await $wire.checkSentence({{ $word->id }}, this.writing);
            this.checking = false;
            window.eosFeedback?.[this.result.ok ? 'right' : 'wrong']?.();
            if (this.result.ok) { this.writing = ''; }
        },
    }"
    class="flex flex-col gap-3"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="font-display text-3xl font-semibold text-ink dark:text-ink-dark">{{ $word->word }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-extrabold text-ink-faint uppercase dark:text-ink-faint-dark">
                @if (filled($word->pos))
                    <span class="tracking-wide">{{ $word->pos }}</span>
                @endif
                <span class="inline-flex items-center gap-1"><span class="{{ $statusDot($word->studyStatus()) }} h-2 w-2 rounded-full"></span>{{ $word->strengthLabel() }}</span>
                @if ($word->source_mission_run_id === null)
                    <span class="rounded-md bg-accent-soft px-1.5 py-0.5 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Added by you</span>
                @elseif ($mission)
                    <span>{{ $mission->code }}</span>
                @endif
            </div>
        </div>
        <x-speak-button :text="$word->word" voice="teddy" />
    </div>

    @if (filled($meaningFa) || filled($word->meaning))
        <div class="space-y-1 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
            @if (filled($meaningFa))
                <p dir="rtl" class="font-fa text-lg font-medium text-ink dark:text-ink-dark">{{ $meaningFa }}</p>
            @endif
            @if (filled($word->meaning))
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $word->meaning }}</p>
            @endif
        </div>
    @endif

    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Sentences</p>
    <div class="space-y-2">
        @forelse ($word->learningSentences() as $item)
            <div wire:key="sentence-{{ $word->id }}-{{ $loop->index }}" class="flex items-start gap-2 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                <div class="min-w-0 flex-1">
                    <p class="mb-0.5 text-[11px] font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $sourceLabels[$item['source']] }}</p>
                    <p class="text-base leading-relaxed text-ink dark:text-ink-dark">{{ $word->highlightIn($item['text']) }}</p>
                </div>
                <x-speak-button :text="$item['text']" voice="teddy" />
            </div>
        @empty
            <p class="rounded-2xl bg-surface p-3 text-sm text-ink-faint dark:bg-surface-dark dark:text-ink-faint-dark">No sentence yet. Make one below.</p>
        @endforelse
    </div>
    <button type="button" x-on:click="another()" x-bind:disabled="making" class="cursor-pointer rounded-2xl bg-surface-sunken py-3 text-sm font-extrabold text-ink-soft disabled:opacity-60 dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
        <span x-show="! making">✦ Another sentence</span><span x-show="making" x-cloak>Thinking…</span>
    </button>
    <p x-show="noneMade" x-cloak class="text-center text-sm font-semibold text-warning-ink">I couldn’t make one just now. Try again.</p>

    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Use it in your own sentence</p>
    <div class="space-y-2 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
        <textarea x-model="writing" rows="2" maxlength="200" aria-label="Your sentence with {{ $word->word }}" placeholder="Write a sentence with “{{ $word->word }}”" class="w-full resize-none rounded-xl border-2 border-line bg-ground p-2.5 text-base text-ink focus:border-accent focus:ring-0 dark:border-line-dark dark:bg-ground-dark dark:text-ink-dark"></textarea>
        <template x-if="result">
            <p class="rounded-xl p-3 text-sm font-semibold" x-bind:class="result.ok ? 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' : 'bg-warning-soft text-warning-ink'">
                <span x-show="result.ok">✓ Saved to your sentences. </span><span x-text="result.note"></span>
                <span x-show="result.better" class="mt-1 block text-ink dark:text-ink-dark">Better: <strong x-text="result.better"></strong></span>
            </p>
        </template>
        <button type="button" x-on:click="check()" x-bind:disabled="checking || writing.trim() === ''" class="w-full cursor-pointer rounded-2xl bg-accent py-3 text-sm font-extrabold text-white disabled:opacity-50 dark:bg-accent-dark">
            <span x-show="! checking">Check my sentence</span><span x-show="checking" x-cloak>Checking…</span>
        </button>
    </div>

    <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">How well you know it</p>
    <div class="grid grid-cols-4 gap-1.5">
        @foreach ($word->ladder() as $step => $done)
            <div @class(['rounded-xl bg-surface py-2 text-center text-xs font-extrabold dark:bg-surface-dark', 'text-success dark:text-success-dark' => $done, 'text-ink-faint dark:text-ink-faint-dark' => ! $done])>
                <span @class(['mx-auto mb-1 flex h-5 w-5 items-center justify-center rounded-full border-2 text-[11px]', 'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark' => $done, 'border-line dark:border-line-dark' => ! $done])>{{ $done ? '✓' : '' }}</span>
                {{ $step }}
            </div>
        @endforeach
    </div>
    <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Next review: {{ $word->isDue() ? 'today' : $word->next_review_at->diffForHumans() }}</p>

    <button type="button" wire:click="removeWord({{ $word->id }})" x-on:click="sheet = false" class="{{ $removeSheetButton }}">@svg('heroicon-o-trash', 'h-4 w-4') Remove from my words</button>
</div>
