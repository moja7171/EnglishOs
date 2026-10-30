{{--
    A reusable "build the sentence" round (Epic D) — the same low-pressure,
    skippable, ungraded shell as <x-quick-round> (see EOS-009 §8), but for
    word-order practice instead of multiple choice: tap words from a bank
    (already scrambled by the content author, not shuffled at runtime) into
    a sentence tray, check, retry if wrong, continue when right. No AI call,
    no form, no Evidence of its own — a caller that wants the result reacts
    to the dispatched "word-order-completed" / "word-order-skipped" browser
    events, or supplies raw Alpine via $onComplete/$onSkip.

    @param list<array{words: list<string>, answer: string}> $cards
        `words` is the scrambled bank for that sentence, in the order the
        content author wrote it — never reshuffled here, so a Livewire
        re-render never changes the puzzle mid-attempt. `answer` is the
        correct sentence, compared case-insensitively against the tray
        joined with single spaces.
    @param string|null $onComplete Raw Alpine statement(s) run when the
        round finishes naturally.
    @param string|null $onSkip Raw Alpine statement(s) run when skipped.
    @param string|null $draftKey A localStorage key: when given, the round
        is kept in this browser after every sentence and comes back where
        it was after a reload, like <x-quick-round>'s; a round that comes
        back finished runs $onComplete again.
    @param bool $lesson The one-task lesson screen's look (lesson design 5):
        no card, "Build the sentence" chip, the tray as a line, word tiles,
        a full-width Check at the bottom and the verdict in an answer sheet;
        each sentence reports its share in 'quick-round-progress'.
--}}
@props(['cards', 'onComplete' => null, 'onSkip' => null, 'draftKey' => null, 'lesson' => false])

