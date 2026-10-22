{{--
    The day's ready-to-paste prompt (see App\Services\PiPrompts), shown on
    the daily voice practice page (/pi) — purely a suggestion, never
    touches Evidence.

    Renders nothing if $task is null (the caller passes a PiPrompts
    *Task() result straight through — same fail-soft convention as every
    PexelsClient call).

    @param array{prompt: string}|null $task
    @param int $rows Visible height of the prompt box.
--}}
@props(['task', 'rows' => 2])

@if ($task)
    <div
        x-data="{ copied: false, openedPi: false, copy() { navigator.clipboard.writeText($refs.prompt.value); this.copied = true; setTimeout(() => this.copied = false, 1500) } }"
        class="rounded-xl border border-dashed border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark"
    >
        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-ink-dark">
            @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')
            Today's prompt
        </p>
        <textarea
            x-ref="prompt"
            readonly
            rows="{{ $rows }}"
            class="mt-2 w-full resize-none rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink-soft dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark"
        >{{ $task['prompt'] }}</textarea>
        <div class="mt-2 flex flex-wrap gap-2">
            <button
                type="button"
                x-on:click="copy()"
                class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
            >
                <span x-show="! copied">Copy prompt</span>
                <span x-show="copied" x-cloak>Copied!</span>
            </button>
            {{-- Copies, then tries Pi's Android app; a phone that can't open it
                 stays on this page with the line below. --}}
            <button
                type="button"
                x-on:click="copy(); openedPi = true; $dispatch('pi-opened'); if (/Android/i.test(navigator.userAgent)) { window.location.href = {{ App\Services\PiPrompts::piAndroidIntentJs() }} }"
                class="cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >Copy &amp; open Pi</button>
        </div>
        <p x-show="openedPi" x-cloak class="mt-2 text-xs font-semibold text-success dark:text-success-dark">Prompt copied ✓ — open the Pi app and paste.</p>
    </div>
@endif
