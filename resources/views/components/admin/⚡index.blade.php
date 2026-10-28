<?php

use App\Services\AdminInsights;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url]
    public string $tab = 'dashboard';

    #[Url]
    public string $search = '';

    /**
     * The route's admin middleware only guards the first page load;
     * every later Livewire request is checked here.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 404);
    }

    /**
     * @return array{today: int, week: int, month: int, total: int}
     */
    #[Computed]
    public function activeUsers(): array
    {
        return app(AdminInsights::class)->activeUserCounts();
    }

    /**
     * @return list<array{user: \App\Models\User, reasons: list<string>}>
     */
    #[Computed]
    public function attention(): array
    {
        return app(AdminInsights::class)->attentionList();
    }

    #[Computed]
    public function users(): Collection
    {
        return app(AdminInsights::class)->usersWithAiUsage(trim($this->search));
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function content(): array
    {
        $insights = app(AdminInsights::class);

        return [
            'abandoned' => $insights->abandonedSteps(),
            'times' => $insights->stepTimes(),
            'hardest' => $insights->hardestSteps(),
            'mistakes' => $insights->commonMistakes(),
        ];
    }
};
?>

@php
    $sectionTitle = 'text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark';
    $hint = 'mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark';
    $emptyRow = 'text-sm text-ink-faint dark:text-ink-faint-dark';
@endphp

