<?php

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * "HH:MM" the reminder goes out at; empty = off.
     */
    public string $time = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->time = auth()->user()->review_reminder_time ?? '';
    }

    /**
     * Whole-hour choices from 6 am to 11 pm — the reminder command runs
     * every 15 minutes, so any of these lands within a quarter hour.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function timeOptions(): array
    {
        return collect(range(6, 23))
            ->mapWithKeys(fn (int $hour) => [sprintf('%02d:00', $hour) => Carbon::createFromTime($hour)->format('g:i A')])
            ->all();
    }

    #[Computed]
    public function hasPhoneAlerts(): bool
    {
        return auth()->user()->pushSubscriptions()->exists();
    }

    public function updatedTime(): void
    {
        $this->validate(['time' => ['present', 'string', Rule::in(['', ...array_keys($this->timeOptions)])]]);

        $user = auth()->user();

        $user->forceFill(['review_reminder_time' => $this->time === '' ? null : $this->time])->save();
        $user->rememberTimezone();

        $this->saved = true;
    }
};
?>

<div class="space-y-3 card p-4">
    <div>
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Daily review reminder</p>
        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">A phone alert at the time you pick, on days you have reviews waiting and haven't reviewed yet. At most one a day.</p>
    </div>

    <div class="flex items-center gap-3">
        <select
            wire:model.live="time"
            aria-label="Reminder time"
            class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
        >
            <option value="">Off</option>
            @foreach ($this->timeOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        @if ($saved)
            <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
            </span>
        @endif
    </div>

    @if (! $this->hasPhoneAlerts)
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Reminders arrive as phone alerts, and you haven’t turned those on yet — open the bell and tap “Get alerts on this phone”.</p>
    @endif
</div>
