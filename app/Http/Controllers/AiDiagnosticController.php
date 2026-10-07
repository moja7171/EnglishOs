<?php

namespace App\Http\Controllers;

use App\Services\AiModelChain;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Temporary, token-gated "why can't this server reach Gemini?" report for a
 * host with no terminal (see /_diag/ai in routes/web.php). Runs the exact
 * GeminiClient path Sage and the Check button use, plus the raw relay and
 * direct-to-Google probes around it, and prints what each one actually
 * returned — the app itself swallows all of this behind "Couldn't reach
 * the AI service". Remove the route once the cause is found.
 */
class AiDiagnosticController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $expected = (string) config('services.diagnostics.token');

        abort_if($expected === '' || ! hash_equals($expected, (string) $request->query('token')), 404);

        $lines = [
            'AI diagnostics — '.now()->toDateTimeString().' ('.config('app.timezone').')',
            '',
            '== Config as the running app sees it ==',
            'config cached: '.(app()->configurationIsCached() ? 'YES (changes to .env are ignored until config:clear)' : 'no'),
            'relay url: '.($this->relayUrl() === '' ? '(EMPTY — calls go straight to Google)' : $this->relayUrl()),
            'relay secret set: '.(config('services.ai_proxy.secret') ? 'yes' : 'NO'),
            'gemini key set: '.(config('services.gemini.key') ? 'yes' : 'NO'),
            'gemini chat chain: '.$this->chainNames((new GeminiClient)->configuredChain(GeminiClient::PROFILE_CHAT)),
            'gemini judge chain: '.$this->chainNames((new GeminiClient)->configuredChain(GeminiClient::PROFILE_JUDGE)),
            'groq whisper chain: '.$this->chainNames((new GroqClient)->configuredChain()),
            'php: '.PHP_VERSION.', curl: '.(function_exists('curl_version') ? curl_version()['version'] : 'missing'),
            '',
            '== 1. Relay reachable? (GET without auth; 401 "bad auth" = healthy) ==',
            ...$this->probeRelay(),
            '',
            '== 2. Exact app path: GeminiClient::chat() — what Sage and Check run (first model of each chain; ?probe=all tries every model and spends one request on each) ==',
            ...$this->probeGeminiClient($request->query('probe') === 'all'),
            '',
            '== 2b. Model chains: does each model still exist, is it in rotation, today\'s counts (no generation quota spent) ==',
            ...$this->chainReport(),
            '',
            '== 2c. Relay POST body-size sweep (HTTP/1.1, real countTokens call; finds the size where requests start to hang) ==',
            ...$this->probeRelaySizeSweep(),
            '',
            '== 2d. Control: big POST bodies straight to Google, no relay and no Cloudflare (403 fast = body got out; timeout = host drops big outbound bodies) ==',
            ...$this->probeDirectBigBodies(),
            '',
            '== 2e. Control: big POST bodies to the relay VPS itself, bypassing Cloudflare (any quick HTTP status = body got out) ==',
            ...$this->probeVpsBigBodies(),
            '',
            '== 2f. Relay upload sweep: big POSTs with NO auth (a healthy relay answers 401 "bad auth" fast; a timeout = this host drops uploads that big — voice recordings are tens of KB) ==',
            ...$this->probeRelayUploadSweep(),
            '',
            '== 2g. Real transcription: GroqClient::transcribe() of a generated 2 s WAV — what a Sage voice question runs ==',
            ...$this->probeGroqTranscription(),
            '',
            '== 3. Direct to Google, no relay (informational — expected to fail on a filtered host) ==',
            ...$this->probeDirect(),
            '',
            '== 4. Recent non-proc_open errors/warnings in laravel.log (newest last) ==',
            ...$this->recentLogErrors(),
            '',
            '== 5. Tail of storage/logs/laravel.log ==',
            ...$this->logTail(),
        ];

        if ($request->boolean('clear_log')) {
            array_push($lines, '', '== 6. Clearing laravel.log (?clear_log=1) ==', $this->clearLog());
        }

        return response($this->redact(implode("\n", $lines)), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    private function relayUrl(): string
    {
        return (string) config('services.ai_proxy.url');
    }

    /** @return array<int, string> */
    private function probeRelay(): array
    {
        if ($this->relayUrl() === '') {
            return ['skipped — no relay configured'];
        }

        return $this->timed(function (): string {
            $response = Http::timeout(10)->get($this->relayUrl());

            return 'HTTP '.$response->status().' — '.mb_substr($response->body(), 0, 200);
        });
    }

    /** @return array<int, string> */
    private function probeGeminiClient(bool $everyModel): array
    {
        $lines = [];
        $models = [];

        foreach ([GeminiClient::PROFILE_CHAT, GeminiClient::PROFILE_JUDGE] as $profile) {
            $chain = (new GeminiClient)->configuredChain($profile);

            foreach ($everyModel ? $chain : array_slice($chain, 0, 1) as $entry) {
                $models[$entry['model']] = true;
            }
        }

        // One model at a time and pinned (no chain walk, no shared memory),
        // so each model's own error isn't hidden behind the next one's.
        foreach (array_keys($models) as $model) {
            $lines[] = "model {$model}:";

            foreach ($this->timed(function () use ($model): string {
                $reply = (new GeminiClient(null, $model, ''))
                    ->chat([['role' => 'user', 'text' => 'Reply with exactly one word: pong']]);

                return 'OK — replied: '.mb_substr($reply, 0, 80);
            }) as $line) {
                $lines[] = '  '.str_replace("\n", "\n  ", $line);
            }
        }

        return $lines;
    }

    /**
     * Per chain: each model's place in the order, whether the provider
     * still serves it (a retired model is stale config), whether the app is
     * currently skipping it and why, and today's success/failure counts.
     *
     * @return array<int, string>
     */
    private function chainReport(): array
    {
        $chains = new AiModelChain;
        $gemini = new GeminiClient;
        $groq = new GroqClient;
        $lines = [];

        $exists = [];
        $groqListing = $groq->listedModels();

        foreach ([GeminiClient::PROFILE_CHAT, GeminiClient::PROFILE_JUDGE] as $profile) {
            $lines[] = "gemini {$profile} chain (best first):";

            foreach ($chains->status('gemini', $gemini->configuredChain($profile)) as $position => $row) {
                $exists[$row['model']] ??= $gemini->modelExists($row['model']);
                $lines[] = $this->chainRow($position + 1, $row, $exists[$row['model']]);
            }
        }

        $lines[] = 'groq whisper chain (best first)'.($groqListing === null ? ' — model listing failed, existence unknown:' : ':');

        foreach ($chains->status('groq', $groq->configuredChain()) as $position => $row) {
            $lines[] = $this->chainRow($position + 1, $row, $groqListing === null ? null : in_array($row['model'], $groqListing, true));
        }

        return $lines;
    }

    /**
     * @param  array{model: string, thinking_level: ?string, available: bool, reason: ?string, since: ?int, until: ?int, today: array{ok: int, fail: int, last_ok_at: ?int, last_fail_at: ?int, last_fail_reason: ?string}}  $row
     */
    private function chainRow(int $position, array $row, ?bool $exists): string
    {
        $existence = match ($exists) {
            true => 'exists',
            false => 'GONE — retired, remove it from the list',
            null => 'existence unknown',
        };

        $rotation = $row['available']
            ? 'in rotation'
            : "SKIPPED ({$row['reason']}) until ".date('H:i:s', (int) $row['until']);

        $level = $row['thinking_level'] !== null ? ":{$row['thinking_level']}" : '';
        $today = $row['today'];
        $lastFailure = $today['last_fail_reason'] !== null ? ", last failure: {$today['last_fail_reason']}" : '';

        return "  {$position}. {$row['model']}{$level} — {$existence}; {$rotation}; today ok {$today['ok']} / failed {$today['fail']}{$lastFailure}";
    }

    /**
     * @param  list<array{model: string, thinking_level: ?string}>  $chain
     */
    private function chainNames(array $chain): string
    {
        return implode(' → ', array_map(
            fn (array $entry) => $entry['model'].($entry['thinking_level'] !== null ? ":{$entry['thinking_level']}" : ''),
            $chain,
        ));
    }

    /** @return array<int, string> */
    private function probeRelaySizeSweep(): array
    {
        if ($this->relayUrl() === '') {
            return ['skipped — no relay configured'];
        }

        $target = 'https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':countTokens';
        $lines = [];

        foreach ([[100, 1.1], [400, 1.1], [800, 1.1], [1100, 1.1], [1300, 1.1], [1450, 1.1], [2000, 1.1], [3000, 1.1]] as [$bytes, $version]) {
            $lines[] = "body ~{$bytes} bytes, HTTP/{$version} to relay:";

            foreach ($this->timed(function () use ($bytes, $version, $target): string {
                $response = Http::withHeaders([
                    'x-goog-api-key' => (string) config('services.gemini.key'),
                    'X-Relay-Url' => $target,
                    'X-Relay-Auth' => (string) config('services.ai_proxy.secret'),
                ])
                    ->withOptions(['version' => $version])
                    ->timeout(6)
                    ->post($this->relayUrl(), ['contents' => [['role' => 'user', 'parts' => [['text' => str_repeat('hello ', intdiv($bytes, 6))]]]]]);

                $negotiated = $response->handlerStats()['http_version'] ?? '?';

                return 'HTTP '.$response->status().' (negotiated curl http_version code '.$negotiated.', 2=1.1, 3=h2) — '.mb_substr(preg_replace('/\s+/', ' ', $response->body()), 0, 80);
            }) as $line) {
                $lines[] = '  '.$line;
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function probeRelayUploadSweep(): array
    {
        if ($this->relayUrl() === '') {
            return ['skipped — no relay configured'];
        }

        $lines = [];

        foreach ([8_000, 32_000, 96_000, 256_000] as $bytes) {
            $lines[] = "body ~{$bytes} bytes, no auth:";

            foreach ($this->timed(function () use ($bytes): string {
                $response = Http::timeout(15)
                    ->withBody(str_repeat('a', $bytes), 'application/octet-stream')
                    ->post($this->relayUrl());

                return 'HTTP '.$response->status().' — '.mb_substr(preg_replace('/\s+/', ' ', $response->body()), 0, 60);
            }) as $line) {
                $lines[] = '  '.$line;
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function probeGroqTranscription(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'diag-wav-').'.wav';
        file_put_contents($path, $this->sampleWav());
        $lines = ['audio: '.filesize($path).' bytes'];

        try {
            foreach ($this->timed(function () use ($path): string {
                $text = app(GroqClient::class)->transcribe($path);

                return 'OK — Whisper returned: "'.trim($text).'" (a tone has no speech; any reply means the whole path works)';
            }) as $line) {
                $lines[] = $line;
            }
        } finally {
            @unlink($path);
        }

        return $lines;
    }

    /** A 2 s, 16 kHz, mono 440 Hz tone as a WAV file's bytes. */
    private function sampleWav(): string
    {
        $rate = 16_000;
        $pcm = '';

        for ($i = 0; $i < $rate * 2; $i++) {
            $pcm .= pack('v', (int) (8000 * sin(2 * M_PI * 440 * $i / $rate)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }

    /** @return array<int, string> */
    private function probeDirectBigBodies(): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.model').':countTokens';
        $lines = [];

        foreach ([3000, 8000] as $bytes) {
            $lines[] = "body ~{$bytes} bytes, direct to Google:";

            foreach ($this->timed(function () use ($bytes, $url): string {
                $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                    ->timeout(8)
                    ->post($url, ['contents' => [['role' => 'user', 'parts' => [['text' => str_repeat('hello ', intdiv($bytes, 6))]]]]]);

                return 'HTTP '.$response->status().' — '.mb_substr((string) preg_replace('/\s+/', ' ', strip_tags($response->body())), 0, 60);
            }) as $line) {
                $lines[] = '  '.$line;
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function probeVpsBigBodies(): array
    {
        $lines = [];

        foreach (['http://64.226.95.102/', 'https://64.226.95.102/'] as $url) {
            foreach ([3000, 8000] as $bytes) {
                $lines[] = "body ~{$bytes} bytes to {$url}:";

                foreach ($this->timed(function () use ($bytes, $url): string {
                    $response = Http::withoutVerifying()
                        ->withOptions(['allow_redirects' => false])
                        ->timeout(8)
                        ->post($url, ['pad' => str_repeat('hello ', intdiv($bytes, 6))]);

                    return 'HTTP '.$response->status();
                }) as $line) {
                    $lines[] = '  '.$line;
                }
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function probeDirect(): array
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.config('services.gemini.fallback_model').':generateContent';

        return $this->timed(function () use ($url): string {
            $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])
                ->timeout(8)
                ->post($url, ['contents' => [['role' => 'user', 'parts' => [['text' => 'Say ok']]]]]);

            return 'HTTP '.$response->status().' — '.mb_substr($response->body(), 0, 200);
        });
    }

    /**
     * Runs a probe and reports its result or, on any failure, the exception
     * class, message and (for HTTP errors) the response status and body.
     *
     * @param  callable(): string  $probe
     * @return array<int, string>
     */
    private function timed(callable $probe): array
    {
        $start = microtime(true);

        try {
            $result = $probe();
        } catch (RequestException $e) {
            $result = 'FAILED '.$e::class.': '.$e->getMessage()
                ."\n  response status: ".$e->response->status()
                ."\n  response body: ".mb_substr($e->response->body(), 0, 600);
        } catch (Throwable $e) {
            $result = 'FAILED '.$e::class.': '.$e->getMessage();
        }

        return [$result, sprintf('(took %.1fs)', microtime(true) - $start)];
    }

    /**
     * First line of each recent ERROR/WARNING entry, skipping the host's
     * constant scheduler proc_open noise that fills the log.
     *
     * @return array<int, string>
     */
    private function recentLogErrors(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return ['(no laravel.log file)'];
        }

        $handle = fopen($path, 'rb');
        fseek($handle, max(0, filesize($path) - 3_000_000));
        $chunk = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[\d{4}-\d\d-\d\d [\d:]+\] \w+\.(?:ERROR|WARNING): .*$/m', $chunk, $matches);

        $entries = array_filter($matches[0], fn (string $line): bool => ! str_contains($line, 'proc_open'));
        $entries = array_map(fn (string $line): string => mb_substr($line, 0, 400), array_slice(array_values($entries), -20));

        return $entries === [] ? ['(none in the last 3 MB)'] : $entries;
    }

    /**
     * Empties laravel.log (the report above has already printed what was
     * worth keeping from it). Truncates in place rather than deleting so the
     * file keeps its owner and permissions for the app to keep writing to.
     */
    private function clearLog(): string
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return '(no laravel.log file)';
        }

        $before = filesize($path);

        return file_put_contents($path, '') === false
            ? 'FAILED to clear (not writable?)'
            : 'cleared: freed '.number_format($before).' bytes';
    }

    /** @return array<int, string> */
    private function logTail(): array
    {
        $path = storage_path('logs/laravel.log');

        if (! is_file($path)) {
            return ['(no laravel.log file)'];
        }

        $handle = fopen($path, 'rb');
        $size = filesize($path);
        fseek($handle, max(0, $size - 6000));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return [
            'log size: '.number_format($size).' bytes, level: '.config('logging.channels.single.level'),
            $tail === '' ? '(empty)' : $tail,
        ];
    }

    /**
     * Strips every configured secret from the report, since exception
     * messages and log lines can echo URLs and headers back.
     */
    private function redact(string $text): string
    {
        $secrets = [
            config('services.gemini.key'),
            config('services.groq.key'),
            config('services.pexels.key'),
            config('services.ai_proxy.secret'),
            config('services.diagnostics.token'),
            config('app.key'),
            config('database.connections.mysql.password'),
        ];

        foreach (array_filter($secrets) as $secret) {
            $text = str_replace((string) $secret, '[redacted]', $text);
        }

        return $text;
    }
}