<div class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6" x-data="{ tab: $wire.entangle('tab').live }">
    <header class="flex items-center gap-3">
        <a href="{{ route('profile') }}" wire:navigate x-data x-on:click="if (history.length > 1) { $event.preventDefault(); history.back() }" aria-label="Back" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-5 w-5')</a>
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-chart-bar', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Admin</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Learners, AI usage and how the missions are going.</p>
        </div>
    </header>

    <x-tab-nav tab-var="tab" :tabs="['dashboard' => 'Dashboard', 'users' => 'Users', 'content' => 'Content']" />

    @if ($tab === 'dashboard')
        <section class="space-y-3">
            <p class="{{ $sectionTitle }}">Active users <span class="font-normal normal-case">· opened the app</span></p>
            <div class="grid grid-cols-3 gap-3">
                @foreach (['today' => 'Today', 'week' => '7 days', 'month' => '30 days'] as $key => $label)
                    <div class="card p-4 text-center">
                        <p class="font-display text-3xl font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ $this->activeUsers[$key] }}</p>
                        <p class="mt-1 text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
            <p class="{{ $hint }}">{{ $this->activeUsers['total'] }} accounts in all.</p>
        </section>

        <section class="space-y-3">
            <div>
                <p class="{{ $sectionTitle }}">Needs attention</p>
                <p class="{{ $hint }}">Stuck on a step, behind one day a day, or a streak that just broke.</p>
            </div>
            @forelse ($this->attention as $row)
                <a wire:key="attention-{{ $row['user']->id }}" href="{{ route('admin.users.show', $row['user']) }}" wire:navigate class="block card p-4 transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                    <div class="flex items-center gap-3">
                        <x-user-avatar :user="$row['user']" class="h-8 w-8 text-xs" />
                        <p class="min-w-0 flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $row['user']->name }}</p>
                        @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint')
                    </div>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($row['reasons'] as $reason)
                            <span class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">{{ $reason }}</span>
                        @endforeach
                    </div>
                </a>
            @empty
                <div class="card p-6 text-center {{ $emptyRow }}">Nobody needs attention right now.</div>
            @endforelse
        </section>
    @endif

    @if ($tab === 'users')
        <section class="space-y-3">
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Search name or email"
                class="w-full rounded-lg border border-line bg-transparent px-3 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            <p class="{{ $hint }}">Most AI used in the last 30 days first. "Share" is each learner's part of today's requests on the app's key.</p>

            @forelse ($this->users as $row)
                @php($user = $row['user'])
                <a wire:key="user-{{ $user->id }}" href="{{ route('admin.users.show', $user) }}" wire:navigate class="block card p-4 transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                    <div class="flex items-center gap-3">
                        <x-user-avatar :user="$user" class="h-8 w-8 text-xs" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 truncate text-sm font-semibold text-ink dark:text-ink-dark">
                                {{ $user->name }}
                                @if ($user->is_admin)
                                    <span class="rounded-full bg-accent-soft px-1.5 py-0.5 text-[10px] font-bold text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Admin</span>
                                @endif
                                @if ($user->ai_daily_limit === 0)
                                    <span class="rounded-full bg-danger-soft px-1.5 py-0.5 text-[10px] font-bold text-danger-ink uppercase">AI off</span>
                                @elseif ($user->ai_daily_limit !== null)
                                    <span class="rounded-full bg-warning-soft px-1.5 py-0.5 text-[10px] font-bold text-warning-ink uppercase">AI {{ $user->ai_daily_limit }}/day</span>
                                @endif
                            </p>
                            <p class="truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ $user->email }} · {{ $user->last_seen_at ? 'seen '.$user->last_seen_at->diffForHumans() : 'not seen yet' }}</p>
                        </div>
                        @svg('heroicon-o-chevron-right', 'h-4 w-4 shrink-0 text-ink-faint')
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-ink-soft tabular-nums sm:grid-cols-4 dark:text-ink-soft-dark">
                        <p><span class="text-ink-faint dark:text-ink-faint-dark">Today</span> {{ $row['todayApp'] }} app · {{ $row['todayOwn'] }} own</p>
                        <p><span class="text-ink-faint dark:text-ink-faint-dark">30 days</span> {{ $row['monthApp'] }} app · {{ $row['monthOwn'] }} own</p>
                        <p><span class="text-ink-faint dark:text-ink-faint-dark">Share</span> {{ $row['share'] }}%</p>
                        <p @class(['text-danger-ink' => $row['failed'] + $row['limited'] + $row['blocked'] > 0])>
                            <span class="text-ink-faint dark:text-ink-faint-dark">Failed</span> {{ $row['failed'] }} · <span class="text-ink-faint dark:text-ink-faint-dark">limit</span> {{ $row['limited'] + $row['blocked'] }}
                        </p>
                    </div>
                </a>
            @empty
                <div class="card p-6 text-center {{ $emptyRow }}">No users found.</div>
            @endforelse
        </section>
    @endif

    @if ($tab === 'content')
        @php($content = $this->content)

        <section class="space-y-2 card p-4">
            <div>
                <p class="{{ $sectionTitle }}">Where learners stop</p>
                <p class="{{ $hint }}">The step an unfinished mission was left on, untouched for {{ AdminInsights::ABANDONED_AFTER_DAYS }}+ days.</p>
            </div>
            @forelse ($content['abandoned'] as $row)
                <div class="flex items-center justify-between gap-3 text-sm">
                    <p class="min-w-0 truncate text-ink dark:text-ink-dark"><span class="font-semibold">{{ $row['mission'] }}</span> › {{ $row['step'] }}</p>
                    <p class="shrink-0 text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $row['learners'] }} {{ Str::plural('learner', $row['learners']) }}</p>
                </div>
            @empty
                <p class="{{ $emptyRow }}">No unfinished missions left behind.</p>
            @endforelse
        </section>

        <section class="space-y-2 card p-4">
            <div>
                <p class="{{ $sectionTitle }}">Time per step</p>
                <p class="{{ $hint }}">Average real minutes vs the planned time, last {{ AdminInsights::WINDOW_DAYS }} days. Breaks over {{ AdminInsights::STEP_GAP_CAP_MINUTES }} min are left out.</p>
            </div>
            @forelse ($content['times'] as $row)
                <div class="flex items-center justify-between gap-3 text-sm">
                    <p class="min-w-0 truncate text-ink dark:text-ink-dark"><span class="font-semibold">{{ $row['mission'] }}</span> › {{ $row['step'] }}</p>
                    <p class="shrink-0 tabular-nums {{ $row['plannedMinutes'] > 0 && $row['minutes'] > $row['plannedMinutes'] * 1.5 ? 'font-semibold text-warning-ink' : 'text-ink-soft dark:text-ink-soft-dark' }}">
                        {{ $row['minutes'] }} min <span class="text-ink-faint dark:text-ink-faint-dark">/ {{ $row['plannedMinutes'] }} · {{ $row['samples'] }}×</span>
                    </p>
                </div>
            @empty
                <p class="{{ $emptyRow }}">No finished steps yet.</p>
            @endforelse
        </section>

        <section class="space-y-2 card p-4">
            <div>
                <p class="{{ $sectionTitle }}">Hardest steps</p>
                <p class="{{ $hint }}">AI checks per learner, last {{ AdminInsights::WINDOW_DAYS }} days — every retry is one more check.</p>
            </div>
            @forelse ($content['hardest'] as $row)
                <div class="flex items-center justify-between gap-3 text-sm">
                    <p class="min-w-0 truncate text-ink dark:text-ink-dark"><span class="font-semibold">{{ $row['mission'] }}</span> › {{ $row['step'] }}</p>
                    <p class="shrink-0 text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $row['requestsPerLearner'] }} <span class="text-ink-faint dark:text-ink-faint-dark">· {{ $row['learners'] }} {{ Str::plural('learner', $row['learners']) }}</span></p>
                </div>
            @empty
                <p class="{{ $emptyRow }}">No AI checks logged yet.</p>
            @endforelse
        </section>

        <section class="space-y-3 card p-4">
            <div>
                <p class="{{ $sectionTitle }}">Common mistakes</p>
                <p class="{{ $hint }}">The same mistake across learners — by how many learners made it.</p>
            </div>
            <div class="space-y-2">
                <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">Error Log</p>
                @forelse ($content['mistakes']['categories'] as $row)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <p class="min-w-0 truncate text-ink dark:text-ink-dark">{{ str_replace('-', ' ', $row['category']) }}</p>
                        <p class="shrink-0 text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $row['learners'] }} {{ Str::plural('learner', $row['learners']) }} <span class="text-ink-faint dark:text-ink-faint-dark">· {{ $row['items'] }}×</span></p>
                    </div>
                @empty
                    <p class="{{ $emptyRow }}">Nothing logged yet.</p>
                @endforelse
            </div>
            <div class="space-y-2">
                <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">Notebook</p>
                @forelse ($content['mistakes']['notebook'] as $row)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <p class="min-w-0 truncate text-ink dark:text-ink-dark">{{ $row['label'] ?? $row['better'] }}</p>
                        <p class="shrink-0 text-ink-soft tabular-nums dark:text-ink-soft-dark">{{ $row['learners'] }} {{ Str::plural('learner', $row['learners']) }}</p>
                    </div>
                @empty
                    <p class="{{ $emptyRow }}">Nothing saved yet.</p>
                @endforelse
            </div>
        </section>
    @endif
</div>
