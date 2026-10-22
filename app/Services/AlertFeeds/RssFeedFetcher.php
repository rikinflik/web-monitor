<?php

namespace App\Services\AlertFeeds;

use App\Models\AlertSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Fetches and normalizes the three feed-based sources (php.net, drupal.org,
 * wordpress.org). Parses both Atom (php.net) and RSS 2.0 (drupal.org,
 * wordpress.org) through the same entry point, since the real feeds differ.
 *
 * severity is always null here — RSS/Atom carry no severity field. Drupal
 * encodes criticality in the title text; parsing that out is out of scope.
 */
final class RssFeedFetcher implements AlertFeedFetcher
{
    /**
     * Per-request timeout in seconds, mirroring SeoCheckService::TIMEOUT.
     */
    private const TIMEOUT = 10;

    /**
     * Maximum accepted response body size (2 MiB). A feed response is
     * typically tens of KB; this bounds a runaway or hostile body before it
     * is handed to simplexml_load_string(), which builds a tree that
     * multiplies memory 10-20x over the raw bytes — in the queue worker
     * shared with CheckMonitorJob, an OOM is a fatal error that escapes the
     * try/catch entirely. Enforced twice: cheaply against the declared
     * Content-Length (assertDeclaredSizeWithinLimit()), and for real against
     * the bytes actually read off the wire (readBodyWithinLimit()) — the
     * latter is what stops an absent or lying header from bypassing the cap.
     */
    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /**
     * Chunk size used when reading the response body off the PSR-7 stream in
     * readBodyWithinLimit(). Small enough that the running total is checked
     * often (the abort fires at most one chunk past the cap), large enough
     * to avoid an excessive number of read() calls for a normally-sized feed.
     */
    private const STREAM_CHUNK_BYTES = 64 * 1024;

    /**
     * Hard cap on items collected per feed poll, bounding the findings array
     * independently of the body-size guard above.
     */
    private const MAX_ITEMS = 500;

    /**
     * Cap on the persisted exception message length (alert_sources.last_error).
     */
    private const MAX_MESSAGE_LENGTH = 300;

    public function fetch(AlertSource $source): array
    {
        $url = $this->configuredUrl($source);
        $this->assertHttpScheme($url, $source);

        $response = $this->request($url, $source);

        if (! $response->successful()) {
            throw AlertFeedException::fromHttpResponse($this->httpFailureMessage($source, $response), $response);
        }

        $this->assertDeclaredSizeWithinLimit($response, $source);
        $body = $this->readBodyWithinLimit($response, $source);

        $xml = $this->parseXml($body, $source);

        return $this->normalizeEntries($xml, $source);
    }

    /**
     * Reject an over-limit declared Content-Length before the body is read
     * at all. This is a cheap early rejection for the common case where the
     * header is present and honest — the real guard against an absent or
     * lying header is readBodyWithinLimit(), which counts bytes as they
     * arrive off the wire.
     */
    private function assertDeclaredSizeWithinLimit(Response $response, AlertSource $source): void
    {
        $declared = $response->header('Content-Length');

        if (is_numeric($declared) && (int) $declared > self::MAX_BODY_BYTES) {
            throw new AlertFeedException(
                "RSS source '{$source->key}' declared a response size over the ".self::MAX_BODY_BYTES.'-byte limit',
            );
        }
    }

    /**
     * Read the response body off the underlying PSR-7 stream in fixed-size
     * chunks, throwing as soon as the running total exceeds the byte cap
     * rather than ever letting the full body accumulate first. Combined with
     * `stream => true` on the outbound request (see request()), the PSR-7
     * body is backed by the live connection instead of an already-buffered
     * string, so this guard genuinely aborts mid-transfer for a response
     * whose Content-Length is absent or understates its real size — not just
     * a post-hoc strlen() check on a body that was fully read regardless.
     */
    private function readBodyWithinLimit(Response $response, AlertSource $source): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $buffer = '';
        $total = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(self::STREAM_CHUNK_BYTES);
                $total += strlen($chunk);

                if ($total > self::MAX_BODY_BYTES) {
                    throw new AlertFeedException(
                        "RSS source '{$source->key}' response body exceeds the ".self::MAX_BODY_BYTES.'-byte limit',
                    );
                }

