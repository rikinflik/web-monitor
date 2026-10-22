<?php

namespace App\Services\AlertFeeds;

use App\Models\AlertSource;

/**
 * Turns a configured AlertSource into a normalized list of findings.
 *
 * Each returned element is an array with exactly the keys: external_id
 * (non-empty string), title (string), url (string), published_at
 * (CarbonImmutable|null), severity (string|null).
 */
interface AlertFeedFetcher
{
    /**
     * Sent on every outbound request from every fetcher. wordpress.org's WAF
     * returns HTTP 403 for Guzzle's bare default User-Agent
     * ("GuzzleHttp/7") — a measured fact, not a hypothesis — so a real
     * identifying value is required, not decoration: do not remove it.
     * Names the application plus a contact-ish identifier (the project's
     * repository) rather than impersonating a browser or curl, which is
     * simply correct manners for a polling bot. Defined once here, shared by
     * both implementations, instead of duplicating the literal per fetcher.
     */
    public const USER_AGENT = 'WebMonitor-AlertBot/1.0 (+https://github.com/rikinflik/web-monitor)';

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: \Carbon\CarbonImmutable|null, severity: string|null}>
     *
     * @throws AlertFeedException on transport failure, non-2xx response, or an unparseable body.
     */
    public function fetch(AlertSource $source): array;
}
