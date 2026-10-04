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

    @param list<array{prompt: string, options: list<string>, correct: int, optionType?: 'text'|'image', difficulty?: 'easy'|'medium'|'hard'}> $cards
        optionType defaults to 'text'; 'image' renders each option as a
        picture (options holds image URLs, not label strings) instead of a
        text button — same tap/color-feedback mechanics either way.
        difficulty is optional per card; when ANY card in the set carries
        one, the round switches to adaptive selection (see below) — a
        caller that never sets it (any set not yet retrofitted) gets
        exactly the old sequential-array-order behavior, unchanged.
    @param string|null $onComplete Raw Alpine statement(s) run when the
        round finishes naturally, e.g. "$wire.call('unlockWriting')".
    @param string|null $onSkip Raw Alpine statement(s) run when skipped.

    Adaptive mode (Story 4, requirements review, 2026-09-04): starts with
    an easy card; after 2 correct answers in a row (correctStreak, already
    tracked below), the next card is 'hard' if any remain unshown, else
    'medium', else whatever's left. A wrong answer (streak resets to 0)
    prefers 'easy'/'medium' for the next card rather than 'hard'. Every
    card is still shown exactly once, same total count either way — only
    the ORDER differs, picked live from the pool of not-yet-shown cards
    rather than authored upfront. A card missing `difficulty` inside an
    otherwise-adaptive set (e.g. Vocabulary Builder's image-match cards
    mixed in alongside its difficulty-tagged meaning-check cards) is
    treated as 'medium'.
--}}
@props(['cards', 'onComplete' => null, 'onSkip' => null])

