<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's session cookie is a sliding window (StartSession resets its
 * Max-Age on every response), so a learner who visits at least once
 * within session.lifetime never actually hits it — in practice that made
 * accounts stay signed in indefinitely, since a daily-habit app is used
 * more often than that. This adds a real, activity-independent cap: a
 * fresh login is required every 30 days no matter how often the account
 * is used in between.
 */
class EnsureSessionNotExpired
{
    private const MAX_DAYS = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $loginAt = $request->session()->get('auth_login_at');

        if ($loginAt === null) {
            // Pre-existing session from before this check existed — start
            // its 30-day clock now rather than leaving it uncapped forever.
            $request->session()->put('auth_login_at', now()->timestamp);
        } elseif ((now()->timestamp - $loginAt) > self::MAX_DAYS * 86400) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', "You've been signed out after 30 days — please sign in again.");
        }

        return $next($request);
    }
}
