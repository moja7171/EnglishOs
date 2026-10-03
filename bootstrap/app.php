<?php

use App\Http\Middleware\EnsureSessionNotExpired;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Set from JS (the browser's IANA zone) so times can be shown in the
        // learner's own timezone; it holds nothing sensitive, so skip encryption.
        $middleware->encryptCookies(except: ['eos_tz']);

        $middleware->alias([
            'session.absolute_timeout' => EnsureSessionNotExpired::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
