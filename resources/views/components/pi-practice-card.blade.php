{{--
    An optional practice prompt for one of the learner's 3 persistent Pi
    chats (see App\Services\PiPrompts and /pi-setup) — purely a suggestion,
    never touches Evidence, never blocks the step, same register as
    <x-optional-challenge>/<x-practice-with-friend>.

    Renders nothing if $task is null (the caller passes a PiPrompts
    *Task() result straight through — same fail-soft convention as every
    PexelsClient call).

    @param string $roleLabel One of PiPrompts::onboardingRoles()'s labels, e.g. "Teacher".
    @param array{instruction: string, prompt: string}|null $task
--}}
@props(['roleLabel', 'task'])

@if ($task)
    <div
        x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.prompt.value); this.copied = true; setTimeout(() => this.copied = false, 1500) } }"
        class="rounded-xl border border-dashed border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark"
    >
        <p class="inline-flex items-center gap-1.5 text-sm font-semibold text-ink dark:text-ink-dark">
            @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')
            {{ $roleLabel }} chat in Pi
        </p>
        <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">{{ $task['instruction'] }}</p>
        <textarea
            x-ref="prompt"
            readonly
            rows="2"
            class="mt-2 w-full resize-none rounded-lg border border-line bg-surface px-2 py-1.5 text-xs text-ink-soft dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark"
        >{{ $task['prompt'] }}</textarea>
        <button
            type="button"
            x-on:click="copy()"
            class="mt-2 cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
        >
            <span x-show="! copied">Copy prompt</span>
            <span x-show="copied" x-cloak>Copied!</span>
        </button>
    </div>
@endif
