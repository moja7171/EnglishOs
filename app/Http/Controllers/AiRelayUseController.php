<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Token-gated web wrapper around `php artisan ai:relay-use` (see
 * App\Console\Commands\AiRelayUse) for a host with no SSH/terminal — e.g.
 * /_diag/ai-relay-use?token=...&target=local.
 */
class AiRelayUseController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $expected = (string) config('services.diagnostics.token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $request->query('token')), 404);

        $target = (string) $request->query('target', '');

        Artisan::call('ai:relay-use', $target !== '' ? ['target' => $target] : []);

        return response(Artisan::output(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
