<?php

namespace Tests\Feature;

use App\Models\Mission;
use Tests\TestCase;

class MissionMoodTest extends TestCase
{
    /**
     * Every built mission needs its own visual mood (EOS-009 §8) — this
     * guards against a new mission silently falling through to
     * moodKey()'s "daily-life" fallback (M01's own mood) just because
     * nobody added its match arm yet.
     */
    public function test_every_built_mission_has_its_own_mood(): void
    {
        $moods = collect(['M01', 'M02', 'M03', 'M04', 'M05', 'M06', 'M07', 'M08', 'M09', 'M10'])
            ->mapWithKeys(fn ($code) => [$code => (new Mission(['code' => $code]))->moodKey()]);

        $this->assertSame([
            'M01' => 'daily-life',
            'M02' => 'connection',
            'M03' => 'focus',
            'M04' => 'nourish',
            'M05' => 'play',
            'M06' => 'learn',
            'M07' => 'kin',
            'M08' => 'mates',
            'M09' => 'self',
            'M10' => 'bond',
        ], $moods->all());

        $this->assertSame($moods->count(), $moods->unique()->count(), 'every built mission must have a unique mood');
    }

    public function test_an_unbuilt_mission_falls_back_to_the_base_mood(): void
    {
        $this->assertSame('daily-life', (new Mission(['code' => 'M11']))->moodKey());
    }
}
