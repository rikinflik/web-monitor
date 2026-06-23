<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group public-status
 */
class PublicStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_page_is_accessible_without_authentication(): void
    {
        $monitor = Monitor::factory()->create();

        $this->get(route('public.status', $monitor->public_token))
            ->assertOk();
    }

    public function test_public_status_page_shows_monitor_name(): void
    {
        $monitor = Monitor::factory()->create(['name' => 'My Service']);

        $this->get(route('public.status', $monitor->public_token))
            ->assertSee('My Service');
    }

    public function test_public_status_page_returns_404_for_invalid_token(): void
    {
        $this->get(route('public.status', 'invalid-token-that-does-not-exist'))
            ->assertNotFound();
    }

    public function test_public_status_page_shows_up_status(): void
    {
        $monitor = Monitor::factory()->create(['status' => 'up']);

        $this->get(route('public.status', $monitor->public_token))
            ->assertSeeText('ONLINE');
    }

    public function test_public_status_page_shows_down_status(): void
    {
        $monitor = Monitor::factory()->down()->create();

        $this->get(route('public.status', $monitor->public_token))
            ->assertSeeText('OFFLINE');
    }

    public function test_public_status_page_shows_recent_logs(): void
    {
        $monitor = Monitor::factory()->create();
        $monitor->logs()->create([
            'status' => 'up',
            'response_time' => 123,
            'status_code' => 200,
            'checked_at' => now(),
        ]);

        $this->get(route('public.status', $monitor->public_token))
            ->assertSee('200');
    }

    public function test_public_status_page_does_not_expose_owner_information(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $monitor = Monitor::factory()->for($user)->create();

        $this->get(route('public.status', $monitor->public_token))
            ->assertDontSee('owner@example.com');
    }
}
