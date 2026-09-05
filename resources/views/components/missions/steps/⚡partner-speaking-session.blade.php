<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\PartnerSession;
use App\Models\User;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * A real spoken conversation about people the learner knows — with an
 * actual friend if one's available (reusing the existing PartnerSession
 * system verbatim, see <x-practice-session-with-friend>), or alone if not.
 * "Do this solo instead" is ALWAYS visible on the choice screen, never
 * hidden behind a friend-availability check — Evidence Before Progress
 * (Article 3) means a learner must never be blocked waiting on a friend to
 * be available, let alone to actually answer.
 *
 * Both paths require every prompt across all 3 round_groups before
 * completing (the same full-requirement model as AI Conversation #2's
 * Final Challenge, not Video Shadowing's partial-threshold one) — this is
 * a conversation-practice step, closer in spirit to that than to a
 * shadowing drill.
 */
new class extends Component
{
    use TracksAiUsage;
    use WithFileUploads;

    public const STEP_KEY = 'partner_speaking_session';

    public MissionRun $run;

    public bool $readOnly = false;

    /** @var 'choice'|'partner'|'solo' */
    public string $mode = 'choice';

    public ?PartnerSession $session = null;

    /** @var array<int, ?UploadedFile> keyed by round_groups index (0-2) */
    public array $soloRecordings = [];

    /** @var array<int, string> keyed by round_groups index — Groq transcript, empty if transcription failed or hasn't run */
    public array $soloTranscripts = [];

    /** @var array<int, list<array{text: string, confidence: string}>> keyed by round_groups index */
    public array $soloSegments = [];

    /** @var array<int, string> keyed by round_groups index — saved recording URLs, for read-only review */
    public array $savedSoloAudioUrls = [];

    /**
     * True once Continue has passed every check and Evidence is saved (or,
     * read-only, once this run's saved Evidence was loaded) — the step then
     * shows a recap before the learner navigates on, same pattern as every
     * other AI-enriched step.
     */
    public bool $completed = false;

    public function mount(): void
    {
        if ($this->readOnly) {
            $data = json_decode($this->run->latestEvidence(self::STEP_KEY)?->content_ref ?? '{}', true);
            $this->mode = ($data['mode'] ?? 'solo') === 'partner' ? 'partner' : 'solo';

            if ($this->mode === 'solo') {
                $this->soloTranscripts = $data['transcripts'] ?? [];
                $this->soloSegments = $data['segments'] ?? [];

                foreach ($this->run->evidence()->where('phase', self::STEP_KEY)->where('type', Evidence::TYPE_AUDIO)->get() as $audio) {
                    $decoded = json_decode($audio->content_ref, true);

                    if (is_array($decoded) && isset($decoded['round_index'], $decoded['url'])) {
                        $this->savedSoloAudioUrls[$decoded['round_index']] = $decoded['url'];
                    }
                }
            } else {
                $this->session = PartnerSession::find($data['partner_session_id'] ?? null);
            }

            $this->completed = true;

            return;
        }

        // Not readOnly means no Evidence exists yet for this step — if a
        // shared session already exists (the learner picked a friend from
        // the choice screen earlier, went and answered some questions
        // there, and has now come back here), land straight in partner
        // mode instead of showing the choice screen again.
        $this->session = PartnerSession::findFor($this->run->mission, self::STEP_KEY, $this->run->learner);

        if ($this->session) {
            $this->mode = 'partner';
        }
    }

    /**
     * Every question across all 3 round_groups, flattened in order — see
     * Mission::conversationPrompts()'s round_groups arm. Used both for the
     * partner path's "answered X of Y" total and (via count()) for knowing
     * when the current learner's own answers are complete.
     *
     * @return list<string>
     */
    #[Computed]
    public function prompts(): array
    {
        return $this->run->mission->conversationPrompts(self::STEP_KEY);
    }

    /**
     * @return list<array{label: string, questions: list<string>}>
     */
    public function roundGroups(): array
    {
        return $this->run->mission->stepContent(self::STEP_KEY)['round_groups'] ?? [];
    }

    /**
     * Never persisted — just switches this visit's local UI state, so a
     * learner is never blocked on a friend being around or answering (see
     * this component's own docblock). Available both from the initial
     * choice screen and from the 48h-stale banner below.
     */
    public function chooseSolo(): void
    {
        $this->mode = 'solo';
    }

    public function soloRecordedCount(): int
    {
        return collect($this->soloRecordings)->filter()->count();
    }

    #[Computed]
    public function partner(): ?User
    {
        return $this->session?->partnerFor($this->run->learner);
    }

    /**
     * Deliberately counts only the CURRENT learner's own answers — never
     * the partner's — so completion is never gated on someone else (see
     * syncPartnerCompletion() and this component's own docblock).
     */
    public function myAnsweredCount(): int
    {
        if (! $this->session) {
            return 0;
        }

        return $this->session->answers()->where('responder_id', $this->run->learner_id)->count();
    }

    /**
     * True once the partner has gone quiet for 48h+ (or never answered
     * anything at all since the session started) — offers switching to
     * solo practice instead of leaving the learner stuck waiting. abs()
     * matters here: Carbon 3's diffInHours() returns a signed value by
     * default (negative when the argument is in the past), unlike Carbon
     * 2's always-absolute default — see the project's own diffInDays
     * gotcha note.
     */
    public function isPartnerStale(): bool
    {
        if (! $this->session || ! $this->partner) {
            return false;
        }

        return abs(now()->diffInHours($this->session->partnerLastActivityAt($this->partner))) >= 48;
    }

    /**
     * Polled every 10s while in partner mode (see the Blade template's
     * wire:poll) — writes real Evidence the moment the CURRENT learner has
     * answered every prompt across all 3 round_groups. Never checks the
     * partner's own answers at all: Evidence Before Progress (Article 3)
     * means a learner must never be blocked waiting on someone else, even
     * their own practice partner.
     */
    public function syncPartnerCompletion(): void
    {
        if (! $this->session || $this->readOnly || $this->run->latestEvidence(self::STEP_KEY)) {
            return;
        }

        $total = count($this->prompts);
        $answered = $this->myAnsweredCount();

        if ($total === 0 || $answered < $total) {
            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => self::STEP_KEY,
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'mode' => 'partner',
                'partner_session_id' => $this->session->id,
                'answered_count' => $answered,
                'total' => $total,
            ]),
        ]);

        $this->completed = true;
    }

    /**
     * One TEXT metadata Evidence (transcripts/segments for all 3 rounds)
     * plus one AUDIO Evidence per round, content_ref JSON-tagged with the
     * round index — modeled directly on Video Shadowing's multi-recording
     * pattern. The recording itself is the real, required artifact; a
     * failed transcription never blocks it (see the try/catch below), same
     * as every other AI-enriched step.
     */
    public function saveSolo(): void
    {
        if ($this->soloRecordedCount() < 3) {
            $this->addError('soloRecordings', 'Record all 3 rounds before continuing.');

            return;
        }

        $mission = $this->run->mission;

        foreach ($this->soloRecordings as $index => $recording) {
            if (! $recording) {
                continue;
            }

            $path = $recording->store('missions/'.strtolower($mission->code).'/evidence', 'public');
            $url = Storage::disk('public')->url($path);

            try {
                $result = app(GroqClient::class)->transcribeWithConfidence($recording->getRealPath());
                $this->recordGroqCall();
                $this->soloTranscripts[$index] = trim($result['text']);
                $this->soloSegments[$index] = $result['segments'];
            } catch (Throwable) {
                // Silent by design — see method docblock.
            }

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => self::STEP_KEY,
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => json_encode(['round_index' => $index, 'url' => $url]),
            ]);

            $this->savedSoloAudioUrls[$index] = $url;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => self::STEP_KEY,
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'mode' => 'solo',
                'transcripts' => $this->soloTranscripts,
                'segments' => $this->soloSegments,
            ]),
        ]);

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:".self::STEP_KEY.':';
    }
};
?>

