<?php

namespace App\Services;

use App\Models\AlertSource;
use App\Services\AlertFeeds\AlertFeedException;
use App\Services\AlertFeeds\AlertFeedFetcher;
use App\Services\AlertFeeds\GitHubAdvisoryFetcher;
use App\Services\AlertFeeds\RssFeedFetcher;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Polls one AlertSource's upstream feed and records any new findings.
 *
 * Retry policy — deliberately NOT a retry, by design:
 *
 * AlertFeedException is caught here and recorded into last_error rather than
 * rethrown, so CheckAlertSourceJob completes successfully even when the feed
 * is broken. Three of the six sources (Laravel/Vue/Angular) share a single
 * unauthenticated GitHub Advisories budget of 60 requests/hour, and the
 * repo-wide job convention is `tries = 3` with a flat, non-jittered
 * `backoff = 10` seconds. If a feed failure were rethrown here, all three
 * retries would land inside the same ~10-second window — almost certainly
 * still inside whatever rate-limit or outage caused the first failure — so
 * they would burn three requests against the shared budget for a guaranteed
 * three failures instead of one. The scheduler (T4) runs hourly, so the next
 * scheduled run already acts as the retry, at zero extra cost and with a far
 * more realistic chance the transient condition has cleared.
 *
 * An exception that is NOT an AlertFeedException is a bug, not a feed
 * problem (e.g. a logic error in this class, or in how a finding is
 * persisted) — it is deliberately left to propagate so the job fails loudly
 * into `failed_jobs` instead of being swallowed and misreported as a broken
 * feed.
 */
final class AlertCheckService
{
    public function __construct(
        private readonly AlertFeedFetcher $rssFetcher = new RssFeedFetcher,
        private readonly AlertFeedFetcher $gitHubAdvisoryFetcher = new GitHubAdvisoryFetcher,
    ) {}

    /**
     * Fetch the source's feed, insert any findings not already recorded, and
     * record the outcome of the attempt on the source itself.
     */
    public function check(AlertSource $source): void
    {
        $fetcher = $this->fetcherFor($source);

        try {
            $findings = $fetcher->fetch($source);
        } catch (AlertFeedException $e) {
            $this->recordFailure($source, $e);

            return;
        }

        $this->insertNewFindings($source, $findings);
        $this->recordSuccess($source);
    }

    /**
     * Resolve the fetcher for the source's kind. A kind no fetcher handles is
     * a programming/configuration error on the AlertSource row itself, not a
     * feed-fetch failure, so it fails loudly here rather than being silently
     * swallowed as a broken feed.
     */
    private function fetcherFor(AlertSource $source): AlertFeedFetcher
    {
        return match ($source->kind) {
            AlertSource::KIND_RSS => $this->rssFetcher,
            AlertSource::KIND_GITHUB_ADVISORY => $this->gitHubAdvisoryFetcher,
            default => throw new InvalidArgumentException(
                "AlertSource '{$source->key}' has unknown kind '{$source->kind}'."
            ),
        };
    }

    /**
     * Insert only findings not already recorded for this source, keyed on
     * (alert_source_id, external_id). Never updates or deletes an existing
     * finding — the recorded history is append-only.
     *
     * @param  list<array{external_id: string, title: string, url: string, published_at: \Carbon\CarbonImmutable|null, severity: string|null}>  $findings
     */
    private function insertNewFindings(AlertSource $source, array $findings): void
    {
        if ($findings === []) {
            return;
        }

        $existingIds = $source->findings()
            ->whereIn('external_id', array_column($findings, 'external_id'))
            ->pluck('external_id')
            ->all();

        foreach ($findings as $finding) {
            if (in_array($finding['external_id'], $existingIds, true)) {
                continue;
            }

            $this->insertFinding($source, $finding);
        }
    }

    /**
     * Insert one finding, tolerating a UniqueConstraintViolationException
     * raised by a concurrent check racing the same (alert_source_id,
     * external_id) pair between the existence check above and this insert —
     * the row exists either way, so there is nothing left to do.
     */
    private function insertFinding(AlertSource $source, array $finding): void
    {
        try {
            $source->findings()->create([
                'external_id' => $finding['external_id'],
                'title' => $finding['title'],
                'url' => $finding['url'],
                'severity' => $finding['severity'],
                'published_at' => $finding['published_at'],
                'first_seen_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost the race to a concurrent check; the row is already there.
        }
    }

    private function recordSuccess(AlertSource $source): void
    {
        $source->update([
            'last_checked_at' => now(),
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Record a failed attempt without touching last_success_at or any
     * previously recorded finding — a broken feed must never look like "the
     * advisories went away".
     */
    private function recordFailure(AlertSource $source, AlertFeedException $e): void
    {
        $source->update([
            'last_checked_at' => now(),
            'last_error' => $this->diagnosticMessage($e),
        ]);
    }

    /**
     * Build an operator-facing message that distinguishes a self-healing
     * transient failure (rate limit, momentary outage) from a permanent one
     * (404, malformed config) — exactly the distinction $e->transient and
     * $e->retryAfterSeconds exist to carry, so an operator reading last_error
     * at 3am does not need to guess which kind of problem woke them.
     *
     * The transient branch deliberately keeps two timescales visibly apart:
     * $e->retryAfterSeconds is the upstream's own retry-after (seconds), but
     * this check only runs again on the hourly schedule (T4) — splicing them
     * into one sentence ("expected to recover... in ~120s on the next
     * scheduled check") would promise a recovery confirmed within seconds
     * that the system will not actually deliver for up to an hour, leaving
     * an operator who refreshes two minutes later looking at a stale error.
     */
    private function diagnosticMessage(AlertFeedException $e): string
    {
        if (! $e->transient) {
            return "Permanent failure (needs attention): {$e->getMessage()}";
        }

        $upstreamRetry = $e->retryAfterSeconds !== null
            ? "the upstream suggested retrying in ~{$e->retryAfterSeconds}s, but "
            : '';

        return "Transient failure, expected to recover automatically; {$upstreamRetry}recovery will only be confirmed here at the next scheduled check (up to 1 hour away): {$e->getMessage()}";
    }
}
