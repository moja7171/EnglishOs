<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A learner who still owes their AI keys sees nothing but the keys page,
 * however they got here: after a new sign-in, from a saved link, or by
 * typing an address.
 */
class EnsureAiKeysSaved
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->mustAddAiKeys()) {
            return redirect()->route('ai-keys.setup');
        }

        return $next($request);
    }
}