@php
    // A bare `self::STEP_KEY` isn't reachable down here in the Blade
    // portion (same constraint as video-shadowing.blade.php's
    // requiredShadowedLines() wrapper) — the literal string is the
    // established convention every other step's Blade section already
    // uses for its own phase key.
    $content = $run->mission->stepContent('partner_speaking_session');
    $roundGroups = $this->roundGroups();
@endphp

<div class="space-y-6">
    <x-hook :text="$content['hook'] ?? null" />

    @if ($completed || $readOnly)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Partner Speaking Session complete
            </p>

            @if ($mode === 'partner')
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                    @if ($this->partner)
                        You and {{ $this->partner->name }} went through every question together.
                    @else
                        You went through every question with a practice partner.
                    @endif
                </p>
            @else
                <div class="space-y-3">
                    @foreach ($roundGroups as $index => $group)
                        <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                            <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">{{ $group['label'] ?? 'Round '.($index + 1) }}</p>
                            @if ($url = $savedSoloAudioUrls[$index] ?? null)
                                <div class="mt-2"><x-audio-player :url="$url" /></div>
                            @endif
                            @if ($transcript = $soloTranscripts[$index] ?? null)
                                <div class="mt-2">
                                    <x-confidence-transcript :segments="$soloSegments[$index] ?? []" :fallback="$transcript" />
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @unless ($readOnly)
                <button
                    wire:click="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >
                    Continue
                </button>
            @endunless
        </div>
    @else
        @if (count($roundGroups))
            <div class="flex flex-wrap gap-1.5">
                @foreach ($roundGroups as $group)
                    <span class="rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $group['label'] ?? '' }}</span>
                @endforeach
            </div>
        @endif

        @if ($mode === 'choice')
            <div class="space-y-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">How do you want to practice this?</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Talk it through with a real friend, or record your own answers alone — either way counts.</p>
                <div class="flex flex-wrap items-center gap-2">
                    <x-practice-session-with-friend :mission="$run->mission" step-key="partner_speaking_session" />
                    <button
                        type="button"
                        wire:click="chooseSolo"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                    >
                        @svg('heroicon-o-microphone', 'h-3.5 w-3.5')
                        Do this solo instead
                    </button>
                </div>
            </div>
        @elseif ($mode === 'partner')
            <div wire:poll.10s="syncPartnerCompletion" class="space-y-3">
                <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your progress</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                        You've answered {{ $this->myAnsweredCount() }} of {{ count($this->prompts) }} questions
                        @if ($this->partner) with {{ $this->partner->name }} @endif.
                    </p>
                    <div class="mt-2">
                        <x-progress-bar>
                            <div
                                class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                                style="width: {{ count($this->prompts) ? min(100, (int) round($this->myAnsweredCount() / count($this->prompts) * 100)) : 0 }}%"
                            ></div>
                        </x-progress-bar>
                    </div>
                    @if ($session)
                        <a
                            href="{{ route('partner-sessions.show', $session) }}"
                            wire:navigate
                            class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                        >
                            @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')
                            Go answer with {{ $this->partner?->name ?? 'your partner' }}
                        </a>
                    @endif
                </div>

                @if ($this->isPartnerStale())
                    <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30">
                        <p class="text-sm text-ink dark:text-ink-dark">
                            {{ $this->partner?->name ?? 'Your partner' }} hasn't answered anything here in a while.
                            You don't have to wait — switch to solo and finish this on your own.
                        </p>
                        <button
                            type="button"
                            wire:click="chooseSolo"
                            class="mt-2 cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                        >Switch to solo</button>
                    </div>
                @endif
            </div>
        @elseif ($mode === 'solo')
            <div wire:loading.class="pointer-events-none" wire:target="saveSolo">
                <div class="space-y-3">
                    @foreach ($roundGroups as $index => $group)
                        <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark" wire:key="solo-round-{{ $index }}">
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $group['label'] ?? 'Round '.($index + 1) }}</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($group['questions'] ?? [] as $question)
                                    <li class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $question }}</li>
                                @endforeach
                            </ul>

                            <div class="mt-2" wire:key="solo-recorder-{{ $index }}">
                                <x-voice-recorder field="soloRecordings.{{ $index }}" :file="$soloRecordings[$index] ?? null" file-name="partner-speaking-solo-{{ $index }}.webm" />
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('soloRecordings')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- The recording itself can only be confirmed server-side (an
                 upload needs a real round-trip either way), so this gates
                 via a plain @if, same pattern as Video Shadowing/Activation. --}}
            @if ($this->soloRecordedCount() >= 3)
                <div class="mt-4">
                    <x-continue-button
                        on-click="$wire.saveSolo()"
                        wire-target="saveSolo"
                        loading-label="Saving your recordings…"
                    />
                </div>
            @endif
        @endif
    @endif
</div>
