<?php

namespace App\Providers;

use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Synthesizers\PreviewMissionRunSynth;
use App\Models\MissionRun;
use App\Notifications\Channels\BellChannel;
use App\Services\AiUsage;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\StreakRing;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every AI call made for a signed-in learner tries their own key
        // first when they saved one (Profile → AI keys), then the app's. A
        // key that no longer decrypts (APP_KEY rotated) just means "none".
        $this->app->bind(GeminiClient::class, fn () => new GeminiClient(personalKey: rescue(fn () => Auth::user()?->gemini_api_key, report: false)));
        $this->app->bind(GroqClient::class, fn () => new GroqClient(personalKey: rescue(fn () => Auth::user()?->groq_api_key, report: false)));

        // Every 'database' (bell) notification goes through BellChannel, so
        // one with a topic replaces its earlier row instead of stacking.
        $this->app->bind(DatabaseChannel::class, BellChannel::class);

        // Works the streak out once per learner per request (see StreakRing).
        $this->app->singleton(StreakRing::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Most cPanel/shared MySQL hosts still default to a 767-byte InnoDB
        // index prefix limit. A default varchar(255) unique/index column
        // under utf8mb4 (4 bytes/char) needs 1020 bytes and fails migration
        // with "Specified key too long" — capping the default length at 191
        // (191*4=764) is the standard Laravel fix and is a no-op on Postgres.
        Schema::defaultStringLength(191);

        Livewire::propertySynthesizer(PreviewMissionRunSynth::class);
        $this->refuseActionsOnPreviewedSteps();
        $this->rememberMissionStepForAiUsage();
    }

    /**
     * An action on a mission step component (anything holding a
     * MissionRun in $run) remembers which run and step it is, so the AI
     * requests it makes are logged against that step (see AiUsage). The
     * step key is the component's own when it has one; otherwise AiUsage
     * falls back to the run's current step.
     */
    private function rememberMissionStepForAiUsage(): void
    {
        on('call', function ($component) {
            $run = property_exists($component, 'run') && isset($component->run) ? $component->run : null;

            if (! $run instanceof MissionRun) {
                return;
            }

            $stepKey = property_exists($component, 'stepKey') && isset($component->stepKey) && is_string($component->stepKey) ? $component->stepKey : null;

            Context::addHidden(AiUsage::STEP_CONTEXT, ['run_id' => $run->id, 'step_key' => $stepKey]);
        });
    }

    /**
     * A step shown in preview (see PreviewsStep) is look-only: any action a
     * client sends to it - save, proceed, an AI call, a file upload - is
     * refused before it runs. Property updates are harmless on their own
     * (nothing persists without an action) and the inputs are disabled by
     * readOnly anyway.
     */
    private function refuseActionsOnPreviewedSteps(): void
    {
        on('call', function ($component, string $method) {
            if (! in_array(PreviewsStep::class, class_uses_recursive($component), true)) {
                return;
            }

            if ($component->preview && $method !== '$refresh') {
                abort(403, 'This step is preview-only.');
            }
        });
    }
}
