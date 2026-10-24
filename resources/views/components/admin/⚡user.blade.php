<?php

use App\Models\AudioListen;
use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\AdminMessage;
use App\Services\AdminInsights;
use App\Services\FinishLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public string $messageBody = '';

    /** Blank means no limit; 0 turns AI off on the app's key. */
    public string $aiLimit = '';

    public ?string $status = null;

    /**
     * The route's admin middleware only guards the first page load;
     * every later Livewire request is checked here.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 404);
    }

    public function mount(): void
    {
        $this->aiLimit = $this->user->ai_daily_limit === null ? '' : (string) $this->user->ai_daily_limit;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function detail(): array
    {
        return app(AdminInsights::class)->userDetail($this->user);
    }

    public function sendMessage(): void
    {
        $this->validate(['messageBody' => ['required', 'string', 'max:'.AdminMessage::MAX_LENGTH]]);

        $this->user->notify(new AdminMessage(trim($this->messageBody)));

        $this->messageBody = '';
        $this->status = 'Message sent.';
    }

    /**
     * Caps the learner's AI requests a day on the app's key. Their own key
     * is never capped.
     */
    public function saveAiLimit(): void
    {
        $this->validate(['aiLimit' => ['nullable', 'integer', 'min:0', 'max:10000']]);

        $this->user->forceFill(['ai_daily_limit' => $this->aiLimit === '' ? null : (int) $this->aiLimit])->save();

        $this->status = match (true) {
            $this->user->ai_daily_limit === null => 'AI limit removed.',
            $this->user->ai_daily_limit === 0 => 'AI turned off on the app key.',
            default => "AI limited to {$this->user->ai_daily_limit} a day.",
        };
        unset($this->detail);
    }

    public function setAiLimit(?int $limit): void
    {
        $this->aiLimit = $limit === null ? '' : (string) $limit;

        $this->saveAiLimit();
    }

    /**
     * @return Collection<int, MissionRun>
     */
    #[Computed]
    public function missionRuns(): Collection
    {
        return $this->user->missionRuns()->with('mission')->get()->sortBy(fn (MissionRun $run) => $run->mission->number())->values();
    }

    /**
     * Undoes a mission the learner reached by skipping the locks as
     * admin — see MissionRun::discard().
     */
    public function discardMissionRun(int $runId): void
    {
        $run = $this->user->missionRuns()->with('mission')->findOrFail($runId);
        $run->discard();

        $this->status = "{$run->mission->code} removed.";
        unset($this->missionRuns, $this->detail);
    }

    /**
     * An admin can't remove their own admin access, so the app always
     * keeps at least the one who is using this page.
     */
    public function toggleAdmin(): void
    {
        abort_if($this->user->is(auth()->user()), 403);

        $this->user->forceFill(['is_admin' => ! $this->user->is_admin])->save();

        $this->status = $this->user->is_admin ? "{$this->user->name} is now an admin." : "{$this->user->name} is no longer an admin.";
    }
};
?>

@php
    $d = $this->detail;
    $sectionTitle = 'text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark';
    $label = 'text-ink-faint dark:text-ink-faint-dark';
    $value = 'font-semibold text-ink tabular-nums dark:text-ink-dark';
    $pillButton = 'cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:pointer-events-none disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark';
    $input = 'w-full rounded-lg border border-line bg-transparent px-3 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark';
    $finishLine = new FinishLine;
    $ai = $d['ai'];
@endphp

