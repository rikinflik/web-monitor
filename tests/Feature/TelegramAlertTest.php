<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorStatusAlert;
use App\Notifications\MonitorStatusChanged;
use App\Notifications\MonitorStillDownAlert;
use App\Notifications\OperatorAlerts;
use App\Services\MonitoringService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Operator alerts pushed to the Telegram ops chat when a monitor goes down,
 * stays down, or comes back.
 */
#[Group('telegram-alerts')]
class TelegramAlertTest extends TestCase
{
    use RefreshDatabase;

    private const OPS_CHAT = '-1001234567890';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram-bot-api.alert_chat_id' => self::OPS_CHAT]);
        config(['services.telegram-bot-api.token' => 'test-token']);
    }

    /**
     * A service whose next $times requests all answer with $status.
     */
    private function serviceReturning(int $status, int $times = 1): MonitoringService
    {
        $responses = array_map(fn () => new Response($status), range(1, $times));
        $stack = HandlerStack::create(new MockHandler($responses));

        return new MonitoringService(new Client(['handler' => $stack]), new OperatorAlerts);
    }

    private function upMonitor(?User $owner = null): Monitor
    {
        return Monitor::factory()
            ->for($owner ?? User::factory())
            ->create(['expected_status_code' => 200]);
    }

    /**
     * Assert the alert went to the configured chat over the telegram channel.
     */
    private function assertAlertedOpsChat(string $notification, ?callable $check = null): void
    {
        Notification::assertSentOnDemand(
            $notification,
            function ($sent, array $channels, AnonymousNotifiable $notifiable) use ($check) {
                return $channels === ['telegram']
                    && $notifiable->routes['telegram'] === self::OPS_CHAT
                    && ($check === null || $check($sent));
            },
        );
    }

    // -------------------------------------------------------------------------
    // The three events
    // -------------------------------------------------------------------------

    public function test_going_down_alerts_the_ops_chat(): void
    {
        Notification::fake();
        $service = $this->serviceReturning(500);

        $service->check($this->upMonitor());

        $this->assertAlertedOpsChat(
            MonitorStatusAlert::class,
            fn (MonitorStatusAlert $n) => $n->status === Monitor::STATUS_DOWN,
        );
    }

    public function test_recovering_alerts_the_ops_chat(): void
    {
        Notification::fake();
        $monitor = $this->upMonitor();
        $service = $this->serviceReturning(500, 1);
        $service->check($monitor);

        $this->serviceReturning(200, 1)->check($monitor);

        $this->assertAlertedOpsChat(
            MonitorStatusAlert::class,
            fn (MonitorStatusAlert $n) => $n->status === Monitor::STATUS_UP,
        );
    }

    public function test_a_still_down_reminder_alerts_the_ops_chat(): void
    {
        Notification::fake();
        $monitor = $this->upMonitor();
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);
        $this->travel(15)->minutes();
        $service->check($monitor);

        $this->assertAlertedOpsChat(
            MonitorStillDownAlert::class,
            fn (MonitorStillDownAlert $n) => $n->reminderNumber === 1,
        );
    }

    // -------------------------------------------------------------------------
    // Independence from the email preferences
    // -------------------------------------------------------------------------

    public function test_the_ops_chat_is_alerted_even_when_nobody_subscribed_by_email(): void
    {
        Notification::fake();

        // Every user opted out, so notificationRecipients() is empty and the
        // email path returns early. The ops chat must still hear about it.
        User::query()->update(['notify_mode' => User::NOTIFY_NONE]);
        $owner = User::factory()->create(['notify_mode' => User::NOTIFY_NONE]);
        $monitor = $this->upMonitor($owner);
        $service = $this->serviceReturning(500, 2);

        $service->check($monitor);
        $this->travel(15)->minutes();
        $service->check($monitor);

        $this->assertAlertedOpsChat(MonitorStatusAlert::class);
        $this->assertAlertedOpsChat(MonitorStillDownAlert::class);
    }

    // -------------------------------------------------------------------------
    // The off switch
    // -------------------------------------------------------------------------

    public function test_an_empty_chat_id_disables_telegram_without_touching_email(): void
    {
        Notification::fake();
        config(['services.telegram-bot-api.alert_chat_id' => '']);
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);

        $this->serviceReturning(500)->check($this->upMonitor($user));

        Notification::assertSentOnDemandTimes(MonitorStatusAlert::class, 0);
        Notification::assertSentTo($user, MonitorStatusChanged::class);
    }

    public function test_an_up_monitor_that_stays_up_alerts_nobody(): void
    {
        Notification::fake();
        $service = $this->serviceReturning(200, 2);
        $monitor = $this->upMonitor();

        $service->check($monitor);
        $this->travel(8)->hours();
        $service->check($monitor);

        Notification::assertSentOnDemandTimes(MonitorStatusAlert::class, 0);
        Notification::assertSentOnDemandTimes(MonitorStillDownAlert::class, 0);
    }
}
