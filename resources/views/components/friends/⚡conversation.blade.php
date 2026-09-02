<?php

use App\Models\DirectMessage;
use App\Models\FriendBlock;
use App\Models\FriendReport;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public User $other;

    public string $body = '';

    public ?UploadedFile $voiceMessage = null;

    public ?UploadedFile $attachment = null;

    public bool $reporting = false;

    public string $reportReason = '';

    /** @var array{strength: string, expression: string, correction: string}|null */
    public ?array $feedback = null;

    public ?string $feedbackError = null;

    /**
     * A small curated set rather than a full picker library — no new
     * dependency, no external CDN call, just plain UTF-8 characters
     * appended straight into the composer.
     *
     * @return list<string>
     */
    public function emojis(): array
    {
        return [
            '😀', '😂', '😊', '😍', '🥰', '😘', '😉', '😎',
            '🤔', '😅', '😢', '😭', '😮', '😴', '🙄', '😇',
            '👍', '👎', '👏', '🙌', '🤝', '🙏', '💪', '✌️',
            '❤️', '🔥', '✨', '🎉', '🎯', '💯', '⭐', '👋',
        ];
    }

    public function mount(): void
    {
        // Strictly 1:1 by design (see User::canMessageWith) — a one-way
        // follow, a stranger, or a blocked pair never reaches this page
        // regardless of how they got the URL.
        abort_unless(auth()->user()->canMessageWith($this->other), 403);

        // Arrives from <x-practice-with-friend> on a mission step — just
        // pre-fills the composer, never auto-sent, so the learner can
        // still edit or discard it. Length-capped since it's untrusted
        // query-string input.
        $prefill = trim((string) request()->query('prefill', ''));

        if ($prefill !== '') {
            $this->body = str($prefill)->limit(500)->toString();
        }
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
     * Called automatically once <x-voice-recorder>'s upload finishes —
     * same "record, then auto-submit" pattern as Activation and the AI
     * Conversation steps, just landing here as a message instead of
     * Evidence. Stored on the private disk (see the migration + the
     * friends.attachment route), never Storage::disk('public').
     */
    public function sendVoiceMessage(): void
    {
        abort_unless(auth()->user()->canMessageWith($this->other), 403);

        if (! $this->voiceMessage) {
            return;
        }

        $path = $this->voiceMessage->store('direct-messages/'.auth()->id(), 'local');

        // Transcribed so the conversation has real, readable text behind
        // it — both for the caption shown under the player and so
        // generateFeedback() below has something to actually read. Silent
        // fallback to a generic label on any failure: sending the voice
        // message itself must never be blocked by a transcription hiccup.
        $body = 'Voice message';
        try {
            $transcript = trim(app(GroqClient::class)->transcribe($this->voiceMessage->getRealPath()));
            $body = $transcript !== '' ? $transcript : $body;
        } catch (Throwable) {
            // Keep the generic fallback label.
        }

        DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_AUDIO,
            'body' => $body,
            'attachment_path' => $path,
            'attachment_name' => 'voice-message.webm',
            'attachment_mime' => $this->voiceMessage->getMimeType(),
        ]);

        $this->voiceMessage = null;
        unset($this->thread);
    }

    public function sendFile(): void
    {
        abort_unless(auth()->user()->canMessageWith($this->other), 403);

        $this->validate([
            // 15MB, and an explicit allowlist — never trust the browser's
            // claimed extension alone, but this at least keeps obviously
            // dangerous types (executables, scripts) out from the start.
            'attachment' => ['required', 'file', 'max:15360', 'extensions:pdf,doc,docx,txt,jpg,jpeg,png,webp,gif,mp3,wav,m4a,webm'],
        ]);

        $path = $this->attachment->store('direct-messages/'.auth()->id(), 'local');

        DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_FILE,
            'body' => $this->attachment->getClientOriginalName(),
            'attachment_path' => $path,
            'attachment_name' => $this->attachment->getClientOriginalName(),
            'attachment_mime' => $this->attachment->getMimeType(),
        ]);

        $this->attachment = null;
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

        DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_NUDGE,
            'body' => $this->nudgeMessage(),
        ]);

        unset($this->thread);
    }

    /**
     * A personalized nudge instead of one of a fixed pair of presets — the
     * AI is grounded in the recipient's REAL streak (never fabricated), and
     * writes in the sender's voice as a short, casual message. Silent
     * fallback to the original hardcoded presets on any failure: sending a
     * nudge is a nice-to-have, never something worth blocking or erroring
     * out on (same non-blocking pattern as every other AI call in the app).
     */
    private function nudgeMessage(): string
    {
        $streak = $this->other->currentStreak();

        $fallback = $streak > 0
            ? "Keep your {$streak}-day streak going today! 🔥"
            : 'Come practice with me today!';

        try {
            $context = $streak > 0
                ? "{$this->other->name}'s current practice streak is {$streak} day(s) in a row."
                : "{$this->other->name} doesn't have an active practice streak right now.";

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $context]],
                systemPrompt: 'Write a short, warm, casual nudge message from '.auth()->user()->name.' to a '
                    .'friend, encouraging them to come practice English today. '.$context.' Sound like a real '
                    .'text message between friends, not a formal notification — one short sentence, at most one '
                    .'emoji. Reply with ONLY the message text, no quotation marks, no explanation.',
            );

            $message = trim($raw, " \t\n\r\0\x0B\"'");

            return $message !== '' ? $message : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
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

    /**
     * On-demand, never persisted — regenerating always reflects the
     * conversation as it stands right now, and there's no need for a new
     * table just to cache something this cheap to recompute. Judges ONLY
     * the requesting learner's own messages (never the friend's) — this is
     * a peer conversation, not something the other person agreed to be
     * graded on. Same strength/expression/correction shape as AI Feedback
     * #1 for consistency across the app.
     */
    public function generateFeedback(): void
    {
        $this->feedbackError = null;

        $mine = $this->conversationTranscript()->where('mine', true);

        if ($mine->isEmpty()) {
            $this->feedbackError = 'Send a few messages first — there\'s nothing to give feedback on yet.';

            return;
        }

        try {
            $transcript = $this->conversationTranscript()
                ->map(fn ($line) => ($line['mine'] ? 'Me' : $this->other->name).': '.$line['text'])
                ->implode("\n");

            $raw = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => $transcript]],
                systemPrompt: 'You are an encouraging English teacher reviewing a real chat conversation between '
                    .'two friends practicing English together. Below, "Me" is '.auth()->user()->levelDescription()
                    .' — give feedback ONLY on "Me"\'s own messages (grammar, natural phrasing, vocabulary). Do '
                    .'NOT evaluate or comment on the other person\'s messages at all — they are only there for '
                    .'context. Reply with ONLY valid JSON, no markdown fences, no extra text, in exactly this '
                    .'shape: {"strength": "one specific thing \"Me\" did well, one sentence", '
                    .'"expression": "one good word or phrase \"Me\" actually used", '
                    .'"correction": "one grammar or vocabulary mistake of \"Me\"\'s to fix, one sentence, phrased kindly"}'
            );

            $data = json_decode(trim($raw), true);

            if (! is_array($data) || ! isset($data['strength'], $data['expression'], $data['correction'])) {
                throw new RuntimeException('Unexpected AI response format.');
            }

            $this->feedback = [
                'strength' => $data['strength'],
                'expression' => $data['expression'],
                'correction' => $data['correction'],
            ];
        } catch (Throwable $e) {
            $this->feedbackError = "Couldn't get feedback from the AI Instructor: {$e->getMessage()}";
        }
    }

    /**
     * @return Collection<int, array{mine: bool, text: string}>
     */
    private function conversationTranscript()
    {
        return auth()->user()->conversationWith($this->other)->get()
            ->reject(fn ($message) => $message->type === DirectMessage::TYPE_NUDGE)
            ->map(fn ($message) => [
                'mine' => $message->sender_id === auth()->id(),
                'text' => $message->type === DirectMessage::TYPE_FILE
                    ? "[attached a file: {$message->attachment_name}]"
                    : $message->body,
            ])
            ->values();
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

<div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6" x-data="{ showEmoji: false }">
    <a href="{{ route('friends.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        Friends
    </a>

    <div class="flex items-center justify-between rounded-2xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
        <div class="flex items-center gap-3">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent-soft font-display text-sm font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                {{ mb_strtoupper(mb_substr($other->name, 0, 1)) }}
            </span>
            <div>
                <h1 class="font-display text-base font-extrabold text-ink dark:text-ink-dark">{{ $other->name }}</h1>
                @if ($streak = $other->currentStreak())
                    <p class="inline-flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                        @svg('heroicon-s-fire', 'h-3 w-3 text-accent-ink dark:text-accent-ink-dark')
                        {{ $streak }}-day streak
                    </p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-1">
            <button
                type="button"
                wire:click="$set('reporting', true)"
                title="Report"
                class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-flag', 'h-4 w-4')</button>
            <button
                type="button"
                wire:click="block"
                wire:confirm="Block {{ $other->name }}? They won't be able to message you."
                title="Block"
                class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-red-100 hover:text-red-600 dark:text-ink-faint-dark dark:hover:bg-red-950"
            >@svg('heroicon-o-no-symbol', 'h-4 w-4')</button>
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

    {{-- Chat card — thread, toolbar, and composer merged into one bordered
         panel with no gap between them, so the input reads as glued to the
         conversation the way a real chat app's message list and input bar
         form a single surface, not three separate floating pieces. --}}
    <div class="overflow-hidden rounded-2xl border border-line dark:border-line-dark">
        {{-- Wallpaper — a subtle dot-grid texture (self-hosted CSS, no
             image/network dependency) so the thread reads as its own
             "room". Auto-scrolls to the newest message on load and
             whenever the thread changes (send, poll refresh, a friend's
             reply) — but only snaps down if the reader was already near
             the bottom, so scrolling up to reread history isn't yanked
             out from under them by the 5s poll. --}}
        <div
            x-data="{
                init() {
                    this.toBottom(true);
                    new MutationObserver(() => this.toBottom()).observe(this.$el, { childList: true, subtree: true });
                },
                toBottom(force = false) {
                    this.$nextTick(() => {
                        const nearBottom = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight < 120;
                        if (force || nearBottom) this.$el.scrollTop = this.$el.scrollHeight;
                    });
                },
            }"
            wire:poll.5s="$refresh"
            class="max-h-[28rem] min-h-[16rem] space-y-0.5 overflow-y-auto p-4"
            style="background-color: var(--color-surface-sunken); background-image: radial-gradient(color-mix(in srgb, var(--color-ink) 10%, transparent) 1px, transparent 1px); background-size: 18px 18px;"
        >
            @forelse ($this->thread as $message)
            @php
                $mine = $message->sender_id === auth()->id();
                $previous = $this->thread[$loop->index - 1] ?? null;
                $grouped = $previous && $previous->sender_id === $message->sender_id && $previous->type !== 'nudge' && $message->type !== 'nudge';
            @endphp
            <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }} {{ $grouped ? 'mt-0.5' : 'mt-2.5' }}">
                <div class="max-w-[75%] px-3 py-2 text-sm shadow-sm
                    {{ $message->type === 'nudge'
                        ? 'rounded-2xl border border-accent-soft bg-accent-soft/60 text-accent-ink dark:border-accent-soft-dark dark:bg-accent-soft-dark/60 dark:text-accent-ink-dark'
                        : ($mine
                            ? 'rounded-2xl rounded-br-sm bg-accent text-white dark:bg-accent-dark'
                            : 'rounded-2xl rounded-bl-sm border border-line bg-surface text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark') }}">
                    @if ($message->type === 'nudge')
                        <span class="inline-flex items-center gap-1 font-semibold">@svg('heroicon-s-fire', 'h-3.5 w-3.5') {{ $message->body }}</span>
                    @elseif ($message->type === 'audio')
                        <div>
                            <x-audio-player-compact :url="route('friends.attachment', $message)" :mine="$mine" />
                            @if ($message->body && $message->body !== 'Voice message')
                                <p class="mt-1.5 text-xs {{ $mine ? 'text-white/75 dark:text-white/75' : 'text-ink-faint dark:text-ink-faint-dark' }}">{{ $message->body }}</p>
                            @endif
                        </div>
                    @elseif ($message->type === 'file')
                        <a
                            href="{{ route('friends.attachment', $message) }}"
                            class="inline-flex items-center gap-1.5 {{ $mine ? 'text-white dark:text-white' : 'text-ink dark:text-ink-dark' }}"
                        >
                            @svg('heroicon-o-paper-clip', 'h-4 w-4 shrink-0')
                            <span class="underline decoration-dotted underline-offset-2">{{ $message->attachment_name }}</span>
                        </a>
                    @else
                        <span class="break-words">{{ $message->body }}</span>
                    @endif

                    <div class="mt-1 flex items-center justify-end gap-1 {{ $mine ? 'text-white/70 dark:text-white/70' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                        <span class="text-[10px] tabular-nums">{{ $message->created_at->format('g:i A') }}</span>
                        @if ($mine && $message->type !== 'nudge')
                            <span
                                class="relative inline-flex h-3 w-4 shrink-0 items-center {{ $message->read_at ? 'opacity-100' : 'opacity-60' }}"
                                title="{{ $message->read_at ? 'Read' : 'Sent' }}"
                            >
                                @svg('heroicon-s-check', 'absolute left-0 h-3 w-3')
                                @svg('heroicon-s-check', 'absolute left-1 h-3 w-3')
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="flex h-full min-h-32 flex-col items-center justify-center gap-2 py-8 text-center">
                @svg('heroicon-o-chat-bubble-left-right', 'h-8 w-8 text-ink-faint/50 dark:text-ink-faint-dark/50')
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No messages yet — say hello!</p>
            </div>
        @endforelse
        </div>

        {{-- Toolbar — icon-only actions with a hover tooltip (title)
             instead of full text labels, so this stays a thin strip glued
             between the thread and the composer rather than a tall row of
             text buttons breaking the "one surface" illusion. --}}
        <div class="flex flex-wrap items-center gap-1 border-t border-line bg-surface px-2 py-1.5 dark:border-line-dark dark:bg-surface-dark">
            <button
                type="button"
                wire:click="sendNudge"
                title="Send an encouragement nudge"
                class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-accent-ink transition-colors hover:bg-accent-soft dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark"
            >@svg('heroicon-s-fire', 'h-4 w-4')</button>

            <label title="Attach a file" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">
                @svg('heroicon-o-paper-clip', 'h-4 w-4')
                <input type="file" wire:model="attachment" class="hidden">
            </label>
            <span wire:loading wire:target="attachment" class="text-xs text-ink-faint dark:text-ink-faint-dark">Uploading…</span>

            @if ($attachment)
                <span class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $attachment->getClientOriginalName() }}</span>
                <button
                    type="button"
                    wire:click="sendFile"
                    wire:loading.attr="disabled"
                    class="cursor-pointer rounded-full bg-accent px-3 py-1 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                >Send file</button>
            @endif

            @error('attachment')
                <span class="text-xs text-red-600">{{ $message }}</span>
            @enderror

            <button
                type="button"
                wire:click="generateFeedback"
                wire:loading.attr="disabled"
                wire:target="generateFeedback"
                title="Get AI feedback on this conversation"
                class="ms-auto inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink disabled:pointer-events-none disabled:opacity-50 dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >
                <span wire:loading.remove wire:target="generateFeedback">@svg('heroicon-o-sparkles', 'h-4 w-4')</span>
                <span wire:loading wire:target="generateFeedback">@svg('heroicon-o-sparkles', 'h-4 w-4 animate-pulse')</span>
            </button>
        </div>

        {{-- Composer — emoji picker, text input, and the voice recorder all
             in one bar, directly attached under the toolbar with no gap. --}}
        <div class="relative flex items-center gap-1.5 border-t border-line bg-surface p-1.5 dark:border-line-dark dark:bg-surface-dark">
            <button
                type="button"
                x-on:click="showEmoji = !showEmoji"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-face-smile', 'h-5 w-5')</button>

            <div
                x-show="showEmoji"
                x-cloak
                x-on:click.outside="showEmoji = false"
                x-transition.opacity.duration.150ms
                class="absolute bottom-full left-0 z-10 mb-2 grid w-64 grid-cols-8 gap-0.5 rounded-2xl border border-line bg-surface p-2 shadow-lg dark:border-line-dark dark:bg-surface-dark"
            >
                @foreach ($this->emojis() as $emoji)
                    <button
                        type="button"
                        x-on:click="$wire.body = $wire.body + '{{ $emoji }}'"
                        class="cursor-pointer rounded-lg py-1 text-lg transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark"
                    >{{ $emoji }}</button>
                @endforeach
            </div>

            <form wire:submit="send" class="flex flex-1 items-center gap-1.5">
                <input
                    type="text"
                    wire:model="body"
                    placeholder="Message {{ $other->name }}…"
                    x-on:focus="showEmoji = false"
                    class="w-full rounded-full border-0 bg-transparent px-2 py-1.5 text-sm text-ink focus:outline-none dark:text-ink-dark"
                >
                <button
                    type="submit"
                    title="Send"
                    class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >@svg('heroicon-s-paper-airplane', 'h-4 w-4')</button>
            </form>

            <div wire:key="voice-recorder-{{ $other->id }}" class="shrink-0">
                <x-voice-recorder field="voiceMessage" on-recorded="sendVoiceMessage" file-name="voice-message.webm" />
            </div>
        </div>
    </div>

    {{-- AI feedback result / error — shown below the chat card since it can
         grow tall; the trigger itself lives in the compact toolbar above so
         it doesn't take space until there's something to show. --}}
    <div wire:loading.class="opacity-60" wire:target="generateFeedback">
        @if ($feedback)
            <div class="space-y-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">AI feedback on your side of the conversation</p>
                    <button
                        type="button"
                        wire:click="generateFeedback"
                        title="Refresh feedback"
                        class="inline-flex h-6 w-6 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                    >@svg('heroicon-o-arrow-path', 'h-3.5 w-3.5')</button>
                </div>
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-xs font-semibold text-success uppercase dark:text-success-dark">One thing you did well</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['strength'] }}</p>
                </div>
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">A good expression you used</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['expression'] }}</p>
                </div>
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="text-xs font-semibold text-amber-600 uppercase">One thing to improve</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $feedback['correction'] }}</p>
                </div>
            </div>
        @elseif ($feedbackError)
            <p class="text-xs text-red-600">{{ $feedbackError }}</p>
        @endif
    </div>
</div>
