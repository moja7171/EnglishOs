<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class StressMarkedLineTest extends TestCase
{
    public function test_a_shadow_line_has_no_space_before_its_full_stop(): void
    {
        $html = Blade::render('“<x-stress-marked-line text="I **love** my **house**." />”');

        $this->assertStringContainsString('“<span >I <strong', $html);
        $this->assertStringContainsString('house</strong>.</span>”', $html);
    }
}
