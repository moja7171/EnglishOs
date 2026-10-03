@props(['friend'])

    <div class="mt-2.5 space-y-2 rounded-xl border border-danger-line bg-danger-soft p-3">
        <textarea
            wire:model="reportReason.{{ $friend->id }}"
            rows="2"
            placeholder="What happened?"
            class="w-full rounded-lg border border-danger-line bg-transparent px-2 py-1 text-sm text-ink dark:text-ink-dark"
        ></textarea>
        <div class="flex gap-2">
            <button
                type="button"
                wire:click="submitReport({{ $friend->id }})"
                wire:loading.attr="disabled"
                wire:target="submitReport({{ $friend->id }})"
                class="cursor-pointer rounded-full border border-danger-line px-3 py-1 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >Submit report</button>
            <button
                type="button"
                wire:click="cancelReport({{ $friend->id }})"
                wire:loading.attr="disabled"
                wire:target="cancelReport({{ $friend->id }})"
                class="cursor-pointer text-xs text-ink-faint underline dark:text-ink-faint-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >Cancel</button>
        </div>
    </div>
