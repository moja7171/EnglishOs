<?php

// Permanent, but locked down (see the guard below) — triggered
// automatically by .cpanel.yml's deployment task via `curl` right after
// every file copy, so a real database change (migration/seed) needs no
// manual browser visit or temporary file upload anymore. Safe to leave
// in place: every command below is idempotent (migrate/seed/cache all
// no-op cleanly on a second run).
//
// Two ways in: the internal curl (REMOTE_ADDR === 127.0.0.1) as before,
// OR a ?token=... matching DEPLOY_TOKEN in .env — added after the
// internal curl path repeatedly could not be confirmed working (a
// canary written right after the REMOTE_ADDR check never appeared
// across several deploys) with no way to see why (no SSH/Terminal on
// this host, cPanel's own UI doesn't surface the deployment task's
// output). The token lets this be triggered directly by visiting the
// URL in a browser instead, which shows the real output right on the
// page — the .cpanel.yml curl call itself never sends one, so that path
// is completely unaffected. Read straight out of .env with a raw parse
// since this all happens before Laravel (and its env() helper) boots.
$deployToken = null;
$envPath = dirname(__DIR__).'/.env';
if (is_file($envPath)) {
    foreach (file($envPath) as $line) {
        if (preg_match('/^DEPLOY_TOKEN=(.*)$/', trim($line), $m)) {
            $deployToken = trim($m[1], " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

$isLocal = ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1';
$hasValidToken = $deployToken && hash_equals($deployToken, $_GET['token'] ?? '');

if (! $isLocal && ! $hasValidToken) {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

function chmodRecursive(string $path, int $perm): void
{
    @chmod($path, $perm);

    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        chmodRecursive($path.'/'.$item, $perm);
    }
}

echo '=== '.date('Y-m-d H:i:s')." ===\n";

$root = dirname(__DIR__);

chmodRecursive($root.'/storage', 0775);
chmodRecursive($root.'/bootstrap/cache', 0775);
echo "chmod'd storage/ and bootstrap/cache/ to 775\n\n";

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

foreach (['storage:link', 'migrate --force', 'db:seed --force'] as $command) {
    echo "--- php artisan $command ---\n";

    try {
        Illuminate\Support\Facades\Artisan::call($command);
        echo Illuminate\Support\Facades\Artisan::output()."\n";
    } catch (Throwable $e) {
        echo 'EXCEPTION: '.get_class($e).': '.$e->getMessage()."\n";
        echo $e->getTraceAsString()."\n\n";
    }
}

// Belt-and-suspenders: some existing rows ended up with a genuine NULL
// is_admin despite the migration declaring NOT NULL DEFAULT 0 (seen on
// production for userId=1, cause not fully understood) — normalize
// those before anything reads the column. Idempotent, no-op once clean.
echo "--- normalize null is_admin ---\n";
$nullAdminsFixed = Illuminate\Support\Facades\DB::table('users')->whereNull('is_admin')->update(['is_admin' => false]);
echo "fixed {$nullAdminsFixed} row(s) with null is_admin\n\n";

// One-time cleanup: these 3 vocab-flashcard images were cached under
// the OLD orientation=square Pexels search, which returned unrelated
// photos for these specific action-verb queries (ironing, cleaner,
// housework — see PexelsClient::imageUrlFor()'s orientation=null
// change). storage/app/public isn't part of the git deploy, so the
// wrong files would otherwise survive here forever; deleting them
// lets the next page view refetch correctly. Safe to leave in this
// script permanently — a no-op once the files are gone.
echo "--- clear stale wrong vocab images ---\n";
foreach (['ironing', 'cleaner', 'housework'] as $word) {
    $path = $root.'/storage/app/public/vocabulary-images/'.$word.'.jpg';
    if (file_exists($path)) {
        unlink($path);
        echo "deleted {$word}.jpg\n";
    }
}
echo "\n";

// Ensures the one admin account exists (and its password matches
// ADMIN_PASSWORD) on every deploy — idempotent. The real password
// lives only in .env (never committed — this repo is public on
// GitHub), with a fixed fallback so this still works before that
// variable is ever set. Deliberately BEFORE config:cache below — once
// config is cached, raw env() calls for anything not baked into a
// config/*.php file return null instead of reading .env directly.
echo "--- ensure admin account ---\n";
$password = env('ADMIN_PASSWORD', 'EnglishOsAdmin2026!');
$admin = App\Models\User::updateOrCreate(
    ['email' => 'admin@englishos.local'],
    ['name' => 'Admin', 'email_verified_at' => now(), 'cefr_level' => 'B1', 'password' => $password]
);
$admin->is_admin = true;
$admin->save();
echo "admin@englishos.local ensured, password synced from ADMIN_PASSWORD.\n";

// TEMPORARY diagnostic for the AI relay — Sage fails with a generic
// "Couldn't reach Sage" on the live site and ask-instructor.blade.php's
// catch(ConnectionException|RequestException) swallows the real reason
// without logging it. Visiting this page directly (with ?token=...) now
// shows this echo right in the browser response, so no more file-writing
// workarounds needed. Remove once diagnosed.
echo "--- AI proxy relay diagnostic ---\n";
echo 'AI_PROXY_URL: '.(env('AI_PROXY_URL') ?: '(not set)')."\n";
echo 'AI_PROXY_SECRET set: '.(env('AI_PROXY_SECRET') ? 'yes' : 'no')."\n";
try {
    $diagClient = new App\Services\GeminiClient();
    $diagResult = $diagClient->chat([['role' => 'user', 'text' => 'Reply with exactly the word: DIAGOK']]);
    echo "Gemini relay test SUCCESS: {$diagResult}\n\n";
} catch (Throwable $e) {
    echo 'Gemini relay test FAILED: '.get_class($e).': '.$e->getMessage()."\n";
    echo $e->getTraceAsString()."\n\n";
}

foreach (['config:cache', 'route:cache', 'view:cache'] as $command) {
    echo "--- php artisan $command ---\n";

    try {
        Illuminate\Support\Facades\Artisan::call($command);
        echo Illuminate\Support\Facades\Artisan::output()."\n";
    } catch (Throwable $e) {
        echo 'EXCEPTION: '.get_class($e).': '.$e->getMessage()."\n";
        echo $e->getTraceAsString()."\n\n";
    }
}

echo "=== done ===\n";
