<?php

namespace App\Services\AlertFeeds;

use App\Models\AlertSource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fetches and normalizes the three GitHub-Advisories-backed sources
 * (Laravel, Vue, Angular) via the public /advisories endpoint. No
 * authentication: the endpoint is public, rate-limited to 60 requests/hour
 * per IP, which is ample for six hourly sources.
 */
final class GitHubAdvisoryFetcher implements AlertFeedFetcher
{
    private const ENDPOINT = 'https://api.github.com/advisories';

    /**
     * Per-request timeout in seconds, mirroring SeoCheckService::TIMEOUT.
     */
    private const TIMEOUT = 10;

    /**
     * Bounded page size: large enough to cover a single ecosystem/package's
     * advisories per hourly poll without unbounded pagination.
     */
    private const PER_PAGE = 100;

    /**
     * Maximum accepted response body size (2 MiB), bounding json_decode()
     * against a runaway or hostile body in the queue worker shared with
     * CheckMonitorJob, where an OOM is a fatal error that escapes the
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
     * to avoid an excessive number of read() calls for a normally-sized
     * response.
     */
    private const STREAM_CHUNK_BYTES = 64 * 1024;

    /**
     * Cap on the persisted exception message length (alert_sources.last_error).
     */
    private const MAX_MESSAGE_LENGTH = 300;

    public function fetch(AlertSource $source): array
    {
        $config = $this->configuredQuery($source);

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->withOptions(['stream' => true])
                ->get(self::ENDPOINT, [
                    'ecosystem' => $config['ecosystem'],
                    'affects' => $config['affects'],
                    'per_page' => self::PER_PAGE,
                ]);
        } catch (Throwable $e) {
            throw new AlertFeedException($this->transportFailureMessage($source, $e), previous: $e);
        }

        if (! $response->successful()) {
            throw AlertFeedException::fromHttpResponse(
                "GitHub Advisories fetch failed for '{$source->key}': HTTP {$response->status()}",
                $response,
            );
        }

        $this->assertDeclaredSizeWithinLimit($response, $source);
        $body = $this->readBodyWithinLimit($response, $source);

        $items = $this->decodeBody($body, $source);

        return $this->normalizeItems($items);
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
                "GitHub Advisories source '{$source->key}' declared a response size over the ".self::MAX_BODY_BYTES.'-byte limit',
            );
        }
    }

    /**
     * Read the response body off the underlying PSR-7 stream in fixed-size
     * chunks, throwing as soon as the running total exceeds the byte cap
     * rather than ever letting the full body accumulate first. Combined with
     * `stream => true` on the outbound request (see fetch()), the PSR-7 body
     * is backed by the live connection instead of an already-buffered
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
                        "GitHub Advisories source '{$source->key}' response body exceeds the ".self::MAX_BODY_BYTES.'-byte limit',
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
     * Build a bounded, sanitized transport-failure message. Never interpolate
     * the raw exception message: Guzzle's ConnectException/RequestException
     * messages embed the full effective request URI, which here includes the
     * configured ecosystem/affects as query-string values. This message is
     * persisted verbatim into alert_sources.last_error and rendered by T5.
     * The caller still passes the original exception as `previous:` for
     * log-level debugging.
     */
    private function transportFailureMessage(AlertSource $source, Throwable $e): string
    {
        $host = parse_url(self::ENDPOINT, PHP_URL_HOST) ?: 'unknown-host';
        $message = "GitHub Advisories fetch transport error for '{$source->key}' (host: {$host}): ".$this->describeCause($e);

        return $this->capMessage($message);
    }

    /**
     * Short, safe description of the failure cause: the exception's short
     * class name, plus a cURL error number when the underlying
     * GuzzleHttp\Exception\ConnectException/RequestException carries one in
     * its handler context — this keeps "could not resolve host" (6)
     * distinguishable from "operation timed out" (28) without repeating the
     * raw (potentially sensitive) message text.
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
     * @return array{ecosystem: string, affects: string}
     *
     * @throws AlertFeedException when config.ecosystem or config.affects is missing.
     */
    private function configuredQuery(AlertSource $source): array
    {
        $ecosystem = $source->config['ecosystem'] ?? null;
        $affects = $source->config['affects'] ?? null;

        if (! is_string($ecosystem) || $ecosystem === '' || ! is_string($affects) || $affects === '') {
            throw new AlertFeedException(
                "GitHub Advisories source '{$source->key}' is missing config.ecosystem or config.affects",
            );
        }

        return ['ecosystem' => $ecosystem, 'affects' => $affects];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decodeBody(string $body, AlertSource $source): array
    {
        $decoded = json_decode($body, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AlertFeedException("GitHub Advisories source '{$source->key}' returned a malformed JSON body");
        }

        // GitHub's error bodies (e.g. rate-limit) are valid JSON *objects*,
        // not lists — is_array() alone accepts an object too, after which
        // every string-keyed value would be silently skipped downstream for
        // lacking a ghsa_id, reporting success with zero findings.
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new AlertFeedException($this->nonListBodyMessage($source, $decoded));
        }

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                throw new AlertFeedException(
                    "GitHub Advisories source '{$source->key}' returned a list element that is not an object",
                );
            }
        }

        return $decoded;
    }

    /**
     * Build the non-list-body message, surfacing GitHub's own "message" field
     * when present (e.g. "API rate limit exceeded for 1.2.3.4") — it names
     * exactly what went wrong.
     */
    private function nonListBodyMessage(AlertSource $source, mixed $decoded): string
    {
        $base = "GitHub Advisories source '{$source->key}' returned a non-list JSON body";

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return "{$base}: {$decoded['message']}";
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{external_id: string, title: string, url: string, published_at: CarbonImmutable|null, severity: string|null}>
     */
    private function normalizeItems(array $items): array
    {
        $findings = [];

        foreach ($items as $item) {
            $externalId = trim((string) ($item['ghsa_id'] ?? ''));

            if ($externalId === '') {
                continue;
            }

            $findings[] = [
                'external_id' => $externalId,
                'title' => (string) ($item['summary'] ?? ''),
                'url' => (string) ($item['html_url'] ?? ''),
                'published_at' => $this->parseDate($item['published_at'] ?? null),
                'severity' => isset($item['severity']) ? (string) $item['severity'] : null,
            ];
        }

        return $findings;
    }

    /**
     * Parse a GitHub timestamp into CarbonImmutable, degrading to null rather
     * than failing the whole item when the date is missing or unparseable.
     */
    /**
     * Earliest plausible publication year. Carbon::parse() accepts some
     * malformed strings loosely rather than throwing — "0000-00-00 00:00:00"
     * parses to year -1 instead of raising. GitHub Advisories (and the
     * Laravel/Vue/Angular ecosystems it covers here) predate none of this, so
     * a parsed year before 1990 is confidently wrong rather than merely old,
     * and is degraded to null exactly like an unparseable string —
     * published_at orders the dashboard, so a silently wrong date would
     * otherwise bury a critical advisory. Consistent with
     * RssFeedFetcher::MIN_PLAUSIBLE_YEAR.
     */
    private const MIN_PLAUSIBLE_YEAR = 1990;

    /**
     * Parse a GitHub timestamp into CarbonImmutable, degrading to null rather
     * than failing the whole item when the date is missing, unparseable, or
     * loosely parsed into an implausible year.
     */
    private function parseDate(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || $date === '') {
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
