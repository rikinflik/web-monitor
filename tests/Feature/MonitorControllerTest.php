<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('monitor-controller')]
class MonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Monitor',
            'url' => 'https://example.com',
            'interval' => 60,
            'timeout' => 10,
            'expected_status_code' => 200,
            'keyword' => null,
            'webhook_url' => null,
            'basic_auth_user' => null,
            'basic_auth_password' => null,
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Authentication guard
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_from_index(): void
    {
        $this->get(route('monitors.index'))->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_create(): void
    {
        $this->get(route('monitors.create'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_store_monitor(): void
    {
        $this->post(route('monitors.store'), $this->validPayload())->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_show(): void
    {
        $monitor = Monitor::factory()->create();
        $this->get(route('monitors.show', $monitor))->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_edit(): void
    {
        $monitor = Monitor::factory()->create();
        $this->get(route('monitors.edit', $monitor))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_update_monitor(): void
    {
        $monitor = Monitor::factory()->create();
        $this->put(route('monitors.update', $monitor), $this->validPayload())->assertRedirect(route('login'));
    }

    public function test_guest_cannot_delete_monitor(): void
    {
        $monitor = Monitor::factory()->create();
        $this->delete(route('monitors.destroy', $monitor))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Index — isolation between users
    // -------------------------------------------------------------------------

    public function test_index_shows_only_the_authenticated_users_monitors(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $own = Monitor::factory()->for($user)->create(['name' => 'My Monitor']);
        Monitor::factory()->for($other)->create(['name' => 'Other Monitor']);

        $this->actingAs($user)
            ->get(route('monitors.index'))
            ->assertSee('My Monitor')
            ->assertDontSee('Other Monitor');
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_store_creates_monitor_with_valid_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['name' => 'New Monitor']))
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseHas('monitors', [
            'name' => 'New Monitor',
            'user_id' => $user->id,
        ]);
    }

    public function test_store_requires_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_store_requires_valid_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['url' => 'not-a-url']))
            ->assertSessionHasErrors('url');
    }

    public function test_store_rejects_private_ip_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['url' => 'http://192.168.1.1/']))
            ->assertSessionHasErrors('url');
    }

    public function test_store_rejects_expected_status_code_below_100(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['expected_status_code' => 99]))
            ->assertSessionHasErrors('expected_status_code');
    }

    public function test_store_rejects_expected_status_code_above_599(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['expected_status_code' => 600]))
            ->assertSessionHasErrors('expected_status_code');
    }

    public function test_store_rejects_interval_below_60_seconds(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('monitors.store'), $this->validPayload(['interval' => 30]))
            ->assertSessionHasErrors('interval');
    }

    // -------------------------------------------------------------------------
    // Show — authorization
    // -------------------------------------------------------------------------

    public function test_owner_can_view_their_monitor(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('monitors.show', $monitor))
            ->assertOk();
    }

    public function test_other_user_cannot_view_a_monitor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $monitor = Monitor::factory()->for($owner)->create();

        $this->actingAs($other)
            ->get(route('monitors.show', $monitor))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function test_owner_can_update_their_monitor(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->for($user)->create(['name' => 'Old Name']);

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), $this->validPayload(['name' => 'New Name']))
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'New Name']);
    }

    public function test_other_user_cannot_update_a_monitor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $monitor = Monitor::factory()->for($owner)->create(['name' => 'Original']);

        $this->actingAs($other)
            ->put(route('monitors.update', $monitor), $this->validPayload(['name' => 'Hijacked']))
            ->assertForbidden();

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'name' => 'Original']);
    }

    public function test_blank_password_on_update_keeps_existing_password(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->for($user)->withBasicAuth('admin', 'secret')->create();

        $this->actingAs($user)
            ->put(route('monitors.update', $monitor), $this->validPayload([
                'basic_auth_user' => 'admin',
                'basic_auth_password' => '',
            ]));

        // Password must still be set (encrypted, so we check it is not null)
        $this->assertNotNull($monitor->fresh()->basic_auth_password);
    }

    // -------------------------------------------------------------------------
    // Destroy
    // -------------------------------------------------------------------------

    public function test_owner_can_delete_their_monitor(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete(route('monitors.destroy', $monitor))
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseMissing('monitors', ['id' => $monitor->id]);
    }

    public function test_other_user_cannot_delete_a_monitor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $monitor = Monitor::factory()->for($owner)->create();

        $this->actingAs($other)
            ->delete(route('monitors.destroy', $monitor))
            ->assertForbidden();

        $this->assertDatabaseHas('monitors', ['id' => $monitor->id]);
    }

    public function test_deleting_a_monitor_also_removes_its_logs(): void
    {
        $user = User::factory()->create();
        $monitor = Monitor::factory()->for($user)->create();
        $monitor->logs()->create([
            'status' => 'up',
            'response_time' => 100,
            'status_code' => 200,
            'checked_at' => now(),
        ]);

        $this->actingAs($user)->delete(route('monitors.destroy', $monitor));

        $this->assertDatabaseMissing('monitor_logs', ['monitor_id' => $monitor->id]);
    }
}
