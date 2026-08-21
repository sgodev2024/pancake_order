<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CustomerCareReclaimScheduleTest extends TestCase
{
    public function test_auto_reclaim_configuration_defaults_are_safe(): void
    {
        $this->assertFalse(config('customer_care.auto_reclaim.enabled'));
        $this->assertSame(500, config('customer_care.auto_reclaim.limit'));
    }

    public function test_auto_reclaim_schedule_is_registered_once_with_mutation_safety(): void
    {
        $events = $this->autoReclaimEvents();

        $this->assertCount(1, $events);

        $event = $events->first();

        $this->assertStringContainsString('customer-care:reclaim-stale', $event->command);
        $this->assertStringContainsString('--execute', $event->command);
        $this->assertStringContainsString('--limit=500', $event->command);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertSame('Asia/Ho_Chi_Minh', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(180, $event->expiresAt);
        $this->assertFalse($event->onOneServer);
    }

    public function test_disabled_flag_prevents_the_scheduled_command_from_running(): void
    {
        config(['customer_care.auto_reclaim.enabled' => false]);

        $this->assertFalse($this->autoReclaimEvent()->filtersPass($this->app));
    }

    public function test_enabled_flag_allows_the_scheduled_command_to_run(): void
    {
        config(['customer_care.auto_reclaim.enabled' => true]);

        $this->assertTrue($this->autoReclaimEvent()->filtersPass($this->app));
    }

    public function test_configured_batch_limit_is_passed_to_the_scheduled_command(): void
    {
        $command = $this->autoReclaimEvent()->command;

        $this->assertSame(1, preg_match('/--limit=(\d+)/', $command, $matches));

        $this->assertSame((string) config('customer_care.auto_reclaim.limit'), $matches[1]);
    }

    public function test_execute_fails_safely_when_assignment_table_is_missing(): void
    {
        config([
            'database.default' => 'phase_f_missing_table',
            'database.connections.phase_f_missing_table' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);

        DB::purge('phase_f_missing_table');
        DB::setDefaultConnection('phase_f_missing_table');

        $output = new BufferedOutput;
        $exitCode = $this->app->make(Kernel::class)->handle(
            new ArrayInput([
                'command' => 'customer-care:reclaim-stale',
                '--execute' => true,
            ]),
            $output
        );

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'customer_care_assignments',
            $output->fetch()
        );
    }

    private function autoReclaimEvent(): Event
    {
        $events = $this->autoReclaimEvents();

        $this->assertCount(1, $events);

        return $events->first();
    }

    private function autoReclaimEvents()
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event) => str_contains(
                $event->command ?? '',
                'customer-care:reclaim-stale'
            ))
            ->values();
    }
}
