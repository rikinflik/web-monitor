<?php

namespace App\Support;

/**
 * Shared item-level URL scheme guard, used by both RssFeedFetcher and
 * GitHubAdvisoryFetcher (T7b) so the rule cannot drift apart between the
 * two feed shapes.
 *
 * Distinct from RssFeedFetcher::assertHttpScheme(), which rejects the
 * *source's own* configured feed URL before ever issuing a request: this
 * guards an individual *item's* link, which — unlike the source URL —
 * reaches a Blade href="{{ }}" sink verbatim (resources/views/alerts/
 * index.blade.php and public-overview.blade.php). Blade's auto-escaping
 * stops attribute breakout but not a javascript:/data: scheme, which
 * executes in the viewer's own origin (the authenticated dashboard, or the
 * token-bearing public overview page). An item's external_id/title are
 * still worth recording even when its one link is not safe to render, so an
 * unsafe scheme is blanked rather than dropping the whole item — see
 * AlertFinding::safeUrl() for the second, independent guard at the actual
 * render boundary.
 */
trait SanitizesFeedUrls
{
    private function sanitizeItemUrl(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }
}
