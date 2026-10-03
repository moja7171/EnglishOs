<?php

use Livewire\Component;

new class extends Component
{
    public bool $enabled = false;

    /**
     * Two-digit 24-hour parts of the reminder time ("00"–"23" / "00"–"59").
     */
    public string $hour = '19';

    public string $minute = '00';

    public bool $saved = false;

    public function mount(): void
    {
        $time = auth()->user()->review_reminder_time;

        $this->enabled = $time !== null;

        if ($time !== null) {
            [$this->hour, $this->minute] = explode(':', $time);
        }
    }

    /**
     * Saves on every change — there is no Save button, the picker is the
     * setting. The reminder command runs every minute, so the exact minute
     * chosen here is honoured.
     */
    public function updated(): void
    {
        $this->validate([
            'enabled' => ['boolean'],
            'hour' => ['required', 'regex:/^([01]\d|2[0-3])$/'],
            'minute' => ['required', 'regex:/^[0-5]\d$/'],
        ]);

        $user = auth()->user();

        $user->forceFill(['review_reminder_time' => $this->enabled ? "{$this->hour}:{$this->minute}" : null])->save();
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
        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">A phone alert at the time you pick, on days you have reviews waiting and haven't reviewed yet. At most one a day.</p>
    </div>

    <label class="flex cursor-pointer items-center gap-2 text-sm text-ink dark:text-ink-dark">
        <input type="checkbox" wire:model.live="enabled" class="h-4 w-4 accent-accent dark:accent-accent-dark">
        Remind me every day
    </label>

    @if ($enabled)
        <div class="flex items-center gap-2">
            <select
                wire:model.live="hour"
                aria-label="Hour (24-hour clock)"
                class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink tabular-nums dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
            >
                @foreach (range(0, 23) as $value)
                    <option value="{{ sprintf('%02d', $value) }}">{{ sprintf('%02d', $value) }}</option>
                @endforeach
            </select>
            <span class="font-semibold text-ink-faint dark:text-ink-faint-dark">:</span>
            <select
                wire:model.live="minute"
                aria-label="Minute"
                class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink tabular-nums dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
            >
                @foreach (range(0, 59) as $value)
                    <option value="{{ sprintf('%02d', $value) }}">{{ sprintf('%02d', $value) }}</option>
                @endforeach
            </select>
            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">24-hour</span>
        </div>
    @endif

    @if ($saved)
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
            @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
        </span>
    @endif

    @if (! $this->hasPhoneAlerts())
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Reminders arrive as phone alerts, and you haven’t turned those on yet — open the bell and tap “Get alerts on this phone”.</p>
    @endif
</div>
