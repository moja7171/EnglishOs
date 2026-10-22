<?php

namespace Database\Factories;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => AiUsageLog::PROVIDER_GEMINI,
            'profile' => 'chat',
            'key_source' => AiUsageLog::KEY_APP,
            'outcome' => AiUsageLog::OUTCOME_OK,
            'created_at' => now(),
        ];
    }

    public function ownKey(): static
    {
        return $this->state(fn (array $attributes) => ['key_source' => AiUsageLog::KEY_OWN]);
    }

    public function failed(string $kind = 'transient'): static
    {
        return $this->state(fn (array $attributes) => ['outcome' => AiUsageLog::OUTCOME_FAILED, 'failure_kind' => $kind]);
    }

    public function limited(): static
    {
        return $this->state(fn (array $attributes) => ['outcome' => AiUsageLog::OUTCOME_LIMITED, 'key_source' => null]);
    }
}
