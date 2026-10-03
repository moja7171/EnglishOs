<?php

use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
use App\Models\User;
use App\Notifications\PartnerAnswerReceived;
use App\Notifications\PartnerSessionStarted;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public PartnerSession $session;

    /** @var array<int, string> keyed by question index — the current user's draft/saved answer text */
    public array $textAnswers = [];

    public ?UploadedFile $voiceAnswer = null;

    public ?string $error = null;

    /**
     * Entirely outside Evidence Before Progress (Article 3) — this never
     * touches either learner's MissionRun. Access requires being one of
     * the two participants AND still passing the same mutual-follow/
     * not-blocked gate messaging itself uses, so a later unfollow or
     * block closes this the same way it closes a DM thread.
     */
    public function mount(): void
    {
        abort_unless($this->session->isAccessibleBy(auth()->user()), 403);
        abort_unless(auth()->user()->canMessageWith($this->partner), 403);

        $this->session->answers()
            ->where('responder_id', auth()->id())
            ->get()
            ->each(fn (PartnerSessionAnswer $answer) => $this->textAnswers[$answer->question_index] = $answer->body);
    }

    #[Computed]
    public function partner(): User
    {
        return $this->session->partnerFor(auth()->user());
    }

    #[Computed]
    public function prompts(): array
    {
        return $this->session->mission->conversationPrompts($this->session->step_key);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, PartnerSessionAnswer>>
     */
    #[Computed]
    public function answersByQuestion()
    {
        // Viewing the session IS reading its alerts (the invite and any
        // "X answered" ones) — matched on the session URL they all carry.
        auth()->user()->unreadNotifications()
            ->whereIn('type', [PartnerAnswerReceived::class, PartnerSessionStarted::class])
            ->where('data->url', route('partner-sessions.show', $this->session))
            ->update(['read_at' => now()]);

        return $this->session->answers()->get()->groupBy('question_index');
    }

    /**
     * Every question has an answer from both people.
     */
    #[Computed]
    public function bothFinished(): bool
    {
        $prompts = $this->prompts;

        if ($prompts === []) {
            return false;
        }

        return collect($prompts)->keys()->every(fn ($index) => $this->answersByQuestion->get($index, collect())->pluck('responder_id')->unique()->count() >= 2);
    }

    public function saveTextAnswer(int $index): void
    {
        $text = trim($this->textAnswers[$index] ?? '');

        if ($text === '') {
            return;
        }

        $answer = PartnerSessionAnswer::updateOrCreate(
            [
                'partner_session_id' => $this->session->id,
                'question_index' => $index,
                'responder_id' => auth()->id(),
            ],
            ['type' => PartnerSessionAnswer::TYPE_TEXT, 'body' => $text]
        );

        $this->notifyPartnerIfNew($answer);

        unset($this->answersByQuestion);
    }

    /**
     * Only the FIRST answer to a given question counts — an edit to an
     * already-answered question (updateOrCreate's update path) never
     * re-fires, so fixing a typo doesn't spam them. And a run of answers
     * collapses into one alert per session: while the partner still has an
     * unread one for this session, a new answer just bumps it to the top
     * (the same one-row-per-burst rule the chat uses).
     */
    private function notifyPartnerIfNew(PartnerSessionAnswer $answer): void
    {
        if (! $answer->wasRecentlyCreated) {
            return;
        }

        $existing = $this->partner->unreadNotifications()
            ->where('type', PartnerAnswerReceived::class)
            ->where('data->url', route('partner-sessions.show', $this->session))
            ->first();

        if ($existing) {
            $existing->forceFill(['created_at' => now()])->save();

            return;
        }

        $this->partner->notify(new PartnerAnswerReceived($this->session, auth()->user()));
    }

    /**
     * Same "record, auto-upload, transcribe" pattern as every other step —
     * the transcript becomes the saved answer text (and stays editable
     * afterward via the same text field/Save button, in case it's not
     * quite right).
     */
    public function saveVoiceAnswer(int $index): void
    {
        if (! $this->voiceAnswer) {
            return;
        }

        $path = $this->voiceAnswer->store('partner-sessions/'.auth()->id(), 'local');

        try {
            $text = trim(app(GroqClient::class)->transcribe($this->voiceAnswer->getRealPath()));
        } catch (\Throwable) {
            $text = '';
        }

        $body = $text !== '' ? $text : 'Voice answer';

        $answer = PartnerSessionAnswer::updateOrCreate(
            [
                'partner_session_id' => $this->session->id,
                'question_index' => $index,
                'responder_id' => auth()->id(),
            ],
            [
                'type' => PartnerSessionAnswer::TYPE_VOICE,
                'body' => $body,
                'attachment_path' => $path,
                'attachment_name' => 'answer.webm',
                'attachment_mime' => $this->voiceAnswer->getMimeType(),
            ]
        );

        $this->notifyPartnerIfNew($answer);

        $this->textAnswers[$index] = $body;
        $this->voiceAnswer = null;
        unset($this->answersByQuestion);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('missions.show', $session->mission) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        {{ $session->mission->title }}
    </a>

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Partner Session</p>
        <div class="flex items-start justify-between gap-3">
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">You &amp; {{ $this->partner->name }}</h1>
            <a
                href="{{ route('friends.conversation', $this->partner) }}"
                wire:navigate
                class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full border border-line px-3 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >
                @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')
                Message {{ $this->partner->name }}
            </a>
        </div>
        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Go through every question together — answer in your own words, whenever you're both around. You'll see {{ $this->partner->name }}'s answer once you've given yours. Nothing here affects either of your missions.</p>
    </div>

    <div wire:poll.5s="$refresh" class="space-y-4">
        @forelse ($this->prompts as $index => $prompt)
            @php
                $mine = $this->answersByQuestion->get($index, collect())->firstWhere('responder_id', auth()->id());
                $partnerAnswer = $this->answersByQuestion->get($index, collect())->firstWhere('responder_id', $this->partner->id);
                $isSaved = $mine && trim($textAnswers[$index] ?? '') === $mine->body;
            @endphp
            <div class="card p-4" wire:key="question-{{ $index }}">
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Question {{ $index + 1 }} of {{ count($this->prompts) }}</p>
                <div class="mt-1 flex items-start justify-between gap-2">
                    <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $prompt }}</p>
                    <x-speak-button :text="$prompt" />
                </div>

                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Your answer</p>
                        <form wire:submit="saveTextAnswer({{ $index }})" class="mt-1 flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="textAnswers.{{ $index }}"
                                aria-label="Your answer to question {{ $index + 1 }}"
                                placeholder="Write your answer…"
                                wire:loading.attr="disabled"
                                wire:target="saveTextAnswer({{ $index }}),saveVoiceAnswer({{ $index }})"
                                class="w-full rounded-lg border border-line bg-transparent px-2 py-2 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                            >
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="saveTextAnswer({{ $index }}),saveVoiceAnswer({{ $index }})"
                                class="h-10 shrink-0 cursor-pointer rounded-full border border-line px-3 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken disabled:pointer-events-none disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >Save</button>
                        </form>
                        @if ($isSaved)
                            <p class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                            </p>
                        @endif
                        <div class="mt-2" wire:key="recorder-{{ $index }}">
                            <x-voice-recorder
                                field="voiceAnswer"
                                :file="null"
                                on-recorded="saveVoiceAnswer"
                                :on-recorded-param="$index"
                                file-name="answer.webm"
                            />
                        </div>
                        @if ($mine?->type === 'voice')
                            <div class="mt-2">
                                <p class="inline-flex items-center gap-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                                    @svg('heroicon-o-microphone', 'h-3.5 w-3.5') Your recording
                                </p>
                                <x-audio-player :url="route('partner-sessions.attachment', $mine)" />
                            </div>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">{{ $this->partner->name }}'s answer</p>
                        @if ($partnerAnswer && $mine)
                            <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ $partnerAnswer->body }}</p>
                            @if ($partnerAnswer->type === 'voice')
                                <div class="mt-1">
                                    <x-audio-player :url="route('partner-sessions.attachment', $partnerAnswer)" />
                                </div>
                            @endif
                        @elseif ($partnerAnswer)
                            <p class="mt-1 inline-flex items-center gap-1.5 text-sm text-ink-soft dark:text-ink-soft-dark">
                                @svg('heroicon-o-lock-closed', 'h-4 w-4 shrink-0')
                                {{ $this->partner->name }} has answered — give yours to see it.
                            </p>
                        @else
                            <p class="mt-1 text-sm text-ink-faint italic dark:text-ink-faint-dark">Waiting for {{ $this->partner->name }}…</p>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">This step doesn't have practice questions yet.</p>
        @endforelse

        @if ($this->bothFinished)
            <div class="card-accent p-4 text-center">
                <p class="font-display text-lg font-extrabold text-ink dark:text-ink-dark">You both finished! 🎉</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Now go back and forth about your favourite answers — ask {{ $this->partner->name }} a follow-up question.</p>
                <a
                    href="{{ route('friends.conversation', $this->partner) }}"
                    wire:navigate
                    class="mt-3 inline-flex h-10 items-center gap-1.5 rounded-full bg-accent px-4 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')
                    Keep talking in chat
                </a>
            </div>
        @endif
    </div>
</div>
