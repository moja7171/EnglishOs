<?php

use App\Models\DirectMessage;
use App\Models\FriendReport;
use App\Models\User;
use App\Notifications\DirectMessageReceived;
use App\Services\AiFeedbackCard;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
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

    /** The chosen FriendReport::CATEGORIES key. */
    public string $reportCategory = '';

    /** A report was just sent — show the confirmation instead of the form. */
    public bool $reportSent = false;

    /** @var array{strength: string, expression: string, correction: array{original: string, corrected: string, why: string, suggestion: string}|null}|null */
    public ?array $feedback = null;

    public ?string $feedbackError = null;

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
        $run = auth()->user()->latestInProgressMissionRun();
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
        $body = 'Voice message';
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

        $transcript = $this->conversationTranscript();

        if ($transcript->where('mine', true)->isEmpty()) {
            $this->feedbackError = 'Send a few messages first — there\'s nothing to give feedback on yet.';

            return;
        }

        try {
            $data = app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => $transcript
                    ->map(fn ($line) => ($line['mine'] ? 'Me' : $this->other->name).': '.$line['text'])
                    ->implode("\n")]],
                systemPrompt: 'You are an encouraging English teacher reviewing a casual text chat between two '
                    .'friends practicing English together. Below, "Me" is '.auth()->user()->levelDescription()
                    .' — give feedback ONLY on "Me"\'s own messages. The other person\'s lines are context only and '
                    .'must never be evaluated or commented on. This is informal chatting, so lowercase, short forms '
                    .'like "u" or relaxed punctuation are fine — only correct a real grammar or vocabulary error. '
                    .'Ignore any line that is not written in English. Choose at most ONE correction, the most '
                    .'useful one; if nothing is worth fixing, set "correction" to null — never invent a mistake. '
                    .'Keep everything short and kind, the way you would for someone easily overwhelmed. Reply with '
                    .'ONLY valid JSON, no markdown fences, no extra text, in exactly this shape: '
                    .'{"strength": "one full sentence, in PERSIAN (Farsi), about one specific thing \"Me\" did well", '
                    .'"expression": "one full sentence, in PERSIAN (Farsi), pointing out one good English word or '
                    .'phrase \"Me\" actually used — you can quote the English itself inside the Persian sentence", '
                    .'"correction": {"original": "\"Me\"\'s own flawed message or sentence, quoted exactly, in ENGLISH", '
                    .'"corrected": "the corrected version of that same text, in ENGLISH", '
                    .'"why": "one short sentence, in PERSIAN (Farsi), explaining the underlying rule", '
                    .'"suggestion": "one short, concrete next step, in PERSIAN (Farsi)"} or null}. '
                    .'Only "original" and "corrected" are in English; every other field is plain Persian, with no '
                    .'English words mixed in unless quoting a specific English word or phrase.',
                requiredKeys: ['strength', 'expression'],
            );

            $correction = $data['correction'] ?? null;

            $this->feedback = [
                'strength' => $data['strength'],
                'expression' => $data['expression'],
                'correction' => is_array($correction) && filled($correction['original'] ?? null) && filled($correction['corrected'] ?? null)
                    ? [
                        'original' => $correction['original'],
                        'corrected' => $correction['corrected'],
                        'why' => $correction['why'] ?? '',
                        'suggestion' => $correction['suggestion'] ?? '',
                    ]
                    : null,
            ];
        } catch (Throwable) {
            $this->feedbackError = "Couldn't check your English right now — try again in a moment.";
        }
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
        $this->feedbackError = null;
    }

    /**
     * @return Collection<int, array{mine: bool, text: string}>
     */
    private function conversationTranscript()
    {
        return auth()->user()->conversationWith($this->other)
            ->reorder()
            ->latest('id')
            ->limit(40)
            ->get()
            ->reverse()
            ->reject(fn ($message) => $message->type === DirectMessage::TYPE_NUDGE)
            ->map(fn ($message) => [
                'mine' => $message->sender_id === auth()->id(),
                'text' => $message->type === DirectMessage::TYPE_FILE
                    ? "[attached a file: {$message->attachment_name}]"
                    : $message->body,
            ])
            ->values();
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
<div class="mx-auto flex min-h-0 w-full max-w-2xl flex-1 flex-col gap-2 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-6" x-data="{ showEmoji: false, showIdeas: false }">
    <div class="flex shrink-0 items-center gap-2 card p-2 pr-2.5">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">
            @svg('heroicon-o-chevron-left', 'h-5 w-5')
        </a>
        <x-user-avatar :user="$other" class="h-10 w-10 text-sm" />
        <div class="min-w-0 flex-1">
            <h1 class="truncate font-display text-base font-extrabold text-ink dark:text-ink-dark">{{ $other->name }}</h1>
            @if ($otherStreak)
                <p class="inline-flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-s-fire', 'h-3 w-3 text-accent-ink dark:text-accent-ink-dark')
                    {{ $otherStreak }}-day streak
                </p>
            @endif
        </div>

        {{-- Same "⋯" pattern as the Friends cards: Report and Block live behind
             one menu instead of two bare icons a thumb-width apart. --}}
        <div class="relative" x-data="{ menu: false }" x-on:click.outside="menu = false" x-on:keydown.escape.window="menu = false">
            <button
                type="button"
                x-on:click="menu = ! menu"
                aria-haspopup="menu"
                aria-label="More options for {{ $other->name }}"
                class="inline-flex h-10 w-10 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
            >@svg('heroicon-o-ellipsis-horizontal', 'h-5 w-5')</button>

            <div x-show="menu" x-cloak x-transition.opacity.duration.150ms role="menu" class="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-line bg-surface py-1 text-left shadow-lg dark:border-line-dark dark:bg-surface-dark">
                <button
                    type="button"
                    wire:click="startReport"
                    x-on:click="menu = false"
                    role="menuitem"
                    class="flex min-h-10 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >
                    @svg('heroicon-o-flag', 'h-4 w-4')
                    Report
                </button>
                <button
                    type="button"
                    wire:click="block"
                    wire:loading.attr="disabled"
                    wire:target="block"
                    wire:confirm="Block {{ $other->name }}? They won't be able to message you."
                    role="menuitem"
                    class="flex min-h-10 w-full cursor-pointer items-center gap-3 px-4 py-2 text-sm font-semibold text-danger-ink transition-colors hover:bg-danger-soft disabled:pointer-events-none disabled:opacity-50"
                >
                    @svg('heroicon-o-no-symbol', 'h-4 w-4')
                    Block
                </button>
            </div>
        </div>
    </div>

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
                            @if ($message->body && $message->body !== 'Voice message')
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
            {{-- Task card — a goal to chat about instead of a blank box. Tapping a
                 question just drops it into the composer; nothing is sent. --}}
            <div x-show="showIdeas" x-cloak x-transition.opacity.duration.150ms class="max-h-56 shrink-0 space-y-2 overflow-y-auto border-t border-line bg-accent-soft/40 px-3 py-3 dark:border-line-dark dark:bg-accent-soft-dark/40">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">Task: interview &amp; report</p>
                        @if ($starterTitle)
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">From your mission “{{ $starterTitle }}”</p>
                        @endif
                    </div>
                    <button type="button" x-on:click="showIdeas = false" aria-label="Close task" class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-dark dark:hover:text-ink-dark">@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
                </div>
                <p class="text-sm text-ink dark:text-ink-dark">1. Ask {{ $other->name }} two or three of these — tap one to put it in your message.</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($starterQuestions as $question)
                        <button
                            type="button"
                            x-on:click="$wire.body = $wire.body ? $wire.body + ' ' + @js($question) : @js($question); showIdeas = false"
                            class="cursor-pointer rounded-full border border-line bg-surface px-3 py-2 text-left text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >{{ $question }}</button>
                    @endforeach
                </div>
                <p class="text-sm text-ink dark:text-ink-dark">2. Then write two sentences <em>about {{ $other->name }}</em> — e.g. “{{ $other->name }} gets up at …”. Watch the <strong>-s</strong> on the verb!</p>
                @if ($starterWords)
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Try using: {{ implode(' · ', $starterWords) }}</p>
                @endif
            </div>

            {{-- Toolbar — the two actions that matter carry a visible label;
                 attach stays an icon (it has an accessible name). Touch
                 targets are 40px. --}}
            <div class="flex shrink-0 flex-wrap items-center gap-1 border-t border-line bg-surface px-2 py-1.5 dark:border-line-dark dark:bg-surface-dark">
                <button
                    type="button"
                    wire:click="draftNudge"
                    wire:loading.attr="disabled"
                    wire:target="draftNudge"
                    x-bind:disabled="$wire.body.trim() !== ''"
                    title="Write an encouragement nudge for you to review and send"
                    class="inline-flex h-10 shrink-0 cursor-pointer items-center gap-1.5 rounded-full px-3 text-xs font-semibold text-accent-ink transition-colors hover:bg-accent-soft dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    @svg('heroicon-s-fire', 'h-4 w-4')
                    Nudge
                </button>
                @if ($draftIsNudge)
                    <span class="text-xs text-ink-faint dark:text-ink-faint-dark">Edit it if you like, then send</span>
                @endif

                <button
                    type="button"
                    x-on:click="showIdeas = ! showIdeas"
                    x-bind:aria-expanded="showIdeas"
                    class="inline-flex h-10 shrink-0 cursor-pointer items-center gap-1.5 rounded-full px-3 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                >
                    @svg('heroicon-o-light-bulb', 'h-4 w-4')
                    Ideas
                </button>

                <label title="Attach a file" class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors focus-within:ring-2 focus-within:ring-accent hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark">
                    @svg('heroicon-o-paper-clip', 'h-4 w-4')
                    <span class="sr-only">Attach a file</span>
                    <input type="file" wire:model="attachment" class="sr-only">
                </label>
                <span wire:loading wire:target="attachment" class="text-xs text-ink-faint dark:text-ink-faint-dark">Uploading…</span>

                @if ($attachment)
                    <span class="max-w-32 truncate text-xs text-ink-faint dark:text-ink-faint-dark">{{ $attachment->getClientOriginalName() }}</span>
                    <button
                        type="button"
                        wire:click="sendFile"
                        wire:loading.attr="disabled"
                        class="cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >Send file</button>
                @endif

                @error('attachment')
                    <span class="text-xs text-danger-ink">{{ $message }}</span>
                @enderror

                <button
                    type="button"
                    wire:click="generateFeedback"
                    wire:loading.attr="disabled"
                    wire:target="generateFeedback"
                    class="ms-auto inline-flex h-10 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark disabled:pointer-events-none disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="generateFeedback">@svg('heroicon-o-sparkles', 'h-4 w-4')</span>
                    <span wire:loading wire:target="generateFeedback">@svg('heroicon-o-sparkles', 'h-4 w-4 animate-pulse')</span>
                    Check my English
                </button>
            </div>

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

            {{-- Composer — emoji picker, growing text box and the voice recorder
                 in one bar. The box grows to ~5 lines; Enter sends on a
                 keyboard, but on a touch screen Enter is a new line and the
                 send button sends. --}}
            <div class="relative flex shrink-0 items-end gap-1.5 border-t border-line bg-surface p-1.5 dark:border-line-dark dark:bg-surface-dark">
                <button
                    type="button"
                    x-on:click="showEmoji = !showEmoji"
                    aria-label="Emoji"
                    class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                >@svg('heroicon-o-face-smile', 'h-5 w-5')</button>

                <div
                    x-show="showEmoji"
                    x-cloak
                    x-on:click.outside="showEmoji = false"
                    x-transition.opacity.duration.150ms
                    class="absolute bottom-full left-0 z-10 mb-2 grid w-64 grid-cols-8 gap-0.5 card p-2 shadow-lg"
                >
                    @foreach ($this->emojis() as $emoji)
                        <button
                            type="button"
                            x-on:click="$wire.body = $wire.body + '{{ $emoji }}'"
                            class="cursor-pointer rounded-lg py-1 text-lg transition-colors hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark"
                        >{{ $emoji }}</button>
                    @endforeach
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
                    <textarea
                        x-ref="box"
                        rows="1"
                        wire:model="body"
                        aria-label="Message {{ $other->name }}"
                        placeholder="Message {{ $other->name }}…"
                        x-on:focus="showEmoji = false"
                        x-on:input="resize()"
                        x-on:keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $el.form.requestSubmit() }"
                        class="max-h-32 min-w-0 flex-1 resize-none rounded-2xl border-0 bg-transparent px-2 py-2.5 text-sm leading-5 text-ink focus:outline-none dark:text-ink-dark"
                    ></textarea>
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="send"
                        aria-label="Send"
                        title="Send"
                        class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >@svg('heroicon-s-paper-airplane', 'h-4 w-4')</button>
                </form>

                <div wire:key="voice-recorder-{{ $other->id }}" class="shrink-0">
                    <x-voice-recorder field="voiceMessage" file-name="voice-message.webm" :compact="true" />
                </div>
            </div>
        @endif

        {{-- Feedback sheet — slides over the chat (it used to render below the
             fold, invisible on a phone) and closes with ✕. --}}
        @if ($feedback || $feedbackError)
            <div wire:loading.class="opacity-60" wire:target="generateFeedback" class="absolute inset-x-0 bottom-0 z-20 max-h-[75%] space-y-3 overflow-y-auto border-t border-line bg-surface p-4 shadow-[0_-8px_24px_rgb(0_0_0/0.12)] dark:border-line-dark dark:bg-surface-dark">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Feedback on your side of the conversation</p>
                    <div class="flex items-center gap-1">
                        @if ($feedback)
                            <button
                                type="button"
                                wire:click="generateFeedback"
                                wire:loading.attr="disabled"
                                wire:target="generateFeedback"
                                aria-label="Refresh feedback"
                                title="Refresh feedback"
                                class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="generateFeedback">@svg('heroicon-o-arrow-path', 'h-4 w-4')</span>
                                <span wire:loading wire:target="generateFeedback">@svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin')</span>
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="dismissFeedback"
                            aria-label="Close feedback"
                            title="Close"
                            class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                        >@svg('heroicon-o-x-mark', 'h-4 w-4')</button>
                    </div>
                </div>

                @if ($feedback)
                    <div class="rounded-xl border-l-4 border-success bg-success/5 p-3 dark:border-success-dark dark:bg-success-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-success uppercase dark:text-success-dark">
                            @svg('heroicon-o-check-circle', 'h-4 w-4')
                            One thing you did well
                        </p>
                        <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['strength'] }}</p>
                    </div>

                    <div class="rounded-xl border-l-4 border-accent bg-accent/5 p-3 dark:border-accent-dark dark:bg-accent-dark/10">
                        <p class="flex items-center gap-1.5 text-xs font-semibold text-accent uppercase dark:text-accent-dark">
                            @svg('heroicon-o-book-open', 'h-4 w-4')
                            A good expression you used
                        </p>
                        <p class="font-fa mt-1 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['expression'] }}</p>
                    </div>

                    @if ($feedback['correction'])
                        <div class="rounded-xl border-l-4 border-warning bg-warning-soft p-3">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-warning-ink uppercase">
                                @svg('heroicon-o-exclamation-triangle', 'h-4 w-4')
                                Something to fix
                            </p>
                            <p class="mt-2 text-sm text-danger-ink line-through decoration-danger">{{ $feedback['correction']['original'] }}</p>
                            <p class="mt-1 text-sm text-success dark:text-success-dark">{{ $feedback['correction']['corrected'] }}</p>
                            @if ($feedback['correction']['why'])
                                <p class="font-fa mt-2 text-sm text-ink dark:text-ink-dark" dir="rtl">{{ $feedback['correction']['why'] }}</p>
                            @endif
                            @if ($feedback['correction']['suggestion'])
                                <p class="font-fa mt-1 flex items-start gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">
                                    @svg('heroicon-o-arrow-trending-up', 'mt-0.5 h-4 w-4 shrink-0')
                                    {{ $feedback['correction']['suggestion'] }}
                                </p>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Nothing to fix in your recent messages — nice work.</p>
                    @endif
                @else
                    <p class="text-sm text-danger-ink">{{ $feedbackError }}</p>
                @endif
            </div>
        @endif
    </div>
</div>
