<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AlertController renders the authenticated dashboard for the six tracked
 * AlertSource ecosystems (global, not user-owned: viewing is open to any
 * authenticated user) and exposes an admin-only synchronous "check now".
 */
#[Group('alert-controller')]
class AlertControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function minimalAtomFeed(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Feed</title></feed>';
    }

    private function minimalRssFeed(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Feed</title></channel></rss>';
    }

    /**
     * Stub every one of the six seeded sources' upstream endpoints with a
     * valid, empty response so a real checkNow() pass completes successfully
     * instead of recording a failure.
     *
     * The GitHub Advisories stub is a closure, not a single Http::response()
     * instance: three of the six sources (Laravel/Vue/Angular) share that
     * URL pattern, and GitHubAdvisoryFetcher reads its response body off a
     * streamed PSR-7 body that it explicitly closes after reading — reusing
     * one pre-built response/stream across those three real requests would
     * make the second and third read a stream already closed by the first,
     * an artifact of sharing a fake response rather than anything the
     * production code (three genuinely independent HTTP responses) does
     * wrong.
     */
    private function fakeAllSixSourcesHealthy(): void
    {
        Http::fake([
            'https://www.php.net/feed.atom' => Http::response($this->minimalAtomFeed(), 200),
            'https://www.drupal.org/security/rss.xml' => Http::response($this->minimalRssFeed(), 200),
            'https://wordpress.org/news/category/security/feed/' => Http::response($this->minimalRssFeed(), 200),
            'https://api.github.com/advisories*' => fn () => Http::response('[]', 200),
        ]);
    }

    // -------------------------------------------------------------------------
    // Authentication guard
    // -------------------------------------------------------------------------

    public function test_guest_is_redirected_from_index(): void
    {
        $this->get(route('alerts.index'))->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_check_now(): void
    {
        $this->post(route('alerts.check-now'))->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Index — open to any authenticated user, lists all six sources
    // -------------------------------------------------------------------------

    public function test_authenticated_non_admin_can_view_index(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertOk();
    }

    public function test_index_lists_all_six_tracked_sources(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($user)->get(route('alerts.index'));

        $response->assertOk();
        foreach (['PHP', 'Drupal', 'WordPress', 'Laravel', 'Vue', 'Angular'] as $name) {
            $response->assertSee($name);
        }
    }

    public function test_index_shows_never_checked_fallback_for_a_fresh_source(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertSee('never');
    }

    // -------------------------------------------------------------------------
    // Failure visibility — AC: a failed source must look obviously different
    // from a quiet one.
    // -------------------------------------------------------------------------

    public function test_failing_source_is_visibly_distinct_from_a_healthy_source(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $failing = AlertSource::where('key', 'php')->firstOrFail();
        $failing->update([
            'last_checked_at' => now(),
            'last_success_at' => Carbon::parse('2026-01-01 00:00:00'),
            'last_error' => 'Permanent failure (needs attention): HTTP 404 from www.php.net',
        ]);

        $healthy = AlertSource::where('key', 'drupal')->firstOrFail();
        $healthy->update([
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ]);

        $response = $this->actingAs($user)->get(route('alerts.index'));

        $response->assertOk();
        $response->assertSee('Check failing');
        $response->assertSee($failing->last_error);
        $response->assertSee('Healthy');

        // Exactly one source is in a failing state; the badge must not leak
        // onto the healthy one.
        $this->assertSame(1, substr_count($response->getContent(), 'Check failing'));
    }

    // -------------------------------------------------------------------------
    // Findings rendering — fallbacks for null published_at / null severity
    // -------------------------------------------------------------------------

    public function test_index_renders_fallback_for_a_finding_with_no_published_date(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'wordpress')->firstOrFail();

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Undated WordPress Advisory',
            'published_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertSee('Undated WordPress Advisory')
            ->assertSee('Unknown date');
    }

    public function test_index_renders_unrated_fallback_for_a_finding_with_no_severity(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'wordpress')->firstOrFail();

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Severity-less WordPress Advisory',
            'severity' => null,
        ]);

        $response = $this->actingAs($user)->get(route('alerts.index'));

        $response->assertSee('Severity-less WordPress Advisory');
        $response->assertSee('Unrated');
        // Must not be rendered as (or alongside) the "Low" badge, which
        // would falsely imply the finding was assessed and found low-risk.
        $response->assertDontSee('>Low<', false);
    }

    // -------------------------------------------------------------------------
    // Ordering — AC: findings are ordered as intended
    // -------------------------------------------------------------------------

    public function test_findings_are_ordered_by_severity_most_severe_first_then_recency_with_unrated_last(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'laravel')->firstOrFail();

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Older Low Finding',
            'severity' => AlertFinding::SEVERITY_LOW,
            'published_at' => now()->subDays(5),
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Unrated Finding',
            'severity' => null,
            'published_at' => now(),
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Older Critical Finding',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
            'published_at' => now()->subDays(10),
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Newer Critical Finding',
            'severity' => AlertFinding::SEVERITY_CRITICAL,
            'published_at' => now()->subDay(),
        ]);

        $html = $this->actingAs($user)->get(route('alerts.index'))->getContent();

        $newerCritical = strpos($html, 'Newer Critical Finding');
        $olderCritical = strpos($html, 'Older Critical Finding');
        $low = strpos($html, 'Older Low Finding');
        $unrated = strpos($html, 'Unrated Finding');

        $this->assertNotFalse($newerCritical);
        $this->assertNotFalse($olderCritical);
        $this->assertNotFalse($low);
        $this->assertNotFalse($unrated);

        // Same severity: more recent first.
        $this->assertLessThan($olderCritical, $newerCritical);
        // Severity trumps recency: critical (even older) before low.
        $this->assertLessThan($low, $olderCritical);
        // Unrated sorts after every declared severity.
        $this->assertLessThan($unrated, $low);
    }

    // -------------------------------------------------------------------------
    // Security ordering (T5c) — AC: security findings sort above the rest
    // within a source's cap, even when newer non-security findings exist.
    // The stored, indexed is_security flag (not a render-time computation)
    // is what makes this possible once the cap is in play.
    // -------------------------------------------------------------------------

    /**
     * Same severity (both unrated) and the non-security item is strictly
     * MORE recent: under the pre-T5c ordering (severity then recency only)
     * the newer non-security item would render first. This fails if the
     * is_security ordering key is ever dropped from the query.
     */
    public function test_a_security_finding_sorts_above_a_newer_non_security_finding(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'php')->firstOrFail();

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Older Security Finding',
            'is_security' => true,
            'severity' => null,
            'published_at' => now()->subDays(5),
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Newer Non-Security Finding',
            'is_security' => false,
            'severity' => null,
            'published_at' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('alerts.index'))->getContent();

        $security = strpos($html, 'Older Security Finding');
        $nonSecurity = strpos($html, 'Newer Non-Security Finding');

        $this->assertNotFalse($security);
        $this->assertNotFalse($nonSecurity);
        $this->assertLessThan($nonSecurity, $security);
    }

    /**
     * The cap itself must respect the security-first ordering: three older
     * security findings plus ten newer non-security findings (13 total,
     * cap 10) must keep all three security findings and drop the three
     * OLDEST non-security ones, not the security findings.
     */
    public function test_cap_keeps_security_findings_over_newer_non_security_ones(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'laravel')->firstOrFail();

        for ($i = 1; $i <= 3; $i++) {
            AlertFinding::factory()->create([
                'alert_source_id' => $source->id,
                'external_id' => "security-{$i}",
                'title' => 'Security Finding '.sprintf('%02d', $i),
                'is_security' => true,
                'severity' => null,
                'published_at' => Carbon::parse('2026-01-01')->addDays($i),
            ]);
        }

        for ($i = 1; $i <= 10; $i++) {
            AlertFinding::factory()->create([
                'alert_source_id' => $source->id,
                'external_id' => "non-security-{$i}",
                'title' => 'Non-Security Finding '.sprintf('%02d', $i),
                'is_security' => false,
                'severity' => null,
                'published_at' => Carbon::parse('2026-06-01')->addDays($i),
            ]);
        }

        $response = $this->actingAs($user)->get(route('alerts.index'));
        $response->assertOk();

        $loadedFindings = $response->viewData('sources')->firstWhere('key', 'laravel')->findings;
        $this->assertCount(10, $loadedFindings);

        for ($i = 1; $i <= 3; $i++) {
            $response->assertSee('Security Finding '.sprintf('%02d', $i));
        }

        // The 3 oldest non-security findings must be dropped by the cap.
        for ($i = 1; $i <= 3; $i++) {
            $response->assertDontSee('Non-Security Finding '.sprintf('%02d', $i));
        }
        for ($i = 4; $i <= 10; $i++) {
            $response->assertSee('Non-Security Finding '.sprintf('%02d', $i));
        }
    }

    /**
     * The table must mark security findings visibly, reusing existing
     * badge/pill styling rather than inventing a new one.
     */
    public function test_a_security_finding_shows_a_security_badge_and_a_non_security_finding_does_not(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'wordpress')->firstOrFail();

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Flagged WordPress Advisory',
            'is_security' => true,
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'title' => 'Unflagged WordPress Item',
            'is_security' => false,
        ]);

        $response = $this->actingAs($user)->get(route('alerts.index'));

        $response->assertOk();
        $response->assertSee('Security');
        // Exactly one "Security" badge: it must not leak onto the
        // non-security row.
        $this->assertSame(1, substr_count($response->getContent(), '>Security<'));
    }

    // -------------------------------------------------------------------------
    // Findings cap — AC: the dashboard must not render every finding a
    // source has ever had (php.net/feed.atom alone produced 495 in a real
    // run). The cap is enforced in the query, so the loaded collection
    // itself (not just the rendered HTML) must be bounded.
    // -------------------------------------------------------------------------

    /**
     * 15 findings on one source, cap is 10: the eager-loaded collection
     * handed to the view must contain exactly 10 rows, while the true total
     * (15) stays visible via the "Showing 10 of 15" text — nothing looks
     * silently hidden.
     */
    public function test_dashboard_caps_findings_per_source_and_shows_true_total(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'php')->firstOrFail();

        // Titles are zero-padded ("01".."15") so no title is a substring of
        // another (e.g. unpadded "Finding 1" would be a substring of
        // "Finding 11"), which would make assertSee/assertDontSee below
        // false positives regardless of what the production code does.
        for ($i = 1; $i <= 15; $i++) {
            $label = sprintf('%02d', $i);
            AlertFinding::factory()->create([
                'alert_source_id' => $source->id,
                'external_id' => "finding-{$label}",
                'title' => "PHP Finding {$label}",
                'published_at' => Carbon::parse('2026-01-01')->addDays($i),
            ]);
        }

        $response = $this->actingAs($user)->get(route('alerts.index'));
        $response->assertOk();

        $loadedFindings = $response->viewData('sources')->firstWhere('key', 'php')->findings;

        // The cap must come from the query itself: if it were merely a
        // Blade-side `take(10)` over an uncapped eager load, the collection
        // handed to the view would still carry all 15 rows here.
        $this->assertCount(10, $loadedFindings);

        $response->assertSee('Showing 10 of 15');

        // The 10 kept are the most recently published ones; the 5 oldest
        // (days 1-5) must not appear at all.
        for ($i = 1; $i <= 5; $i++) {
            $response->assertDontSee('PHP Finding '.sprintf('%02d', $i));
        }
        for ($i = 11; $i <= 15; $i++) {
            $response->assertSee('PHP Finding '.sprintf('%02d', $i));
        }
    }

    /**
     * Mixes severities and dates past the cap (12 findings, cap 10) to prove
     * truncation discards the least-important rows (lowest severity, then
     * oldest) rather than disturbing the severity-then-recency order itself.
     */
    public function test_cap_discards_lowest_priority_findings_while_preserving_order(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $source = AlertSource::where('key', 'laravel')->firstOrFail();

        // 10 critical findings, newest first by day offset, that must all
        // survive the cap. Zero-padded labels ("01".."10") so no title is a
        // substring of another.
        for ($i = 1; $i <= 10; $i++) {
            AlertFinding::factory()->create([
                'alert_source_id' => $source->id,
                'external_id' => "critical-{$i}",
                'title' => 'Critical Finding '.sprintf('%02d', $i),
                'severity' => AlertFinding::SEVERITY_CRITICAL,
                'published_at' => Carbon::parse('2026-01-01')->addDays($i),
            ]);
        }

        // 2 low-severity findings that rank below all 10 criticals and must
        // be dropped by the cap entirely.
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'external_id' => 'low-1',
            'title' => 'Low Finding That Must Be Dropped 1',
            'severity' => AlertFinding::SEVERITY_LOW,
            'published_at' => Carbon::parse('2026-01-01')->addDays(20),
        ]);
        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'external_id' => 'low-2',
            'title' => 'Low Finding That Must Be Dropped 2',
            'severity' => AlertFinding::SEVERITY_LOW,
            'published_at' => Carbon::parse('2026-01-01')->addDays(21),
        ]);

        $response = $this->actingAs($user)->get(route('alerts.index'));
        $response->assertOk();

        $loadedFindings = $response->viewData('sources')->firstWhere('key', 'laravel')->findings;

        $this->assertCount(10, $loadedFindings);
        $this->assertTrue($loadedFindings->every(fn ($f) => $f->severity === AlertFinding::SEVERITY_CRITICAL));

        $response->assertDontSee('Low Finding That Must Be Dropped 1');
        $response->assertDontSee('Low Finding That Must Be Dropped 2');

        // Severity-then-recency order still holds among the surviving 10:
        // day+10 (newest) must render before day+01 (oldest).
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Critical Finding 01'),
            strpos($html, 'Critical Finding 10'),
        );
    }

    // -------------------------------------------------------------------------
    // Check now — admin only, synchronous
    // -------------------------------------------------------------------------

    public function test_non_admin_cannot_check_now(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->post(route('alerts.check-now'))
            ->assertForbidden();
    }

    public function test_admin_check_now_succeeds_and_refreshes_data(): void
    {
        $this->fakeAllSixSourcesHealthy();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('alerts.check-now'))
            ->assertRedirect(route('alerts.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            6,
            AlertSource::query()->whereNotNull('last_checked_at')->count(),
            'Expected every tracked source to have been checked.'
        );
    }

    public function test_navigation_shows_the_alerts_tab(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->get(route('alerts.index'))
            ->assertSee('Tech Update Alerts');
    }
}
