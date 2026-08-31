<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'title', 'module', 'outcome', 'phases'])]
class Mission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'phases' => 'array',
        ];
    }

    /**
     * @return HasMany<MissionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(MissionRun::class);
    }

    /**
     * Flattens phases[].steps[] into an ordered list of step keys, in the
     * order a learner must complete them (EOS-009 §7).
     *
     * @return list<string>
     */
    public function stepKeys(): array
    {
        return collect($this->phases ?? [])
            ->flatMap(fn (array $phase) => collect($phase['steps'] ?? [])
                ->map(fn ($step) => is_array($step) ? $step['key'] : $step))
            ->values()
            ->all();
    }

    /**
     * The phase definition (label, mode, steps...) that contains the given
     * step key, or null if the key isn't part of this mission.
     */
    public function phaseFor(string $stepKey): ?array
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if ((is_array($step) ? $step['key'] : $step) === $stepKey) {
                    return $phase;
                }
            }
        }

        return null;
    }

    /**
     * A human-readable label for a step key — its authored 'label' if the
     * seeder gave it one, otherwise the key title-cased as a fallback.
     */
    public function stepLabel(string $stepKey): string
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (is_array($step) && ($step['key'] ?? null) === $stepKey) {
                    return $step['label'] ?? $stepKey;
                }
            }
        }

        return str($stepKey)->replace('_', ' ')->title()->toString();
    }

    /**
     * The full authored step definition for a step key (questions,
     * vocabulary, quick-check items, etc.), or an empty array for a
     * plain-string step that has no content of its own yet.
     */
    public function stepContent(string $stepKey): array
    {
        foreach ($this->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (is_array($step) && ($step['key'] ?? null) === $stepKey) {
                    return $step;
                }
            }
        }

        return [];
    }
}
