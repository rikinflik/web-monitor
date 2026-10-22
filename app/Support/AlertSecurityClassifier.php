<?php

namespace App\Support;

use App\Models\AlertSource;

/**
 * The single place that decides whether an AlertFinding counts as
 * "security" — used both by AlertCheckService at insert time and by the
 * one-off backfill migration (T5c), so the two can never drift into
 * different answers for the same row.
 *
 * Two constraints drive the two branches:
 * - A `security_only` source (drupal's `/security/rss.xml`, wordpress's
 *   security category feed, and the three GitHub Advisories sources, where
 *   every item is by definition a vulnerability disclosure) is
 *   security-only by construction: the title is never inspected, because a
 *   known truth beats a guess.
 * - Only `php` (today: the one source with `security_only = false`) polls a
 *   general news feed and needs a heuristic on the title.
 */
final class AlertSecurityClassifier
{
    /**
     * Conservative, case-insensitive keyword match applied only to
     * non-security-only sources. Matches "security", "advisory", "CVE-",
     * and the "vulnerab*" stem (vulnerable/vulnerability/vulnerabilities).
     *
     * Deliberately erring toward flagging: a false positive costs a little
     * noise in the dashboard, a false negative hides a genuine advisory.
     *
     * Known gap, written down rather than discovered later: a security fix
     * shipped in a release post with none of this wording (e.g. a terse
     * changelog entry) would NOT be flagged by this heuristic. In practice
     * php.net has published both a dedicated advisory post and release
     * posts for the same vulnerability (2026-10-21: the CVE-2026-109618/
     * 109619 advisory alongside four version release posts), so the
     * advisory itself carries the flag — but a release-only disclosure with
     * no matching wording is a real, accepted blind spot of this heuristic.
     */
    private const KEYWORD_PATTERN = '/security|advisory|cve-|vulnerab/i';

    /**
     * Decide whether a finding from the given source, with the given
     * title, should be flagged as security-relevant.
     */
    public static function isSecurity(AlertSource $source, string $title): bool
    {
        if ($source->security_only) {
            return true;
        }

        return preg_match(self::KEYWORD_PATTERN, $title) === 1;
    }
}
