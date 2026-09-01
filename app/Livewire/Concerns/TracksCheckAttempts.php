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
 */
trait TracksCheckAttempts
{
    /** @var array<int|string, int> keyed by field key — consecutive failed check attempts */
    public array $checkAttempts = [];

    /** @var array<int|string, bool> keyed by field key — true once the reveal offer should show */
    public array $offerReveal = [];

    /**
     * Call after every AI (or locally judged) check verdict for a field.
     */
    protected function trackCheckAttempt(int|string $key, string $severity): void
    {
        if ($severity === 'none') {
            $this->clearCheckAttempt($key);

            return;
        }

        $this->checkAttempts[$key] = ($this->checkAttempts[$key] ?? 0) + 1;

        if ($this->checkAttempts[$key] >= 3) {
            $this->offerReveal[$key] = true;
        }
    }

    protected function clearCheckAttempt(int|string $key): void
    {
        unset($this->checkAttempts[$key], $this->offerReveal[$key]);
    }

    /**
     * Declining doesn't end the offer forever — it resets the count so the
     * same question comes back after 3 more failed attempts, decided fresh
     * by the learner each time, not just once.
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
            $onCorrected($corrected);
            $this->clearCheckAttempt($key);
        } catch (ConnectionException|RequestException) {
            $this->checkErrors[$errorBagKey] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$errorBagKey] = "Couldn't check this one: {$e->getMessage()}";
        }
    }
}
