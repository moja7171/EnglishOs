<?php

namespace App\Livewire\Concerns;

use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Shared "3 failed attempts, then offer to write it for them" behavior for
 * every step with an AI-checked sentence field (Vocabulary Builder,
 * Listening, Grammar in Context — see EOS-009 §8). This is the one
 * deliberate exception to SentenceChecker::check()'s "never write the
 * corrected sentence for them" rule: it only fires after the learner has
 * genuinely tried three times on the same field, and only when they
 * explicitly say yes.
 *
 * Every class using this trait is a mission step component and so already
 * has `public MissionRun $run;` in scope — trackCheckAttempt() relies on
 * it to feed MissionRun::recordStruggleSignal() for mid-run tone
 * adaptation (see MissionRun::aiToneGuidance()).
 */
trait TracksCheckAttempts
{
    /** @var array<int|string, int> keyed by field key — consecutive failed check attempts */
    public array $checkAttempts = [];

    /** @var array<int|string, bool> keyed by field key — true once the reveal offer should show */
    public array $offerReveal = [];

    /** Attempts before the reveal is offered, for a learner who is doing fine. */
    public const REVEAL_AFTER_ATTEMPTS = 3;

    /** ...and for one who is visibly struggling — see MissionRun::isStruggling(). */
    public const REVEAL_AFTER_ATTEMPTS_WHEN_STRUGGLING = 2;

    /**
     * Call after every AI (or locally judged) check verdict for a field.
     */
    protected function trackCheckAttempt(int|string $key, string $severity): void
    {
        if ($severity === 'none') {
            $this->clearCheckAttempt($key);

            return;
        }

        if ($severity === 'major') {
            $this->run->recordStruggleSignal();
        }

        $this->checkAttempts[$key] = ($this->checkAttempts[$key] ?? 0) + 1;

        if ($this->checkAttempts[$key] >= $this->revealThreshold()) {
            $this->offerReveal[$key] = true;
        }
    }

    /**
     * Three tries normally, two once the learner is struggling. This is
     * the whole of S2's mechanism and it only ever moves one way: a
     * struggling learner is offered help SOONER, never asked for more.
     * Nothing here changes what the step requires to be complete — the
     * reveal was always available at attempt 3, it just arrives earlier
     * for someone who needs it. See
     * [[project_growth_without_discouragement_stories]] S2.
     */
    public function revealThreshold(): int
    {
        return $this->run->isStruggling()
            ? self::REVEAL_AFTER_ATTEMPTS_WHEN_STRUGGLING
            : self::REVEAL_AFTER_ATTEMPTS;
    }

    /**
     * True on the attempt just before the offer appears, so
     * <x-almost-reveal-notice> can warn the learner it's coming instead
     * of the offer arriving out of nowhere. Lives here rather than as a
     * hardcoded `=== 2` in eight different step views, which is what it
     * was before the threshold could move.
     */
    public function isAlmostRevealing(int|string $key): bool
    {
        return ($this->checkAttempts[$key] ?? 0) === $this->revealThreshold() - 1;
    }

    protected function clearCheckAttempt(int|string $key): void
    {
        unset($this->checkAttempts[$key], $this->offerReveal[$key]);
    }

    /**
     * Declining doesn't end the offer forever — it resets the count so the
     * same question comes back after another full round of failed
     * attempts (see revealThreshold()), decided fresh by the learner each
     * time, not just once.
     */
    public function declineCheckReveal(int|string $key): void
    {
        unset($this->offerReveal[$key]);
        $this->checkAttempts[$key] = 0;
    }

    /**
     * Asks SentenceChecker to directly rewrite the learner's text, hands
     * the result to $onCorrected to store wherever the caller's field
     * lives, and clears this field's attempt count. Errors are reported the
     * same way as SentenceChecker::check() failures elsewhere in the app —
     * a clean message, never the raw HTTP response body.
     */
    protected function revealCorrectionFor(
        int|string $key,
        string $context,
        string $text,
        int|string $errorBagKey,
        callable $onCorrected,
    ): void {
        unset($this->checkErrors[$errorBagKey]);

        try {
            $corrected = app(SentenceChecker::class)->correct($context, $text);
            $this->run->increment('gemini_calls'); // see App\Livewire\Concerns\TracksAiUsage
            $onCorrected($corrected);
            $this->clearCheckAttempt($key);
        } catch (ConnectionException|RequestException) {
            $this->checkErrors[$errorBagKey] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$errorBagKey] = "Couldn't check this one: {$e->getMessage()}";
        }
    }
}
