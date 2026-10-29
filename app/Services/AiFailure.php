<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Turns a failed AI call into a line that is safe to show a learner.
 * Provider exceptions carry configuration and HTTP details ("GEMINI_API_KEY
 * is not set.") that mean nothing to a learner, so the real cause goes to
 * the log and the learner gets a plain "try again" instead.
 */
class AiFailure
{
    /**
     * @param  string  $what  What could not be done, e.g. "Couldn't check this one".
     */
    public static function message(Throwable $e, string $what): string
    {
        // The admin's daily cap for this learner, not a failure to report.
        if ($e instanceof AiLimitReached) {
            return "{$what} — you've used today's AI checks. Try again tomorrow.";
        }

        report($e);

        return "{$what} — please try again in a moment.";
    }

    /**
     * The line under a sentence or answer whose AI check could not run.
     * Says what kind of trouble it was (too many checks at once, the
     * connection, today's quota gone) with a short tag, so a report from a
     * learner's phone says which. The page also lets the learner go on
     * without the check, so none of these is a dead end.
     */
    public static function checkMessage(Throwable $e): string
    {
        if ($e instanceof AiLimitReached) {
            return "Couldn't check this one — you've used today's AI checks. You can continue.";
        }

        if (! $e instanceof ConnectionException && ! $e instanceof RequestException) {
            return self::message($e, "Couldn't check this one");
        }

        // RequestException's message carries the raw HTTP response body,
        // never shown to the learner; AiModelChain already logged the cause.
        return match ((new AiModelChain)->classify($e)['kind']) {
            AiModelChain::KIND_RATE_LIMIT => 'Too many checks at once — wait a minute, then try again. (busy)',
            AiModelChain::KIND_DAILY_QUOTA => 'Checking is used up for now — try again later, or continue. (finished)',
            AiModelChain::KIND_BAD_REQUEST => "Checking isn't working right now — you can continue. (setup)",
            default => 'Connection problem — try again in a moment. (connection)',
        };
    }
}
