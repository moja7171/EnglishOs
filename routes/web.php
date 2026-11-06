<?php

use App\Http\Controllers\AiDiagnosticController;
use App\Http\Controllers\AiRelayUseController;
use App\Http\Controllers\DictionaryLookupController;
use App\Http\Controllers\DictionaryMeaningPersianController;
use App\Http\Controllers\DictionaryPersianController;
use App\Http\Controllers\ListeningAudioController;
use App\Http\Controllers\ServiceHealthController;
use App\Http\Controllers\VocabularyWordController;
use App\Models\AiPractice;
use App\Models\DirectMessage;
use App\Models\InstructorMessage;
use App\Models\Mission;
use App\Models\PairGame;
use App\Models\PairPracticeSession;
use App\Models\PartnerSession;
use App\Models\PartnerSessionAnswer;
use App\Models\User;
use App\Models\VoiceFeedback;
use App\Notifications\PartnerSessionStarted;
use App\Services\MissionGrid;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Temporary: token-gated report on why the host can't reach Gemini. Remove
// together with AiDiagnosticController once the cause is found.
Route::get('/_diag/ai', AiDiagnosticController::class)->middleware('throttle:6,1');

// Token-gated health report for the cron + phone-alert service (reminders and
// every other push). Same token as above; see ServiceHealthController.
Route::get('/_diag/health', ServiceHealthController::class)->middleware('throttle:6,1');

// Token-gated web wrapper around `php artisan ai:relay-use` for a host with
// no SSH/terminal — the profile page's admin-only "AI Relay" tab is the
// normal way to do this; this route is the fallback if that's unreachable.
Route::get('/_diag/ai-relay-use', AiRelayUseController::class)->middleware('throttle:6,1');

// A learner's invite link. A visitor signs up (or logs in) first and the
// request to that friend follows; someone signed in sees them on Friends.
Route::get('/i/{code}', function (string $code) {
    abort_unless(User::where('invite_code', $code)->exists(), 404);

    if (auth()->check()) {
        return redirect()->route('friends.index', ['invite' => $code]);
    }

    session()->put('friend_invite_code', $code);

    return redirect()->route('register');
})->middleware('throttle:30,1')->name('invite');

Route::middleware('guest')->group(function () {
    Route::get('/welcome', fn () => view('welcome'))->name('welcome');
    Route::get('/login', fn () => view('auth.login'))->name('login');
    Route::get('/register', fn () => view('auth.register'))->name('register');
});

