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
 * On a host with no SSH/terminal, App\Http\Controllers\AiRelayUseController
 * exposes this same command behind the token-gated /_diag/ai-relay-use route.
 */
class AiRelayUse extends Command
{
    protected $signature = 'ai:relay-use {target? : "vps" or "local" — omit to just show the current target}';

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

        if ($target === null) {
            $this->info('Current target: '.config('services.ai_proxy.target'));
            $this->info('Resolved URL: '.(config('services.ai_proxy.url') ?: '(empty)'));

            return self::SUCCESS;
        }

        if (! in_array($target, ['vps', 'local'], true)) {
            $this->error("Target must be \"vps\" or \"local\", got \"{$target}\".");

            return self::FAILURE;
        }

        $this->writeTarget($target);
        $this->call('config:clear');

        $this->info("AI_PROXY_TARGET set to \"{$target}\" and config cache cleared.");

        return self::SUCCESS;
    }

    private function writeTarget(string $target): void
    {
        $path = $this->envPath ?? app()->environmentFilePath();
        $contents = file_get_contents($path);

        $contents = preg_match('/^AI_PROXY_TARGET=.*$/m', $contents)
            ? preg_replace('/^AI_PROXY_TARGET=.*$/m', "AI_PROXY_TARGET={$target}", $contents)
            : rtrim($contents)."\nAI_PROXY_TARGET={$target}\n";

        file_put_contents($path, $contents);
    }
}
