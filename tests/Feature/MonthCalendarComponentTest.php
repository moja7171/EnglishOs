<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MonthCalendarComponentTest extends TestCase
{
    public function test_it_renders_weekday_headers(): void
    {
        $html = Blade::render('<x-month-calendar :days="[]" />');

        $this->assertStringContainsString('Sun', $html);
        $this->assertStringContainsString('Sat', $html);
    }

    public function test_it_renders_every_days_number(): void
    {
        $days = [
            ['date' => '2026-09-01', 'day' => 1, 'active' => false, 'future' => false, 'inMonth' => true, 'isToday' => false],
            ['date' => '2026-09-02', 'day' => 2, 'active' => true, 'future' => false, 'inMonth' => true, 'isToday' => false],
        ];

        $html = Blade::render('<x-month-calendar :days="$days" />', ['days' => $days]);

        $this->assertStringContainsString('>1<', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringContainsString('2026-09-02 — practiced', $html);
    }

    public function test_days_outside_the_month_are_dimmed(): void
    {
        $days = [
            ['date' => '2026-08-31', 'day' => 31, 'active' => false, 'future' => false, 'inMonth' => false, 'isToday' => false],
        ];

        $html = Blade::render('<x-month-calendar :days="$days" />', ['days' => $days]);

        $this->assertStringContainsString('text-ink-faint/40', $html);
    }
}
