<?php

namespace App\Support;

use App\Models\AlertFinding;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Shared per-source findings ranking, used by both AlertController and
 * PublicOverviewController (T7a) so the cap, the ordering, and the severity
 * vocabulary driving it cannot drift apart between the two surfaces the way
 * they had before this trait existed — the two methods below were
 * character-for-character duplicated, with only each controller's own
 * FINDINGS_PER_SOURCE cap differing.
 *
 * The consuming class must declare its own `private const FINDINGS_PER_SOURCE`
 * — static::FINDINGS_PER_SOURCE below resolves to whichever class uses this
 * trait, since traits are compiled into the using class rather than
 * inherited from a common parent.
 */
trait ConstrainsFindingsPerSource
{
    /**
     * Constrain the eager-loaded `findings` relation to at most
     * self::FINDINGS_PER_SOURCE rows per source, ranked by is_security
     * (flagged findings first), then severity (most severe first), then
     * recency — the same ordering intent as AlertFinding::severityRank(),
     * computed here in SQL via a window function so the database discards
     * the rows the caller never shows instead of this application
     * hydrating all of them first. A finding with no published_at ranks
     * last within its own severity tier, since both MariaDB and SQLite
     * treat NULL as the lowest value in `ORDER BY ... DESC`.
     *
     * is_security sorts first, ahead of severity and recency, because it is
     * read from a stored column set at insert (see AlertSecurityClassifier
     * and AlertCheckService), not computed here: with the cap in play, a
     * render-time flag would let a critical advisory fall off the list
     * within weeks, buried under routine release announcements that keep
     * accumulating above it.
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
                    ->where('rnk', '<=', static::FINDINGS_PER_SOURCE);
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
