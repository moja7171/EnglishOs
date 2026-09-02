<?php

use App\Models\DirectMessage;
use App\Models\InstructorMessage;
use App\Models\Mission;
use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
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

    Route::get('/profile', function () {
        return view('profile');
    })->name('profile');

    Route::get('/vocabulary', function () {
        return view('vocabulary');
    })->name('vocabulary.index');

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

    // Same private-disk pattern as friends.attachment above, but gated to
    // the one learner this message belongs to — an Ask the AI Instructor
    // attachment is never shared with anyone else.
    Route::get('/instructor/messages/{message}/attachment', function (InstructorMessage $message) {
        abort_unless($message->hasAttachment(), 404);
        abort_unless($message->isAccessibleBy(auth()->user()), 403);

        return $message->type === InstructorMessage::TYPE_VOICE
            ? Storage::disk('local')->response($message->attachment_path)
            : Storage::disk('local')->download($message->attachment_path, $message->attachment_name);
    })->name('instructor.attachment');

    // Finds (or starts) the one shared Partner Session for this
    // mission+step+pair and sends both friends to the exact same place —
    // order-independent, see PartnerSession::findOrStartFor(). Gated on
    // the same mutual-follow-and-not-blocked check as messaging itself.
    Route::get('/missions/{mission:code}/{step}/practice-with/{friend}', function (Mission $mission, string $step, User $friend) {
        abort_unless(auth()->user()->canMessageWith($friend), 403);

        $session = PartnerSession::findOrStartFor($mission, $step, auth()->user(), $friend);

        return redirect()->route('partner-sessions.show', $session);
    })->name('missions.practice-with-friend');

    Route::get('/practice-sessions/{session}', function (PartnerSession $session) {
        abort_unless($session->isAccessibleBy(auth()->user()), 403);

        return view('partner-session', compact('session'));
    })->name('partner-sessions.show');

    // Same private-disk pattern as friends.attachment/instructor.attachment.
    Route::get('/practice-sessions/answers/{answer}/attachment', function (PartnerSessionAnswer $answer) {
        abort_unless($answer->hasAttachment(), 404);
        abort_unless($answer->isAccessibleBy(auth()->user()), 403);

        return $answer->type === PartnerSessionAnswer::TYPE_VOICE
            ? Storage::disk('local')->response($answer->attachment_path)
            : Storage::disk('local')->download($answer->attachment_path, $answer->attachment_name);
    })->name('partner-sessions.attachment');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/login');
    })->name('logout');
});
