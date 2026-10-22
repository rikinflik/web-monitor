<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicOverviewController;
use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Token-gated public overview page: lists every monitor currently down plus
 * the tracked technology alerts, with no login required.
 */
#[Group('public-overview')]
class PublicOverviewTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-very-secret-global-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitoring.public_overview_token' => self::TOKEN]);
    }

    public function test_correct_token_returns_200(): void
    {
        $this->get(route('public.overview', self::TOKEN))
            ->assertOk();
    }

    public function test_wrong_token_returns_404(): void
    {
        $this->get(route('public.overview', 'wrong-token'))
            ->assertNotFound();
    }

    public function test_empty_configured_token_404s_for_any_supplied_token(): void
    {
        config(['monitoring.public_overview_token' => '']);

        $this->get(route('public.overview', 'whatever'))
            ->assertNotFound();
    }

    public function test_unset_configured_token_404s(): void
    {
        config(['monitoring.public_overview_token' => null]);

        $this->get(route('public.overview', self::TOKEN))
            ->assertNotFound();
    }

    /**
     * The naive bug this guards against: comparing the configured token and
     * the supplied token with `===` (or even `hash_equals`) lets an empty
     * configured token match an empty supplied token, since '' === '' and
     * hash_equals('', '') are both true in PHP. A route segment can never
     * actually carry an empty string, so the only way to exercise this is to
     * call the controller directly with both sides empty.
     */
    public function test_empty_configured_token_404s_even_when_supplied_token_is_also_empty(): void
    {
        config(['monitoring.public_overview_token' => '']);

        $controller = app(PublicOverviewController::class);

        try {
            $controller->show('');
            $this->fail('Expected a 404 when both the configured and supplied tokens are empty.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_down_monitor_is_shown(): void
    {
        $monitor = Monitor::factory()->down()->create(['name' => 'Down Service']);

        $this->get(route('public.overview', self::TOKEN))
            ->assertSee('Down Service');
    }

    public function test_up_monitor_is_not_shown(): void
    {
        $monitor = Monitor::factory()->create(['name' => 'Up Service', 'status' => 'up']);

        $this->get(route('public.overview', self::TOKEN))
            ->assertDontSee('Up Service');
    }

    public function test_empty_state_copy_shown_when_nothing_is_down(): void
    {
        Monitor::factory()->create(['status' => 'up']);

        $this->get(route('public.overview', self::TOKEN))
            ->assertSee('All monitors are currently up');
    }

    public function test_response_carries_noindex_and_no_referrer_headers(): void
    {
        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_outbound_advisory_links_carry_rel_noreferrer(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'A critical PHP advisory',
            'url' => 'https://php.net/advisory/1',
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertSee('rel="noreferrer noopener"', false);
        $response->assertSee('https://php.net/advisory/1', false);
    }

    public function test_failing_alert_source_is_visible(): void
    {
        AlertSource::factory()->create([
            'name' => 'WordPress',
            'last_error' => 'HTTP 403 from wordpress.org',
            'last_checked_at' => now(),
        ]);

        $this->get(route('public.overview', self::TOKEN))
            ->assertSee('Check failing')
            ->assertSee('HTTP 403 from wordpress.org');
    }

    public function test_route_is_throttled(): void
    {
        $middleware = Route::getRoutes()->getByName('public.overview')->gatherMiddleware();

        $this->assertContains('throttle:30,1', $middleware);
    }

    /**
     * The token is a secret carried in a URL: a `===`/loose comparison is
     * timing-distinguishable and leaks information about how many leading
     * characters matched, byte by byte, to an attacker who can measure
     * response time. This property is not observable through an HTTP
     * response (both `===` and hash_equals() return the same true/false),
     * so it is pinned by reading the source directly — the only way to
     * prove the safe primitive is actually used rather than accidentally
     * behaviourally-equivalent loose equality.
     */
    public function test_token_comparison_uses_hash_equals_not_loose_equality(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/PublicOverviewController.php'));

        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertStringNotContainsString('$configuredToken === $token', $source);
        $this->assertStringNotContainsString('$token === $configuredToken', $source);
    }
}
