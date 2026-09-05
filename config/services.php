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

];
