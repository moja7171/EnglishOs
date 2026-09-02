<?php

use App\Models\DirectMessage;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/register', fn () => view('auth.register'))->name('register');
});

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('home');
    })->name('home');

    Route::get('/missions/{mission:code}/{step?}', function (Mission $mission, ?string $step = null) {
        return view('mission-runner', compact('mission', 'step'));
    })->name('missions.show');

    Route::get('/friends', function () {
        return view('friends');
    })->name('friends.index');

    Route::get('/friends/{user}/conversation', function (User $user) {
        return view('friends-conversation', compact('user'));
    })->name('friends.conversation');

    // The one and only way a DM attachment is ever served — it lives on
    // the private disk, never a public/guessable URL, since it's shared
    // between two specific people, not something the uploader alone owns
    // (unlike a mission recording). Audio plays inline (for <audio src>);
    // everything else forces a real download.
    Route::get('/friends/messages/{message}/attachment', function (DirectMessage $message) {
        abort_unless($message->hasAttachment(), 404);
        abort_unless($message->isAccessibleBy(auth()->user()), 403);

        return $message->type === DirectMessage::TYPE_AUDIO
            ? Storage::disk('local')->response($message->attachment_path)
            : Storage::disk('local')->download($message->attachment_path, $message->attachment_name);
    })->name('friends.attachment');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
});