                $buffer .= $chunk;
            }
        } finally {
            $stream->close();
        }

        return $buffer;
    }

    /**
     * @throws AlertFeedException when config.url is missing — a misconfigured
     *                            source should surface through the same error channel T3 records.
     */
    private function configuredUrl(AlertSource $source): string
    {
        $url = $source->config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new AlertFeedException("RSS source '{$source->key}' is missing config.url");
        }

        return $url;
    }

    /**
     * Reject anything but http/https before issuing any request: the URL is
     * read straight from the database and fed into an outbound request, so a
     * scheme check here is the only SSRF guard on this path.
     */
    private function assertHttpScheme(string $url, AlertSource $source): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new AlertFeedException("RSS source '{$source->key}' has an unsupported URL scheme");
        }
    }

    /**
     * Never follow redirects, mirroring SeoCheckService's convention: the
     * configured URL comes straight from the database, so a 3xx toward an
     * RFC1918 address or a cloud metadata endpoint must surface as a loud
     * error (see httpFailureMessage()) rather than be silently fetched.
     *
     * `stream => true` keeps the response body backed by the live connection
     * instead of letting Guzzle's default handler buffer it into memory
     * eagerly, so readBodyWithinLimit() can genuinely abort mid-transfer.
     */
    private function request(string $url, AlertSource $source): Response
    {
        try {
            return Http::timeout(self::TIMEOUT)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);
        } catch (Throwable $e) {
            throw new AlertFeedException($this->transportFailureMessage($source, $url, $e), previous: $e);
        }
    }

    /**
     * Build the non-2xx failure message, calling out a 3xx specifically as a
     * "moved" feed and naming only the redirect target's host (never the
     * full Location, which may carry a query string).
     */
    private function httpFailureMessage(AlertSource $source, Response $response): string
    {
        $status = $response->status();

        if ($status >= 300 && $status < 400) {
            $host = parse_url((string) $response->header('Location'), PHP_URL_HOST) ?: 'unknown-host';

            return "RSS source '{$source->key}' appears to have moved: HTTP {$status} to host '{$host}'";
        }

        return "RSS fetch failed for '{$source->key}': HTTP {$status}";
    }

    /**
     * Build a bounded, sanitized transport-failure message. Never interpolate
     * the raw exception message: Guzzle's ConnectException/RequestException
     * messages embed the full effective request URI — including any
     * userinfo credentials in config.url — and this message is persisted
     * verbatim into alert_sources.last_error and rendered by T5. The caller
     * still passes the original exception as `previous:` for log-level
     * debugging.
     */
    private function transportFailureMessage(AlertSource $source, string $url, Throwable $e): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: 'unknown-host';
        $message = "RSS fetch transport error for '{$source->key}' (host: {$host}): ".$this->describeCause($e);

        return $this->capMessage($message);
    }

    /**
     * Short, safe description of the failure cause: the exception's short
     * class name, plus a cURL error number when the underlying
     * GuzzleHttp\Exception\ConnectException/RequestException carries one in
     * its handler context — this keeps "could not resolve host" (6)
     * distinguishable from "operation timed out" (28) without repeating the
     * raw (potentially credential-bearing) message text.
     */
    private function describeCause(Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();
        $errno = $this->curlErrno($e) ?? $this->curlErrno($e->getPrevious());

        return $errno !== null ? "{$class} (curl errno {$errno})" : $class;
    }

    private function curlErrno(?Throwable $e): ?int
    {
        if ($e === null || ! method_exists($e, 'getHandlerContext')) {
            return null;
        }

        $errno = $e->getHandlerContext()['errno'] ?? null;

        return is_int($errno) ? $errno : null;
    }

    private function capMessage(string $message): string
    {
        return strlen($message) > self::MAX_MESSAGE_LENGTH
            ? substr($message, 0, self::MAX_MESSAGE_LENGTH - 3).'...'
            : $message;
    }

    /**
     * Parse the response body as XML, guarding against XXE: external entity
     * loading is disabled and LIBXML_NONET blocks network-based entities,
     * since this is third-party XML.
     *
     * libxml_set_external_entity_loader() sets process-global state. In a
     * long-lived queue worker, leaving the no-op loader attached after this
     * call would permanently block external entity resolution for every
     * later job, not just this one. It is reset to the default in the same
     * finally as libxml_use_internal_errors() — PHP exposes no getter for a
     * previously-set custom loader, so passing null resets to the libxml
     * default rather than restoring a prior custom loader, which is the best
     * available behaviour here.
     */
    private function parseXml(string $body, AlertSource $source): SimpleXMLElement
    {
        libxml_set_external_entity_loader(static fn () => null);
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
            libxml_set_external_entity_loader(null);
        }

        if ($xml === false) {
            throw new AlertFeedException("RSS source '{$source->key}' returned a malformed XML body");
        }

        return $xml;
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, severity: string|null}>
     */
    private function normalizeEntries(SimpleXMLElement $xml, AlertSource $source): array
    {
        $entries = $this->extractEntries($xml, $source);

        $findings = [];

        foreach ($entries as $entry) {
            $externalId = $entry['external_id'];

            if ($externalId === '') {
                continue;
            }

            $findings[] = [
                'external_id' => $externalId,
                'title' => $entry['title'],
                'url' => $entry['url'],
                'published_at' => $this->parseDate($entry['date']),
                'severity' => null,
            ];
        }

        return $findings;
    }

    /**
     * Recognise only Atom (<feed>) and RSS 2.0 (<rss> with a <channel>).
     * A well-formed document in any other shape — XHTML, a sitemap, a
     * relocated-feed stub, or RSS 1.0's <rdf:RDF> (whose <item> elements are
     * siblings of <channel>, not children of it) — would otherwise parse
     * cleanly through the RSS 2.0 walk and silently yield zero items, making
     * a moved/broken feed indistinguishable from "no new advisories".
     *
     * @return list<array{external_id: string, title: string, url: string, date: string}>
     */
    private function extractEntries(SimpleXMLElement $xml, AlertSource $source): array
    {
        $root = $xml->getName();

        if ($root === 'feed') {
            return $this->atomEntries($xml);
        }

        if ($root === 'rss' && isset($xml->channel)) {
            return $this->rssItems($xml);
        }

        throw new AlertFeedException(
            "RSS source '{$source->key}' response parsed but is not a recognised feed format (root element: <{$root}>)",
        );
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, date: string}>
     */
    private function atomEntries(SimpleXMLElement $feed): array
    {
        $entries = [];

        foreach ($feed->entry as $entry) {
            if (count($entries) >= self::MAX_ITEMS) {
                break;
            }

            $entries[] = [
                'external_id' => trim((string) $entry->id),
                'title' => trim((string) $entry->title),
                'url' => $this->atomLink($entry),
                'date' => (string) ($entry->published ?: $entry->updated),
            ];
        }

        return $entries;
    }

    /**
     * Pick the entry's link href, preferring a rel="alternate" (or unmarked)
     * link over other relations (e.g. "self").
     */
    private function atomLink(SimpleXMLElement $entry): string
    {
        $fallback = '';

        foreach ($entry->link as $link) {
            $rel = (string) $link['rel'];
            $href = (string) $link['href'];

            if ($rel === '' || $rel === 'alternate') {
                return $href;
            }

            $fallback = $fallback === '' ? $href : $fallback;
        }

        return $fallback;
    }

    /**
     * @return list<array{external_id: string, title: string, url: string, date: string}>
     */
    private function rssItems(SimpleXMLElement $rss): array
    {
        $items = [];

        foreach ($rss->channel->item as $item) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }

            $link = trim((string) $item->link);
            $guid = trim((string) $item->guid);

            $items[] = [
                'external_id' => $guid !== '' ? $guid : $link,
                'title' => trim((string) $item->title),
                'url' => $link,
                'date' => (string) $item->pubDate,
            ];
        }

        return $items;
    }

    /**
     * Earliest plausible publication year. Carbon::parse() accepts some
     * malformed strings loosely rather than throwing — "0000-00-00 00:00:00"
     * (a common artefact of badly generated feeds) parses to year -1 instead
     * of raising. None of the six monitored ecosystems (oldest: PHP, 1994)
     * predate 1990, so a parsed year before it is confidently wrong rather
     * than merely old, and is degraded to null exactly like an unparseable
     * string — published_at orders the dashboard, so a silently wrong date
     * would otherwise bury a critical advisory instead of surfacing the
     * parse failure.
     */
    private const MIN_PLAUSIBLE_YEAR = 1990;

    /**
     * Parse a feed date string into CarbonImmutable, degrading to null rather
     * than failing the whole item when a date is missing, unparseable, or
     * loosely parsed into an implausible year.
     */
    private function parseDate(string $date): ?CarbonImmutable
    {
        if ($date === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }

        return $parsed->year >= self::MIN_PLAUSIBLE_YEAR ? $parsed : null;
    }
}
