<?php

use App\Livewire\Concerns\GivesConversationFeedback;
use App\Models\DirectMessage;
use App\Models\FriendReport;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\PairPractice;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use GivesConversationFeedback;
    use WithFileUploads;

    /** A pause this long between two messages starts a new chat session. */
    private const SESSION_GAP_MINUTES = 60;

    /** How many messages from before the latest session go along as context. */
    private const CONTEXT_LINES = 6;

    public User $other;

    public string $body = '';

    public ?UploadedFile $voiceMessage = null;

    public ?UploadedFile $attachment = null;

    public bool $reporting = false;

    public string $reportReason = '';

    /** The chosen FriendReport::CATEGORIES key. */
    public string $reportCategory = '';

    /** A report was just sent — show the confirmation instead of the form. */
    public bool $reportSent = false;

    /** Mission this learner is on, for the "Interview & report" task card. */
    public ?string $starterTitle = null;

    /** @var list<string> Three questions to ask the friend — from the current mission, else generic. */
    public array $starterQuestions = [];

    /** @var list<string> Up to three of the mission's vocabulary words to try using. */
    public array $starterWords = [];

    /** The composer holds an AI-drafted nudge, so sending it keeps the nudge type. */
    public bool $draftIsNudge = false;

    /** How many of the newest messages are loaded (see window()). */
    public int $limit = 50;

    /** Read once — the poll re-renders every 5s and the streak is a UNION over all Evidence. */
    public int $otherStreak = 0;

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

        $this->otherStreak = $this->other->currentStreak();

        $this->loadStarters();

        // Arrives from <x-practice-with-friend> on a mission step — just
        // pre-fills the composer, never auto-sent, so the learner can
        // still edit or discard it. Length-capped since it's untrusted
        // query-string input.
        $prefill = trim((string) request()->query('prefill', ''));

        if ($prefill !== '') {
            $this->body = str($prefill)->limit(500)->toString();
        }
    }

    /**
     * Built once here (public state survives the 5s polls) so the task card
     * costs nothing per refresh. Questions come from the mission the learner
     * is on right now — the same interview questions they've been practising —
     * so the chat extends their lesson; with no mission in progress a few
     * generic A2-friendly questions stand in.
     */
    private function loadStarters(): void
    {
        $run = auth()->user()->currentInProgressMissionRun();
        $questions = $run ? $run->mission->conversationPrompts('ai_conversation_1') : [];

        if ($questions === []) {
            $questions = [
                'What did you do today?',
                'What do you usually do on the weekend?',
                'What is your favourite place in your city?',
                'What time do you usually wake up?',
            ];
        }

        $this->starterTitle = $run?->mission->title;
        $this->starterQuestions = collect($questions)->shuffle()->take(3)->values()->all();
        $this->starterWords = $run ? array_slice($run->selectedVocabularyWords(), 0, 3) : [];
    }

    /**
     * Clearing the box discards a pending nudge draft, so whatever is typed
     * next goes out as a normal message.
     */
    public function updatedBody(string $value): void
    {
        if (trim($value) === '') {
            $this->draftIsNudge = false;
        }
    }

    public function send(): void
    {
        $text = trim($this->body);

        if ($text === '') {
            return;
        }

        if (! $this->canMessage) {
            return;
        }

        $this->notifyRecipient(DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => $this->draftIsNudge ? DirectMessage::TYPE_NUDGE : DirectMessage::TYPE_MESSAGE,
            'body' => $text,
        ]));

        $this->body = '';
        $this->draftIsNudge = false;
        unset($this->thread);
    }

    /**
     * Shared by every send*() method below — a friend only ever needs to
     * hear about a burst of messages once. While an unread notification
     * from this sender already exists, the new message just bumps it to the
     * top of the bell instead of stacking another row; it's marked read the
     * moment the recipient opens (or is sitting in) the conversation, which
     * starts a fresh notification for whatever comes next. Nudges keep
     * their own notification since they read and look different.
     */
    private function notifyRecipient(DirectMessage $message): void
    {
        if ($this->other->hasMuted(auth()->user())) {
            return;
        }

        $kind = $message->type === DirectMessage::TYPE_NUDGE ? DirectMessage::TYPE_NUDGE : DirectMessage::TYPE_MESSAGE;

        $existing = $this->other->unreadNotifications()
            ->where('type', DirectMessageReceived::class)
            ->where('data->sender_id', auth()->id())
            ->where('data->kind', $kind)
            ->first();

        if ($existing) {
            $existing->forceFill(['created_at' => now()])->save();

            return;
        }

        $this->other->notify(new DirectMessageReceived($message));
    }

    /**
     * Called from the "Send voice message" button once the learner has
     * listened to the recording waiting in the preview strip (unlike the
     * mission steps, which auto-submit — a chat message is public to a
     * friend, so the sender gets to hear it first). Stored on the private
     * disk (see the migration + the friends.attachment route), never
     * Storage::disk('public').
     */
    public function sendVoiceMessage(): void
    {
        if (! $this->canMessage) {
            return;
        }

        if (! $this->voiceMessage) {
            return;
        }

        $path = $this->voiceMessage->store('direct-messages/'.auth()->id(), 'local');

        // Transcribed so the conversation has real, readable text behind
        // it — both for the caption shown under the player and so
        // generateFeedback() below has something to actually read. Silent
        // fallback to a generic label on any failure: sending the voice
        // message itself must never be blocked by a transcription hiccup.
        $body = DirectMessage::VOICE_FALLBACK_BODY;
        try {
            $transcript = trim(app(GroqClient::class)->transcribe($this->voiceMessage->getRealPath()));
            $body = $transcript !== '' ? $transcript : $body;
        } catch (Throwable) {
            // Keep the generic fallback label.
        }

        $this->notifyRecipient(DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_AUDIO,
            'body' => $body,
            'attachment_path' => $path,
            'attachment_name' => 'voice-message.webm',
            'attachment_mime' => $this->voiceMessage->getMimeType(),
        ]));

        $this->voiceMessage = null;
        unset($this->thread);
    }

    public function discardVoiceMessage(): void
    {
        $this->voiceMessage = null;
    }

    public function sendFile(): void
    {
        if (! $this->canMessage) {
            return;
        }

        $this->validate([
            // 15MB, and an explicit allowlist — never trust the browser's
            // claimed extension alone, but this at least keeps obviously
            // dangerous types (executables, scripts) out from the start.
            'attachment' => ['required', 'file', 'max:15360', 'extensions:pdf,doc,docx,txt,jpg,jpeg,png,webp,gif,mp3,wav,m4a,webm'],
        ]);

        $path = $this->attachment->store('direct-messages/'.auth()->id(), 'local');

        $this->notifyRecipient(DirectMessage::create([
            'sender_id' => auth()->id(),
            'recipient_id' => $this->other->id,
            'type' => DirectMessage::TYPE_FILE,
            'body' => $this->attachment->getClientOriginalName(),
            'attachment_path' => $path,
            'attachment_name' => $this->attachment->getClientOriginalName(),
            'attachment_mime' => $this->attachment->getMimeType(),
        ]));

        $this->attachment = null;
        unset($this->thread);
    }

    /**
     * One-tap encouragement instead of typing something from scratch — but it
     * only fills the composer. The learner reads it, edits it or throws it
     * away, and sends it themselves (same "never auto-sent" rule as the
     * mission prefill): nothing goes out under their name unseen. It never
     * overwrites something they already typed. The text adapts to whether the
     * *recipient's* streak actually needs saving, using the same
     * User::currentStreak() the header badge and Friends list already trust.
     */
    public function draftNudge(): void
    {
        if (! $this->canMessage || trim($this->body) !== '') {
            return;
        }

        $this->body = $this->nudgeMessage();
        $this->draftIsNudge = true;
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

        // Each draft is a paid AI call, so cap them per sender and friend;
        // past the cap the preset text still works, just without the AI.
        $limiterKey = 'nudge-draft:'.auth()->id().':'.$this->other->id;

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return $fallback;
        }

        RateLimiter::hit($limiterKey, 3600);

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

    /**
     * ⋯ › Mute / Unmute: their messages, nudges and activity alerts stop
     * (or start again) notifying; the chat itself stays.
     */
    public function toggleMute(): void
    {
        auth()->user()->setMuted($this->other, ! $this->muted);

        unset($this->muted);
    }

    #[Computed]
    public function muted(): bool
    {
        return auth()->user()->hasMuted($this->other);
    }

    /**
     * ⋯ › Unfollow: the chat closes until one of you follows again.
     */
    public function unfollow(): void
    {
        auth()->user()->unfollow($this->other);

        $this->redirect(route('friends.index'), navigate: true);
    }

    /**
     * The green strip under the header: how often the two practised
     * together and where the friend is right now (mission · day · step) —
     * kept for a few minutes, so the 5s poll doesn't redo it.
     *
     * @return array{times: int, daysInRow: int, daysSince: ?int, where: ?string}
     */
    #[Computed(persist: true, seconds: 300)]
    public function pairStrip(): array
    {
        $run = $this->other->currentInProgressMissionRun();
        $where = null;

        if ($run) {
            $step = $run->currentStepKey();
            $day = collect($run->dayProgress())->search(fn (array $missionDay) => $missionDay['current']);
            $where = $this->other->name.' is on '.$run->mission->code
                .($day !== false ? ' · Day '.($day + 1) : '')
                .($step ? ' · '.$run->mission->stepLabel($step) : '');
        }

        return app(PairPractice::class)->together(auth()->user(), $this->other) + ['where' => $where];
    }

    public function block(): void
    {
        auth()->user()->block($this->other);

        $this->redirect(route('friends.index'), navigate: true);
    }

    public function startReport(): void
    {
        $this->reportSent = false;
        $this->reporting = true;
    }

    public function cancelReport(): void
    {
        $this->reporting = false;
        $this->reportReason = '';
        $this->reportCategory = '';
    }

    public function dismissReportSent(): void
    {
        $this->reportSent = false;
    }

    /**
     * Same rules as the Friends page: a preset category is required, details
     * are optional, and the other person's latest real message is the
     * snapshot (never the reporter's own, never a nudge).
     */
    public function submitReport(): void
    {
        if (! array_key_exists($this->reportCategory, FriendReport::CATEGORIES)) {
            return;
        }

        $details = trim($this->reportReason);

        FriendReport::create([
            'reporter_id' => auth()->id(),
            'reported_id' => $this->other->id,
            'category' => $this->reportCategory,
            'reason' => $details !== '' ? mb_substr($details, 0, 1000) : FriendReport::CATEGORIES[$this->reportCategory],
            'message_snapshot' => auth()->user()->lastMessageFrom($this->other),
        ]);

        $this->reporting = false;
        $this->reportReason = '';
        $this->reportCategory = '';
        $this->reportSent = true;
    }

    /**
     * Feedback on the LATEST SESSION of this chat only — the messages
     * since the last pause of SESSION_GAP_MINUTES or more (so "today's
     * chat", or the last one if today has none). Up to CONTEXT_LINES
     * messages from before that pause go along as context the AI must not
     * judge. Judges ONLY the requesting learner's own messages (never the
     * friend's) — this is a peer conversation, not something the other
     * person agreed to be graded on.
     */
    public function generateFeedback(): void
    {
        ['earlier' => $earlier, 'session' => $session] = $this->latestSession();

        if ($session->where('mine', true)->isEmpty()) {
            $this->feedback = null;
            $this->feedbackError = 'Send a few messages first — there\'s nothing to give feedback on yet.';

            return;
        }

        $lines = fn (Collection $messages) => $messages
            ->map(fn ($line) => ($line['mine'] ? 'Me' : $this->other->name).': '.$line['text'])
            ->implode("\n");

        $this->requestConversationFeedback(
            ($earlier->isEmpty() ? '' : "EARLIER (context only):\n".$lines($earlier)."\n\n")
                ."LATEST SESSION:\n".$lines($session),
            situation: 'a casual text chat between two friends practicing English together. "Me" is the learner. '
                .'Judge ONLY "Me"\'s lines under LATEST SESSION; lines under EARLIER and the other person\'s lines '
                .'are context only. This is informal chatting, so lowercase, short forms like "u" or relaxed '
                .'punctuation are fine.',
            source: 'friends_chat',
        );
    }

    /**
     * The newest 40 messages, split at the last pause of
     * SESSION_GAP_MINUTES or more. Nudges are left out, and so is a voice
     * message whose transcription failed (its body is only the fallback
     * label, not anything the learner said).
     *
     * @return array{earlier: Collection<int, array{mine: bool, text: string}>, session: Collection<int, array{mine: bool, text: string}>}
     */
    private function latestSession(): array
    {
        $messages = auth()->user()->conversationWith($this->other)
            ->reorder()
            ->latest('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->reject(fn ($message) => $message->type === DirectMessage::TYPE_NUDGE
                || ($message->type === DirectMessage::TYPE_AUDIO && $message->body === DirectMessage::VOICE_FALLBACK_BODY))
            ->values();

        $sessionStart = 0;

        foreach ($messages as $index => $message) {
            if ($index > 0 && $messages[$index - 1]->created_at->diffInMinutes($message->created_at) >= self::SESSION_GAP_MINUTES) {
                $sessionStart = $index;
            }
        }

        $toLines = fn (Collection $part) => $part->map(fn ($message) => [
            'mine' => $message->sender_id === auth()->id(),
            'text' => $message->type === DirectMessage::TYPE_FILE
                ? "[attached a file: {$message->attachment_name}]"
                : $message->body,
        ])->values();

        return [
            'earlier' => $toLines($messages->slice(max(0, $sessionStart - self::CONTEXT_LINES), min($sessionStart, self::CONTEXT_LINES))),
            'session' => $toLines($messages->slice($sessionStart)),
        ];
    }

    /**
     * Re-evaluated on every request (so on every poll) — the other side can
     * unfollow or block while this page is open, and mount() only checked
     * once. When false the composer is replaced by a calm locked bar, the
     * poll stops, and sending is a quiet no-op that leaves any typed draft
     * in place instead of throwing a 403.
     */
    #[Computed]
    public function canMessage(): bool
    {
        return auth()->user()->canMessageWith($this->other);
    }

    /**
     * The newest $limit messages plus one more, oldest first — the extra
     * row only tells hasEarlier() whether there's anything above the window.
     * The poll re-runs this every 5s, so it must never load the full history.
     *
     * @return Collection<int, DirectMessage>
     */
    #[Computed]
    public function window()
    {
        return auth()->user()->conversationWith($this->other)
            ->reorder()
            ->latest('id')
            ->limit($this->limit + 1)
            ->get()
            ->reverse()
            ->values();
    }

    #[Computed]
    public function hasEarlier(): bool
    {
        return $this->window->count() > $this->limit;
    }

    /**
     * Adds another page above. The scroll position is restored by the button
     * itself (see the template) — Safari's scroll anchoring isn't reliable.
     */
    public function loadEarlier(): void
    {
        $this->limit += 50;

        unset($this->window, $this->thread, $this->hasEarlier);
    }

    #[Computed]
    public function thread()
    {
        $messages = $this->window->take(-$this->limit)->values();

        if (! $this->canMessage) {
            return $messages;
        }

        // One UPDATE for everything unread in the pair — including anything
        // above the loaded window — and only when there is something to mark.
        DirectMessage::query()
            ->where('sender_id', $this->other->id)
            ->where('recipient_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        auth()->user()->unreadNotifications()
            ->where('type', DirectMessageReceived::class)
            ->where('data->sender_id', $this->other->id)
            ->update(['read_at' => now()]);

        return $messages;
    }

    #[Computed]
    public function timezone(): string
    {
        return auth()->user()->displayTimezone();
    }

    /**
     * "Today" / "Yesterday" / "Mon, Sep 28" (plus the year when it isn't this
     * one), judged in the learner's own timezone.
     */
    public function dayLabel(CarbonInterface $localDate): string
    {
        $today = now($this->timezone)->startOfDay();

        return match (true) {
            $localDate->isSameDay($today) => 'Today',
            $localDate->isSameDay($today->copy()->subDay()) => 'Yesterday',
            $localDate->year === $today->year => $localDate->format('D, M j'),
            default => $localDate->format('D, M j, Y'),
        };
    }
};
?>

