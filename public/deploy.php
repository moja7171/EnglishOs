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

// NOTE: there used to be a "clear stale wrong vocab images" block here
// that deleted ironing/cleaner/housework.jpg on every deploy so the next
// page view would refetch them from Pexels. Removed 2026-09-12: the
// Pexels cache is now shipped as real files on this branch (see
// scripts/deploy-push.sh) and production never fetches from Pexels
// itself — so deleting them here just left production with no image at
// all. The corrected images are the ones this branch now carries.

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

// TEMPORARY — the user still couldn't log in with the account above
// despite this block running successfully. A second, freshly-created
// account with its own known password rules out anything specific to
// the FIRST account (stale browser-saved credentials, some quirk tied
// to that one row) — if this one also fails to log in, the problem is
// the login mechanism itself, not that account. Auth::attempt() run
// directly here checks the credentials work at the auth layer,
// independent of the actual login form/session/CSRF. Remove once
// diagnosed.
echo "--- create a second admin account + test Auth::attempt directly ---\n";
$admin2 = App\Models\User::updateOrCreate(
    ['email' => 'admin2@englishos.local'],
    ['name' => 'Admin Two', 'email_verified_at' => now(), 'cefr_level' => 'B1', 'password' => 'EnglishOsAdmin2Fresh!']
);
$admin2->is_admin = true;
$admin2->save();
echo "admin2@englishos.local created/ensured — password: EnglishOsAdmin2Fresh!\n";
foreach ([['admin@englishos.local', $password], ['admin2@englishos.local', 'EnglishOsAdmin2Fresh!']] as [$email, $pw]) {
    $ok = Illuminate\Support\Facades\Auth::attempt(['email' => $email, 'password' => $pw]);
    Illuminate\Support\Facades\Auth::logout();
    echo "Auth::attempt($email, <password>) = ".($ok ? 'TRUE (credentials work)' : 'FALSE (credentials rejected)')."\n";
}

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

// TEMPORARY — user reports the site feels slow on initial page load since
// the last deploy. Pexels caching was ruled out from outside (images/build
// assets already load in 20-160ms over plain curl), but authenticated
// mission-runner pages can't be reached from outside without a production
// session, and there's no SSH/Terminal on this host to check opcache or
// time a real request directly. Placed AFTER config/route/view:cache above
// so the timings below reflect exactly what a real visitor gets post-deploy,
// not a cold, uncached state. Remove once diagnosed.
echo "--- opcache status ---\n";
if (function_exists('opcache_get_status')) {
    $opStatus = opcache_get_status(false);
    echo 'opcache_get_status(): '.($opStatus ? 'enabled' : 'FALSE (opcache extension loaded but disabled/not running)')."\n";
    if ($opStatus) {
        echo 'memory used: '.round($opStatus['memory_usage']['used_memory'] / 1024 / 1024, 1).'MB, hit rate: '.round($opStatus['opcache_statistics']['opcache_hit_rate'] ?? 0, 1)."%\n";
    }
    echo 'opcache.enable: '.ini_get('opcache.enable')."\n";
    echo 'opcache.validate_timestamps: '.ini_get('opcache.validate_timestamps')." (if '1', every request stats every cached file on disk — a real cost on slow shared-hosting filesystems)\n";
} else {
    echo "opcache extension NOT loaded at all — every request recompiles the whole framework from source, this alone can be the entire slowdown\n";
}
echo "\n";

echo "--- compiled cache files on disk ---\n";
foreach (['bootstrap/cache/config.php', 'bootstrap/cache/routes-v7.php', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php'] as $cacheFile) {
    $cachePath = $root.'/'.$cacheFile;
    echo $cacheFile.': '.(is_file($cachePath) ? 'EXISTS ('.filesize($cachePath).' bytes)' : 'MISSING')."\n";
}
$compiledViews = is_dir(storage_path('framework/views')) ? glob(storage_path('framework/views').'/*.php') : [];
echo 'compiled Blade views cached: '.count($compiledViews)."\n\n";

echo "--- timed authenticated page loads (self-request as admin@englishos.local) ---\n";
try {
    $diagStore = app('session.store');
    $diagStore->start();
    Illuminate\Support\Facades\Auth::guard('web')->login($admin, false);
    $diagStore->save();
    $diagCookieName = config('session.cookie');
    $diagPrefix = Illuminate\Cookie\CookieValuePrefix::create($diagCookieName, app('encrypter')->getKey());
    $diagCookieValue = Illuminate\Support\Facades\Crypt::encrypt($diagPrefix.$diagStore->getId(), false);
    $diagHost = parse_url(config('app.url'), PHP_URL_HOST) ?: 'englishos.growwise.ir';

    foreach (['/missions', '/missions/M02', '/progress'] as $diagPath) {
        $diagStart = microtime(true);
        try {
            $diagResp = Illuminate\Support\Facades\Http::withHeaders(['Cookie' => $diagCookieName.'='.$diagCookieValue])
                ->withoutVerifying()
                ->withOptions(['curl' => [CURLOPT_CONNECT_TO => ["{$diagHost}:443:127.0.0.1:443"]]])
                ->timeout(30)
                ->get("https://{$diagHost}{$diagPath}");
            $diagMs = round((microtime(true) - $diagStart) * 1000);
            echo "{$diagPath} -> HTTP {$diagResp->status()} in {$diagMs}ms (".strlen($diagResp->body())." bytes)\n";
        } catch (Throwable $e) {
            $diagMs = round((microtime(true) - $diagStart) * 1000);
            echo "{$diagPath} -> FAILED after {$diagMs}ms: ".get_class($e).': '.$e->getMessage()."\n";
        }
    }
    Illuminate\Support\Facades\Auth::logout();
} catch (Throwable $e) {
    echo 'self-request diagnostic setup FAILED: '.get_class($e).': '.$e->getMessage()."\n";
}
echo "\n";

echo "=== done ===\n";
