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

foreach (['storage:link', 'migrate --force', 'db:seed --force', 'config:cache', 'route:cache', 'view:cache'] as $command) {
    echo "--- php artisan $command ---\n";

    try {
        Illuminate\Support\Facades\Artisan::call($command);
        echo Illuminate\Support\Facades\Artisan::output()."\n";
    } catch (Throwable $e) {
        echo 'EXCEPTION: '.get_class($e).': '.$e->getMessage()."\n";
        echo $e->getTraceAsString()."\n\n";
    }
}

// Ensures the one admin account exists on every deploy — idempotent,
// and deliberately does NOT reset the password on an existing account.
// The password itself can't be hardcoded here (this repo is public on
// GitHub): generated fresh only the first time, printed once to this
// log (private — cPanel's own deployment log, never committed to git).
echo "--- ensure admin account ---\n";
$admin = App\Models\User::where('email', 'admin@englishos.local')->first();

if (! $admin) {
    $password = bin2hex(random_bytes(12));
    $admin = App\Models\User::create([
        'name' => 'Admin',
        'email' => 'admin@englishos.local',
        'password' => $password,
        'email_verified_at' => now(),
        'cefr_level' => 'B1',
    ]);
    echo "Created admin@englishos.local — password (shown once, save it now): {$password}\n";
} else {
    echo "admin@englishos.local already exists, password left untouched.\n";
}

if (! $admin->is_admin) {
    $admin->is_admin = true;
    $admin->save();
    echo "is_admin set to true.\n";
}

echo "=== done ===\n";
