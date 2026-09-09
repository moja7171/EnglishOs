<?php

// Permanent, but locked down to server-local requests only (see the
// REMOTE_ADDR check below) — triggered automatically by .cpanel.yml's
// deployment task via `curl` right after every file copy, so a real
// database change (migration/seed) needs no manual browser visit or
// temporary file upload anymore. Safe to leave in place: every command
// below is idempotent (migrate/seed/cache all no-op cleanly on a
// second run), and the IP check means no outside visitor can reach it
// no matter how many times this file is deployed.
//
// Can't use a shared-secret token instead (the more common pattern for
// this) because .cpanel.yml lives in a PUBLIC GitHub repo — anything
// written there is visible to anyone, so a "secret" baked in there
// wouldn't be secret at all.

if (($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit('Forbidden');
}

set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

// Buffered so the whole run's output can ALSO be written to a file below
// (cPanel's own UI doesn't surface the deployment task's stdout anywhere
// visible) — still echoed exactly as before, this just adds a second,
// reliably readable copy.
ob_start();

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
// without logging it, so there's nothing in laravel.log to read. This
// makes one real call and echoes the exact exception here instead.
// Remove once diagnosed.
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

$output = ob_get_clean();
echo $output;
file_put_contents($root.'/storage/logs/deploy-output.log', $output);
