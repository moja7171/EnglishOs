<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // AI Instructor LLM (conversation, feedback, error extraction,
    // mission result decisions) — EOS-009 §8.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        // Used only when the primary model's own timeout+retry is exhausted
        // (e.g. the 2026-09-04 outage where the pinned model hung outright
        // while this alias, on the same key, responded fine). A moving
        // "-latest" alias on purpose — uptime over behavioral pinning,
        // since its only job here is "something that works".
        'fallback_model' => env('GEMINI_FALLBACK_MODEL', 'gemini-flash-latest'),
    ],

    // Speech-to-text for recorded Evidence audio — EOS-009 §11.
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'whisper_model' => env('GROQ_WHISPER_MODEL', 'whisper-large-v3-turbo'),
        // Same fallback treatment as gemini.fallback_model above — a
        // different, genuinely available Whisper variant on Groq (verified
        // against Groq's model docs 2026-09-04), not a guessed name.
        'fallback_model' => env('GROQ_FALLBACK_MODEL', 'whisper-large-v3'),
    ],

    'pexels' => [
        // Free tier, instant key approval, generous limits (200 req/hour) —
        // see App\Services\PexelsClient. Optional feature: absent key just
        // means vocabulary flashcards silently skip images, nothing breaks.
        'key' => env('PEXELS_API_KEY'),
    ],

    // Routes GeminiClient/GroqClient/PexelsClient's outbound calls through
    // a small HTTP relay instead of connecting directly — for when the app
    // runs somewhere that can't reach these providers itself (e.g.
    // Iran-based shared hosting, where Gemini/Groq/Pexels are filtered).
    // Not a real forward proxy (that needs the CONNECT method over a raw
    // TCP tunnel — infra that turned out to need paid/verified services);
    // this relay speaks plain HTTP request/response instead, so it works
    // over a simple HTTP tunnel (e.g. a free localhost.run/ngrok HTTP
    // tunnel to the relay script in scripts/ai-relay.py, run on a machine
    // that CAN reach these providers). See
    // App\Services\Concerns\UsesOutboundProxy.
    //
    // Two relay slots are kept side by side — "vps" (a real always-on VPS,
    // scripts/vps-relay-setup.sh) and "local" (a laptop running
    // scripts/ai-relay.js plus a localhost.run tunnel, started by hand) —
    // and AI_PROXY_TARGET picks which one is live. Switch with
    // `php artisan ai:relay-use vps|local` (see App\Console\Commands\
    // AiRelayUse), or the token-gated /_diag/ai-relay-use route on a host
    // with no SSH/terminal. Both slots empty by default: every call
    // connects directly, same as before this existed.
    'ai_proxy' => (static function (): array {
        $target = env('AI_PROXY_TARGET', 'vps') === 'local' ? 'local' : 'vps';

        return [
            'target' => $target,
            'url' => $target === 'local' ? env('AI_PROXY_URL_LOCAL') : env('AI_PROXY_URL_VPS'),
            'secret' => $target === 'local' ? env('AI_PROXY_SECRET_LOCAL') : env('AI_PROXY_SECRET_VPS'),
            // Whether each slot has a URL set, for the profile page's
            // admin-only "AI Relay" tab — read here (not via env() in the
            // view) because env() outside config files returns null once
            // config:cache has run.
            'configured' => [
                'vps' => env('AI_PROXY_URL_VPS') !== null && env('AI_PROXY_URL_VPS') !== '',
                'local' => env('AI_PROXY_URL_LOCAL') !== null && env('AI_PROXY_URL_LOCAL') !== '',
            ],
            // Both slots' raw values regardless of which one is live, for
            // App\Console\Commands\AiRelaySyncLocalUrl: it asks the "vps"
            // relay what the "local" tunnel's current URL is.
            'slots' => [
                'vps' => ['url' => env('AI_PROXY_URL_VPS'), 'secret' => env('AI_PROXY_SECRET_VPS')],
                'local' => ['url' => env('AI_PROXY_URL_LOCAL')],
            ],
        ];
    })(),

    // Gates the temporary /_diag/ai report (App\Http\Controllers\AiDiagnosticController).
    // Empty token = the route 404s.
    'diagnostics' => [
        'token' => env('DEPLOY_TOKEN'),
    ],

];
