<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Most cPanel/shared MySQL hosts still default to a 767-byte InnoDB
        // index prefix limit. A default varchar(255) unique/index column
        // under utf8mb4 (4 bytes/char) needs 1020 bytes and fails migration
        // with "Specified key too long" — capping the default length at 191
        // (191*4=764) is the standard Laravel fix and is a no-op on Postgres.
        Schema::defaultStringLength(191);
    }
}
