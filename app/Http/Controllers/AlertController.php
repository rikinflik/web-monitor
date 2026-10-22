<?php

namespace App\Http\Controllers;

use App\Jobs\CheckAlertSourceJob;
use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authenticated dashboard for the six tracked AlertSource ecosystems
 * (PHP, Drupal, WordPress, Laravel, Vue, Angular).
 *
 * Sources are global (no user_id): index() is open to any authenticated
 * user, there is no ownership scoping to apply here (unlike
 * MonitorController, which scopes through $request->user()->monitors()).
 * checkNow() forces an immediate poll and is restricted to admins, mirroring
 * the role gate already used by User::canAccessPanel() for the Filament
 * admin panel.
 */
final class AlertController extends Controller
{
    /**
     * How many of a source's most recent findings the dashboard renders.
     * Some sources carry a feed's entire history rather than just recent
     * items — a single real run left php.net/feed.atom with 495 rows for
     * one source alone — so rendering every row is both unusable and
     * unbounded. 10 is a deliberate choice: enough for a single glance-at
     * screen without scrolling, small enough that the query never has to
     * hydrate the long tail the dashboard would not show anyway. The true
     * total is still surfaced via withCount() below, so nothing looks
     * silently hidden.
     */
    private const FINDINGS_PER_SOURCE = 10;

    /**
     * List every tracked source with its recent findings, capped per source
     * and ordered by severity (most severe first) then recency — see
     * AlertFinding::severityRank(). The cap and the ordering are both
     * enforced in SQL (constrainToRecentPerSource()), not in PHP or Blade:
     * loading every row just to display a handful is exactly the cost this
     * guards against.
     */
    public function index(Request $request): View
    {
        $sources = AlertSource::query()
            ->withCount('findings')
            ->with(['findings' => fn ($query) => $this->constrainToRecentPerSource($query)])
            ->orderBy('name')
            ->get();

        $isAdmin = $request->user()->role === User::ROLE_ADMIN;

        return view('alerts.index', compact('sources', 'isAdmin'));
    }

    /**
     * Force an immediate synchronous poll of every tracked source so the
     * redirect reflects fresh results right away, mirroring how
     * SeoCheckController::recheck() uses CheckSeoJob::dispatchSync().
     */
    public function checkNow(Request $request): RedirectResponse
    {
        abort_if($request->user()->role !== User::ROLE_ADMIN, 403);

        AlertSource::all()->each(
            fn (AlertSource $source) => CheckAlertSourceJob::dispatchSync($source)
        );

        return redirect()->route('alerts.index')->with('success', 'Check completed for all sources.');
    }

    /**
     * Constrain the eager-loaded `findings` relation to at most
     * self::FINDINGS_PER_SOURCE rows per source, ranked by is_security
     * (flagged findings first), then severity (most severe first), then
     * recency — the same ordering intent as AlertFinding::severityRank(),
     * computed here in SQL via a window function so the database discards
     * the rows the dashboard never shows instead of this application
     * hydrating all of them first. A finding with no published_at ranks
     * last within its own severity tier, since both MariaDB and SQLite
     * treat NULL as the lowest value in `ORDER BY ... DESC`.
     *
     * is_security sorts first, ahead of severity and recency, because it is
     * read from a stored column set at insert (see AlertSecurityClassifier
     * and AlertCheckService), not computed here: with the cap in play, a
     * render-time flag would let the current php.net critical advisory fall
     * off the list within weeks, buried under routine release
     * announcements that keep accumulating above it.
     *
     * Applied as a `whereIn('id', ...)` subquery rather than a plain
     * `limit()`, since a `limit()` on this eager-load query would cap the
     * combined result across every source, not per source.
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
