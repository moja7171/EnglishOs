@props(['friend', 'mutual' => false])

{{-- Everything destructive lives behind "⋯" (anchored dropdown, same
     pattern as the account menu — not a bottom sheet) so Unfollow is
     never a thumb-width from Message. Unfollow asks for a second tap
     inside the menu: it also closes the conversation until they follow
     back, which is a bigger deal than the label suggests. --}}
<div
    class="relative"
    x-data="{ menu: false, confirming: false }"
    x-on:click.outside="menu = false; confirming = false"
    x-on:keydown.escape.window="menu = false; confirming = false"
>
    <button
        type="button"
        x-on:click="menu = ! menu; confirming = false"
        aria-haspopup="menu"
        aria-label="More options for {{ $friend->name }}"
        class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
    >@svg('heroicon-o-ellipsis-horizontal', 'h-5 w-5')</button>

    <div
        x-show="menu"
        x-cloak
        x-transition.origin.top.right.scale.95.opacity.duration.150ms
        role="menu"
        class="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-line bg-surface py-1 text-left shadow-lg dark:border-line-dark dark:bg-surface-dark"
    >
        <div x-show="! confirming">
            <button
                type="button"
                x-on:click="confirming = true"
                role="menuitem"
                class="flex min-h-10 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-danger-ink transition-colors hover:bg-danger-soft"
            >
                @svg('heroicon-o-user-minus', 'h-4 w-4')
                Unfollow
            </button>
            <button
                type="button"
                wire:click="block({{ $friend->id }})"
                wire:loading.attr="disabled"
                wire:target="block({{ $friend->id }})"
                wire:confirm="Block {{ $friend->name }}? They won't be able to message you, and you won't see each other's activity."
                role="menuitem"
                class="flex min-h-10 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >
                @svg('heroicon-o-no-symbol', 'h-4 w-4')
                Block
            </button>
            <button
                type="button"
                wire:click="startReport({{ $friend->id }})"
                x-on:click="menu = false"
                role="menuitem"
                class="flex min-h-10 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >
                @svg('heroicon-o-flag', 'h-4 w-4')
                Report
            </button>
        </div>

        <div x-show="confirming" x-cloak class="space-y-2.5 px-4 py-3">
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">
                Unfollow {{ $friend->name }}? @if ($mutual) You won't be able to message each other until you follow again. @endif
            </p>
            <div class="flex gap-2">
                <button
                    type="button"
                    wire:click="unfollow({{ $friend->id }})"
                    wire:loading.attr="disabled"
                    wire:target="unfollow({{ $friend->id }})"
                    class="cursor-pointer rounded-full bg-danger px-3 py-1.5 text-xs font-semibold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                >Unfollow</button>
                <button
                    type="button"
                    x-on:click="confirming = false"
                    class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Cancel</button>
            </div>
        </div>
    </div>
</div>