@if (count($cards))
    <div
        x-data="{
            cards: @js($cards),
            index: 0,
            tray: [],
            bankUsed: [],
            checked: false,
            correct: false,
            correctCount: 0,
            finished: false,
            skipped: false,
            draftKey: @js($draftKey),
            init() {
                if (! this.draftKey) return;
                let saved = null;
                try { saved = JSON.parse(localStorage.getItem(this.draftKey)); } catch (e) {}
                if (! saved || saved.total !== this.cards.length) return;
                this.index = saved.index;
                this.correctCount = saved.correctCount;
                this.finished = saved.finished;
                if (this.finished) {
                    {{ $onComplete }}
                }
            },
            saveDraft() {
                if (! this.draftKey) return;
                try {
                    localStorage.setItem(this.draftKey, JSON.stringify({
                        total: this.cards.length,
                        index: this.index,
                        correctCount: this.correctCount,
                        finished: this.finished,
                    }));
                } catch (e) {}
            },
            get card() { return this.cards[this.index] ?? null },
            get isLast() { return this.index >= this.cards.length - 1 },
            get built() { return this.tray.join(' ') },
            tap(i) {
                if (this.checked) return;
                this.tray.push(this.card.words[i]);
                this.bankUsed.push(i);
            },
            untap(pos) {
                if (this.checked) return;
                this.tray.splice(pos, 1);
                this.bankUsed.splice(pos, 1);
            },
            check() {
                this.checked = true;
                this.correct = this.built.trim().toLowerCase().replace(/[.?!]+$/, '') === this.card.answer.trim().toLowerCase().replace(/[.?!]+$/, '');
                if (this.correct) { this.correctCount++; window.eosSound?.playSuccess() }
            },
            reset() { this.tray = []; this.bankUsed = []; this.checked = false; this.correct = false },
            advance() {
                if (this.isLast) {
                    this.finished = true;
                    this.saveDraft();
                    $dispatch('word-order-completed', { correct: this.correctCount, total: this.cards.length });
                    {{ $onComplete }}
                    return;
                }
                this.index++;
                this.reset();
                this.saveDraft();
                $dispatch('quick-round-progress', { share: this.index / this.cards.length });
            },
            skip() {
                this.skipped = true;
                $dispatch('word-order-skipped');
                {{ $onSkip }}
            },
            // A finished round the learner came back to (‹) has no card left to tap: this is its way on.
            proceed() {
                {{ $onComplete }}
            },
        }"
        x-show="!skipped"
        {{ $attributes->class([$lesson ? 'flex flex-col' : 'rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark']) }}
    >
        @if ($lesson)
        <template x-if="!finished && card">
            <div class="flex min-h-[calc(100dvh-13rem)] flex-col gap-5 pt-2">
                <div class="flex flex-1 flex-col gap-5">
                    <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Build the sentence</span>
                    <div class="flex min-h-14 flex-wrap items-end gap-2 border-b-2 border-line pb-2 dark:border-line-dark">
                        <template x-for="(word, pos) in tray" :key="pos">
                            <button type="button" x-on:click="untap(pos)" :disabled="checked" class="cursor-pointer rounded-xl border-2 border-b-4 border-line bg-surface px-3 py-1.5 text-base font-bold text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark" x-text="word"></button>
                        </template>
                    </div>
                    <div class="flex flex-wrap justify-center gap-2">
                        <template x-for="(word, i) in card.words" :key="i">
                            <button
                                type="button"
                                x-on:click="tap(i)"
                                :disabled="checked || bankUsed.includes(i)"
                                :class="bankUsed.includes(i) ? 'border-line bg-line text-transparent dark:border-line-dark dark:bg-line-dark' : 'border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark'"
                                class="cursor-pointer rounded-xl border-2 border-b-4 px-3 py-1.5 text-base font-bold"
                                x-text="word"
                            ></button>
                        </template>
                    </div>
                </div>

                <div class="flex flex-col items-stretch gap-3 pb-2">
                    <div x-show="!checked" class="flex flex-col gap-3">
                        <x-continue-button on-click="check()" wire-target="__none" ready-when="tray.length > 0">Check</x-continue-button>
                        <button type="button" x-on:click="skip" class="cursor-pointer text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
                    </div>
                    <x-answer-sheet when="checked" tone="correct ? 'good' : 'almost'" title="correct ? '✓ Right!' : 'Not quite'">
                        <span x-show="!correct" x-text="card.answer"></span>
                        <x-slot:actions>
                            <div x-show="correct"><x-continue-button on-click="advance()" wire-target="__none" /></div>
                            <div x-show="!correct" class="flex flex-col gap-3">
                                <x-continue-button on-click="reset()" wire-target="__none">Try again</x-continue-button>
                                <button type="button" x-on:click="advance()" class="cursor-pointer text-sm font-bold text-ink-soft dark:text-ink-soft-dark">Continue anyway</button>
                            </div>
                        </x-slot:actions>
                    </x-answer-sheet>
                </div>
            </div>
        </template>
        @else
        <template x-if="!finished && card">
            <div>
                <div class="flex items-center gap-3">
                    <div class="flex-1">
                        <x-progress-bar>
                            <div
                                class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                                :style="`width: ${(index + 1) / cards.length * 100}%`"
                            ></div>
                        </x-progress-bar>
                    </div>
                    <button
                        type="button"
                        x-on:click="skip"
                        class="shrink-0 cursor-pointer text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
                    >Skip</button>
                </div>

                <p class="mt-3 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Build the sentence</p>

                <div class="mt-2 flex min-h-11 flex-wrap items-center gap-1.5 rounded-lg border border-dashed border-line p-2 dark:border-line-dark">
                    <template x-for="(word, pos) in tray" :key="pos">
                        <button
                            type="button"
                            x-on:click="untap(pos)"
                            :disabled="checked"
                            class="cursor-pointer rounded-full border border-accent bg-accent-soft px-3 py-1 text-sm font-semibold text-accent-ink disabled:cursor-not-allowed dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-ink-dark"
                            x-text="word"
                        ></button>
                    </template>
                </div>

                <div class="mt-2 flex flex-wrap gap-1.5">
                    <template x-for="(word, i) in card.words" :key="i">
                        <button
                            type="button"
                            x-show="!bankUsed.includes(i)"
                            x-on:click="tap(i)"
                            :disabled="checked"
                            class="cursor-pointer rounded-full border border-line px-3 py-1 text-sm font-semibold text-ink hover:bg-surface-sunken disabled:cursor-not-allowed dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
                            x-text="word"
                        ></button>
                    </template>
                </div>

                <div class="mt-3 flex items-center gap-3">
                    <button
                        type="button"
                        x-show="!checked"
                        x-on:click="check"
                        :disabled="tray.length === 0"
                        class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
                    >Check</button>
                    <button
                        type="button"
                        x-show="checked && !correct"
                        x-cloak
                        x-on:click="reset"
                        class="cursor-pointer rounded-full border border-line px-4 py-1.5 text-sm font-semibold text-ink hover:bg-surface-sunken dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
                    >Try again</button>
                    <button
                        type="button"
                        x-show="checked && correct"
                        x-cloak
                        x-on:click="advance"
                        class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white dark:bg-accent-dark"
                    >Continue</button>
                    <span x-show="checked" x-cloak class="inline-flex items-center gap-1 text-sm font-semibold" :class="correct ? 'text-success dark:text-success-dark' : 'text-danger-ink'">
                        <span x-show="correct">@svg('heroicon-o-check-circle', 'h-4 w-4')</span>
                        <span x-text="correct ? 'Correct!' : 'Not quite — try again.'"></span>
                    </span>
                </div>
            </div>
        </template>

        @endif

        <template x-if="finished">
            <div class="flex flex-col gap-5">
                <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    <span x-text="correctCount === cards.length ? `All ${cards.length} — nice!` : `${correctCount} of ${cards.length} — nice!`"></span>
                </p>
                @if ($lesson && $onComplete)
                    <button type="button" x-on:click="proceed()" class="w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-colors hover:opacity-90 dark:bg-accent-dark">Continue</button>
                @endif
            </div>
        </template>
    </div>
@endif
