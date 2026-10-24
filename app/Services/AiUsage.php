<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\MissionRun;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Writes one AiUsageLog row per AI request made for the signed-in learner
 * (GeminiClient::chat(), GroqClient's transcriptions), and enforces the
 * admin's daily cap on the app's key (users.ai_daily_limit). The learner's
 * own key is never capped — it costs the app nothing.
 *
 * Inside a mission step, the step is known from the Livewire call (see
 * AppServiceProvider::rememberMissionStepForAiUsage()), so the admin page
 * can tell which steps cost the most AI.
 *
 * Logging is best-effort: a failed write never touches the AI call.
 */
class AiUsage
{
    /** The hidden Context key holding ['run_id' => int, 'step_key' => ?string]. */
    public const STEP_CONTEXT = 'ai_usage_step';

    /** The hidden Context key set while only the learner's own key may answer (see ownKeyOnly()). */
    public const OWN_KEY_ONLY_CONTEXT = 'ai_usage_own_key_only';

    /**
     * Runs the call with the app's key held back: the learner's own key
     * still answers, but a fallback to the app's key is refused like a
     * used-up daily cap (AiLimitReached).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    public function ownKeyOnly(Closure $call): mixed
    {
        return Context::scope($call, hidden: [self::OWN_KEY_ONLY_CONTEXT => true]);
    }

    /**
     * Runs one walk of a provider's model chain on one key and logs how it
     * ended. The walk's exception is rethrown unchanged.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $call
     * @return TResult
     */
    public function track(string $provider, ?string $profile, string $keySource, Closure $call): mixed
    {
        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->record($provider, $profile, $keySource, AiUsageLog::OUTCOME_FAILED, (new AiModelChain)->classify($e)['kind']);

            throw $e;
        }

        $this->record($provider, $profile, $keySource, AiUsageLog::OUTCOME_OK);

        return $result;
    }

    /**
     * Called right before a request on the app's key. Throws, and logs the
     * refusal, once the signed-in learner has used up the admin's cap, or
     * inside ownKeyOnly().
     *
     * @throws AiLimitReached
     */
    public function ensureAppKeyAllowed(string $provider, ?string $profile): void
    {
        if (Context::getHidden(self::OWN_KEY_ONLY_CONTEXT) === true) {
            $this->record($provider, $profile, AiUsageLog::KEY_APP, AiUsageLog::OUTCOME_BLOCKED);

            throw new AiLimitReached('The app key is held back for this request.');
        }

        $user = Auth::user();

        if (! $user instanceof User || $user->ai_daily_limit === null) {
            return;
        }

        if ($this->appKeyRequestsToday($user) < $user->ai_daily_limit) {
            return;
        }

        $this->record($provider, $profile, AiUsageLog::KEY_APP, AiUsageLog::OUTCOME_BLOCKED);

        throw new AiLimitReached("AI daily limit of {$user->ai_daily_limit} reached for user {$user->id}.");
    }

    /**
     * Successful requests on the app's key since the start of today —
     * what the admin's daily cap counts.
     */
    public function appKeyRequestsToday(User $user): int
    {
        return AiUsageLog::query()
            ->where('user_id', $user->id)
            ->where('key_source', AiUsageLog::KEY_APP)
            ->where('outcome', AiUsageLog::OUTCOME_OK)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * A request the app refused by its own hourly cap, before any AI call.
     */
    public function recordLimited(string $provider, ?string $profile = null): void
    {
        $this->record($provider, $profile, null, AiUsageLog::OUTCOME_LIMITED);
    }

    private function record(string $provider, ?string $profile, ?string $keySource, string $outcome, ?string $failureKind = null): void
    {
        try {
            AiUsageLog::create([
                'user_id' => Auth::id(),
                'provider' => $provider,
                'profile' => $profile,
                'key_source' => $keySource,
                'outcome' => $outcome,
                'failure_kind' => $failureKind,
                ...$this->currentStep(),
            ]);
        } catch (Throwable) {
            // Usage counts are informational; losing one is fine.
        }
    }

    /**
     * @return array{mission_code: ?string, step_key: ?string}
     */
    private function currentStep(): array
    {
        $step = Context::getHidden(self::STEP_CONTEXT);

        if (! is_array($step) || ! isset($step['run_id'])) {
            return ['mission_code' => null, 'step_key' => null];
        }

        $run = MissionRun::query()->with('mission')->find($step['run_id']);

        return [
            'mission_code' => $run?->mission?->code,
            'step_key' => $step['step_key'] ?? $run?->currentStepKey(),
        ];
    }
}
