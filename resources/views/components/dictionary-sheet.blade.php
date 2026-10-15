{{--
    The tap-a-word dictionary: a bottom sheet, one per page, that opens when
    a word is tapped inside any element marked x-lookup (see Alpine
    directive 'lookup' and Alpine.data('dictionarySheet') in
    resources/js/app.js). It shows the Persian and English meanings of the
    word and lets the learner keep the meaning that fits the sentence they
    met it in by adding it to My Words.

    Lives in the layout, outside every Livewire component on purpose: a
    lookup is a plain fetch to route('dictionary.lookup'), so it never
    re-renders the step being read, and the sheet survives a step changing
    underneath it. Sits above the full-screen layers of the audio and video
    players (z-50), and Esc closes only the sheet, not the full screen behind
    it (the listener is registered in the capture phase and stops the key).
--}}
<div
    x-data="dictionarySheet({ lookup: @js(route('dictionary.lookup')), persian: @js(route('dictionary.persian')), store: @js(route('vocabulary.store')) })"
    x-on:eos-lookup.window="show($event.detail)"
    x-on:keydown.escape.window.capture="if (isOpen) { $event.stopPropagation(); close() }"
>
    <div
        x-show="isOpen"
        x-cloak
        x-on:click="close()"
        x-transition.opacity.duration.200ms
        aria-hidden="true"
        class="fixed inset-0 z-[60] bg-ink/40"
    ></div>

    <div
        x-show="isOpen"
        x-cloak
        x-swipe-close="close()"
        x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
        x-transition:enter-start="translate-y-full"
        x-transition:enter-end="translate-y-0"
        x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
        x-transition:leave-start="translate-y-0"
        x-transition:leave-end="translate-y-full"
        role="dialog"
        aria-modal="true"
        aria-label="Dictionary"
        class="fixed inset-x-0 bottom-0 z-[70] mx-auto max-h-[80dvh] w-full max-w-lg touch-pan-y overflow-y-auto overscroll-contain rounded-t-2xl border border-b-0 border-line bg-surface pb-[env(safe-area-inset-bottom)] text-left shadow-2xl dark:border-line-dark dark:bg-surface-dark"
    >
        <div class="mx-auto mt-2 mb-1 h-1 w-10 rounded-full bg-line dark:bg-line-dark" aria-hidden="true"></div>

        <div class="space-y-4 p-4 sm:p-5">
            <div class="flex items-start justify-between gap-3">
                <p class="font-display min-w-0 text-2xl font-extrabold break-words text-ink dark:text-ink-dark" x-text="word"></p>

                <div class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        x-on:click="speak()"
                        title="Hear it"
                        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border border-line text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >
                        <span class="sr-only">Hear the word</span>
                        @svg('heroicon-o-speaker-wave', 'h-4 w-4')
                    </button>
                    <button
                        type="button"
                        x-on:click="close()"
                        title="Close"
                        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                    >
                        <span class="sr-only">Close the dictionary</span>
                        @svg('heroicon-o-x-mark', 'h-5 w-5')
                    </button>
                </div>
            </div>

            <div x-show="loading" x-cloak class="animate-pulse space-y-2" aria-live="polite">
                <div class="h-6 w-1/3 rounded bg-surface-sunken dark:bg-surface-sunken-dark"></div>
                <div class="h-4 w-full rounded bg-surface-sunken dark:bg-surface-sunken-dark"></div>
                <div class="h-4 w-4/5 rounded bg-surface-sunken dark:bg-surface-sunken-dark"></div>
            </div>

            <div x-show="failed" x-cloak class="space-y-2 rounded-xl bg-danger-soft p-3 text-sm text-danger-ink" role="alert">
                <p>The dictionary could not be reached. Check your connection and try again.</p>
                <button
                    type="button"
                    x-on:click="show({ word, sentence })"
                    class="cursor-pointer rounded-full border border-danger-line px-3 py-1 text-xs font-semibold transition-colors hover:bg-white/60"
                >Try again</button>
            </div>

            <template x-if="entry">
                <div class="space-y-4">
                    <div
                        x-show="persianLines.length"
                        dir="rtl"
                        class="font-fa space-y-1 rounded-xl bg-accent-soft px-4 py-3 text-accent-ink transition-opacity dark:bg-accent-soft-dark dark:text-accent-ink-dark"
                        x-bind:class="improving ? 'opacity-70' : ''"
                    >
                        <template x-for="(line, index) in persianLines" :key="index">
                            <p class="text-xl font-semibold">
                                <span x-show="line.label" class="ml-1.5 text-xs font-medium opacity-70" x-text="line.label + ':'"></span>
                                <span x-text="line.words"></span>
                            </p>
                        </template>
                    </div>

                    <div x-show="current" class="space-y-1.5">
                        <span class="inline-block rounded-full bg-surface-sunken px-2.5 py-0.5 text-[11px] font-semibold tracking-wide text-ink-soft uppercase dark:bg-surface-sunken-dark dark:text-ink-soft-dark" x-text="current?.pos"></span>
                        <p class="text-base text-ink dark:text-ink-dark" x-text="current?.definition"></p>
                    </div>

                    <p x-show="example" class="border-l-2 border-accent pl-3 text-sm text-ink-soft italic dark:border-accent-dark dark:text-ink-soft-dark" x-text="example"></p>

                    <div x-show="entry.english_status === 'unavailable'" class="space-y-2 rounded-xl bg-warning-soft p-3 text-sm text-warning-ink" role="status">
                        <p>The English meaning could not be loaded right now.</p>
                        <button
                            type="button"
                            x-on:click="show({ word, sentence })"
                            class="cursor-pointer rounded-full border border-warning-line px-3 py-1 text-xs font-semibold transition-colors hover:bg-white/60"
                        >Try again</button>
                    </div>
                    <p x-show="entry.english_status === 'unknown'" class="text-sm text-ink-soft dark:text-ink-soft-dark">
                        The dictionary has no entry for this exact form. If it is a plural or a past form, tap the base word instead.
                    </p>

                    <div x-show="senses.length > 1" class="space-y-2">
                        <button
                            type="button"
                            x-on:click="showOthers = ! showOthers"
                            x-bind:aria-expanded="showOthers"
                            class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
                        >
                            <span x-text="showOthers ? 'Hide other meanings' : 'Other meanings (' + (senses.length - 1) + ')'"></span>
                            <span x-bind:class="showOthers ? 'rotate-180' : ''" class="transition-transform">@svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')</span>
                        </button>

                        <div x-show="showOthers" x-cloak class="space-y-1.5">
                            <template x-for="(sense, index) in senses" :key="index">
                                <button
                                    type="button"
                                    x-show="index !== chosen"
                                    x-on:click="chosen = index; showOthers = false"
                                    class="block w-full cursor-pointer rounded-xl border border-line p-2.5 text-left transition-colors hover:border-ink-faint dark:border-line-dark"
                                >
                                    <span class="text-[11px] font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark" x-text="sense.pos"></span>
                                    <span class="block text-sm text-ink dark:text-ink-dark" x-text="sense.definition"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </template>

            <div x-show="entry" x-cloak class="space-y-2">
                <button
                    type="button"
                    x-on:click="add()"
                    x-bind:disabled="saved || saving"
                    class="inline-flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-full px-5 py-2.5 text-sm font-semibold transition-colors disabled:cursor-default"
                    x-bind:class="saved
                        ? 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark'
                        : 'bg-accent text-white hover:opacity-90 disabled:opacity-60 dark:bg-accent-dark'"
                >
                    <span x-show="saved" class="inline-flex items-center gap-1.5">@svg('heroicon-s-check', 'h-4 w-4') In My Words</span>
                    <span x-show="! saved && ! saving">Add to My Words</span>
                    <span x-show="! saved && saving">Adding…</span>
                </button>
                <p x-show="addFailed" x-cloak class="text-center text-xs text-danger-ink" role="alert">Could not add it. Try again in a moment.</p>
            </div>
        </div>
    </div>
</div>
