{{--
    The report form shared by the Friends cards and the chat: pick what's going
    on from four preset reasons (no English essay needed), optionally add
    detail, send. The caller owns the state, so everything it needs is passed
    in as the Livewire property/method names to bind to.

    @param \App\Models\User $friend Who is being reported.
    @param string|null $selected The currently chosen category key.
    @param string $categoryModel Livewire property holding the category key.
    @param string $detailsModel Livewire property holding the optional details.
    @param string $submitAction Livewire call that sends the report.
    @param string $cancelAction Livewire call that closes the form.
--}}
@props(['friend', 'selected' => null, 'categoryModel', 'detailsModel', 'submitAction', 'cancelAction'])

<div class="mt-2.5 space-y-2.5 rounded-xl border border-danger-line bg-danger-soft p-3">
    <p class="text-xs font-semibold text-danger-ink">Report {{ $friend->name }} — what's going on?</p>

    <div class="flex flex-wrap gap-1.5" role="radiogroup" aria-label="Reason for the report">
        @foreach (\App\Models\FriendReport::CATEGORIES as $key => $label)
            <label class="cursor-pointer">
                <input type="radio" wire:model.live="{{ $categoryModel }}" value="{{ $key }}" class="peer sr-only">
                <span class="inline-flex min-h-10 items-center rounded-full border border-danger-line px-3 text-xs font-semibold text-danger-ink transition-colors peer-checked:border-danger peer-checked:bg-danger peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-danger">{{ $label }}</span>
            </label>
        @endforeach
    </div>

    <textarea
        wire:model="{{ $detailsModel }}"
        rows="2"
        maxlength="1000"
        aria-label="Anything else we should know? (optional)"
        placeholder="Anything else we should know? (optional)"
        class="w-full rounded-lg border border-danger-line bg-transparent px-2 py-1.5 text-sm text-ink placeholder:text-ink-faint dark:text-ink-dark"
    ></textarea>

    <div class="flex items-center gap-3">
        <button
            type="button"
            wire:click="{{ $submitAction }}"
            wire:loading.attr="disabled"
            wire:target="{{ $submitAction }}"
            @disabled(! $selected)
            class="min-h-10 cursor-pointer rounded-full border border-danger-line px-4 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
        >Send report</button>
        <button
            type="button"
            wire:click="{{ $cancelAction }}"
            class="min-h-10 cursor-pointer text-xs text-ink-faint underline dark:text-ink-faint-dark"
        >Cancel</button>
    </div>
</div>
