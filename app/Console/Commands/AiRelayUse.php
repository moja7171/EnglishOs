<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Switches which of the two AI_PROXY_URL_{VPS,LOCAL}/AI_PROXY_SECRET_{VPS,LOCAL}
 * slots in .env (see config/services.php's ai_proxy.target) GeminiClient/GroqClient/
 * PexelsClient route through, by rewriting AI_PROXY_TARGET in .env and
 * clearing the config cache. "vps" is a real always-on VPS
 * (scripts/vps-relay-setup.sh); "local" is a laptop running
 * scripts/ai-relay.js plus a localhost.run tunnel, started by hand.
 *
 * --local-url also rewrites AI_PROXY_URL_LOCAL: a free localhost.run tunnel
 * gets a new random URL every time it reconnects, so the laptop publishes
 * the fresh one here (scripts/relay-publish-url.cjs) instead of someone
 * editing production's .env by hand. Only https://<id>.lhr.life URLs are
 * accepted, so a leaked token can't repoint the relay at an arbitrary host
 * that would then receive the app's API keys.
 *
 * On a host with no SSH/terminal, App\Http\Controllers\AiRelayUseController
 * exposes this same command behind the token-gated /_diag/ai-relay-use route.
 */
class AiRelayUse extends Command
{
    protected $signature = 'ai:relay-use
        {target? : "vps" or "local" — omit to just show the current target}
        {--local-url= : Also set AI_PROXY_URL_LOCAL to this https://<id>.lhr.life tunnel URL}';

    protected $description = 'Switches the active AI proxy relay (vps/local) by rewriting AI_PROXY_TARGET in .env';

    /**
     * Defaults to the real .env; overridable via a contextual container
     * binding so tests never touch the project's actual .env file.
     */
    public function __construct(private readonly ?string $envPath = null)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = $this->argument('target');
        $localUrl = $this->option('local-url');

        if ($target !== null && ! in_array($target, ['vps', 'local'], true)) {
            $this->error("Target must be \"vps\" or \"local\", got \"{$target}\".");

            return self::FAILURE;
        }

        if ($localUrl !== null) {
            $localUrl = rtrim(trim((string) $localUrl), '/');

            if (! preg_match('#^https://[a-z0-9-]+\.lhr\.life$#', $localUrl)) {
                $this->error('--local-url must look like https://<id>.lhr.life.');

                return self::FAILURE;
            }
        }

        if ($target === null && $localUrl === null) {
            $this->info('Current target: '.config('services.ai_proxy.target'));
            $this->info('Resolved URL: '.(config('services.ai_proxy.url') ?: '(empty)'));

            return self::SUCCESS;
        }

        if ($localUrl !== null) {
            $this->writeEnvValue('AI_PROXY_URL_LOCAL', $localUrl);
        }

        if ($target !== null) {
            $this->writeEnvValue('AI_PROXY_TARGET', $target);
        }

        $this->call('config:clear');

        if ($localUrl !== null) {
            $this->info("AI_PROXY_URL_LOCAL set to \"{$localUrl}\".");
        }

        if ($target !== null) {
            $this->info("AI_PROXY_TARGET set to \"{$target}\" and config cache cleared.");
        }

        return self::SUCCESS;
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $path = $this->envPath ?? app()->environmentFilePath();
        $contents = file_get_contents($path);

        $contents = preg_match("/^{$key}=.*$/m", $contents)
            ? preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $contents)
            : rtrim($contents)."\n{$key}={$value}\n";

        file_put_contents($path, $contents);
    }
}
