<?php

namespace App\Livewire\Synthesizers;

use App\Models\Mission;
use App\Models\PreviewMissionRun;
use App\Models\User;
use Livewire\Mechanisms\HandleComponents\Synthesizers\Synth;

/**
 * Livewire's stock model synthesizer would rebuild an unsaved
 * PreviewMissionRun as an empty model on the next request. This one carries
 * the learner and mission ids in the (checksummed) snapshot meta instead,
 * and refuses to rebuild a preview for anyone but the signed-in learner.
 */
class PreviewMissionRunSynth extends Synth
{
    public static $key = 'eos-preview-run';

    public static function match($target): bool
    {
        return $target instanceof PreviewMissionRun;
    }

    /**
     * @return array{0: null, 1: array<string, int|null>}
     */
    public function dehydrate(PreviewMissionRun $target): array
    {
        return [null, [
            'class' => $target::class,
            'learner' => $target->learner_id,
            'mission' => $target->mission_id,
        ]];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function hydrate(mixed $data, array $meta): PreviewMissionRun
    {
        abort_unless(auth()->id() === $meta['learner'], 403);

        return PreviewMissionRun::for(
            User::findOrFail($meta['learner']),
            Mission::findOrFail($meta['mission']),
        );
    }
}
