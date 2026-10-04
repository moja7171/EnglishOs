<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The shared host disables proc_open(), and Schedule::command() launches each
 * task as a child process, so it would fail there. Closures run in-process.
 */
Schedule::call(fn () => Artisan::call('notifications:prune-read'))
    ->name('notifications:prune-read')
    ->daily();
Schedule::call(fn () => Artisan::call('review:send-reminders'))
    ->name('review:send-reminders')
    ->everyMinute()
    ->withoutOverlapping();
