<?php

use App\Models\AudioListen;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The "Listens N" badge for one recording, video or daily pick: dots
 * toward the listens the program asks for (AudioListen::targetFor(): 4 for a
 * mission episode, 2 for a daily pick or a video), the count, when the last one was,
 * and (on tap) the day-by-day split and the latest listens with their
 * times.
 *
 * Beside an in-app player the player's tracker (eosListenTracker in
 * resources/js/app.js) dispatches `listen-completed` once at least 90% of
 * the recording has genuinely played through; this component stores that
 * listen and re-renders. Every instance on the page receives the event, so
 * each ignores one meant for another mission, source or pick.
 *
 * A pick that plays outside the app (selfReportable) can't be heard by the
 * player, so it ignores that event and offers the learner's own "I
 * listened to this one" button instead — stored as a self-reported listen
 * and drawn with hollow dots, so it is never mistaken for a verified one.
 *
 * A mission player (allowManualReport) offers the same button next to its
 * verified count: a player that won't play, a weak connection or a
 * recording heard elsewhere must never block the learner, so they can say
 * they listened. Nothing in the program waits on this count — it only
 * feeds the badge and the learner's own history.
 */
new class extends Component
{
    #[Locked]
    public string $missionCode;

    #[Locked]
    public string $source;

    #[Locked]
    public ?int $dayNumber = null;

    #[Locked]
    public ?string $pickKey = null;

    #[Locked]
    public bool $selfReportable = false;

    #[Locked]
    public bool $allowManualReport = false;

    /**
     * @return array{total: int, selfReported: int, today: int, last: ?Illuminate\Support\Carbon, byDay: array<int, int>, recent: Illuminate\Support\Collection<int, AudioListen>}
     */
    #[Computed]
    public function summary(): array
    {
        return auth()->user()->audioListenSummary($this->missionCode, $this->source, $this->pickKey);
    }

    #[On('listen-completed')]
    public function record(string $missionCode, string $source, float $duration = 0, ?string $pickKey = null): void
    {
        if ($this->selfReportable || $missionCode !== $this->missionCode || $source !== $this->source || $pickKey !== $this->pickKey) {
            return;
        }

        $minimumGapSeconds = AudioListen::MIN_GAP_FRACTION * min(max($duration, 5), 7200);

        $this->store(selfReported: false, minimumGapSeconds: $minimumGapSeconds);
    }

    public function reportListen(): void
    {
        if (! $this->selfReportable && ! $this->allowManualReport) {
            return;
        }

        if ($this->store(selfReported: true, minimumGapSeconds: AudioListen::SELF_REPORT_GAP_SECONDS)) {
            $this->dispatch('listen-self-reported');
        }
    }

    /**
     * A real listen takes about as long as the recording itself, so a
     * second one arriving sooner than the minimum gap is a duplicate or a
     * faked call, not another listen.
     */
    private function store(bool $selfReported, float $minimumGapSeconds): bool
    {
        $user = auth()->user();

        $latest = $user->audioListens()
            ->where('mission_code', $this->missionCode)
            ->where('source', $this->source)
            ->where('pick_key', $this->pickKey)
            ->latest()
            ->latest('id')
            ->first();

        if ($latest && $latest->created_at->diffInSeconds(now(), true) < $minimumGapSeconds) {
            return false;
        }

        $user->audioListens()->create([
            'mission_code' => $this->missionCode,
            'source' => $this->source,
            'day_number' => $this->dayNumber,
            'pick_key' => $this->pickKey,
            'self_reported' => $selfReported,
        ]);

        unset($this->summary);

        return true;
    }
};
?>

@php
    $summary = $this->summary;
    $target = \App\Models\AudioListen::targetFor($source);
    $reached = $summary['total'] >= $target;
    $canReport = $selfReportable || $allowManualReport;
@endphp

