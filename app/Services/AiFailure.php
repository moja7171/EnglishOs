<?php

namespace App\Services;

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
}
