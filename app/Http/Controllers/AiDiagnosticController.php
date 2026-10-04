<?php

namespace App\Http\Controllers;

use App\Services\GeminiClient;
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
            '== 3. Direct to Google, no relay (informational — expected to fail on a filtered host) ==',
            ...$this->probeDirect(),
            '',
            '== 4. Tail of storage/logs/laravel.log ==',
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
