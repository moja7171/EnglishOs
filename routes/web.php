<?php

use App\Models\Mission;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
});

Route::get('/missions/{mission:code}', function (Mission $mission) {
    return view('mission-runner', compact('mission'));
})->name('missions.show');
