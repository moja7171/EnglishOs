<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * Marks a mission step component as able to render in preview — the
 * look-but-don't-touch view of a step the learner hasn't reached yet.
 * The runner passes preview=true (always together with readOnly=true);
 * the flag is locked so a crafted request can't flip it back off, and
 * App\Providers\AppServiceProvider refuses every action call on a
 * component where it is set, which is what actually keeps a preview from
 * recording Evidence or spending AI quota — hiding the buttons is only
 * cosmetics.
 */
trait PreviewsStep
{
    #[Locked]
    public bool $preview = false;
}