{{-- Full-height chat (the page passes fill to the layout): a compact top bar,
     the thread taking all the remaining height, and the composer pinned at
     the bottom — so with the phone keyboard open the input is never pushed
     below the fold. Report, feedback and the locked state all live inside
     this one frame instead of stacking below a fixed-height box. --}}
<div class="mx-auto flex min-h-0 w-full max-w-2xl flex-1 flex-col gap-2 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-6" x-data="{ showEmoji: false, showIdeas: false, showPlus: false }">
    <div class="flex shrink-0 items-center gap-2 card p-2 pr-1.5">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </a>
        <x-user-avatar :user="$other" class="h-10 w-10 text-sm" />
        <div class="min-w-0 flex-1">
            <h1 class="truncate font-display text-base font-extrabold text-ink dark:text-ink-dark">{{ $other->name }}</h1>
            @if ($otherStreak)
                <p class="text-xs font-bold text-success dark:text-success-dark">🔥 {{ $otherStreak }}-day streak</p>
            @endif
        </div>

        @if ($this->canMessage)
            <button
                type="button"
                wire:click="generateFeedback"
                wire:loading.attr="disabled"
                wire:target="generateFeedback"
                title="Check my English"
                aria-label="Check my English"
                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-accent-ink transition-colors hover:bg-accent-soft disabled:pointer-events-none disabled:opacity-50 dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark"
            >
                <span wire:loading.remove wire:target="generateFeedback">@svg('heroicon-s-sparkles', 'h-5 w-5')</span>
                <span wire:loading wire:target="generateFeedback">@svg('heroicon-s-sparkles', 'h-5 w-5 animate-pulse')</span>
            </button>
        @endif

        {{-- Everything about this friendship behind one ⋯. --}}
        <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape.window="menu = false">
            <button
                type="button"
                x-on:click="menu = ! menu"
                aria-haspopup="menu"
                aria-label="More options for {{ $other->name }}"
                class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-ellipsis-horizontal', 'h-5 w-5')</button>

            <div x-show="menu" x-cloak x-transition.origin.top.right.scale.95.opacity.duration.150ms role="menu" class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-2xl border border-line bg-surface py-1 text-left shadow-lg dark:border-line-dark dark:bg-surface-dark">
                <button type="button" wire:click="toggleMute" x-on:click="menu = false" role="menuitem" class="flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    @svg($this->muted ? 'heroicon-o-bell' : 'heroicon-o-bell-slash', 'h-4 w-4')
                    {{ $this->muted ? 'Unmute' : 'Mute' }}
                </button>
                <button type="button" wire:click="unfollow" wire:confirm="Unfollow {{ $other->name }}? You won't be able to message each other until you follow again." role="menuitem" class="flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    @svg('heroicon-o-user-minus', 'h-4 w-4')
                    Unfollow
                </button>
                <button
                    type="button"
                    wire:click="block"
                    wire:loading.attr="disabled"
                    wire:target="block"
                    wire:confirm="Block {{ $other->name }}? They won't be able to message you."
                    role="menuitem"
                    class="flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:opacity-50"
                >
                    @svg('heroicon-o-no-symbol', 'h-4 w-4')
                    Block
                </button>
                <button type="button" wire:click="startReport" x-on:click="menu = false" role="menuitem" class="flex min-h-11 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-danger-ink transition-colors hover:bg-danger-soft">
                    @svg('heroicon-o-flag', 'h-4 w-4')
                    Report
                </button>
            </div>
        </div>
    </div>

    @if ($this->canMessage)
        {{-- How the two practise together, and where the friend is now. --}}
        @php $strip = $this->pairStrip; @endphp
        <div class="flex shrink-0 items-center gap-3 rounded-2xl bg-success-soft px-3 py-2 dark:bg-success-soft-dark">
            <p class="min-w-0 flex-1 text-xs font-extrabold text-success dark:text-success-dark">
                👫 {{ $strip['times'] > 0 ? $strip['times'].' '.Str::plural('time', $strip['times']).' together'.($strip['daysInRow'] > 1 ? ' · '.$strip['daysInRow'].' days in a row' : '') : 'Practice together' }}
                @if ($strip['where'])
                    <span class="block truncate font-bold text-ink-soft dark:text-ink-soft-dark">{{ $strip['where'] }}</span>
                @endif
            </p>
            <a href="{{ route('pair-practice.index') }}" wire:navigate class="shrink-0 rounded-full bg-success px-3 py-1.5 text-xs font-extrabold text-white dark:bg-success-dark">Practice</a>
        </div>
    @endif

    @if ($reporting)
        <div class="shrink-0">
            <x-friends.report-form :friend="$other" :selected="$reportCategory" category-model="reportCategory" details-model="reportReason" submit-action="submitReport" cancel-action="cancelReport" />
        </div>
    @elseif ($reportSent)
        <div class="shrink-0">
            <x-friends.report-sent :friend="$other" block-action="block" dismiss-action="dismissReportSent" />
        </div>
    @endif

    {{-- Chat card — thread, toolbar and composer in one bordered panel with no
         gap between them, like a real chat app's list and input bar. It is
         also the positioning context for the feedback sheet. --}}
    <div class="relative flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-line dark:border-line-dark">
        {{-- Auto-scrolls to the newest message on load and whenever the thread
             changes (send, poll refresh, a friend's reply) — but only snaps
             down if the reader was already near the bottom, so scrolling up
             to reread history isn't yanked away by the 5s poll. --}}
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
            @if ($this->canMessage) wire:poll.5s="$refresh" @endif
            role="log"
            aria-live="polite"
            aria-label="Conversation with {{ $other->name }}"
            class="chat-wallpaper min-h-0 flex-1 space-y-0.5 overflow-y-auto p-4"
        >
            @if ($this->hasEarlier)
                <div class="flex justify-center pb-2">
                    <button
                        type="button"
                        x-on:click="const el = $root; const fromBottom = el.scrollHeight - el.scrollTop; $wire.loadEarlier().then(() => $nextTick(() => { el.scrollTop = el.scrollHeight - fromBottom }))"
                        wire:loading.attr="disabled"
                        wire:target="loadEarlier"
                        class="cursor-pointer rounded-full border border-line bg-surface px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:opacity-50"
                    >Load earlier messages</button>
                </div>
            @endif

            @forelse ($this->thread as $message)
            @php
                $mine = $message->sender_id === auth()->id();
                $previous = $this->thread[$loop->index - 1] ?? null;
                $localTime = $message->created_at->copy()->setTimezone($this->timezone);
                $newDay = ! $previous || ! $previous->created_at->copy()->setTimezone($this->timezone)->isSameDay($localTime);
                $grouped = ! $newDay && $previous->sender_id === $message->sender_id && $previous->type !== 'nudge' && $message->type !== 'nudge';
            @endphp
            @if ($newDay)
                <div class="flex justify-center pt-2 pb-1">
                    <span class="rounded-full bg-surface/80 px-3 py-0.5 text-[11px] font-semibold text-ink-faint shadow-sm dark:bg-surface-dark/80 dark:text-ink-faint-dark">{{ $this->dayLabel($localTime) }}</span>
                </div>
            @endif
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
                            @if ($message->body && $message->body !== App\Models\DirectMessage::VOICE_FALLBACK_BODY)
                                @if ($mine)
                                    {{-- The sender's own transcript is a mirror of how clearly they came across. --}}
                                    <p class="mt-1.5 text-xs text-white/75 dark:text-white/75">{{ $message->body }}</p>
                                @else
                                    {{-- The listener's job is to listen first; the text is one tap away. --}}
                                    <div x-data="{ showText: false }">
                                        <button type="button" x-show="! showText" x-on:click="showText = true" class="mt-1 cursor-pointer text-xs font-semibold text-accent-ink underline decoration-dotted underline-offset-2 dark:text-accent-ink-dark">Show text</button>
                                        <p x-show="showText" x-cloak class="mt-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $message->body }}</p>
                                    </div>
                                @endif
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
                        <span class="break-words whitespace-pre-line">{{ $message->body }}</span>
                    @endif

                    <div class="mt-1 flex items-center justify-end gap-1 {{ $mine ? 'text-white/70 dark:text-white/70' : 'text-ink-faint dark:text-ink-faint-dark' }}">
                        <span class="text-[10px] tabular-nums">{{ $localTime->format('g:i A') }}</span>
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
                @if ($this->canMessage)
                    <button type="button" x-on:click="showIdeas = true" class="mt-1 cursor-pointer rounded-full border border-line bg-surface px-3 py-2 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">Need something to talk about?</button>
                @endif
            </div>
        @endforelse
        </div>

        @if (! $this->canMessage)
            <div class="flex shrink-0 items-center gap-2 border-t border-line bg-surface px-4 py-3 text-sm text-ink-soft dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark">
                @svg('heroicon-o-lock-closed', 'h-4 w-4 shrink-0')
                You can't message {{ $other->name }} right now.
            </div>
        @else
            {{-- Ideas: questions from the learner's mission to ask, then two
                 sentences about the friend. A tap puts one in the box. --}}
            <x-bottom-sheet show="showIdeas" label="Ideas">
                <x-slot:title>Ask {{ $other->name }}, then write 2 sentences about them</x-slot:title>
                <div class="flex flex-col gap-2">
                    @foreach ($starterQuestions as $question)
                        <button
                            type="button"
                            x-on:click="$wire.body = $wire.body ? $wire.body + ' ' + @js($question) : @js($question); showIdeas = false"
                            class="cursor-pointer rounded-xl bg-surface-sunken px-3 py-2.5 text-left text-sm font-semibold text-ink transition-colors hover:bg-accent-soft dark:bg-surface-sunken-dark dark:text-ink-dark dark:hover:bg-accent-soft-dark"
                        >{{ $question }}</button>
                    @endforeach
                </div>
                @if ($starterWords)
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Try: {{ implode(' · ', $starterWords) }}</p>
                @endif
            </x-bottom-sheet>

            {{-- A recording waits here until the learner has listened to it and
                 chosen to send it (or record again) — nothing is sent while
                 they're still deciding. --}}
            @if ($voiceMessage)
                <div class="flex shrink-0 flex-wrap items-center gap-2 border-t border-line bg-surface-sunken px-3 py-2 dark:border-line-dark dark:bg-surface-sunken-dark">
                    @if ($voiceMessage->isPreviewable())
                        <x-audio-player-compact :url="$voiceMessage->temporaryUrl()" />
                    @endif
                    <div class="ms-auto flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="discardVoiceMessage"
                            wire:loading.attr="disabled"
                            wire:target="sendVoiceMessage"
                            class="cursor-pointer rounded-full border border-line px-3 py-2 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark disabled:pointer-events-none disabled:opacity-50"
                        >Record again</button>
                        <button
                            type="button"
                            wire:click="sendVoiceMessage"
                            wire:loading.attr="disabled"
                            wire:target="sendVoiceMessage"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-accent px-4 py-2 text-xs font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:opacity-50"
                        >
                            <span wire:loading.remove wire:target="sendVoiceMessage">Send voice message</span>
                            <span wire:loading wire:target="sendVoiceMessage">Sending…</span>
                        </button>
                    </div>
                </div>
            @endif

            @if ($attachment || $errors->has('attachment'))
                <div class="flex shrink-0 items-center gap-2 border-t border-line bg-surface-sunken px-3 py-2 text-xs dark:border-line-dark dark:bg-surface-sunken-dark">
                    @if ($attachment)
                        @svg('heroicon-o-paper-clip', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                        <span class="min-w-0 flex-1 truncate text-ink-soft dark:text-ink-soft-dark">{{ $attachment->getClientOriginalName() }}</span>
                        <button type="button" wire:click="sendFile" wire:loading.attr="disabled" class="cursor-pointer rounded-full bg-accent px-3 py-1.5 font-semibold text-white hover:opacity-90 disabled:opacity-50 dark:bg-accent-dark">Send file</button>
                    @endif
                    @error('attachment')
                        <span class="text-danger-ink">{{ $message }}</span>
                    @enderror
                </div>
            @endif

            {{-- One row like a messenger: ＋ (Nudge, Ideas, File), the box
                 with 😊 inside, and the mic — which turns into send once
                 something is typed. Enter sends on a keyboard; on a touch
                 screen Enter is a new line. --}}
            <div class="relative flex shrink-0 items-end gap-1.5 border-t border-line bg-surface p-1.5 dark:border-line-dark dark:bg-surface-dark">
                <button
                    type="button"
                    x-on:click="showPlus = ! showPlus"
                    x-bind:aria-expanded="showPlus"
                    aria-label="More"
                    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors"
                    x-bind:class="showPlus ? 'bg-accent text-white dark:bg-accent-dark' : 'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark'"
                >
                    <span x-show="! showPlus">@svg('heroicon-o-plus', 'h-5 w-5')</span>
                    <span x-show="showPlus" x-cloak>@svg('heroicon-o-x-mark', 'h-5 w-5')</span>
                </button>

                <div x-show="showPlus" x-cloak x-on:click.outside="showPlus = false" x-transition.opacity.duration.150ms class="absolute bottom-full left-1.5 z-20 mb-2 flex w-60 flex-col rounded-2xl border border-line bg-surface p-1.5 shadow-xl dark:border-line-dark dark:bg-surface-dark">
                    <button
                        type="button"
                        wire:click="draftNudge"
                        x-on:click="showPlus = false"
                        x-bind:disabled="$wire.body.trim() !== ''"
                        class="flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-surface-sunken disabled:pointer-events-none disabled:opacity-50 dark:hover:bg-surface-sunken-dark"
                    >
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-base dark:bg-accent-soft-dark" aria-hidden="true">🔥</span>
                        <span class="text-sm font-extrabold text-ink dark:text-ink-dark">Nudge<span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">A short push to practice</span></span>
                    </button>
                    <button type="button" x-on:click="showPlus = false; showIdeas = true" class="flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-base dark:bg-accent-soft-dark" aria-hidden="true">💡</span>
                        <span class="text-sm font-extrabold text-ink dark:text-ink-dark">Ideas<span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Questions from your mission</span></span>
                    </button>
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-base dark:bg-accent-soft-dark" aria-hidden="true">📎</span>
                        <span class="text-sm font-extrabold text-ink dark:text-ink-dark">File</span>
                        <input type="file" wire:model="attachment" x-on:change="showPlus = false" class="sr-only">
                    </label>
                </div>

                <form
                    wire:submit="send"
                    class="flex min-w-0 flex-1 items-end gap-1.5"
                    x-data="{
                        resize() {
                            const box = this.$refs.box;
                            box.style.height = 'auto';
                            box.style.height = Math.min(box.scrollHeight, 128) + 'px';
                        },
                    }"
                    x-init="$watch('$wire.body', () => $nextTick(() => resize())); $nextTick(() => resize())"
                >
                    <div class="relative flex min-w-0 flex-1 items-end rounded-3xl bg-surface-sunken pr-1 dark:bg-surface-sunken-dark">
                        <textarea
                            x-ref="box"
                            rows="1"
                            wire:model="body"
                            aria-label="Message {{ $other->name }}"
                            placeholder="Message"
                            x-on:focus="showEmoji = false; showPlus = false"
                            x-on:input="resize()"
                            x-on:keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $el.form.requestSubmit() }"
                            class="max-h-32 min-w-0 flex-1 resize-none border-0 bg-transparent px-3.5 py-2.5 text-sm leading-5 text-ink focus:ring-0 focus:outline-none dark:text-ink-dark"
                        ></textarea>
                        <button
                            type="button"
                            x-on:click="showEmoji = ! showEmoji"
                            aria-label="Emoji"
                            class="inline-flex h-10 w-9 shrink-0 cursor-pointer items-center justify-center text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
                        >@svg('heroicon-o-face-smile', 'h-5 w-5')</button>

                        <div
                            x-show="showEmoji"
                            x-cloak
                            x-on:click.outside="showEmoji = false"
                            x-transition.opacity.duration.150ms
                            class="absolute right-0 bottom-full z-10 mb-2 grid w-64 grid-cols-8 gap-0.5 card p-2 shadow-lg"
                        >
                            @foreach ($this->emojis() as $emoji)
                                <button
                                    type="button"
                                    x-on:click="$wire.body = $wire.body + '{{ $emoji }}'"
                                    class="cursor-pointer rounded-lg py-1 text-lg transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark"
                                >{{ $emoji }}</button>
                            @endforeach
                        </div>
                    </div>
                    <button
                        type="submit"
                        x-show="$wire.body.trim() !== ''"
                        x-cloak
                        wire:loading.attr="disabled"
                        wire:target="send"
                        aria-label="Send"
                        title="Send"
                        class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >@svg('heroicon-s-paper-airplane', 'h-4 w-4')</button>
                </form>

                <div wire:key="voice-recorder-{{ $other->id }}" x-show="$wire.body.trim() === ''" class="shrink-0">
                    <x-voice-recorder field="voiceMessage" file-name="voice-message.webm" :compact="true" />
                </div>
            </div>
        @endif

        {{-- Feedback sheet — slides over the chat (it used to render below the
             fold, invisible on a phone) and closes with ✕. --}}
        @if ($feedback || $feedbackError)
            <div wire:loading.class="opacity-60" wire:target="generateFeedback" class="absolute inset-x-0 bottom-0 z-20 max-h-[75%] overflow-y-auto border-t border-line bg-surface p-4 shadow-[0_-8px_24px_rgb(0_0_0/0.12)] dark:border-line-dark dark:bg-surface-dark">
                <x-conversation-feedback :feedback="$feedback" :error="$feedbackError" action="generateFeedback" />
            </div>
        @endif
    </div>
</div>