<div x-data="{ open: false }" class="flex flex-col items-start gap-2">
    <div class="flex flex-wrap items-center gap-2">
        <button
            type="button"
            x-on:click="open = ! open"
            x-bind:aria-expanded="open"
            class="inline-flex cursor-pointer items-center gap-2 rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-semibold text-ink-soft tabular-nums dark:bg-surface-sunken-dark dark:text-ink-soft-dark"
            aria-label="Listens, with history"
        >
            @svg('heroicon-o-speaker-wave', 'h-3.5 w-3.5')
            <span class="inline-flex items-center gap-1" aria-hidden="true">
                @for ($dot = 1; $dot <= $target; $dot++)
                    <span @class([
                        'h-2 w-2 rounded-full',
                        'bg-accent dark:bg-accent-dark' => $dot <= $summary['total'] - $summary['selfReported'],
                        'border border-accent bg-accent-soft dark:border-accent-dark dark:bg-accent-soft-dark' => $dot > $summary['total'] - $summary['selfReported'] && $dot <= $summary['total'],
                        'bg-line dark:bg-line-dark' => $dot > $summary['total'],
                    ])></span>
                @endfor
            </span>
            <span>
                Listens: <span data-testid="listen-count">{{ $summary['total'] }}</span>
                @unless ($reached)
                    <span class="font-normal text-ink-faint dark:text-ink-faint-dark">of {{ $target }}</span>
                @endunless
            </span>
            @if ($reached)
                @svg('heroicon-s-check', 'h-3.5 w-3.5 text-success dark:text-success-dark')
            @endif
            @if ($summary['selfReported'] > 0)
                <span class="font-normal text-ink-faint dark:text-ink-faint-dark">· {{ $selfReportable ? 'self-reported' : $summary['selfReported'].' self-reported' }}</span>
            @endif
        </button>

        @if ($canReport)
            <button
                type="button"
                wire:click="reportListen"
                wire:loading.attr="disabled"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink transition-colors hover:bg-surface-sunken disabled:opacity-60 dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
            >{{ $selfReportable ? 'I listened to this one' : 'I listened to this' }} @svg('heroicon-s-check', 'h-3.5 w-3.5')</button>
        @endif
    </div>

    @if ($summary['total'] > 0)
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark" data-testid="listen-recency">
            Today {{ $summary['today'] }}× · Last {{ $summary['last']->diffForHumans() }}
        </p>
    @endif

    <div x-show="open" x-cloak class="w-full max-w-xs space-y-2 rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
        @if (count($summary['byDay']) > 0 && $pickKey === null)
            <p data-testid="listen-by-day">
                <span class="font-semibold text-ink dark:text-ink-dark">By day:</span>
                @foreach ($summary['byDay'] as $day => $times)
                    Day {{ $day }} ×{{ $times }}{{ $loop->last ? '' : ' ·' }}
                @endforeach
            </p>
        @endif

        @forelse ($summary['recent'] as $listen)
            <p class="flex justify-between gap-2" wire:key="listen-{{ $listen->id }}">
                <span>{{ $listen->created_at->format('D j M, H:i') }}</span>
                <span class="text-ink-faint dark:text-ink-faint-dark">
                    {{ $listen->day_number ? "Day {$listen->day_number}" : '' }}{{ $listen->self_reported ? ' · self-reported' : '' }}
                </span>
            </p>
        @empty
            <p>No listens yet.</p>
        @endforelse

        <p class="border-t border-line pt-2 text-ink-faint dark:border-line-dark dark:text-ink-faint-dark">
            @if ($selfReportable)
                This one plays outside the app, so we can't hear it — it counts when you tell us.
            @else
                Counts each time you hear at least 90% of it, from the start. Skipping ahead doesn't count.
                @if ($allowManualReport)
                    Heard it another way, or the player won't play? Tell us and it counts too (hollow dot) — nothing is locked either way.
                @endif
            @endif
        </p>
    </div>
</div>
