<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use App\Notifications\MonitorStatusChanged;
use App\Notifications\TestNotification;
use App\Services\MonitoringService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('notification-preferences')]
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(MockHandler $mock): MonitoringService
    {
        $stack = HandlerStack::create($mock);
        return new MonitoringService(new Client(['handler' => $stack]));
    }

    /**
     * Drive a monitor from "up" to "down" so a status change fires.
     */
    private function takeDown(Monitor $monitor): void
    {
        $this->makeService(new MockHandler([new Response(500)]))->check($monitor);
    }

    // -------------------------------------------------------------------------
    // Recipient resolution
    // -------------------------------------------------------------------------

    public function test_all_mode_user_is_notified_about_a_monitor_they_do_not_own(): void
    {
        Notification::fake();

        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $bystander = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);

        $this->takeDown($monitor);

        Notification::assertSentTo($bystander, MonitorStatusChanged::class);
        Notification::assertSentTo($monitor->user, MonitorStatusChanged::class);
    }

    public function test_none_mode_user_is_never_notified_even_when_they_own_the_monitor(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['notify_mode' => User::NOTIFY_NONE]);
        $monitor = Monitor::factory()->for($owner)->create(['expected_status_code' => 200]);

        $this->takeDown($monitor);

        Notification::assertNothingSentTo($owner);
    }

    public function test_selected_mode_user_only_receives_subscribed_monitors(): void
    {
        Notification::fake();

        $subscribed = Monitor::factory()->create(['expected_status_code' => 200]);
        $ignored = Monitor::factory()->create(['expected_status_code' => 200]);

        $user = User::factory()->create(['notify_mode' => User::NOTIFY_SELECTED]);
        $user->notifiedMonitors()->attach($subscribed);

        $this->takeDown($subscribed);
        $this->takeDown($ignored);

        Notification::assertSentTo(
            $user,
            MonitorStatusChanged::class,
            fn (MonitorStatusChanged $n) => $n->monitor->is($subscribed),
        );
        Notification::assertSentToTimes($user, MonitorStatusChanged::class, 1);
    }

    public function test_selected_mode_without_subscriptions_receives_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_mode' => User::NOTIFY_SELECTED]);
        $monitor = Monitor::factory()->for($user)->create(['expected_status_code' => 200]);

        $this->takeDown($monitor);

        Notification::assertNothingSentTo($user);
    }

    public function test_all_mode_covers_monitors_created_after_the_preference_was_saved(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);

        $this->takeDown($monitor);

        Notification::assertSentTo($user, MonitorStatusChanged::class);
    }

    // -------------------------------------------------------------------------
    // Profile form
    // -------------------------------------------------------------------------

    public function test_profile_page_lists_every_monitor_to_choose_from(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create(['name' => 'Guia salarial']);

        $response = $this->actingAs($user)->get('/profile');

        $response->assertOk();
        $response->assertSee('Notification Preferences');
        $response->assertSee('Guia salarial');
        $response->assertSee($monitor->url);
    }

    public function test_user_can_switch_to_a_selected_list_of_monitors(): void
    {
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);
        $wanted = Monitor::factory()->create();
        $unwanted = Monitor::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile/notifications', [
                'notify_mode' => User::NOTIFY_SELECTED,
                'monitors' => [$wanted->id],
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame(User::NOTIFY_SELECTED, $user->notify_mode);
        $this->assertTrue($user->notifiedMonitors->contains($wanted));
        $this->assertFalse($user->notifiedMonitors->contains($unwanted));
    }

    public function test_user_can_opt_out_of_everything(): void
    {
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);

        $this->actingAs($user)
            ->patch('/profile/notifications', ['notify_mode' => User::NOTIFY_NONE])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::NOTIFY_NONE, $user->refresh()->notify_mode);
    }

    public function test_selection_survives_a_round_trip_through_another_mode(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->create();

        $this->actingAs($user)->patch('/profile/notifications', [
            'notify_mode' => User::NOTIFY_SELECTED,
            'monitors' => [$monitor->id],
        ]);
        $this->actingAs($user)->patch('/profile/notifications', [
            'notify_mode' => User::NOTIFY_ALL,
        ]);

        $this->assertTrue($user->refresh()->notifiedMonitors->contains($monitor));
    }

    public function test_selected_mode_with_no_checkboxes_clears_the_list(): void
    {
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_SELECTED]);
        $monitor = Monitor::factory()->create();
        $user->notifiedMonitors()->attach($monitor);

        $this->actingAs($user)
            ->patch('/profile/notifications', ['notify_mode' => User::NOTIFY_SELECTED])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $user->refresh()->notifiedMonitors);
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);

        $this->actingAs($user)
            ->patch('/profile/notifications', ['notify_mode' => 'everything'])
            ->assertSessionHasErrors('notify_mode', errorBag: 'updateNotificationPreferences');

        $this->assertSame(User::NOTIFY_ALL, $user->refresh()->notify_mode);
    }

    public function test_unknown_monitor_id_is_rejected(): void
    {
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_ALL]);

        $this->actingAs($user)
            ->patch('/profile/notifications', [
                'notify_mode' => User::NOTIFY_SELECTED,
                'monitors' => [999999],
            ])
            ->assertSessionHasErrors('monitors.0', errorBag: 'updateNotificationPreferences');

        $this->assertSame(User::NOTIFY_ALL, $user->refresh()->notify_mode);
    }

    public function test_guests_cannot_update_notification_preferences(): void
    {
        $this->patch('/profile/notifications', ['notify_mode' => User::NOTIFY_NONE])
            ->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Test email button
    // -------------------------------------------------------------------------

    public function test_profile_page_offers_a_test_email_button(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/profile');

        $response->assertSee('Send test email');
        $response->assertSee($user->email);
    }

    public function test_test_email_goes_to_the_signed_in_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $bystander = User::factory()->create();

        $response = $this->actingAs($user)->post('/profile/notifications/test');

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('test-notification-sent', session('status'));

        Notification::assertSentTo($user, TestNotification::class);
        Notification::assertNotSentTo($bystander, TestNotification::class);
    }

    public function test_test_email_is_sent_whatever_the_notification_mode(): void
    {
        Notification::fake();
        $user = User::factory()->create(['notify_mode' => User::NOTIFY_NONE]);

        $this->actingAs($user)->post('/profile/notifications/test');

        // Opting out of alerts must not disable the diagnostic itself.
        Notification::assertSentTo($user, TestNotification::class);
    }

    public function test_a_transport_failure_is_reported_instead_of_crashing(): void
    {
        Notification::shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused: smtp.example.com:587'));

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/profile/notifications/test');

        $response->assertRedirect('/profile');
        $response->assertSessionHasErrors('test', errorBag: 'sendTestNotification');
        $this->assertStringContainsString(
            'Connection refused',
            session('errors')->getBag('sendTestNotification')->first('test'),
        );
    }

    public function test_guests_cannot_send_test_emails(): void
    {
        $this->post('/profile/notifications/test')->assertRedirect('/login');
    }
}
