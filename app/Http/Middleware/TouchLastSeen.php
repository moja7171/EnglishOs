<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps users.last_seen_at — when the learner last opened the app — for the
 * admin page's "active users" and "last seen". Written at most once every
 * THROTTLE_MINUTES so a busy session isn't a write per request, and without
 * touching updated_at.
 */
class TouchLastSeen
{
    private const THROTTLE_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(self::THROTTLE_MINUTES)))) {
            $user->last_seen_at = now();
            $user->timestamps = false;
            $user->saveQuietly();
            $user->timestamps = true;
        }

        return $next($request);
    }
}
