<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Open (no token, no session) public overview page: the technology alerts in
 * full plus a down-monitor COUNT only — never a monitor's name, URL, or
 * outage duration. See PublicOverviewController::open() for why those
 * fields never even enter PHP for this page, unlike the token-gated
 * show() above it.
 */
#[Group('public-overview')]
class PublicOpenOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_overview_returns_200_with_no_session_and_no_token(): void
    {
        $this->get(route('public.overview.open'))
            ->assertOk();
    }

    /**
     * The test that matters most: a monitor's name and URL must never reach
     * this page, while the down COUNT must.
     */
    public function test_a_down_monitor_name_and_url_never_appear_but_the_count_does(): void
    {
        Monitor::factory()->down()->create([
            'name' => 'Totally Secret Client Portal',
            'url' => 'https://secret-client-portal.example.com',
        ]);

        $response = $this->get(route('public.overview.open'));

        $response->assertOk();
        $response->assertDontSee('Totally Secret Client Portal');
        $response->assertDontSee('secret-client-portal.example.com', false);
        $response->assertSee('1 web caiguda');
    }

    public function test_count_pluralizes_for_more_than_one_down_monitor(): void
    {
        Monitor::factory()->down()->create(['name' => 'Alpha', 'url' => 'https://alpha.example.com']);
        Monitor::factory()->down()->create(['name' => 'Beta', 'url' => 'https://beta.example.com']);
        Monitor::factory()->down()->create(['name' => 'Gamma', 'url' => 'https://gamma.example.com']);

        $response = $this->get(route('public.overview.open'));

        $response->assertSee('3 webs caigudes');
        $response->assertDontSee('Alpha');
        $response->assertDontSee('Beta');
        $response->assertDontSee('Gamma');
    }

    public function test_empty_state_copy_shown_when_nothing_is_down(): void
    {
        Monitor::factory()->create(['status' => Monitor::STATUS_UP]);

        $this->get(route('public.overview.open'))
            ->assertSee('Totes les webs actives');
    }

    public function test_login_button_links_to_the_login_route(): void
    {
        $response = $this->get(route('public.overview.open'));

        $response->assertSee('href="'.route('login').'"', false);
    }

    public function test_response_carries_noindex_and_no_referrer_headers(): void
    {
        $response = $this->get(route('public.overview.open'));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_technology_alerts_are_shown(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'A critical PHP advisory',
            'url' => 'https://php.net/advisory/1',
        ]);

        $this->get(route('public.overview.open'))
            ->assertSee('A critical PHP advisory');
    }

    public function test_severity_pill_renders_on_the_open_page(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            // Deliberately a title that does NOT contain the word "Critical"
            // itself — otherwise assertSee('>Critical<') below would pass on
            // the title text alone, proving nothing about the pill.
            'title' => 'A Noteworthy Advisory',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
        ]);

        $this->get(route('public.overview.open'))
            ->assertSee('>Critical<', false);
    }

    /**
     * The route must not collide with GET /overview/{token} — both must
     * resolve independently.
     */
    public function test_open_route_does_not_collide_with_the_token_route(): void
    {
        config(['monitoring.public_overview_token' => 'some-token']);

        $this->get(route('public.overview.open'))->assertOk();
        $this->get(route('public.overview', 'some-token'))->assertOk();
    }

    public function test_route_is_throttled(): void
    {
        $middleware = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('public.overview.open')
            ->gatherMiddleware();

        $this->assertContains('throttle:30,1', $middleware);
    }
}
