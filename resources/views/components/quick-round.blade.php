{{--
    A reusable "quick round" of tap-card checks — the shared interaction
    shell behind every low-pressure receptive exercise in the app (My
    Words' meaning check, Vocabulary Builder's pre-writing warm-up,
    Listening's comprehension check, Grammar in Context's Quick Check,
    Mission Brief's confidence check — see EOS-009 §8). One card at a
    time, tap an answer, instant color feedback, auto-advances after a
    beat — no submit button, no form, no AI call (a plain index-match).
    Always skippable and never itself creates Evidence or blocks
    progress; a caller that wants to react to the result (e.g. "only
    require a written sentence after 2 misses") listens for the
    dispatched "quick-round-completed" / "quick-round-skipped" browser
    events, or supplies raw Alpine via $onComplete/$onSkip.

    @param list<array{prompt: string, options: list<string>, correct: int}> $cards
    @param string|null $onComplete Raw Alpine statement(s) run when the
        round finishes naturally, e.g. "$wire.call('unlockWriting')".
    @param string|null $onSkip Raw Alpine statement(s) run when skipped.
--}}
@props(['cards', 'onComplete' => null, 'onSkip' => null])

@if (count($cards))
    <div
        x-data="{
            cards: @js($cards),
            index: 0,
            selected: null,
            correctCount: 0,
            correctStreak: 0,
            finished: false,
            skipped: false,
            get card() { return this.cards[this.index] ?? null },
            get isLast() { return this.index >= this.cards.length - 1 },
            pick(i) {
                if (this.selected !== null) return;
                this.selected = i;
                if (i === this.card.correct) {
                    this.correctCount++;
                    this.correctStreak++;
                    window.eosSound?.playSuccess();
                } else {
                    this.correctStreak = 0;
                }
                setTimeout(() => this.advance(), 700);
            },
            advance() {
                if (this.isLast) {
                    this.finished = true;
                    $dispatch('quick-round-completed', { correct: this.correctCount, total: this.cards.length });
                    {{ $onComplete }}
                    return;
                }
                this.index++;
                this.selected = null;
            },
            skip() {
                this.skipped = true;
                $dispatch('quick-round-skipped');
                {{ $onSkip }}
            },
        }"
        x-show="!skipped"
        {{ $attributes->class(['rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark']) }}
    >
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

                <p class="mt-3 flex items-center gap-1.5 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                    Quick check
                    <span x-show="correctStreak >= 3" x-cloak class="inline-flex items-center gap-0.5 text-accent-ink dark:text-accent-ink-dark">
                        @svg('heroicon-s-fire', 'h-3.5 w-3.5') <span x-text="correctStreak"></span>
                    </span>
                </p>
                <p class="mt-1 text-base font-bold text-ink dark:text-ink-dark" x-text="card.prompt"></p>

                <div class="mt-3 grid gap-2" :class="card.options.length <= 2 ? 'grid-cols-2' : 'grid-cols-1 sm:grid-cols-2'">
                    <template x-for="(option, i) in card.options" :key="i">
                        <button
                            type="button"
                            x-on:click="pick(i)"
                            :disabled="selected !== null"
                            :class="{
                                'border-success bg-success-soft text-success dark:border-success-dark dark:bg-success-soft-dark dark:text-success-dark': selected !== null && i === card.correct,
                                'border-red-300 bg-red-50 text-red-600 dark:border-red-800 dark:bg-red-950': selected === i && i !== card.correct,
                                'border-line text-ink hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark': selected === null || (i !== card.correct && selected !== i),
                            }"
                            class="cursor-pointer rounded-xl border px-3 py-2.5 text-left text-sm font-semibold transition-colors disabled:cursor-not-allowed"
                            x-text="option"
                        ></button>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="finished">
            <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-success dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                <span x-text="correctCount === cards.length ? `All ${cards.length} — nice!` : `${correctCount} of ${cards.length} — nice!`"></span>
            </p>
        </template>
    </div>
@endif
