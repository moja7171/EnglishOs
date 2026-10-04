<?php

namespace App\Http\Controllers;

use App\Services\GeminiClient;
use App\Services\SentenceChecker;
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
            'gemini model / fallback: '.config('services.gemini.model').' / '.config('services.gemini.fallback_model'),
            'php: '.PHP_VERSION.', curl: '.(function_exists('curl_version') ? curl_version()['version'] : 'missing'),
            '',
            '== 1. Relay reachable? (GET without auth; 401 "bad auth" = healthy) ==',
            ...$this->probeRelay(),
            '',
            '== 2. Exact app path: GeminiClient::chat() — what Sage and Check run ==',
            ...$this->probeGeminiClient(),
            '',
            '== 2b. Realistic requests: Sage-style chat and a real sentence check (full app chain, 2 runs each) ==',
            ...$this->probeRealisticRequests(),
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
    private function probeGeminiClient(): array
    {
        $lines = [];

        // One model at a time with no fallback, so the primary's own error
        // isn't hidden behind the fallback's (chat() rethrows only the last).
        foreach ([config('services.gemini.model'), config('services.gemini.fallback_model')] as $model) {
            $lines[] = "model {$model}:";

            foreach ($this->timed(function () use ($model): string {
                $reply = (new GeminiClient(null, (string) $model, ''))
                    ->chat([['role' => 'user', 'text' => 'Reply with exactly one word: pong']]);

                return 'OK — replied: '.mb_substr($reply, 0, 80);
            }) as $line) {
                $lines[] = '  '.str_replace("\n", "\n  ", $line);
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function probeRealisticRequests(): array
    {
        $systemPrompt = str_repeat('You are Sage, a warm, concise English tutor for an intermediate learner. Keep answers short and encouraging. ', 12);
        $lines = [];

        foreach (range(1, 2) as $run) {
            $lines[] = "Sage-style chat (systemInstruction + maxOutputTokens 220 + history), run {$run}:";

            foreach ($this->timed(fn (): string => 'OK — '.mb_substr(app(GeminiClient::class)->chat([
                ['role' => 'user', 'text' => 'Hi Sage'],
                ['role' => 'model', 'text' => 'Hello! How can I help with your English today?'],
                ['role' => 'user', 'text' => 'What is the difference between "make" and "do"?'],
            ], $systemPrompt, 220), 0, 120)) as $line) {
                $lines[] = '  '.str_replace(chr(10), chr(10).'  ', $line);
            }
        }

        foreach (range(1, 2) as $run) {
            $lines[] = "SentenceChecker::check() (what the Check button runs), run {$run}:";

            foreach ($this->timed(function (): string {
                $result = app(SentenceChecker::class)->check(
                    'Judge whether the learner used the target word correctly.',
                    'the word is missing or used with the wrong meaning',
                    'A personal sentence using the word "routine".',
                    'I have a morning routine, I wake up at seven.',
                );

                return 'OK — '.json_encode($result);
            }) as $line) {
                $lines[] = '  '.str_replace(chr(10), chr(10).'  ', $line);
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
