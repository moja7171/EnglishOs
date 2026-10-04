<?php

use App\Models\DirectMessage;
use App\Models\InstructorMessage;
use App\Models\Mission;
use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
use App\Models\User;
use App\Notifications\PartnerSessionStarted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/register', fn () => view('auth.register'))->name('register');
});

Route::middleware(['auth', 'session.absolute_timeout'])->group(function () {
    Route::get('/', function () {
        // One-time gate before a learner's first mission: send them to set
        // up their 3 persistent Pi chats first (see App\Services\PiPrompts
        // and /pi-setup). Existing users are grandfathered by the
        // pi_onboarded_at migration's backfill, so this only ever catches
        // someone truly new — though anyone can still revisit /pi-setup
        // later from the account menu to re-copy the setup messages.
        if (! auth()->user()->pi_onboarded_at) {
            return redirect()->route('pi.setup');
        }

        return view('home');
    })->name('home');

    Route::get('/pi-setup', function () {
        return view('pi-setup');
    })->name('pi.setup');

    Route::get('/missions/{mission:code}/{step?}', function (Mission $mission, ?string $step = null) {
        return view('mission-runner', compact('mission', 'step'));
    })->name('missions.show');

    Route::get('/profile', function () {
        return view('profile');
    })->name('profile');

    Route::get('/progress', function () {
        return view('progress');
    })->name('progress.index');

    // /program and /guide used to be two separate, divergent "how this
    // works" pages (an English roadmap doc and a Persian FAQ/orientation
    // doc) reachable from different parts of the app with no link between
    // them. Merged into one page at /guide; /program redirects here for
    // anyone with the old link bookmarked.
    Route::redirect('/program', '/guide', 301)->name('program.guide');

    Route::get('/guide', function () {
        return view('learner-guide');
    })->name('learner.guide');

    // The daily listening picks. No parameters = the learner's own current
    // program day; /listening/M03/2 opens a specific day (days ahead of the
    // learner are bounced back to today by the component, not here, since
    // only it knows the learner's progress).
    Route::get('/listening/{missionCode?}/{day?}', function (?string $missionCode = null, ?int $day = null) {
        return view('listening', compact('missionCode', 'day'));
    })->where(['missionCode' => 'M(0[1-9]|1[0-9]|2[0-4])', 'day' => '[1-4]'])->name('listening.show');

    // The daily voice practice with Pi, outside the app — same shape as
    // /listening: no parameters = the day the learner is pointed at today,
    // /pi/M03/2 opens a specific day (days ahead of the learner are bounced
    // back by the component, which alone knows their progress).
    Route::get('/pi/{missionCode?}/{day?}', function (?string $missionCode = null, ?int $day = null) {
        return view('pi-practice', compact('missionCode', 'day'));
    })->where(['missionCode' => 'M(0[1-9]|1[0-9]|2[0-4])', 'day' => '[1-4]'])->name('pi.practice');

    Route::get('/placement', function () {
        return view('placement');
    })->name('placement');

    Route::get('/checkpoint/{mission:code}', function (Mission $mission) {
        return view('checkpoint', compact('mission'));
    })->name('missions.checkpoint');

    Route::get('/vocabulary', function () {
        return view('vocabulary');
    })->name('vocabulary.index');

    Route::get('/speaking', function () {
        return view('speaking');
    })->name('speaking.index');

    Route::get('/review', function () {
        return view('review');
    })->name('review.index');

    Route::get('/friends', function () {
        return view('friends');
    })->name('friends.index');

    Route::get('/friends/board', function () {
        return view('friends-board');
    })->name('friends.board');

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

        // The partner has no other way to learn a session exists until the
        // first answer lands, so starting one is the invite. Only a brand-new
        // session notifies — revisiting it never re-fires.
        if ($session->wasRecentlyCreated) {
            $friend->notify(new PartnerSessionStarted($session, auth()->user()));
        }

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
