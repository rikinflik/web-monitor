<?php

namespace App\Services\AlertFeeds;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Raised by an AlertFeedFetcher when a source cannot be fetched: a transport
 * failure, a non-2xx response, a body that cannot be parsed, or a source
 * whose config is missing a key the fetcher needs.
 *
 * T3's AlertCheckService catches this and records the message verbatim into
 * alert_sources.last_error, so the message must stay short, diagnostic, and
 * free of secrets (fetchers sanitize transport-exception messages before
 * constructing one of these).
 *
 * Carries structured, machine-readable retry context — $status, $transient,
 * $retryAfterSeconds — so a future rate-aware retry policy (intended
 * consumers: T3's AlertCheckService / T4's queued job) can tell a
 * self-healing rate-limit apart from a permanent failure without reopening
 * this file. This class classifies; it does NOT implement any retry/backoff
 * policy itself.
 */
final class AlertFeedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly bool $transient = false,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Build an exception for a non-2xx HTTP response, classifying GitHub's
     * rate-limit signals — 429, or 403 with X-RateLimit-Remaining: 0 — as
     * transient and carrying a retry-after hint in seconds when the response
     * supplies one (Retry-After, falling back to X-RateLimit-Reset). 404/410
     * and any other non-2xx are treated as permanent (transient: false).
     */
    public static function fromHttpResponse(string $message, Response $response): self
    {
        $status = $response->status();
        $transient = self::isTransientStatus($status, $response);

        return new self(
            $message,
            $status,
            $transient,
            $transient ? self::parseRetryAfterSeconds($response) : null,
        );
    }

    private static function isTransientStatus(int $status, Response $response): bool
    {
        return $status === 429
            || ($status === 403 && $response->header('X-RateLimit-Remaining') === '0');
    }

    /**
     * Prefer the standard Retry-After header (seconds); fall back to
     * GitHub's X-RateLimit-Reset (a Unix timestamp) converted to a
     * seconds-from-now delta.
     */
    private static function parseRetryAfterSeconds(Response $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return (int) $retryAfter;
        }

        $reset = $response->header('X-RateLimit-Reset');

        if (is_numeric($reset)) {
            return max(0, (int) $reset - time());
        }

        return null;
    }
}
