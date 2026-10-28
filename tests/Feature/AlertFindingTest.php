<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AlertFinding's severity vocabulary: normalization at the fetcher boundary
 * (AlertFinding::normalizeSeverity()), ranking (severityRank()), and the
 * label/pill derivation used by the Blade views (severityBadge()).
 *
 * T7a: the REST `GET /advisories` endpoint GitHubAdvisoryFetcher actually
 * calls returns `unknown | low | medium | high | critical` — `moderate` is
 * the GraphQL-only spelling, accepted here as an input alias but never
 * stored. `medium` is the canonical vocabulary value.
 */
#[Group('alert-finding')]
class AlertFindingTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // normalizeSeverity() — boundary normalization
    // -------------------------------------------------------------------------

    public function test_normalize_severity_lowercases_and_trims(): void
    {
        $this->assertSame('critical', AlertFinding::normalizeSeverity(' Critical '));
    }

    public function test_normalize_severity_maps_moderate_alias_to_medium(): void
    {
        $this->assertSame('medium', AlertFinding::normalizeSeverity('moderate'));
    }

    public function test_normalize_severity_maps_moderate_alias_case_insensitively(): void
    {
        $this->assertSame('medium', AlertFinding::normalizeSeverity('Moderate'));
    }

    public function test_normalize_severity_passes_through_known_medium(): void
    {
        $this->assertSame('medium', AlertFinding::normalizeSeverity('medium'));
    }

    /**
     * GitHub's REST endpoint's own "unknown" value must degrade to null —
     * not be stored as a real-looking but unranked label.
     */
    public function test_normalize_severity_maps_github_unknown_to_null(): void
    {
        $this->assertNull(AlertFinding::normalizeSeverity('unknown'));
    }

    public function test_normalize_severity_maps_unrecognized_string_to_null(): void
    {
        $this->assertNull(AlertFinding::normalizeSeverity('bogus-severity'));
    }

    public function test_normalize_severity_passes_through_null(): void
    {
        $this->assertNull(AlertFinding::normalizeSeverity(null));
    }

    // -------------------------------------------------------------------------
    // severityRank() — most severe first, medium between high and low
    // -------------------------------------------------------------------------

    public function test_medium_ranks_below_high_and_above_low(): void
    {
        $high = AlertFinding::factory()->make(['severity' => AlertFinding::SEVERITY_HIGH]);
        $medium = AlertFinding::factory()->make(['severity' => AlertFinding::SEVERITY_MEDIUM]);
        $low = AlertFinding::factory()->make(['severity' => AlertFinding::SEVERITY_LOW]);

        $this->assertLessThan($medium->severityRank(), $high->severityRank());
        $this->assertLessThan($low->severityRank(), $medium->severityRank());
    }

    // -------------------------------------------------------------------------
    // severityBadge() — single source of truth for the Blade pill chain
    // -------------------------------------------------------------------------

    public function test_severity_badge_returns_medium_label_for_medium(): void
    {
        $finding = AlertFinding::factory()->make(['severity' => AlertFinding::SEVERITY_MEDIUM]);

        $this->assertSame('Medium', $finding->severityBadge()['label']);
    }

    public function test_severity_badge_returns_unrated_for_null_severity(): void
    {
        $finding = AlertFinding::factory()->make(['severity' => null]);

        $this->assertSame('Unrated', $finding->severityBadge()['label']);
    }

    /**
     * Guards against the exact defect this task fixes: every declared
     * severity must have its own badge, so adding a value to SEVERITIES
     * without updating the badge map cannot silently fall through to
     * "Unrated" again.
     */
    public function test_every_declared_severity_has_a_non_unrated_badge(): void
    {
        foreach (AlertFinding::SEVERITIES as $severity) {
            $finding = AlertFinding::factory()->make(['severity' => $severity]);

            $this->assertNotSame(
                'Unrated',
                $finding->severityBadge()['label'],
                "Severity '{$severity}' must not fall through to the Unrated badge."
            );
        }
    }

    // -------------------------------------------------------------------------
    // safeUrl() — T7b defect 1: the second, independent guard at the actual
    // href="{{ }}" render boundary. Fetchers already blank an unsafe scheme
    // before a finding is ever inserted (RssFeedFetcher/GitHubAdvisoryFetcher),
    // but a row that reaches this column by any other path (a future fetcher,
    // a manual seed, a bug) must still never produce a dangerous href.
    // -------------------------------------------------------------------------

    public function test_safe_url_returns_null_for_a_javascript_scheme(): void
    {
        $finding = AlertFinding::factory()->make(['url' => 'javascript:alert(document.cookie)']);

        $this->assertNull($finding->safeUrl());
    }

    public function test_safe_url_returns_null_for_a_data_scheme(): void
    {
        $finding = AlertFinding::factory()->make(['url' => 'data:text/html,<script>alert(1)</script>']);

        $this->assertNull($finding->safeUrl());
    }

    public function test_safe_url_returns_the_url_for_an_https_scheme(): void
    {
        $finding = AlertFinding::factory()->make(['url' => 'https://example.test/advisory/1']);

        $this->assertSame('https://example.test/advisory/1', $finding->safeUrl());
    }

    public function test_safe_url_returns_the_url_for_an_http_scheme(): void
    {
        $finding = AlertFinding::factory()->make(['url' => 'http://example.test/advisory/1']);

        $this->assertSame('http://example.test/advisory/1', $finding->safeUrl());
    }

    public function test_safe_url_returns_null_for_an_empty_url(): void
    {
        $finding = AlertFinding::factory()->make(['url' => '']);

        $this->assertNull($finding->safeUrl());
    }
}
