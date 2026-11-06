{{--
    The sheet for one word, shared by every word: its meanings, every
    sentence to learn it from (the learner's own, the AI's, the lesson's)
    with the word picked out, "Another sentence", a place to write one of
    their own that the AI checks, and how far along the word is.

    The data of every word rides on this element (data-words, see
    VocabularyWord::sheetData()), and the Alpine component notebookWordSheet
    (resources/js/app.js) shows the tapped one at once — no round trip to
    the server. Only "Another sentence", "Check my sentence" and "Remove"
    call it.

    Needs $sheets (word id => data) and $removeSheetButton.
--}}
<div
    x-data="notebookWordSheet"
    x-effect="open(sheet)"
    data-words="{{ json_encode($sheets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
    x-show="active && word"
    x-cloak
    class="flex flex-col gap-3"
>
    <template x-if="word">
        <div class="flex flex-col gap-3">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-display text-3xl font-semibold text-ink dark:text-ink-dark" x-text="word.word"></p>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs font-extrabold text-ink-faint uppercase dark:text-ink-faint-dark">
                        <span x-show="word.pos" class="tracking-wide" x-text="word.pos"></span>
                        <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" x-bind:class="statusDot"></span><span x-text="word.strength"></span></span>
                        <span x-show="word.commonness" class="inline-flex items-center gap-1">
                            <span class="flex gap-0.5" aria-hidden="true">
                                <template x-for="dot in 3" :key="dot"><span class="h-1.5 w-1.5 rounded-full" x-bind:class="dot <= word.commonness ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark'"></span></template>
                            </span>
                            <span x-text="['Less common', 'Common', 'Very common'][(word.commonness || 1) - 1]"></span>
                        </span>
                        <span x-show="word.mine" class="rounded-md bg-accent-soft px-1.5 py-0.5 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Added by you</span>
                        <span x-show="! word.mine && word.mission" x-text="word.mission"></span>
                    </div>
                </div>
                <button type="button" x-on:click="window.eosVoice?.teddy(word.word)" title="Read aloud" class="inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full border border-line p-1.5 text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    <span class="sr-only">Read aloud</span>
                    @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
                </button>
            </div>

            <div x-show="word.fa || word.meaning" class="space-y-1 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                <p x-show="word.fa" dir="rtl" class="font-fa text-lg font-medium text-ink dark:text-ink-dark" x-text="word.fa"></p>
                <p x-show="word.meaning" class="text-sm text-ink-soft dark:text-ink-soft-dark" x-text="word.meaning"></p>
            </div>

            <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Sentences</p>
            <div class="space-y-2">
                <template x-for="(item, index) in word.sentences" :key="index">
                    <div class="flex items-start gap-2 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                        <div class="min-w-0 flex-1">
                            <p class="mb-0.5 text-[11px] font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark" x-text="sourceLabels[item.source]"></p>
                            <p class="text-base leading-relaxed whitespace-pre-wrap text-ink dark:text-ink-dark"><span x-text="item.before"></span><strong x-show="item.hit" class="font-bold text-accent-ink dark:text-accent-ink-dark" x-text="item.hit"></strong><span x-text="item.after"></span></p>
                        </div>
                        <button type="button" x-on:click="window.eosVoice?.teddy(item.text)" title="Read aloud" class="inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full border border-line p-1.5 text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                            <span class="sr-only">Read aloud</span>
                            @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
                        </button>
                    </div>
                </template>
                <p x-show="! word.sentences.length" class="rounded-2xl bg-surface p-3 text-sm text-ink-faint dark:bg-surface-dark dark:text-ink-faint-dark">No sentence yet. Make one below.</p>
            </div>
            <button type="button" x-on:click="another()" x-bind:disabled="making" class="cursor-pointer rounded-2xl bg-surface-sunken py-3 text-sm font-extrabold text-ink-soft disabled:opacity-60 dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                <span x-show="! making">✦ Another sentence</span><span x-show="making" x-cloak>Thinking…</span>
            </button>
            <p x-show="noneMade" x-cloak class="text-center text-sm font-semibold text-warning-ink">I couldn’t make one just now. Try again.</p>

            <p class="text-xs font-extrabold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Use it in your own sentence</p>
            <div class="space-y-2 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                <textarea x-model="writing" rows="2" maxlength="200" x-bind:aria-label="'Your sentence with ' + word.word" x-bind:placeholder="'Write a sentence with “' + word.word + '”'" class="w-full resize-none rounded-xl border-2 border-line bg-ground p-2.5 text-base text-ink focus:border-accent focus:ring-0 dark:border-line-dark dark:bg-ground-dark dark:text-ink-dark"></textarea>
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
                <template x-for="step in word.ladder" :key="step.step">
                    <div class="rounded-xl bg-surface py-2 text-center text-xs font-extrabold dark:bg-surface-dark" x-bind:class="step.done ? 'text-success dark:text-success-dark' : 'text-ink-faint dark:text-ink-faint-dark'">
                        <span class="mx-auto mb-1 flex h-5 w-5 items-center justify-center rounded-full border-2 text-[11px]" x-bind:class="step.done ? 'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark' : 'border-line dark:border-line-dark'" x-text="step.done ? '✓' : ''"></span>
                        <span x-text="step.step"></span>
                    </div>
                </template>
            </div>
            <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Next review: <span x-text="word.next"></span></p>

            <button type="button" x-on:click="$wire.removeWord(word.id); sheet = false" class="{{ $removeSheetButton }}">@svg('heroicon-o-trash', 'h-4 w-4') Remove from my words</button>
        </div>
    </template>
</div>