Route::middleware(['auth', 'session.absolute_timeout', 'ai_keys.saved'])->group(function () {
    Route::get('/', function () {
        return view('home');
    })->name('home');

    Route::get('/pi-setup', function () {
        return view('pi-setup');
    })->name('pi.setup');

    // All 24 missions as photo cards (App\Services\MissionGrid), a tab of
    // the bottom nav. An old ?day=M03-2 link (that day's sheet, now gone)
    // opens the mission's own page on that day instead.
    Route::get('/missions', function (MissionGrid $grid) {
        if (preg_match('/^(M(?:0[1-9]|1[0-9]|2[0-4]))-([1-4])$/', (string) request()->query('day'), $match) === 1) {
            return redirect($grid->pageUrl($match[1], (int) $match[2]));
        }

        return view('missions', ['grid' => $grid->for(auth()->user())]);
    })->name('missions.index');

    Route::get('/missions/{mission:code}/{step?}', function (Mission $mission, ?string $step = null) {
        return view('mission-runner', compact('mission', 'step'));
    })->name('missions.show');

    Route::get('/profile', function () {
        return view('profile');
    })->name('profile');

    Route::get('/progress', function () {
        return view('progress');
    })->name('progress.index');

    // A finished mission's recap now lives on the mission's own page.
    Route::get('/progress/{mission:code}', function (Mission $mission) {
        return redirect()->route('missions.show', [$mission, 'overview']);
    })->name('progress.mission');

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
    Route::get('/listening/audio/{missionCode}/{slug}', ListeningAudioController::class)
        ->where(['missionCode' => 'M(0[1-9]|1[0-9]|2[0-4])', 'slug' => '[a-z0-9-]+'])
        ->name('listening.audio');

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

    // Right after sign-up: both AI keys before anything else (see
    // EnsureAiKeysSaved). Nothing left to add = nothing to see here.
    Route::get('/ai-keys', function () {
        return auth()->user()->mustAddAiKeys() ? view('ai-keys-setup') : redirect()->route('home');
    })->withoutMiddleware('ai_keys.saved')->name('ai-keys.setup');

    // After the keys: the three guide cards once, then the placement test.
    Route::get('/onboarding', fn () => view('onboarding'))->name('onboarding');

    Route::get('/placement', function () {
        return view('placement');
    })->name('placement');

    Route::get('/checkpoint/{mission:code}', function (Mission $mission) {
        return view('checkpoint', compact('mission'));
    })->name('missions.checkpoint');

    // My Words is the Notebook's Words tab now (A10).
    Route::get('/vocabulary', fn () => redirect()->route('notebook.index', ['tab' => 'words']))->name('vocabulary.index');

    // The tap-a-word sheet (<x-dictionary-sheet>): look a word up, and add
    // it to My Words. Plain JSON so neither re-renders the text being read.
    Route::get('/dictionary', DictionaryLookupController::class)
        ->middleware('throttle:60,1')
        ->name('dictionary.lookup');

    Route::get('/dictionary/persian', DictionaryPersianController::class)
        ->middleware('throttle:30,1')
        ->name('dictionary.persian');

    Route::get('/dictionary/meaning', DictionaryMeaningPersianController::class)
        ->middleware('throttle:30,1')
        ->name('dictionary.meaning');

    Route::post('/vocabulary', [VocabularyWordController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('vocabulary.store');

    // Speaking Recall's questions come in Daily Review (A9).
    Route::get('/speaking', fn () => redirect()->route('review.index'))->name('speaking.index');

    Route::get('/review', function () {
        return view('review-tracks');
    })->name('review.index');

    Route::get('/review/{track}', function (string $track) {
        return view('review', ['track' => $track]);
    })->whereIn('track', array_keys(User::REVIEW_TRACKS))->name('review.track');

    Route::get('/notebook', function () {
        return view('notebook');
    })->name('notebook.index');

    // Admins only (404 for everyone else): usage, learners who need a
    // look, step analytics, and one learner's full picture.
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', function () {
            return view('admin');
        })->name('index');

        Route::get('/users/{user}', function (User $user) {
            return view('admin-user', compact('user'));
        })->name('users.show');
    });

    Route::get('/friends', function () {
        return view('friends');
    })->name('friends.index');

    Route::get('/friends/board', function () {
        return view('friends-board');
    })->name('friends.board');

    // Teddy, the AI classmate: not a friend row, just his page in the friends' strip.
    Route::get('/friends/teddy', function () {
        return view('friends-teddy');
    })->name('friends.teddy');

    // A friend's page (or my own): their week, mission days and month,
    // what each day held, and what the two of us played. Mutual friends
    // only; counts and dates, never an answer.
    Route::get('/friends/{user}/page', function (User $user) {
        abort_unless($user->is(auth()->user()) || auth()->user()->canMessageWith($user), 403);

        return view('friends-profile', compact('user'));
    })->name('friends.profile');

    // Pair Practice: two friends, each on their own phone, side by side or
    // on a video call. The list of friends (today's progress, nudge, pair
    // streak) and one round's page; a round belongs to both friends,
    // whoever started it.
    Route::get('/pair', function () {
        return view('pair-practice');
    })->name('pair-practice.index');

    Route::get('/pair/{session}', function (PairPracticeSession $session) {
        abort_unless($session->isAccessibleBy(auth()->user()), 403);

        return view('pair-practice-session', compact('session'));
    })->name('pair-practice.show');

    // Practice with Teddy, the AI classmate, when no friend is around.
    Route::get('/practice-ai/{practice}', function (AiPractice $practice) {
        abort_unless($practice->learner_id === auth()->id(), 403);

        return view('ai-practice', compact('practice'));
    })->name('ai-practice.show');

    // Games: two friends, each on their own phone, 5 or 10 minutes of one
    // game. The photo-tile list, one game's setup (friend + length) and
    // one game being played, which belongs to both friends.
    Route::get('/games', function () {
        return view('games');
    })->name('games.index');

    Route::get('/games/play/{pairGame}', function (PairGame $pairGame) {
        abort_unless($pairGame->isAccessibleBy(auth()->user()), 403);

        return view('games-play', compact('pairGame'));
    })->name('games.play');

    // A learner's own recorded piece from a game or Pair Practice round —
    // private, for its learner only (the "play my voice" on a feedback card).
    Route::get('/voice-feedback/{voiceFeedback}/clips/{clip}', function (VoiceFeedback $voiceFeedback, int $clip) {
        abort_unless($voiceFeedback->learner_id === auth()->id(), 403);

        $path = $voiceFeedback->clips[$clip]['path'] ?? null;

        abort_unless($path && Storage::disk(VoiceFeedback::DISK)->exists($path), 404);

        return Storage::disk(VoiceFeedback::DISK)->response($path);
    })->name('voice-feedback.clip');

    Route::get('/games/{game}', function (string $game) {
        abort_unless(in_array($game, PairGame::PLAYABLE, true), 404);

        return view('games-setup', compact('game'));
    })->name('games.setup');

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

    // The app just went to the background (see app.js): from now on alerts
    // buzz the phone, without waiting for the in-app check to run out.
    Route::post('/app-hidden', function () {
        auth()->user()->markLeftApp();

        return response()->noContent();
    })->withoutMiddleware('ai_keys.saved')->middleware('throttle:60,1')->name('app.hidden');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect('/login');
    })->withoutMiddleware('ai_keys.saved')->name('logout');
});
