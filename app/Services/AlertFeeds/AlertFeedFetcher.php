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
     * @return list<array{external_id: string, title: string, url: string, published_at: \Carbon\CarbonImmutable|null, severity: string|null}>
     *
     * @throws AlertFeedException on transport failure, non-2xx response, or an unparseable body.
     */
    public function fetch(AlertSource $source): array;
}
