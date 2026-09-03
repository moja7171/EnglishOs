<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SkillRadarComponentTest extends TestCase
{
    public function test_it_renders_every_skill_label_and_value(): void
    {
        $skills = ['Listening' => 4.2, 'Vocabulary' => 3.8, 'Grammar' => 4.0, 'Speaking' => 3.5, 'Writing' => 4.5];

        $html = Blade::render('<x-skill-radar :skills="$skills" />', ['skills' => $skills]);

        $this->assertStringContainsString('Listening', $html);
        $this->assertStringContainsString('Vocabulary', $html);
        $this->assertStringContainsString('4.2', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    public function test_it_renders_nothing_with_fewer_than_3_skills(): void
    {
        $html = Blade::render('<x-skill-radar :skills="$skills" />', ['skills' => ['Listening' => 4.0, 'Writing' => 3.0]]);

        $this->assertSame('', trim($html));
    }

    public function test_an_empty_skill_set_renders_nothing(): void
    {
        $html = Blade::render('<x-skill-radar :skills="[]" />');

        $this->assertSame('', trim($html));
    }
}
