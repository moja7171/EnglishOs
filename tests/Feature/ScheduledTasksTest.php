<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    /**
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        return app(Schedule::class)->events();
    }

    public function test_every_scheduled_task_runs_in_process_because_the_host_has_no_proc_open(): void
    {
        $events = $this->scheduledEvents();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertInstanceOf(
                CallbackEvent::class,
                $event,
                'Shell-launched schedule events need proc_open(), which the production host disables.'
            );
        }
    }

    public function test_review_reminders_and_notification_pruning_are_scheduled(): void
    {
        $names = array_map(fn (Event $event): string => $event->description, $this->scheduledEvents());

        $this->assertContains('review:send-reminders', $names);
        $this->assertContains('notifications:prune-read', $names);
        $this->assertContains('scheduler:heartbeat', $names);
    }
}
