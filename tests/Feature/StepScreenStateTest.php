<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A step's Alpine `screen` is set from server state when the page first
 * renders. Without wire:ignore.self on that root, any Livewire re-render
 * that changes the state re-reads x-data and throws the learner to another
 * screen (the Check that jumped to the next word on its own).
 */
class StepScreenStateTest extends TestCase
{
    public function test_every_step_whose_first_screen_comes_from_the_server_ignores_its_own_root_on_re_render(): void
    {
        $files = glob(resource_path('views/components/missions/steps/*.blade.php'));
        $checked = 0;

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! preg_match('/x-data="\{\s*screen:\s*\{\{/', $source)) {
                continue;
            }

            $checked++;
            $this->assertStringContainsString('wire:ignore.self', $source, basename($file).' reads its first screen from the server but its root can be re-read on re-render.');
        }

        $this->assertGreaterThanOrEqual(8, $checked);
    }
}
