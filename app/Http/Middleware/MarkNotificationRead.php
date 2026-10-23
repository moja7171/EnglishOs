<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tapped phone alert opens its page with ?notification={id} (see
 * public/sw.js): that bell row is read now, so the badge doesn't keep
 * asking about something the learner has just opened. Only the signed-in
 * learner's own row can be touched.
 */
class MarkNotificationRead
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->query('notification');
        $user = $request->user();

        if ($user instanceof User && is_string($id) && $id !== '') {
            $user->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);
        }

        return $next($request);
    }
}
