<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_can_access_admin_panel(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertStatus(200);
    }

    public function test_regular_user_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);

        $response = $this->actingAs($user)->get('/admin');

        $response->assertStatus(403);
    }

    public function test_guest_cannot_access_admin_panel(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/admin/login');
    }
}
