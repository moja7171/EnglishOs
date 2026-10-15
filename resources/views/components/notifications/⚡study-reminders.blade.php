<?php

use Livewire\Component;

new class extends Component
{
    /**
     * The two study-time slots, in the order of the users table's
     * study_reminder_time_1 / study_reminder_time_2 columns.
     *
     * @var list<array{enabled: bool, hour: string, minute: string}>
     */
    public array $times = [
        ['enabled' => false, 'hour' => '12', 'minute' => '00'],
        ['enabled' => false, 'hour' => '20', 'minute' => '00'],
    ];

    public bool $saved = false;

    public function mount(): void
    {
        $user = auth()->user();

        foreach ([0, 1] as $index) {
            $time = $user->{'study_reminder_time_'.($index + 1)};

            if ($time !== null) {
                [$hour, $minute] = explode(':', $time);
                $this->times[$index] = ['enabled' => true, 'hour' => $hour, 'minute' => $minute];
            }
        }
    }

    /**
     * Saves on every change — there is no Save button, the pickers are the
     * setting. A slot whose time has already passed today starts tomorrow,
     * instead of buzzing at once for a time that is gone; moving it to a
     * time still ahead today clears that, so today's alert still goes out.
     */
    public function updated(string $name): void
    {
        $this->validate([
            'times' => ['array', 'size:2'],
            'times.*.enabled' => ['boolean'],
            'times.*.hour' => ['required', 'regex:/^([01]\d|2[0-3])$/'],
            'times.*.minute' => ['required', 'regex:/^[0-5]\d$/'],
        ]);

        $user = auth()->user();
        $user->rememberTimezone();

        $changed = (int) (explode('.', $name)[1] ?? -1);
        $attributes = [];

        foreach ($this->times as $index => $slot) {
            $number = $index + 1;
            $time = $slot['enabled'] ? "{$slot['hour']}:{$slot['minute']}" : null;

            $attributes += $index === $changed
                ? $user->studyReminderAttributes($number, $time)
                : ["study_reminder_time_{$number}" => $time];
        }

        $user->forceFill($attributes)->save();

        $this->saved = true;
    }
};
?>

<div class="space-y-3 card p-4">
    <div>
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Study times</p>
        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">A phone alert at up to two times a day to start your English, whether or not you've already studied. Off until you pick a time.</p>
    </div>

    @foreach ($times as $index => $slot)
        <div class="space-y-2" wire:key="study-slot-{{ $index }}">
            <label class="flex cursor-pointer items-center gap-2 text-sm text-ink dark:text-ink-dark">
                <input type="checkbox" wire:model.live="times.{{ $index }}.enabled" class="h-4 w-4 accent-accent dark:accent-accent-dark">
                {{ $index === 0 ? 'First study time' : 'Second study time' }}
            </label>

            @if ($slot['enabled'])
                <div class="flex items-center gap-2">
                    <select
                        wire:model.live="times.{{ $index }}.hour"
                        aria-label="Hour (24-hour clock)"
                        class="rounded-xl border border-line bg-surface px-3 py-2 text-sm text-ink tabular-nums dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
                    >
                        @foreach (range(0, 23) as $value)
                            <option value="{{ sprintf('%02d', $value) }}">{{ sprintf('%02d', $value) }}</option>
                        @endforeach
                    </select>
                    <span class="font-semibold text-ink-faint dark:text-ink-faint-dark">:</span>
                    <select
                        wire:model.live="times.{{ $index }}.minute"
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
        </div>
    @endforeach

    @if ($saved)
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
            @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
        </span>
    @endif
</div>