<div class="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('admin.index', ['tab' => 'users']) }}" wire:navigate class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All users
    </a>

    <header class="flex items-center gap-3">
        <x-user-avatar :user="$user" class="h-11 w-11 text-sm" />
        <div class="min-w-0">
            <h1 class="flex items-center gap-2 truncate font-display text-2xl font-extrabold text-ink dark:text-ink-dark">
                {{ $user->name }}
                @if ($user->is_admin)
                    <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Admin</span>
                @endif
            </h1>
            <p class="truncate text-sm text-ink-soft dark:text-ink-soft-dark">
                {{ $user->email }} · {{ $user->cefr_level }} · joined {{ $user->created_at?->toFormattedDateString() }}
            </p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $user->last_seen_at ? 'Last seen '.$user->last_seen_at->diffForHumans() : 'Not seen since last seen was tracked' }}</p>
        </div>
    </header>

    @if ($status)
        <p class="rounded-lg bg-success-soft px-3 py-2 text-sm font-semibold text-success-ink dark:bg-success-soft-dark dark:text-success-ink-dark">{{ $status }}</p>
    @endif

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Where they are</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
            <p><span class="{{ $label }}">Mission</span><br><span class="{{ $value }}">{{ $d['mission'] ? $d['mission']->code.' · '.$d['mission']->title : 'None in progress' }}</span></p>
            <p><span class="{{ $label }}">Step</span><br><span class="{{ $value }}">{{ $d['stepLabel'] ?? '—' }}</span></p>
            <p><span class="{{ $label }}">Missions done</span><br><span class="{{ $value }}">{{ $d['missionsDone'] }}</span></p>
            <p><span class="{{ $label }}">Streak</span><br><span class="{{ $value }}">{{ $d['streak'] }} days</span> <span class="{{ $label }}">· best {{ $d['longestStreak'] }}</span></p>
            <p><span class="{{ $label }}">Practice time</span><br><span class="{{ $value }}">{{ $d['practiceMinutes'] }} min</span></p>
        </div>
    </section>

    <section class="space-y-2 card p-4">
        <div>
            <p class="{{ $sectionTitle }}">Missions opened</p>
            <p class="mt-0.5 text-xs {{ $label }}">Remove one opened by skipping the locks. Its answers and recordings go too.</p>
        </div>
        @forelse ($this->missionRuns as $run)
            <div wire:key="run-{{ $run->id }}" class="flex items-center justify-between gap-3 text-sm">
                <p class="min-w-0 truncate"><span class="{{ $value }}">{{ $run->mission->code }}</span> <span class="{{ $label }}">· {{ $run->mission->title }} · {{ str_replace('_', ' ', $run->status) }}</span></p>
                <button
                    type="button"
                    wire:click="discardMissionRun({{ $run->id }})"
                    wire:confirm="Remove {{ $run->mission->code }} and everything {{ $user->name }} did in it?"
                    class="shrink-0 cursor-pointer rounded-full px-3 py-1 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft"
                >Remove</button>
            </div>
        @empty
            <p class="text-sm {{ $label }}">None yet.</p>
        @endforelse
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Finish line</p>
        @if ($d['forecast'])
            <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
                <p><span class="{{ $label }}">Program days</span><br><span class="{{ $value }}">{{ $d['forecast']['daysDone'] }} / {{ \App\Services\ProgramPlanner::TOTAL_DAYS }}</span></p>
                <p><span class="{{ $label }}">At their pace</span><br><span class="{{ $value }}">{{ $d['forecast']['paceDate']?->toFormattedDateString() ?? 'No pace yet' }}</span></p>
                <p><span class="{{ $label }}">One day a day</span><br><span class="{{ $value }}">{{ $d['forecast']['steadyDate']->toFormattedDateString() }}</span></p>
            </div>
            @if ($d['daysBehind'] > 0)
                <p class="text-sm font-semibold {{ $d['daysBehind'] >= AdminInsights::BEHIND_DAYS ? 'text-warning-ink' : 'text-ink-soft dark:text-ink-soft-dark' }}">{{ $d['daysBehind'] }} program {{ Str::plural('day', $d['daysBehind']) }} behind one a day.</p>
            @else
                <p class="text-sm font-semibold text-success dark:text-success-dark">On schedule or ahead.</p>
            @endif
        @else
            <p class="text-sm {{ $label }}">The program hasn't started yet.</p>
        @endif
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Practice</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
            <p><span class="{{ $label }}">Listens</span><br><span class="{{ $value }}">{{ ($d['listens'][AudioListen::SOURCE_LISTENING] ?? 0) + ($d['listens'][AudioListen::SOURCE_LISTENING_PICK] ?? 0) }}</span> <span class="{{ $label }}">· {{ $d['listeningDays'] }} days ticked</span></p>
            <p><span class="{{ $label }}">Shadowing videos</span><br><span class="{{ $value }}">{{ $d['listens'][AudioListen::SOURCE_VIDEO_SHADOWING] ?? 0 }}</span></p>
            <p><span class="{{ $label }}">Recordings</span><br><span class="{{ $value }}">{{ $d['recordings'] }}</span></p>
            <p><span class="{{ $label }}">Pi practice</span><br><span class="{{ $value }}">{{ $d['piDays'] }} days</span></p>
            <p><span class="{{ $label }}">Pair practice</span><br><span class="{{ $value }}">{{ $d['pairSessions'] }}</span> <span class="{{ $label }}">· {{ $d['pairSessionsFinished'] }} finished</span></p>
            <p><span class="{{ $label }}">Last pair practice</span><br><span class="{{ $value }}">{{ $d['lastPairSession'] ? Carbon::parse($d['lastPairSession'])->diffForHumans() : '—' }}</span></p>
        </div>
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Words and mistakes</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <p><span class="{{ $label }}">Word cards</span><br><span class="{{ $value }}">{{ $d['words'] }}</span></p>
            <p><span class="{{ $label }}">Reviews due</span><br><span class="{{ $value }} {{ $d['dueReviews'] > 20 ? 'text-warning-ink' : '' }}">{{ $d['dueReviews'] }}</span></p>
        </div>
        <div class="space-y-1 text-sm">
            <p class="{{ $label }}">Top mistakes</p>
            @forelse ($d['topErrorCategories'] as $category => $count)
                <p class="text-ink dark:text-ink-dark">{{ str_replace('-', ' ', $category) }} <span class="{{ $label }}">· {{ $count }}×</span></p>
            @empty
                @forelse ($d['topNotebookMistakes'] as $entry)
                    <p class="text-ink dark:text-ink-dark"><span class="text-danger-ink line-through">{{ $entry->original }}</span> → {{ $entry->better }} <span class="{{ $label }}">· {{ $entry->times_seen }}×</span></p>
                @empty
                    <p class="{{ $label }}">None yet.</p>
                @endforelse
            @endforelse
        </div>
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">AI usage</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
            <p><span class="{{ $label }}">Today</span><br><span class="{{ $value }}">{{ $ai['todayApp'] }} app · {{ $ai['todayOwn'] }} own</span></p>
            <p><span class="{{ $label }}">30 days</span><br><span class="{{ $value }}">{{ $ai['monthApp'] }} app · {{ $ai['monthOwn'] }} own</span></p>
            <p><span class="{{ $label }}">Own keys</span><br><span class="{{ $value }}">{{ collect(['Gemini' => $user->gemini_api_key, 'Groq' => $user->groq_api_key])->filter()->keys()->join(', ') ?: 'None' }}</span></p>
            <p><span class="{{ $label }}">Failed (30 days)</span><br><span class="{{ $value }} {{ $ai['failed'] > 0 ? 'text-danger-ink' : '' }}">{{ $ai['failed'] }}</span></p>
            <p><span class="{{ $label }}">Hit the hourly cap</span><br><span class="{{ $value }}">{{ $ai['limited'] }}</span></p>
            <p><span class="{{ $label }}">Refused by your limit</span><br><span class="{{ $value }}">{{ $ai['blocked'] }}</span></p>
        </div>
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Phone and files</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3">
            <p>
                <span class="{{ $label }}">Phone alerts</span><br>
                @if ($d['pushDevices'] > 0)
                    <span class="font-semibold text-success dark:text-success-dark">On · {{ $d['pushDevices'] }} {{ Str::plural('device', $d['pushDevices']) }}</span>
                @else
                    <span class="font-semibold text-warning-ink">Off</span>
                @endif
            </p>
            <p><span class="{{ $label }}">Reminders</span><br><span class="{{ $value }}">{{ $user->hasStudyReminderTimes() ? 'Study times set' : 'No study times' }} · review {{ $user->review_reminder_enabled ? 'on' : 'off' }}</span></p>
            <p><span class="{{ $label }}">Files on server</span><br><span class="{{ $value }}">{{ Number::fileSize($d['storageBytes'], precision: 1) }}</span></p>
        </div>
        @if ($d['lastPushDeviceAt'])
            <p class="text-xs {{ $label }}">Phone last registered {{ Carbon::parse($d['lastPushDeviceAt'])->diffForHumans() }}. A phone that stops accepting alerts is removed by itself.</p>
        @endif
    </section>

    <section class="space-y-2 card p-4">
        <p class="{{ $sectionTitle }}">Friends</p>
        <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <p><span class="{{ $label }}">Messages sent</span><br><span class="{{ $value }}">{{ $d['messagesSent'] }}</span></p>
            <p><span class="{{ $label }}">Messages received</span><br><span class="{{ $value }}">{{ $d['messagesReceived'] }}</span></p>
        </div>
    </section>

    <section class="space-y-3 card p-4">
        <p class="{{ $sectionTitle }}">Send a message</p>
        <form wire:submit="sendMessage" class="space-y-2">
            <textarea wire:model="messageBody" rows="2" maxlength="{{ AdminMessage::MAX_LENGTH }}" dir="auto" placeholder="Shows in their bell and on their phone" class="{{ $input }}"></textarea>
            @error('messageBody')
                <p class="text-sm text-danger-ink">{{ $message }}</p>
            @enderror
            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" wire:target="sendMessage" class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark">Send</button>
            </div>
        </form>
    </section>

    <section class="space-y-3 card p-4">
        <div>
            <p class="{{ $sectionTitle }}">AI limit</p>
            <p class="mt-0.5 text-xs {{ $label }}">AI requests a day on the app's key. Their own key is never limited. Used today: {{ $d['appKeyRequestsToday'] }}.</p>
        </div>
        <form wire:submit="saveAiLimit" class="flex flex-wrap items-center gap-2">
            <input type="number" min="0" wire:model="aiLimit" placeholder="No limit" class="{{ $input }} w-32">
            <button type="submit" class="{{ $pillButton }}">Save</button>
            <button type="button" wire:click="setAiLimit(0)" class="cursor-pointer rounded-full px-3 py-1 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft">Turn AI off</button>
            @if ($user->ai_daily_limit !== null)
                <button type="button" wire:click="setAiLimit(null)" class="{{ $pillButton }}">Remove limit</button>
            @endif
        </form>
        @error('aiLimit')
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror
    </section>

    <section class="space-y-3 card p-4">
        <div>
            <p class="{{ $sectionTitle }}">Admin access</p>
            <p class="mt-0.5 text-xs {{ $label }}">Admins see this page, skip mission locks and get AI outage alerts.</p>
        </div>
        @if ($user->is(auth()->user()))
            <p class="text-sm {{ $label }}">This is you — another admin can change your access.</p>
        @else
            <button
                type="button"
                wire:click="toggleAdmin"
                wire:confirm="{{ $user->is_admin ? 'Remove admin access from '.$user->name.'?' : 'Make '.$user->name.' an admin?' }}"
                class="{{ $user->is_admin ? 'cursor-pointer rounded-full px-3 py-1 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft' : $pillButton }}"
            >{{ $user->is_admin ? 'Remove admin' : 'Make admin' }}</button>
        @endif
    </section>
</div>
