<?php

use Livewire\Component;

new class extends Component
{
    public bool $enabled = false;

    public bool $saved = false;

    public function mount(): void
    {
        $this->enabled = auth()->user()->review_reminder_enabled;
    }

    /**
     * Saves on every change — there is no Save button, the switch is the
     * setting.
     */
    public function updated(): void
    {
        $this->validate(['enabled' => ['boolean']]);

        $user = auth()->user();

        $user->forceFill(['review_reminder_enabled' => $this->enabled])->save();
        $user->rememberTimezone();

        $this->saved = true;
    }

    public function hasPhoneAlerts(): bool
    {
        return auth()->user()->pushSubscriptions()->exists();
    }
};
?>

<div class="space-y-3 card p-4">
    <div>
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Daily review reminder</p>
        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">At 20:30, only when cards are waiting.</p>
    </div>

    <label class="flex cursor-pointer items-center gap-2 text-sm text-ink dark:text-ink-dark">
        <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 accent-accent dark:accent-accent-dark">
        Remind me if I haven't reviewed
    </label>

    @if ($saved)
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
            @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
        </span>
    @endif

    @if (! $this->hasPhoneAlerts())
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Turn on phone alerts first: bell › “Get alerts on this phone”.</p>
    @endif
</div>
