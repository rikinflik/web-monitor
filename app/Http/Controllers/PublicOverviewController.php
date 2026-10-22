<?php

namespace App\Http\Controllers;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Response;

/**
 * Public (no-login), token-gated overview page: every monitor currently
 * down, plus the tracked technology alerts and each source's health.
 *
 * Gated by a single global, unguessable token (config('monitoring.public_overview_token'))
 * rather than per-monitor opt-in — the user deliberately rejected both a
 * fixed open URL (would expose which client sites are down to anyone who
 * finds it) and a per-monitor flag (easy to forget to set). An empty or
 * unset configured token disables the route entirely: it 404s, the same as
 * a wrong token and the same as a route that does not exist at all, so a
 * guesser learns nothing from the response.
 *
 * Two properties protect the token once the page is reachable:
 * - noindex (`<meta name="robots">` + `X-Robots-Tag` header) keeps it out of
 *   search engines.
 * - `Referrer-Policy: no-referrer`, paired with `rel="noreferrer noopener"`
 *   on every outbound link in the view, stops the token (part of this page's
 *   own URL) from being leaked via the `Referer` header to the third-party
 *   sites (github.com, php.net, drupal.org, wordpress.org) this page links
 *   to as advisory sources.
 */
final class PublicOverviewController extends Controller
{
    /**
     * How many of a source's most recent findings this page renders —
     * tighter than the authenticated dashboard's cap (AlertController::
     * FINDINGS_PER_SOURCE = 10) since this is a glance-only public summary,
     * not the working dashboard. The true total is still shown alongside
     * the cap so nothing looks silently hidden.
     */
    private const FINDINGS_PER_SOURCE = 5;

    /**
     * Render the public overview, or 404 when the supplied token does not
     * match the configured one.
     *
     * The configured-empty check happens before hash_equals() and is
     * unconditional: hash_equals('', '') is true in PHP, so without this
     * guard an unset/empty configured token would let an empty supplied
     * token through. A route segment can never actually be empty in
     * practice, but the guard does not rely on that — it is correct even if
     * called directly.
     */
    public function show(string $token): Response
    {
        $configuredToken = (string) config('monitoring.public_overview_token', '');

        abort_if($configuredToken === '', 404);
        abort_unless(hash_equals($configuredToken, $token), 404);

        $downMonitors = Monitor::query()
            ->where('status', Monitor::STATUS_DOWN)
            ->orderBy('name')
            ->get();

        $sources = AlertSource::query()
            ->withCount('findings')
            ->with(['findings' => fn ($query) => $this->constrainToRecentPerSource($query)])
            ->orderBy('name')
            ->get();

        return response()
            ->view('public-overview', compact('downMonitors', 'sources'))
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Constrain the eager-loaded `findings` relation to at most
     * self::FINDINGS_PER_SOURCE rows per source, ranked is_security first,
     * then severity (most severe first), then recency — the same ordering
     * approach as AlertController::constrainToRecentPerSource(), computed
     * in SQL via a window function so the database discards the rows this
     * page never shows rather than this application hydrating all of them
     * first.
     */
    private function constrainToRecentPerSource(HasMany $query): void
    {
        $severityRank = $this->severityRankSql();

        $query
            ->whereIn('id', function (QueryBuilder $recentIds) use ($severityRank): void {
                $recentIds
                    ->select('id')
                    ->fromSub(function (QueryBuilder $ranked) use ($severityRank): void {
                        $ranked
                            ->select('id')
                            ->selectRaw(
                                "ROW_NUMBER() OVER (PARTITION BY alert_source_id ORDER BY is_security DESC, {$severityRank}, published_at DESC, id ASC) as rnk"
                            )
                            ->from('alert_findings');
                    }, 'ranked_findings')
                    ->where('rnk', '<=', self::FINDINGS_PER_SOURCE);
            })
            ->orderByDesc('is_security')
            ->orderByRaw($severityRank)
            ->orderByDesc('published_at')
            ->orderBy('id');
    }

    /**
     * Build a SQL `CASE` expression ranking `severity` exactly like
     * AlertFinding::severityRank() (most severe first, unrated last),
     * generated from AlertFinding::SEVERITIES so the SQL and PHP orderings
     * cannot drift apart.
     */
    private function severityRankSql(): string
    {
        $cases = [];

        foreach (AlertFinding::SEVERITIES as $index => $severity) {
            $cases[] = "WHEN '{$severity}' THEN {$index}";
        }

        $unratedRank = count(AlertFinding::SEVERITIES);

        return 'CASE severity '.implode(' ', $cases)." ELSE {$unratedRank} END";
    }
}
