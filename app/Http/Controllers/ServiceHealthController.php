<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\PushTest;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Token-gated "is everything the phone alerts need actually working?" report,
 * for a host with no terminal (see /_diag/health in routes/web.php). Covers the
 * cron, the scheduled tasks, the VAPID keys, the learners' subscriptions and
 * whether this server can reach the browsers' push services — which is what
 * the daily review reminder AND every other phone alert (messages, follow
 * requests, partner sessions, streaks) depend on, since they all go through
 * the same push channel. Add `&send_test=<user id or email>` to push a real
 * test alert through that channel to one learner's devices.
 */
class ServiceHealthController extends Controller
{
    /** @var list<string> */
    private array $problems = [];

    public function __invoke(Request $request): Response
    {
        $expected = (string) config('services.diagnostics.token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $request->query('token')), 404);

        $sections = [
            $this->cron(),
            $this->phpHost(),
            $this->vapid(),
            $this->learners(),
            $this->pushServices(),
            $this->recentPushFailures(),
        ];

        if ($request->filled('send_test')) {
            $sections[] = $this->sendTest((string) $request->query('send_test'));
        }

        $verdict = $this->problems === []
            ? ['RESULT: everything checked looks healthy.']
            : ['RESULT: '.count($this->problems).' problem(s) to fix:', ...array_map(fn (string $problem): string => ' - '.$problem, $this->problems)];

        $lines = ['Service health — '.now()->toDateTimeString().' UTC', '', ...$verdict];

        foreach ($sections as $section) {
            array_push($lines, '', ...$section);
        }

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @return list<string>
     */
    private function cron(): array
    {
        $lines = ['== 1. Cron and scheduled tasks =='];
        $lastRun = Cache::get('scheduler:last-run');

        if (! is_int($lastRun)) {
            $lines[] = $this->fail('Cron heartbeat', 'never seen — cPanel cron is not running `schedule:run`, or the heartbeat code is not deployed yet', 'cron is not firing (no heartbeat ever recorded)');
        } else {
            $secondsAgo = now()->getTimestamp() - $lastRun;
            $detail = Carbon::createFromTimestamp($lastRun)->toDateTimeString().' UTC, '.$secondsAgo.'s ago';

            $lines[] = $secondsAgo <= 150
                ? $this->ok('Cron heartbeat', $detail.' — firing every minute')
                : $this->fail('Cron heartbeat', $detail.' — stale', 'cron stopped or runs less often than every minute');
        }

        $events = app(Schedule::class)->events();
        $names = array_map(fn (Event $event): string => $event->description ?? $event->command ?? '?', $events);

        foreach (['review:send-reminders', 'notifications:prune-read', 'scheduler:heartbeat'] as $expected) {
            $lines[] = in_array($expected, $names, true)
                ? $this->ok('Scheduled task', $expected)
                : $this->fail('Scheduled task', $expected.' is NOT registered (old code deployed?)', "scheduled task {$expected} is missing");
        }

        $shellEvents = array_filter($events, fn (Event $event): bool => ! $event instanceof CallbackEvent);
        $lines[] = $shellEvents === []
            ? $this->ok('Tasks run in-process', 'no shell-launched tasks')
            : $this->fail('Tasks run in-process', count($shellEvents).' task(s) launch a shell and need proc_open', 'a scheduled task uses Schedule::command(), which fails on this host');

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function phpHost(): array
    {
        $lines = ['== 2. PHP host =='];
        $lines[] = 'php '.PHP_VERSION.', '.PHP_SAPI;

        foreach (['openssl', 'curl', 'mbstring'] as $extension) {
            $lines[] = extension_loaded($extension)
                ? $this->ok("ext-{$extension}", 'loaded')
                : $this->fail("ext-{$extension}", 'missing', "PHP extension {$extension} is missing (web push needs it)");
        }

        $lines[] = extension_loaded('gmp') || extension_loaded('bcmath')
            ? $this->ok('ext-gmp or ext-bcmath', 'loaded')
            : $this->warn('ext-gmp or ext-bcmath', 'neither loaded — web push encryption is much slower without one');

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
        $lines[] = in_array('proc_open', $disabled, true)
            ? $this->ok('proc_open', 'disabled on this host (expected) — tasks must stay in-process')
            : $this->ok('proc_open', 'available');

        $lines[] = app()->configurationIsCached()
            ? $this->warn('Config cache', 'config is cached — .env edits are ignored until config:clear')
            : $this->ok('Config cache', 'not cached');

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function vapid(): array
    {
        $lines = ['== 3. Web push keys (values are never printed) =='];

        $public = (string) config('webpush.vapid.public_key');
        $private = (string) config('webpush.vapid.private_key');
        $subject = (string) config('webpush.vapid.subject');

        $lines[] = $public !== '' && $private !== ''
            ? $this->ok('VAPID keys', 'public key ('.strlen($public).' chars) and private key set')
            : $this->fail('VAPID keys', 'public='.($public !== '' ? 'set' : 'EMPTY').', private='.($private !== '' ? 'set' : 'EMPTY'), 'VAPID keys are not set in .env — no push can be sent');

        $lines[] = Str::startsWith($subject, ['mailto:', 'https://'])
            ? $this->ok('VAPID subject', 'set')
            : $this->warn('VAPID subject', 'should be mailto:you@example.com or an https URL — some push services reject the alert without it');

        $lines[] = config('webpush.client_options.proxy')
            ? $this->ok('Outbound proxy', 'WEBPUSH_PROXY is set — pushes go through it')
            : 'Outbound proxy: none (pushes go straight from this server)';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function learners(): array
    {
        $lines = ['== 4. Learners and devices =='];

        try {
            $subscriptions = DB::table(config('webpush.table_name'))->count();
            $devicesUsers = DB::table(config('webpush.table_name'))->distinct()->count('subscribable_id');
            $reminderOn = User::query()->whereNotNull('review_reminder_time')->count();
            $reminderReachable = User::query()->whereNotNull('review_reminder_time')->whereHas('pushSubscriptions')->count();
            $noZone = User::query()->whereNotNull('review_reminder_time')->whereHas('pushSubscriptions')->whereNull('timezone')->count();
            $sentRecently = User::query()->where('last_review_reminder_on', '>=', now()->subDay()->toDateString())->count();
        } catch (Throwable $e) {
            $lines[] = $this->fail('Database', Str::limit($e->getMessage(), 160), 'could not read the push/reminder tables');

            return $lines;
        }

        $lines[] = "devices subscribed: {$subscriptions} (across {$devicesUsers} learner(s))";
        $lines[] = $subscriptions > 0
            ? $this->ok('Phone alerts', 'at least one device is subscribed')
            : $this->warn('Phone alerts', 'no device has turned alerts on yet — nobody can receive reminders or any other alert');
        $lines[] = "reminder switched on: {$reminderOn}, of which can actually be reached by push: {$reminderReachable}";
        $lines[] = $noZone === 0
            ? $this->ok('Learner timezone', 'every reachable learner has one saved')
            : $this->warn('Learner timezone', "{$noZone} learner(s) have none saved yet, so their time is read as Tehran until they open the app");
        $lines[] = "reminders sent in the last day: {$sentRecently}";

        return $lines;
    }

    /**
     * Any HTTP status at all (even 400/404) proves the TCP+TLS path out of
     * this server is open — the app only needs to be able to POST there.
     *
     * @return list<string>
     */
    private function pushServices(): array
    {
        $lines = ['== 5. Can this server reach the browsers\' push services? =='];
        $options = array_filter(['proxy' => config('webpush.client_options.proxy')]);

        $targets = [
            'Google FCM (Chrome, Android, most Edge)' => 'https://fcm.googleapis.com/',
            'Mozilla (Firefox)' => 'https://updates.push.services.mozilla.com/',
            'Apple (Safari, iPhone)' => 'https://web.push.apple.com/',
        ];

        $reached = 0;

        foreach ($targets as $label => $url) {
            $start = microtime(true);

            try {
                $status = Http::withOptions($options)->connectTimeout(5)->timeout(8)->get($url)->status();
                $reached++;
                $lines[] = $this->ok($label, "HTTP {$status} in ".round((microtime(true) - $start) * 1000).' ms');
            } catch (ConnectionException $e) {
                $lines[] = $this->fail($label, 'UNREACHABLE — '.Str::limit($e->getMessage(), 120), "this server cannot reach {$label}");
            } catch (Throwable $e) {
                $lines[] = $this->fail($label, Str::limit($e->getMessage(), 120), "{$label} check errored");
            }
        }

        if ($reached === 0) {
            $lines[] = 'Every push service is unreachable: no phone alert of any kind (reminders, messages, follow requests...) can be delivered. Set WEBPUSH_PROXY to a working proxy.';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function recentPushFailures(): array
    {
        $lines = ['== 6. Recent refused pushes in laravel.log (newest last) =='];
        $log = storage_path('logs/laravel.log');

        if (! is_file($log)) {
            return [...$lines, $this->ok('Log', 'no log file')];
        }

        $handle = fopen($log, 'rb');
        $size = filesize($log);
        fseek($handle, max(0, $size - 400_000));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $matches = array_values(array_filter(
            explode("\n", $tail),
            fn (string $line): bool => str_contains($line, 'Review reminder push was not accepted'),
        ));

        if ($matches === []) {
            return [...$lines, $this->ok('Refused pushes', 'none in the recent log')];
        }

        $lines[] = $this->warn('Refused pushes', count($matches).' recent — the push service turned a reminder down; last ones:');

        foreach (array_slice($matches, -5) as $match) {
            $lines[] = '  '.Str::limit($match, 280);
        }

        return $lines;
    }

    /**
     * Sends through the exact channel every alert uses and prints what the
     * push service said for each of the learner's devices.
     *
     * @return list<string>
     */
    private function sendTest(string $who): array
    {
        $lines = ['== 7. Test alert to '.Str::limit($who, 60).' =='];

        $user = ctype_digit($who) ? User::find((int) $who) : User::where('email', $who)->first();

        if ($user === null) {
            return [...$lines, $this->fail('Learner', 'not found', "send_test learner {$who} not found")];
        }

        if ($user->pushSubscriptions()->doesntExist()) {
            return [...$lines, $this->warn('Devices', 'this learner has no subscribed device')];
        }

        try {
            $reports = app(WebPushChannel::class)->send($user, new PushTest);
        } catch (Throwable $e) {
            return [...$lines, $this->fail('Send', Str::limit($e->getMessage(), 200), 'sending the test alert threw an error')];
        }

        foreach ($reports as $report) {
            $host = parse_url($report->getEndpoint(), PHP_URL_HOST);

            $lines[] = $report->isSuccess()
                ? $this->ok($host, 'accepted — the phone should show it in a moment')
                : $this->fail($host, ($report->isSubscriptionExpired() ? 'subscription expired — turn alerts off/on on that phone: ' : '').Str::limit($report->getReason(), 160), "push service {$host} refused the test alert");
        }

        return $lines;
    }

    private function ok(string $label, string $detail): string
    {
        return "[ OK ] {$label}: {$detail}";
    }

    private function warn(string $label, string $detail): string
    {
        return "[WARN] {$label}: {$detail}";
    }

    private function fail(string $label, string $detail, string $problem): string
    {
        $this->problems[] = $problem;

        return "[FAIL] {$label}: {$detail}";
    }
}
