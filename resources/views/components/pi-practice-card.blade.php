{{--
    The day's ready-to-paste prompt (see App\Services\PiPrompts), shown on
    the daily voice practice page (/pi) — purely a suggestion, never
    touches Evidence. One big "Copy & open Pi" button; the prompt itself
    and the day's plan stay folded below it.

    Renders nothing if $task is null (the caller passes a PiPrompts
    *Task() result straight through — same fail-soft convention as every
    PexelsClient call).

    @param array{prompt: string, plan: list<string>}|null $task
    @param string $planLabel The plan fold's title, e.g. "Today's plan".
--}}
@props(['task', 'planLabel' => "Today's plan"])

@if ($task)
    <div x-data="{ copied: false, openedPi: false, copy() { navigator.clipboard.writeText($refs.prompt.value); this.copied = true; setTimeout(() => this.copied = false, 1500) } }">
        {{-- Copies, then tries Pi's Android app; a phone that can't open it
             stays on this page with the line below. --}}
        <button
            type="button"
            x-on:click="copy(); openedPi = true; if (/Android/i.test(navigator.userAgent)) { window.location.href = {{ App\Services\PiPrompts::piAndroidIntentJs() }} }"
            class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-full bg-accent px-5 py-3 text-base font-bold text-white shadow-sm transition-colors hover:opacity-90 dark:bg-accent-dark"
        >
            @svg('heroicon-s-microphone', 'h-5 w-5') Copy &amp; open Pi
        </button>
        <p x-show="openedPi" x-cloak class="mt-2 text-center text-xs text-success dark:text-success-dark">Prompt copied ✓ — open the Pi app and paste.</p>

        <div class="mt-3 divide-y divide-line border-t border-line text-sm dark:divide-line-dark dark:border-line-dark">
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center gap-2 py-2.5 text-ink-soft dark:text-ink-soft-dark">
                    @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                    <span class="min-w-0 flex-1">Prompt</span>
                    @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
                </summary>
                <div class="pb-3">
                    <textarea
                        x-ref="prompt"
                        readonly
                        rows="6"
                        class="w-full resize-none rounded-lg border border-line bg-surface-sunken px-2 py-1.5 text-xs text-ink-soft dark:border-line-dark dark:bg-surface-sunken-dark dark:text-ink-soft-dark"
                    >{{ $task['prompt'] }}</textarea>
                    <button
                        type="button"
                        x-on:click="copy()"
                        class="mt-2 cursor-pointer rounded-full border border-line px-3 py-1 text-xs text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >
                        <span x-show="! copied">Copy prompt</span>
                        <span x-show="copied" x-cloak>Copied!</span>
                    </button>
                </div>
            </details>
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center gap-2 py-2.5 text-ink-soft dark:text-ink-soft-dark">
                    @svg('heroicon-o-list-bullet', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                    <span class="min-w-0 flex-1">{{ $planLabel }}</span>
                    @svg('heroicon-o-chevron-down', 'h-4 w-4 shrink-0 text-ink-faint transition-transform group-open:rotate-180 dark:text-ink-faint-dark')
                </summary>
                <ol class="list-decimal space-y-1 ps-5 pb-3 text-ink-soft dark:text-ink-soft-dark">
                    @foreach ($task['plan'] as $planStep)
                        <li>{{ $planStep }}</li>
                    @endforeach
                </ol>
            </details>
        </div>
    </div>
@endif
