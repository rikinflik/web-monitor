<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicOverviewController;
use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        Monitor::factory()->down()->create(['name' => 'Down Service']);

        $this->get(route('public.overview', self::TOKEN))
            ->assertSee('Down Service');
    }

    public function test_up_monitor_is_not_shown(): void
    {
        Monitor::factory()->create(['name' => 'Up Service', 'status' => 'up']);

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

    /**
     * T8: this page has never rendered a severity pill, only the Security
     * flag — mirrors AlertController's dashboard, which derives its pill
     * from AlertFinding::severityBadge().
     */
    public function test_severity_pill_renders_alongside_the_security_flag(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            // Deliberately a title that does NOT contain the word "Critical"
            // itself — otherwise assertSee('Critical') below would pass on
            // the title text alone, proving nothing about the pill.
            'title' => 'A Noteworthy Advisory',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
            'is_security' => true,
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertSee('>Critical<', false);
        $response->assertSee('Security');
    }

    /**
     * T7b defect 1: mirrors AlertControllerTest's equivalent on this surface
     * — a finding whose url carries an unsafe scheme must never render as a
     * clickable href on the public (token-gated, no-login) page either.
     */
    public function test_a_finding_with_a_javascript_scheme_url_does_not_render_a_dangerous_href(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);

        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Finding With Dangerous Link',
            'url' => 'javascript:alert(document.cookie)',
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertOk();
        $response->assertSee('Finding With Dangerous Link');
        $response->assertDontSee('href="javascript:', false);
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

    // -------------------------------------------------------------------------
    // Staleness (T7e) — same contract as AlertControllerTest's equivalent,
    // pinned on this surface too since the public page previously rendered
    // no staleness signal at all.
    // -------------------------------------------------------------------------

    public function test_a_source_checked_before_the_staleness_threshold_renders_stale_not_healthy(): void
    {
        AlertSource::factory()->create([
            'name' => 'WordPress',
            'last_checked_at' => now()->subHours(AlertSource::STALE_AFTER_HOURS)->subMinute(),
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertOk();
        $response->assertSee('Stale');
        $response->assertDontSee('>Healthy<', false);
    }

    public function test_a_source_checked_within_the_staleness_threshold_still_renders_healthy(): void
    {
        AlertSource::factory()->create([
            'name' => 'WordPress',
            'last_checked_at' => now()->subHours(AlertSource::STALE_AFTER_HOURS)->addMinute(),
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertOk();
        $response->assertSee('Healthy');
        $response->assertDontSee('Stale');
    }

    /**
     * Precedence pin at the rendered-page level, mirroring AlertControllerTest's
     * equivalent: stale + failing must render "Check failing", not "Stale".
     */
    public function test_a_source_that_is_both_stale_and_failing_renders_check_failing_not_stale(): void
    {
        AlertSource::factory()->create([
            'name' => 'WordPress',
            'last_checked_at' => now()->subHours(AlertSource::STALE_AFTER_HOURS)->subDay(),
            'last_error' => 'Connection timed out after 10s',
        ]);

        $response = $this->get(route('public.overview', self::TOKEN));

        $response->assertOk();
        $response->assertSee('Check failing');
        $response->assertDontSee('Stale');
    }

    /**
     * T7d: this page's URL is the secret (/overview/{token}); a third-party
     * script running in-page could read window.location.href and exfiltrate
     * the token, which the Referrer-Policy/rel="noreferrer" hardening does
     * not close. It must compile locally via Vite instead.
     */
    public function test_page_does_not_load_tailwind_from_a_cdn(): void
    {
        $this->get(route('public.overview', self::TOKEN))
            ->assertDontSee('cdn.tailwindcss.com', false);
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

    /**
     * T7a: the "Showing the N most recent findings per source" prose must be
     * derived from PublicOverviewController::FINDINGS_PER_SOURCE (read here
     * via reflection, since the constant is private) rather than a literal
     * "5" in the Blade template — otherwise a future change to the cap
     * silently desyncs the copy from the actual behavior. Reading the
     * constant's live value (rather than hardcoding "5" in this assertion
     * too) means this test only passes if the page's wording actually
     * tracks whatever the constant currently says.
     */
    public function test_findings_per_source_prose_derives_from_the_constant(): void
    {
        $cap = (new \ReflectionClass(PublicOverviewController::class))->getConstant('FINDINGS_PER_SOURCE');

        $this->get(route('public.overview', self::TOKEN))
            ->assertSee("Showing the {$cap} most recent findings per source", false);
    }

    // -------------------------------------------------------------------------
    // Ordering (T7a) — this page shares its ranking SQL with AlertController
    // (constrainToRecentPerSource()/severityRankSql()); these tests pin that
    // contract on this surface too, so it cannot silently drift from the
    // dashboard's.
    // -------------------------------------------------------------------------

    /**
     * T7a: `medium` is the canonical vocabulary value for GitHub's REST
     * severity. The older/newer pairing (medium is OLDER than low) proves
     * severity, not recency, drives the order.
     */
    public function test_medium_severity_finding_outranks_a_low_severity_finding(): void
    {
        $source = AlertSource::factory()->create(['name' => 'Vue']);

        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Newer Low Finding',
            'severity' => AlertFinding::SEVERITY_LOW,
            'published_at' => now(),
        ]);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Older Medium Finding',
            'severity' => AlertFinding::SEVERITY_MEDIUM,
            'published_at' => now()->subDays(5),
        ]);

        $html = $this->get(route('public.overview', self::TOKEN))->getContent();

        $medium = strpos($html, 'Older Medium Finding');
        $low = strpos($html, 'Newer Low Finding');

        $this->assertNotFalse($medium);
        $this->assertNotFalse($low);
        $this->assertLessThan($low, $medium);
    }

    /**
     * Mirrors AlertControllerTest's equivalent, pinning the full
     * is_security / severity / recency ordering contract on this surface:
     * severity trumps recency, and unrated sorts after every declared
     * severity.
     */
    public function test_findings_are_ordered_by_severity_most_severe_first_then_recency_with_unrated_last(): void
    {
        $source = AlertSource::factory()->create(['name' => 'Laravel']);

        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Older Low Finding',
            'severity' => AlertFinding::SEVERITY_LOW,
            'published_at' => now()->subDays(5),
        ]);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Unrated Finding',
            'severity' => null,
            'published_at' => now(),
        ]);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Older Critical Finding',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
            'published_at' => now()->subDays(10),
        ]);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Newer Critical Finding',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
            'published_at' => now()->subDay(),
        ]);

        $html = $this->get(route('public.overview', self::TOKEN))->getContent();

        $newerCritical = strpos($html, 'Newer Critical Finding');
        $olderCritical = strpos($html, 'Older Critical Finding');
        $low = strpos($html, 'Older Low Finding');
        $unrated = strpos($html, 'Unrated Finding');

        $this->assertNotFalse($newerCritical);
        $this->assertNotFalse($olderCritical);
        $this->assertNotFalse($low);
        $this->assertNotFalse($unrated);

        $this->assertLessThan($olderCritical, $newerCritical);
        $this->assertLessThan($low, $olderCritical);
        $this->assertLessThan($unrated, $low);
    }

    // -------------------------------------------------------------------------
    // Findings cap (T7c defect 5) — this surface shares constrainToRecentPerSource()
    // with AlertController, but unlike AlertControllerTest's equivalent
    // (test_dashboard_caps_findings_per_source_and_shows_true_total()),
    // nothing here previously exercised the cap boundary: every ordering
    // test above creates fewer findings than FINDINGS_PER_SOURCE, so a
    // change to the constant's value, or a broken/deleted ORDER BY inside
    // the shared ROW_NUMBER() window (which decides which rows survive the
    // cap, not just how they render), would not fail anything in this file.
    // -------------------------------------------------------------------------

    /**
     * 8 findings on one source, cap is PublicOverviewController::FINDINGS_PER_SOURCE
     * (5): the eager-loaded collection handed to the view must contain
     * exactly 5 rows — the 5 most recently published — while the true total
     * (8) stays visible via "Showing 5 of 8".
     */
    public function test_public_overview_caps_findings_per_source_and_shows_true_total(): void
    {
        // Reuses the seeded 'php' source (RefreshDatabase seeds the six
        // fixed ecosystems via AlertSourceSeeder) rather than
        // AlertSource::factory()->create(['name' => 'PHP']): a factory row
        // would collide on the name "PHP" with the already-seeded source,
        // making firstWhere('name', 'PHP') below pick whichever of the two
        // happens to sort first — ambiguous and not what this test means to
        // exercise.
        $source = AlertSource::where('key', 'php')->firstOrFail();

        // Zero-padded labels ("01".."08") so no title is a substring of
        // another (e.g. unpadded "Finding 1" would be a substring of
        // "Finding 11"/"Finding 1X"), which would make assertSee/
        // assertDontSee below false positives regardless of production code.
        for ($i = 1; $i <= 8; $i++) {
            $label = sprintf('%02d', $i);
            AlertFinding::factory()->for($source, 'alertSource')->create([
                'external_id' => "finding-{$label}",
                'title' => "PHP Finding {$label}",
                'published_at' => Carbon::parse('2026-01-01')->addDays($i),
            ]);
        }

        $response = $this->get(route('public.overview', self::TOKEN));
        $response->assertOk();

        $loadedFindings = $response->viewData('sources')->firstWhere('name', 'PHP')->findings;

        // The cap must come from the query itself: if it were merely a
        // Blade-side take(5) over an uncapped eager load, the collection
        // handed to the view would still carry all 8 rows here.
        $this->assertCount(5, $loadedFindings);

        $response->assertSee('Showing 5 of 8');

        // The 5 kept are the most recently published; the 3 oldest
        // (days 1-3) must not appear at all.
        for ($i = 1; $i <= 3; $i++) {
            $response->assertDontSee('PHP Finding '.sprintf('%02d', $i));
        }
        for ($i = 4; $i <= 8; $i++) {
            $response->assertSee('PHP Finding '.sprintf('%02d', $i));
        }
    }

    /**
     * Mirrors AlertControllerTest's equivalent: a security finding must
     * sort above a strictly newer non-security finding of the same
     * (unrated) severity — this fails if the `is_security DESC` ordering
     * key is ever dropped from the shared query.
     */
    public function test_a_security_finding_sorts_above_a_newer_non_security_finding(): void
    {
        $source = AlertSource::factory()->create(['name' => 'PHP']);

        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Older Security Finding',
            'is_security' => true,
            'severity' => null,
            'published_at' => now()->subDays(5),
        ]);
        AlertFinding::factory()->for($source, 'alertSource')->create([
            'title' => 'Newer Non-Security Finding',
            'is_security' => false,
            'severity' => null,
            'published_at' => now(),
        ]);

        $html = $this->get(route('public.overview', self::TOKEN))->getContent();

        $security = strpos($html, 'Older Security Finding');
        $nonSecurity = strpos($html, 'Newer Non-Security Finding');

        $this->assertNotFalse($security);
        $this->assertNotFalse($nonSecurity);
        $this->assertLessThan($nonSecurity, $security);
    }
}