@if (count($cards))
    <div
        x-data="{
            cards: @js($cards),
            shown: [],
            index: 0,
            selected: null,
            correctCount: 0,
            correctStreak: 0,
            finished: false,
            skipped: false,
            entering: false,
            letters: ['A', 'B', 'C', 'D', 'E', 'F'],
            get adaptive() { return this.cards.some(c => c.difficulty !== undefined) },
            get card() { return this.adaptive ? (this.cards[this.shown[this.index]] ?? null) : (this.cards[this.index] ?? null) },
            get isLast() { return this.index >= this.cards.length - 1 },
            difficultyOf(i) { return this.cards[i].difficulty ?? 'medium' },
            // Picks the next not-yet-shown card index, preferring (in
            // order) each level in `levels`; falls back to whichever
            // unshown card comes first in authored order if none of the
            // preferred levels have anything left.
            pickNextIndex(levels) {
                const remaining = this.cards.map((_, i) => i).filter(i => !this.shown.includes(i));
                for (const level of levels) {
                    const match = remaining.find(i => this.difficultyOf(i) === level);
                    if (match !== undefined) return match;
                }
                return remaining[0];
            },
            init() {
                if (this.adaptive) {
                    this.shown = [this.pickNextIndex(['easy', 'medium', 'hard'])];
                }
            },
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
                setTimeout(() => this.advance(), 900);
            },
            // The next card fades/slides in: flip `entering` on for one
            // frame so the transition has a start state to animate from.
            settle() {
                this.entering = true;
                this.$nextTick(() => requestAnimationFrame(() => { this.entering = false }));
            },
            stateOf(i) {
                if (this.selected === null) return 'idle';
                if (i === this.card.correct) return 'correct';
                return this.selected === i ? 'wrong' : 'muted';
            },
            advance() {
                if (this.adaptive) {
                    if (this.shown.length >= this.cards.length) {
                        this.finished = true;
                        $dispatch('quick-round-completed', { correct: this.correctCount, total: this.cards.length });
                        {{ $onComplete }}
                        return;
                    }
                    const levels = this.correctStreak >= 2 ? ['hard', 'medium', 'easy'] : ['easy', 'medium', 'hard'];
                    this.shown.push(this.pickNextIndex(levels));
                    this.index++;
                    this.selected = null;
                    this.settle();
                    return;
                }
                if (this.isLast) {
                    this.finished = true;
                    $dispatch('quick-round-completed', { correct: this.correctCount, total: this.cards.length });
                    {{ $onComplete }}
                    return;
                }
                this.index++;
                this.selected = null;
                this.settle();
            },
            skip() {
                this.skipped = true;
                $dispatch('quick-round-skipped');
                {{ $onSkip }}
            },
        }"
        x-show="!skipped"
        {{ $attributes->class(['card-sunken p-4 shadow-sm sm:p-5']) }}
    >
        <template x-if="!finished && card">
            <div>
                <div class="flex items-center gap-3">
                    <div class="flex-1">
                        <x-progress-bar>
                            <div
                                class="h-full rounded-full bg-accent transition-all duration-500 ease-out dark:bg-accent-dark"
                                :style="`width: ${(index + 1) / cards.length * 100}%`"
                            ></div>
                        </x-progress-bar>
                    </div>
                    <span class="shrink-0 text-xs font-bold text-ink-faint tabular-nums dark:text-ink-faint-dark" x-text="`${index + 1}/${cards.length}`"></span>
                    <button
                        type="button"
                        x-on:click="skip"
                        class="-my-1 -mr-1.5 shrink-0 cursor-pointer rounded-full px-2.5 py-1 text-xs font-semibold text-ink-faint transition-colors hover:bg-surface hover:text-ink-soft dark:text-ink-faint-dark dark:hover:bg-surface-dark dark:hover:text-ink-soft-dark"
                    >Skip</button>
                </div>

                <div
                    class="transition duration-200 ease-out motion-reduce:transition-none"
                    :class="entering ? 'translate-y-1.5 opacity-0' : 'translate-y-0 opacity-100'"
                >
                    <p class="mt-4 flex items-center gap-2 text-xs font-bold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">
                        Quick check
                        <span x-show="correctStreak >= 3" x-cloak class="inline-flex items-center gap-0.5 rounded-full bg-accent-soft px-2 py-0.5 normal-case dark:bg-accent-soft-dark">
                            @svg('heroicon-s-fire', 'h-3.5 w-3.5') <span x-text="correctStreak"></span>
                        </span>
                    </p>
                    <p class="mt-1.5 font-display text-xl leading-snug font-bold text-ink dark:text-ink-dark" x-text="card.prompt"></p>

                    <div class="mt-4 grid gap-2.5" :class="card.optionType === 'image' ? 'grid-cols-2' : 'grid-cols-1 sm:grid-cols-2'">
                        <template x-for="(option, i) in card.options" :key="i">
                            <button
                                type="button"
                                x-on:click="pick(i)"
                                :disabled="selected !== null"
                                :data-state="stateOf(i)"
                                :class="card.optionType === 'image' ? 'overflow-hidden p-1.5!' : ''"
                                class="choice"
                            >
                                {{-- The `<img>` markup only ships in the server-rendered HTML when
                                     THIS invocation actually has an image card — every caller that
                                     never uses optionType:'image' (My Words, Listening, Grammar in
                                     Context, Mission Brief) keeps emitting exactly the same output
                                     as before this feature existed, not a dead `<template>` block. --}}
                                @if (collect($cards)->contains('optionType', 'image'))
                                <template x-if="card.optionType === 'image'">
                                    <img :src="option" alt="" class="h-20 w-full rounded-xl object-cover">
                                </template>
                                @endif
                                <template x-if="card.optionType !== 'image'">
                                    <span class="flex min-w-0 flex-1 items-center gap-3">
                                        <span class="choice-key" x-text="letters[i]"></span>
                                        <span class="min-w-0 flex-1 leading-snug" x-text="option"></span>
                                        <span x-show="stateOf(i) === 'correct'" x-cloak class="shrink-0">@svg('heroicon-s-check-circle', 'h-5 w-5')</span>
                                        <span x-show="stateOf(i) === 'wrong'" x-cloak class="shrink-0">@svg('heroicon-s-x-circle', 'h-5 w-5')</span>
                                    </span>
                                </template>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>

        <template x-if="finished">
            <div class="flex items-center gap-3">
                <span class="animate-badge-pop flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                    @svg('heroicon-s-check', 'h-6 w-6')
                </span>
                <p class="font-display text-lg font-bold text-ink dark:text-ink-dark" x-text="correctCount === cards.length ? `All ${cards.length} — nice!` : `${correctCount} of ${cards.length} — nice!`"></p>
            </div>
        </template>
    </div>
@endif
