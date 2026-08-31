<?php

namespace Tests\Feature;

use App\Models\Mission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionHookContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_m01_step_has_a_non_empty_hook(): void
    {
        $this->seed(\Database\Seeders\MissionSeeder::class);

        $mission = Mission::where('code', 'M01')->firstOrFail();

        foreach ($mission->stepKeys() as $key) {
            $hook = $mission->stepContent($key)['hook'] ?? null;

            $this->assertNotEmpty($hook, "Step \"{$key}\" is missing a hook.");
        }
    }
}
