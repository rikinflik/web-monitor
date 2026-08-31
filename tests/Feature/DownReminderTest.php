<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorStatusChanged;
use App\Notifications\MonitorStillDown;
use App\Services\MonitoringService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Reminders for a monitor that stays down, on the backoff configured in
 * config/monitoring.php.
 */
#[Group('down-reminders')]
class DownReminderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A service whose next $times requests all answer with $status.
     *
     * Each response is a distinct object: the checker reads the body, which
     * would exhaust a shared stream.
     */
    private function serviceReturning(int $status, int $times = 1): MonitoringService
    {
        $responses = array_map(fn () => new Response($status), range(1, $times));
        $stack = HandlerStack::create(new MockHandler($responses));

        return new MonitoringService(new Client(['handler' => $stack]));
    }

    private function upMonitor(?User $owner = null): Monitor
    {
        return Monitor::factory()
            ->for($owner ?? User::factory())
            ->create(['expected_status_code' => 200]);
    }

    // -------------------------------------------------------------------------
    // Backoff
    // -------------------------------------------------------------------------

    public function test_no_reminder_before_the_first_backoff_step(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);

        $this->travel(14)->minutes();
        $service->check($monitor);

        Notification::assertNotSentTo($user, MonitorStillDown::class);
    }

    public function test_first_reminder_lands_fifteen_minutes_into_the_outage(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);

        $this->travel(15)->minutes();
        $service->check($monitor);

        Notification::assertSentTo(
            $user,
            MonitorStillDown::class,
            fn (MonitorStillDown $n) => $n->reminderNumber === 1,
        );
    }

    public function test_reminders_follow_the_configured_backoff(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(500, 30);

        $service->check($monitor);

        $sent = 0;

        // 15m, 30m, 1h, 2h, 4h and then 4h forever.
        foreach ([15, 30, 60, 120, 240, 240] as $step) {
            $this->travel($step - 1)->minutes();
            $service->check($monitor);
            Notification::assertSentToTimes($user, MonitorStillDown::class, $sent);

            $this->travel(1)->minutes();
            $service->check($monitor);
            Notification::assertSentToTimes($user, MonitorStillDown::class, ++$sent);
        }

        $this->assertSame(6, $monitor->refresh()->down_reminders_sent);
    }

    public function test_an_up_monitor_never_gets_reminders(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(200, 2);

        $service->check($monitor);
        $this->travel(8)->hours();
        $service->check($monitor);

        Notification::assertNotSentTo($user, MonitorStillDown::class);
    }

    // -------------------------------------------------------------------------
    // Recovery
    // -------------------------------------------------------------------------

    public function test_recovery_sends_a_restored_email_and_clears_the_outage(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);

        $this->serviceReturning(500)->check($monitor);
        $this->travel(90)->minutes();
        $this->serviceReturning(200)->check($monitor);

        $monitor->refresh();
        $this->assertSame(Monitor::STATUS_UP, $monitor->status);
        $this->assertNull($monitor->down_since);
        $this->assertNull($monitor->last_down_notified_at);
        $this->assertSame(0, $monitor->down_reminders_sent);

        Notification::assertSentTo(
            $user,
            MonitorStatusChanged::class,
            fn (MonitorStatusChanged $n) => $n->status === Monitor::STATUS_UP
                && $n->downSince !== null,
        );
    }

    public function test_a_new_outage_restarts_the_backoff(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);

        // First outage, escalated well past the opening step.
        $this->serviceReturning(500)->check($monitor);
        $this->travel(15)->minutes();
        $this->serviceReturning(500)->check($monitor);
        $this->travel(30)->minutes();
        $this->serviceReturning(500)->check($monitor);
        $this->assertSame(2, $monitor->refresh()->down_reminders_sent);

        // Recovers, then falls over again.
        $this->serviceReturning(200)->check($monitor);
        $this->serviceReturning(500)->check($monitor);
        $this->assertSame(0, $monitor->refresh()->down_reminders_sent);

        // The opening step is short again.
        $this->travel(15)->minutes();
        $this->serviceReturning(500)->check($monitor);

        $this->assertSame(1, $monitor->refresh()->down_reminders_sent);
    }

    // -------------------------------------------------------------------------
    // Mail contents
    // -------------------------------------------------------------------------

    public function test_restored_email_reports_how_long_the_outage_lasted(): void
    {
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);

        $mail = (new MonitorStatusChanged($monitor, Monitor::STATUS_UP, now()->subHours(2)))
            ->toMail($user);

        $this->assertStringContainsString('RESTORED', $mail->subject);
        $this->assertStringContainsString('2h', $mail->subject);
    }

    public function test_reminder_email_reports_the_running_outage(): void
    {
        $user = User::factory()->create();
        $monitor = $this->upMonitor($user);
        $monitor->update([
            'status' => Monitor::STATUS_DOWN,
            'down_since' => now()->subHours(3),
        ]);

        $mail = (new MonitorStillDown($monitor, 4))->toMail($user);

        $this->assertStringContainsString('Still DOWN', $mail->subject);
        $this->assertStringContainsString('3h', $mail->subject);
    }

    // -------------------------------------------------------------------------
    // Interaction with notification preferences
    // -------------------------------------------------------------------------

    public function test_reminders_respect_the_opt_out(): void
    {
        Notification::fake();
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_NONE]);
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);
        $this->travel(15)->minutes();
        $service->check($monitor);

        Notification::assertNothingSentTo($user);
    }

    public function test_backoff_advances_even_when_nobody_is_subscribed(): void
    {
        Notification::fake();
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_NONE]);
        $monitor = $this->upMonitor($user);
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);
        $this->travel(15)->minutes();
        $service->check($monitor);

        // Opting back in mid-outage must not replay the skipped steps.
        $this->assertSame(1, $monitor->refresh()->down_reminders_sent);
    }
}
