<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\SeoCheck;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('seo-check-controller')]
class SeoCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a mocked Guzzle client so the synchronous re-check never makes real
     * network calls (6 OK responses cover the three redirect probes plus
     * robots + sitemap, with headroom for the sitemap_index fallback).
     */
    private function fakeGuzzle(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
        ]));
        $this->app->bind(Client::class, fn () => new Client(['handler' => $stack]));
    }

    // -------------------------------------------------------------------------
    // Authentication guard
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_from_index(): void
    {
        $this->get(route('seo.index'))->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_create(): void
    {
        $this->get(route('seo.create'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_store(): void
    {
        $this->post(route('seo.store'), ['name' => 'X', 'url' => 'https://example.com'])
            ->assertRedirect(route('login'));
    }

    public function test_guest_cannot_recheck(): void
    {
        $seoCheck = Monitor::factory()->create()->seoCheck;
        $this->post(route('seo.recheck', $seoCheck))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Index isolation
    // -------------------------------------------------------------------------

    public function test_index_shows_only_the_authenticated_users_entries(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Monitor::factory()->for($user)->create(['name' => 'My SEO URL']);
        Monitor::factory()->for($other)->create(['name' => 'Other SEO URL']);

        $this->actingAs($user)
            ->get(route('seo.index'))
            ->assertOk()
            ->assertSee('My SEO URL')
            ->assertDontSee('Other SEO URL');
    }

    // -------------------------------------------------------------------------
    // Store
    // -------------------------------------------------------------------------

    public function test_store_creates_monitor_with_pinned_defaults_and_paired_seo_check(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seo.store'), ['name' => 'New SEO URL', 'url' => 'https://example.com'])
            ->assertRedirect(route('seo.index'));

        $this->assertDatabaseHas('monitors', [
            'name' => 'New SEO URL',
            'user_id' => $user->id,
            'interval' => 60,
            'timeout' => 30,
            'expected_status_code' => 200,
        ]);
        $this->assertDatabaseCount('seo_checks', 1);
    }

    public function test_store_requires_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seo.store'), ['name' => '', 'url' => 'https://example.com'])
            ->assertSessionHasErrors('name');
    }

    public function test_store_requires_valid_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seo.store'), ['name' => 'X', 'url' => 'not-a-url'])
            ->assertSessionHasErrors('url');
    }

    public function test_store_rejects_private_ip_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('seo.store'), ['name' => 'X', 'url' => 'http://192.168.1.1/'])
            ->assertSessionHasErrors('url');
    }

    // -------------------------------------------------------------------------
    // Recheck — ownership + synchronous execution
    // -------------------------------------------------------------------------

    public function test_owner_can_recheck_and_is_redirected_with_flash(): void
    {
        $this->fakeGuzzle();
        $user = User::factory()->create();
        $seoCheck = Monitor::factory()->for($user)->create()->seoCheck;

        $this->actingAs($user)
            ->post(route('seo.recheck', $seoCheck))
            ->assertRedirect(route('seo.index'))
            ->assertSessionHas('success');
    }

    public function test_other_user_cannot_recheck(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $seoCheck = Monitor::factory()->for($owner)->create()->seoCheck;

        $this->actingAs($other)
            ->post(route('seo.recheck', $seoCheck))
            ->assertForbidden();
    }

    public function test_recheck_runs_synchronously_and_sets_last_checked_at(): void
    {
        $this->fakeGuzzle();
        $user = User::factory()->create();
        $seoCheck = Monitor::factory()->for($user)->create()->seoCheck;
        $this->assertNull($seoCheck->last_checked_at);

        $this->actingAs($user)->post(route('seo.recheck', $seoCheck));

        $this->assertNotNull($seoCheck->fresh()->last_checked_at);
    }

    // -------------------------------------------------------------------------
    // Index table UI (PR 5)
    // -------------------------------------------------------------------------

    public function test_index_renders_a_column_header_for_each_check(): void
    {
        $user = User::factory()->create();
        Monitor::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('seo.index'));

        $response->assertSee('www');
        $response->assertSee('HTTPS');
        $response->assertSee('Trailing');
        $response->assertSee('robots');
        $response->assertSee('Sitemap');
        $response->assertSee('Revisión');
    }

    public function test_index_shows_never_checked_fallback_for_new_entry(): void
    {
        $user = User::factory()->create();
        Monitor::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('seo.index'))
            ->assertSee('Nunca');
    }

    public function test_index_renders_detected_redirect_token_with_green_pill(): void
    {
        $user = User::factory()->create();
        $seoCheck = Monitor::factory()->for($user)->create()->seoCheck;
        $seoCheck->update(['www_redirect' => SeoCheck::WWW_TO_NO_WWW]);

        $response = $this->actingAs($user)->get(route('seo.index'));

        $response->assertSee(SeoCheck::WWW_TO_NO_WWW);
        $response->assertSee('bg-green-100 text-green-800');
    }

    public function test_index_renders_failed_robots_with_red_pill(): void
    {
        $user = User::factory()->create();
        $seoCheck = Monitor::factory()->for($user)->create()->seoCheck;
        $seoCheck->update(['robots_ok' => false]);

        $response = $this->actingAs($user)->get(route('seo.index'));

        $response->assertSee('bg-red-100 text-red-800');
    }

    public function test_index_renders_none_dimension_with_gray_pill(): void
    {
        $user = User::factory()->create();
        Monitor::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('seo.index'))
            ->assertSee('bg-gray-100 text-gray-800');
    }

    public function test_navigation_shows_the_seo_tab(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('seo.index'))
            ->assertSee('SEO / Redirects');
    }

    public function test_monitor_index_still_renders_without_regression(): void
    {
        $user = User::factory()->create();
        Monitor::factory()->for($user)->create(['name' => 'Regression Fixture']);

        $this->actingAs($user)
            ->get(route('monitors.index'))
            ->assertOk()
            ->assertSee('Regression Fixture')
            ->assertSee('Mis Monitores');
    }
}
