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

// Echoed, not written to a file — .cpanel.yml's curl call captures
// stdout straight into cPanel's own deployment log, so there's no
// separate deploy.log to remember to clean up.
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
