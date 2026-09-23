<?php

namespace App\Livewire\Concerns;

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\GroqClient;
use App\Services\PexelsClient;
use App\Services\PiPrompts;
use App\Services\SpokenAnswerChecker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\WithFileUploads;

/**
 * Shared logic for "Listen Again", which opens Day 2/3/4 of a mission —
 * one file per day (⚡daily-listen-2/3/4.blade.php) so each has its own
 * real, distinct step key (Evidence Before Progress requires a FRESH row
 * per day; reusing one key across days would let listening once satisfy
 * every later day too).
 *
 * Mission structure redesign, Epic C: the old "listen once, then type any
 * word you remember" recall was replaced with a small (2-line), mandatory
 * shadowing exercise — the same lenient AI judgment SpokenAnswerChecker's
 * checkShadowing() gives everywhere else, on lines that are never the
 * same ones any other daily-listen day used (see each day's own seeded
 * shadow_lines). Later redesign: Listen Again became this mission's ONLY
 * shadowing practice (Day 1 Listening dropped it entirely — see
 * ⚡listening.blade.php), driven by the real Whisper pause points in
 * shadowTimestamps() below instead of a plain static list + recorder.
 */
trait DailyListenStep
{
    use TracksAiUsage;
    use TracksCheckAttempts;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    public bool $listened = false;

    /** @var array<int, ?UploadedFile> keyed by shadowLines() index */
    public array $shadowRecordings = [];

    /** @var array<int, string> saved recording URLs, for read-only review */
    public array $savedShadowUrls = [];

    /** @var array<string, array{severity: string, hint: string}> keyed by "shadow_{index}" */
    public array $feedback = [];

    /** @var array<string, string> keyed by "shadow_{index}" — per-line check failure message */
    public array $checkErrors = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $this->listened = true;

        foreach ($this->run->evidence()->where('phase', $this->phaseKey())->where('type', Evidence::TYPE_AUDIO)->get() as $audio) {
            $decoded = json_decode($audio->content_ref, true);

            if (is_array($decoded) && isset($decoded['line_index'], $decoded['url'])) {
                $this->savedShadowUrls[$decoded['line_index']] = $decoded['url'];
            }
        }
    }

    /**
     * Called the moment the audio finishes playing once — real completed
     * listens only (the same "audio-ended" signal Day 1's own Listening
     * step uses), not just pressing play. Shadowing only makes sense once
     * the learner has actually heard the line in context first.
     */
    public function markListened(): void
    {
        $this->listened = true;
    }

    /**
     * This day's own small shadow-line pool — distinct from every other
     * daily-listen day's, so the same audio never asks for the exact
     * same line twice across a mission (see each day's own seeded
     * shadow_lines under its own step key).
     *
     * @return list<string>
     */
    public function shadowLines(): array
    {
        return $this->run->mission->stepContent($this->phaseKey())['shadow_lines'] ?? [];
    }

    /**
     * Real Whisper-derived pause points, parallel to shadowLines() by
     * index — see missions:cache-shadow-timestamps. [] entries (or a
     * missing index) mean that line just never pauses playback; shadowing
     * it is still possible manually, it just isn't automatic.
     *
     * @return list<array{start: float, end: float}|null>
     */
    public function shadowTimestamps(): array
    {
        return $this->run->mission->stepContent($this->phaseKey())['shadow_timestamps'] ?? [];
    }

    /**
     * Every real chunk of Day 1's Listening audio (this day's own, reused
     * audio) with real timing — drives the synced text panel. Same cache
     * as shadowTimestamps(), different key.
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    public function listeningSegments(): array
    {
        return $this->listeningContent()['listening_segments'] ?? [];
    }

    public function shadowedCount(): int
    {
        return collect($this->feedback)
            ->filter(fn ($item, $key) => str_starts_with($key, 'shadow_') && $item['severity'] === 'none')
            ->count();
    }

    /**
     * Fires automatically once a shadow recording finishes uploading (see
     * <x-voice-recorder>'s onRecorded).
     */
    public function checkShadowLine(int $index): void
    {
        $recording = $this->shadowRecordings[$index] ?? null;
        $target = $this->shadowLines()[$index] ?? null;

        if (! $recording || ! $target) {
            return;
        }

        $key = "shadow_{$index}";
        unset($this->checkErrors[$key]);

        try {
            $transcript = trim(app(GroqClient::class)->transcribe($recording->getRealPath()));
            $this->recordGroqCall();

            $data = app(SpokenAnswerChecker::class)->checkShadowing(
                strip_tags(str_replace('**', '', $target)),
                $transcript,
                $this->run->learner->levelDescription(),
            );
            $this->recordGeminiCall();

            $this->feedback[$key] = $data;
            $this->trackCheckAttempt($key, $data['severity']);
        } catch (Throwable $e) {
            $this->checkErrors[$key] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    public function save(): void
    {
        $lines = $this->shadowLines();

        if ($this->shadowedCount() < count($lines)) {
            $this->addError('shadowRecordings', 'Shadow every line before continuing.');

            return;
        }

        $mission = $this->run->mission;

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => $this->phaseKey(),
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['listened' => true, 'shadowed_lines' => count($lines)]),
        ]);

        foreach ($this->shadowRecordings as $index => $recording) {
            if (! $recording) {
                continue;
            }

            $path = $recording->store('missions/'.strtolower($mission->code).'/evidence', 'public');
            $url = Storage::disk('public')->url($path);

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => $this->phaseKey(),
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => json_encode(['line_index' => $index, 'url' => $url]),
            ]);

            $this->savedShadowUrls[$index] = $url;
        }

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Reuses Day 1's real Listening content (audio) — this mission only
     * has one real listening episode; the point is repeated exposure to
     * the same audio, not fresh content every day.
     */
    protected function listeningContent(): array
    {
        return $this->run->mission->stepContent('listening');
    }

    public function hook(): ?string
    {
        return $this->run->mission->stepContent($this->phaseKey())['hook'] ?? null;
    }

    /**
     * This day's own cover image — each day gets its own image_query (for
     * visual variety across the 4 listens of the same episode), fetched
     * and cached the same fetch-once principle as every other
     * PexelsClient call. Purely decorative, fails soft (null) on no
     * query/no key/any error.
     */
    public function heroImageUrl(): ?string
    {
        $query = $this->run->mission->stepContent($this->phaseKey())['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-'.$this->phaseKey(), $query);
    }

    /** @return array{instruction: string, prompt: string}|null */
    public function piTask(): ?array
    {
        return app(PiPrompts::class)->coachTask($this->run, $this->phaseKey());
    }

    abstract protected function phaseKey(): string;
}
