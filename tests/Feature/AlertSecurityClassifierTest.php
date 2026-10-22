<?php

namespace Tests\Feature;

use App\Models\AlertSource;
use App\Support\AlertSecurityClassifier;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AlertSecurityClassifier is the single place that decides whether a
 * finding counts as "security" — used both by AlertCheckService at insert
 * time and by the one-off backfill migration, so the two can never drift.
 *
 * Two constraints drive the two branches:
 * - A `security_only` source (drupal, wordpress, laravel, vue, angular) is
 *   security-only by construction: every item it ever returns is a
 *   vulnerability disclosure, so the title is never inspected.
 * - Only `php` polls a general news feed and needs a conservative,
 *   err-toward-flagging keyword heuristic on the title.
 */
#[Group('alert-security-classifier')]
class AlertSecurityClassifierTest extends TestCase
{
    private function sourceWithSecurityOnly(bool $securityOnly): AlertSource
    {
        $source = new AlertSource;
        $source->security_only = $securityOnly;

        return $source;
    }

    public function test_a_security_only_source_flags_a_title_with_no_security_wording_at_all(): void
    {
        $source = $this->sourceWithSecurityOnly(true);

        $this->assertTrue(
            AlertSecurityClassifier::isSecurity($source, 'Drupal 10.5.2 and 11.2.2 are now available')
        );
    }

    public function test_a_non_security_only_source_flags_the_real_php_critical_advisory_title(): void
    {
        $source = $this->sourceWithSecurityOnly(false);

        $this->assertTrue(
            AlertSecurityClassifier::isSecurity(
                $source,
                'Security advisory: Critical remote code execution vulnerabilities, update now'
            )
        );
    }

    public function test_a_non_security_only_source_does_not_flag_a_routine_release_title(): void
    {
        $source = $this->sourceWithSecurityOnly(false);

        $this->assertFalse(
            AlertSecurityClassifier::isSecurity($source, 'PHP 8.5.12 Released!')
        );
    }

    public function test_keyword_match_is_case_insensitive_and_covers_cve_and_vulnerab_stem(): void
    {
        $source = $this->sourceWithSecurityOnly(false);

        $this->assertTrue(AlertSecurityClassifier::isSecurity($source, 'patched CVE-2026-109618 today'));
        $this->assertTrue(AlertSecurityClassifier::isSecurity($source, 'new VULNERABILITY disclosed'));
        $this->assertTrue(AlertSecurityClassifier::isSecurity($source, 'an ADVISORY was published'));
    }
}
