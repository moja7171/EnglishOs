<?php

use App\Models\DirectMessage;
use App\Models\FriendBlock;
use App\Models\FriendReport;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public User $other;

    public string $body = '';

    public bool $reporting = false;

    public string $reportReason = '';

    public function mount(): void
    {
        // Strictly 1:1 by design (see User::canMessageWith) — a one-way
        // follow, a stranger, or a blocked pair never reaches this page
        // regardless of how they got the URL.
        abort_unless(auth()->user()->canMessageWith($this->other), 403);
    }

    public function send(): void
    {
        $text = trim($this->body);

        if ($text === '') {
            return;
        }

        abort_unless(auth()->user()->canMessageWith($this->other), 403);

        DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_MESSAGE,
            'body' => $text,
        ]);

        $this->body = '';
        unset($this->thread);
    }

    /**
     * One-tap encouragement instead of typing something from scratch —
     * the message text adapts to whether the *recipient's* streak
     * actually needs saving, using the same User::currentStreak() the
     * header badge and Friends list already trust.
     */
    public function sendNudge(): void
    {
        abort_unless(auth()->user()->canMessageWith($this->other), 403);

        $streak = $this->other->currentStreak();

        $body = $streak > 0
            ? "Keep your {$streak}-day streak going today! 🔥"
            : 'Come practice with me today!';

        DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => $body,
        ]);

        unset($this->thread);
    }

    public function block(): void
    {
        FriendBlock::firstOrCreate([
            'blocker_id' => auth()->id(),
            'blocked_id' => $this->other->id,
        ]);

        $this->redirect(route('friends.index'), navigate: true);
    }

    public function submitReport(): void
    {
        $reason = trim($this->reportReason);

        if ($reason === '') {
            return;
        }

        FriendReport::create([
            'reporter_id' => auth()->id(),
            'reported_id' => $this->other->id,
            'reason' => $reason,
            'message_snapshot' => $this->thread->last()?->body,
        ]);

        $this->reporting = false;
        $this->reportReason = '';
    }

    #[Computed]
    public function thread()
    {
        $messages = auth()->user()->conversationWith($this->other)->get();

        $messages->where('recipient_id', auth()->id())->whereNull('read_at')->each->update(['read_at' => now()]);

        return $messages;
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <a href="{{ route('friends.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        Friends
    </a>

    <div class="flex items-center justify-between">
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $other->name }}</h1>
        <div class="flex items-center gap-3">
            <button type="button" wire:click="block" wire:confirm="Block {{ $other->name }}? They won't be able to message you." class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-red-600 dark:text-ink-faint-dark">Block</button>
            <button type="button" wire:click="$set('reporting', true)" class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-red-600 dark:text-ink-faint-dark">Report</button>
        </div>
    </div>

    @if ($reporting)
        <div class="space-y-2 rounded-xl border border-red-200 bg-red-50 p-3 dark:border-red-900 dark:bg-red-950">
            <p class="text-xs font-semibold text-red-600">Report {{ $other->name }}</p>
            <textarea
                wire:model="reportReason"
                rows="2"
                placeholder="What happened?"
                class="w-full rounded-lg border border-red-300 bg-transparent px-2 py-1 text-sm text-ink dark:border-red-800 dark:text-ink-dark"
            ></textarea>
            <div class="flex gap-2">
                <button type="button" wire:click="submitReport" class="cursor-pointer rounded-full border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-100 dark:border-red-800 dark:hover:bg-red-950">Submit report</button>
                <button type="button" wire:click="$set('reporting', false)" class="cursor-pointer text-xs text-ink-faint underline dark:text-ink-faint-dark">Cancel</button>
            </div>
        </div>
    @endif

    <div wire:poll.5s="$refresh" class="space-y-2 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
        @forelse ($this->thread as $message)
            @php $mine = $message->sender_id === auth()->id(); @endphp
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
                <div class="max-w-[75%] rounded-2xl px-3 py-2 text-sm
                    {{ $message->type === 'nudge'
                        ? 'border border-accent-soft bg-accent-soft/60 text-accent-ink dark:border-accent-soft-dark dark:bg-accent-soft-dark/60 dark:text-accent-ink-dark'
                        : ($mine
                            ? 'bg-ink text-ground dark:bg-ink-dark dark:text-ground-dark'
                            : 'border border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark') }}">
                    @if ($message->type === 'nudge')
                        <span class="inline-flex items-center gap-1 font-semibold">@svg('heroicon-s-fire', 'h-3.5 w-3.5') {{ $message->body }}</span>
                    @else
                        {{ $message->body }}
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No messages yet — say hello!</p>
        @endforelse
    </div>

    <div class="flex items-center gap-2">
        <button
            type="button"
            wire:click="sendNudge"
            class="inline-flex shrink-0 cursor-pointer items-center gap-1 rounded-full border border-accent-soft px-3 py-2 text-xs font-semibold text-accent-ink transition-colors hover:bg-accent-soft dark:border-accent-soft-dark dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark"
        >@svg('heroicon-s-fire', 'h-3.5 w-3.5') Nudge</button>

        <form wire:submit="send" class="flex flex-1 items-center gap-2">
            <input
                type="text"
                wire:model="body"
                placeholder="Message {{ $other->name }}…"
                class="w-full rounded-full border border-line bg-transparent px-4 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            <button
                type="submit"
                class="cursor-pointer rounded-full bg-accent px-4 py-2 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >Send</button>
        </form>
    </div>
</div>
