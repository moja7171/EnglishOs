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

@php
    $prompts = $this->prompts;
    $total = count($prompts);
    $partner = $this->partner;
    // Open on the first question the learner has not answered yet; a learner
    // who has answered everything lands on the last screen.
    $startAt = collect($prompts)->keys()->first(
        fn ($index) => ! $this->answersByQuestion->get($index, collect())->contains('responder_id', auth()->id())
    ) ?? $total;
@endphp

<div class="mx-auto flex min-h-[calc(100dvh-5rem)] max-w-lg flex-col gap-4 px-4 pb-2" x-data="{ q: {{ $startAt }}, total: {{ $total }} }">
    <div data-step-header wire:ignore class="sticky top-0 z-10 -mx-4 bg-ground px-4 pt-3 pb-2 dark:bg-ground-dark">
        <x-lesson-header :close="route('missions.show', $session->mission)" progress-expr="total ? Math.min(q, total) / total : 0" />
        <div class="mt-2 flex items-center justify-center gap-2 text-sm font-extrabold text-ink-soft dark:text-ink-soft-dark">
            <x-user-avatar :user="auth()->user()" class="h-6 w-6 text-[10px]" />
            <span>You &amp; {{ $partner->name }}</span>
            <x-user-avatar :user="$partner" class="h-6 w-6 text-[10px]" />
            <span x-show="q < total" class="text-xs font-bold text-ink-faint tabular-nums dark:text-ink-faint-dark" x-text="(q + 1) + '/' + total"></span>
        </div>
    </div>

    <div wire:poll.5s="$refresh" class="flex flex-1 flex-col">
        @forelse ($prompts as $index => $prompt)
            @php
                $mine = $this->answersByQuestion->get($index, collect())->firstWhere('responder_id', auth()->id());
                $partnerAnswer = $this->answersByQuestion->get($index, collect())->firstWhere('responder_id', $partner->id);
                $isSaved = $mine && trim($textAnswers[$index] ?? '') === $mine->body;
            @endphp
            <section wire:key="question-{{ $index }}" x-show="q === {{ $index }}" @if ($index !== $startAt) x-cloak @endif class="flex flex-1 flex-col gap-4">
                <div class="flex items-start justify-center gap-2 pt-2 text-center">
                    <p class="font-display lesson-title text-ink dark:text-ink-dark">{{ $prompt }}</p>
                    <x-speak-button :text="$prompt" />
                </div>

                <div class="space-y-2">
                    <form wire:submit="saveTextAnswer({{ $index }})" class="space-y-2">
                        <textarea
                            wire:model="textAnswers.{{ $index }}"
                            rows="3"
                            aria-label="Your answer to question {{ $index + 1 }}"
                            placeholder="Write your answer…"
                            wire:loading.attr="disabled"
                            wire:target="saveTextAnswer({{ $index }}),saveVoiceAnswer({{ $index }})"
                            class="block w-full resize-none rounded-2xl border-2 border-line bg-surface px-4 py-3 text-base leading-relaxed text-ink focus:border-accent focus:ring-0 focus:outline-none disabled:opacity-50 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark"
                        ></textarea>
                        <div class="flex items-center justify-between gap-3">
                            @if ($isSaved)
                                <p class="inline-flex items-center gap-1 text-sm font-bold text-success dark:text-success-dark">
                                    @svg('heroicon-o-check-circle', 'h-4 w-4') Saved
                                </p>
                            @else
                                <span></span>
                            @endif
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="saveTextAnswer({{ $index }}),saveVoiceAnswer({{ $index }})"
                                class="cursor-pointer rounded-full bg-accent px-5 py-2 text-sm font-extrabold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                            >Save</button>
                        </div>
                    </form>

                    <div class="flex flex-col items-center gap-2" wire:key="recorder-{{ $index }}">
                        <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">or say it</p>
                        <x-voice-recorder
                            field="voiceAnswer"
                            :file="null"
                            on-recorded="saveVoiceAnswer"
                            :on-recorded-param="$index"
                            file-name="answer.webm"
                        />
                    </div>

                    @if ($mine?->type === 'voice')
                        <div>
                            <p class="inline-flex items-center gap-1 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">
                                @svg('heroicon-o-microphone', 'h-3.5 w-3.5') Your recording
                            </p>
                            <x-audio-player :url="route('partner-sessions.attachment', $mine)" />
                        </div>
                    @endif
                </div>

                {{-- What the partner said: hidden until this learner has answered too. --}}
                @if ($partnerAnswer && $mine)
                    <div class="rounded-2xl bg-success-soft px-4 py-3 dark:bg-success-soft-dark">
                        <p class="text-xs font-extrabold text-success dark:text-success-dark">{{ $partner->name }}</p>
                        <p class="mt-0.5 text-base text-ink dark:text-ink-dark">{{ $partnerAnswer->body }}</p>
                        @if ($partnerAnswer->type === 'voice')
                            <div class="mt-1">
                                <x-audio-player :url="route('partner-sessions.attachment', $partnerAnswer)" />
                            </div>
                        @endif
                    </div>
                @elseif ($partnerAnswer)
                    <p class="flex items-center gap-2 rounded-2xl bg-surface-sunken px-4 py-3 text-sm font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                        @svg('heroicon-o-lock-closed', 'h-4 w-4 shrink-0')
                        {{ $partner->name }} has answered — give yours to see it.
                    </p>
                @else
                    <p class="px-1 text-sm font-semibold text-ink-faint italic dark:text-ink-faint-dark">Waiting for {{ $partner->name }}…</p>
                @endif
            </section>
        @empty
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">This step doesn't have practice questions yet.</p>
        @endforelse

        {{-- The last screen. --}}
        @if ($total > 0)
            <section x-show="q >= total" @if ($startAt < $total) x-cloak @endif class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
                @if ($this->bothFinished)
                    <span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-success-soft text-4xl dark:bg-success-soft-dark">🎉</span>
                    <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">You both finished!</p>
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Ask {{ $partner->name }} a follow-up about their favourite answer.</p>
                    <a
                        href="{{ route('friends.conversation', $partner) }}"
                        wire:navigate
                        class="mt-2 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-accent py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark"
                    >
                        @svg('heroicon-o-chat-bubble-left-right', 'h-5 w-5')
                        Keep talking in chat
                    </a>
                @else
                    <span class="inline-flex h-20 w-20 items-center justify-center rounded-full bg-surface-sunken text-4xl dark:bg-surface-sunken-dark">⏳</span>
                    <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Waiting for {{ $partner->name }}</p>
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">You will see their answers here.</p>
                @endif
                <a
                    href="{{ route('friends.conversation', $partner) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-bold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >
                    @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')
                    Message {{ $partner->name }}
                </a>
            </section>

            <div class="sticky bottom-20 -mx-4 flex gap-2 bg-ground px-4 py-3 dark:bg-ground-dark" x-show="q < total">
                <button type="button" x-show="q > 0" x-on:click="q--" class="cursor-pointer rounded-2xl border-2 border-line px-5 py-3 text-base font-extrabold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Back</button>
                <button type="button" x-on:click="q++" class="flex-1 cursor-pointer rounded-2xl bg-accent py-3 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark" x-text="q === total - 1 ? 'Done' : 'Next'"></button>
            </div>
        @endif
    </div>
</div>
