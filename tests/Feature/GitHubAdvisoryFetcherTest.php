<?php

namespace Tests\Feature;

use App\Models\AlertSource;
use App\Services\AlertFeeds\AlertFeedException;
use App\Services\AlertFeeds\GitHubAdvisoryFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * GitHubAdvisoryFetcher handles the GitHub-Advisories-backed sources
 * (Laravel, Vue, Angular), calling the public /advisories endpoint with the
 * source's configured ecosystem + affects package list.
 */
#[Group('github-advisory-fetcher')]
class GitHubAdvisoryFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Defect 11: Http::fake() with URL-keyed patterns falls through to
        // the REAL handler when nothing matches (preventStrayRequests
        // defaults to false). If ENDPOINT changes and a fixture pattern
        // stops matching, the suite would silently hit the real network.
        Http::preventStrayRequests();
    }

    /**
     * Real-shape fixture matching the task brief's example element, plus a
     * sibling to prove multiple items normalize independently.
     */
    private function advisoriesJson(): array
    {
        return [
            [
                'ghsa_id' => 'GHSA-jh5r-qr3c-85q8',
                'cve_id' => 'CVE-2026-102279',
                'html_url' => 'https://github.com/advisories/GHSA-jh5r-qr3c-85q8',
                'summary' => 'Laravel: XSS in Debug Page Information',
                'severity' => 'low',
                'published_at' => '2026-09-15T10:00:00Z',
            ],
            [
                'ghsa_id' => 'GHSA-xxxx-yyyy-zzzz',
                'cve_id' => 'CVE-2026-999999',
                'html_url' => 'https://github.com/advisories/GHSA-xxxx-yyyy-zzzz',
                'summary' => 'Laravel: Another advisory',
                'severity' => 'high',
                'published_at' => '2026-09-20T08:30:00Z',
            ],
        ];
    }

    private function githubSource(string $ecosystem, string $affects): AlertSource
    {
        return AlertSource::factory()->make([
            'key' => 'laravel',
            'kind' => AlertSource::KIND_GITHUB_ADVISORY,
            'config' => ['ecosystem' => $ecosystem, 'affects' => $affects],
        ]);
    }

    public function test_maps_github_advisories_into_normalized_findings(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->advisoriesJson(), 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertCount(2, $findings);

        $advisory = $findings[0];
        $this->assertSame('GHSA-jh5r-qr3c-85q8', $advisory['external_id']);
        $this->assertSame('Laravel: XSS in Debug Page Information', $advisory['title']);
        $this->assertSame('https://github.com/advisories/GHSA-jh5r-qr3c-85q8', $advisory['url']);
        $this->assertTrue($advisory['published_at']->equalTo(Carbon::parse('2026-09-15T10:00:00Z')));
        $this->assertSame('low', $advisory['severity']);

        // Defect 12: the sibling carries a distinct severity and external_id
        // — asserting it proves independent per-item normalization, which
        // inspecting $findings[0] alone cannot (a normalizer that mapped
        // every item from the first element's fields would still pass).
        $second = $findings[1];
        $this->assertSame('GHSA-xxxx-yyyy-zzzz', $second['external_id']);
        $this->assertSame('high', $second['severity']);
    }

    public function test_valid_empty_response_yields_empty_array(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertSame([], $findings);
    }

    public function test_non_200_response_throws_alert_feed_exception(): void
    {
        Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            // Defect 3: a bare 403 with no rate-limit header is a permanent
            // failure, distinct from GitHub's actual rate-limit signal below.
            $this->assertSame(403, $e->status);
            $this->assertFalse($e->transient);
            $this->assertNull($e->retryAfterSeconds);
        }
    }

    /**
     * Defect 3: GitHub signals secondary rate limiting as 429 + Retry-After.
     * This must be classified transient, carrying the retry-after hint, so a
     * future rate-aware policy (T3/T4) can tell it apart from a permanent
     * failure without reopening this file.
     */
    public function test_429_response_is_transient_with_retry_after(): void
    {
        Http::fake(['api.github.com/*' => Http::response('', 429, ['Retry-After' => '30'])]);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertSame(429, $e->status);
            $this->assertTrue($e->transient);
            $this->assertSame(30, $e->retryAfterSeconds);
        }
    }

    /**
     * Defect 3: GitHub signals primary rate limiting as 403 +
     * X-RateLimit-Remaining: 0, falling back to X-RateLimit-Reset (a Unix
     * timestamp) for the retry-after hint when Retry-After is absent.
     */
    public function test_403_with_rate_limit_remaining_zero_is_transient(): void
    {
        $resetAt = time() + 120;

        Http::fake(['api.github.com/*' => Http::response('', 403, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) $resetAt,
        ])]);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertSame(403, $e->status);
            $this->assertTrue($e->transient);
            $this->assertNotNull($e->retryAfterSeconds);
            $this->assertEqualsWithDelta(120, $e->retryAfterSeconds, 2);
        }
    }

    public function test_malformed_json_throws_alert_feed_exception(): void
    {
        Http::fake(['api.github.com/*' => Http::response('{not json', 200)]);

        $this->expectException(AlertFeedException::class);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
    }

    /**
     * Defect 2: GitHub's error bodies (e.g. rate-limit) are valid JSON
     * objects, not lists. is_array() alone accepts them, after which
     * normalizeItems() silently skips every string-keyed value and reports
     * success with zero findings. Must throw instead, surfacing the object's
     * own "message" field.
     */
    public function test_json_object_body_throws_alert_feed_exception_with_message(): void
    {
        $body = json_encode([
            'message' => 'API rate limit exceeded for 1.2.3.4',
            'documentation_url' => 'https://docs.github.com/rest/overview/rate-limits-for-the-rest-api',
        ]);

        Http::fake(['api.github.com/*' => Http::response($body, 200)]);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('API rate limit exceeded for 1.2.3.4', $e->getMessage());
        }
    }

    /**
     * An item with no ghsa_id cannot yield a usable external_id and must be
     * skipped, while a sibling item is kept.
     */
    public function test_item_without_ghsa_id_is_skipped_but_siblings_kept(): void
    {
        $items = $this->advisoriesJson();
        unset($items[0]['ghsa_id']);

        Http::fake(['api.github.com/*' => Http::response($items, 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertCount(1, $findings);
        $this->assertSame('GHSA-xxxx-yyyy-zzzz', $findings[0]['external_id']);
    }

    public function test_request_uses_bounded_timeout(): void
    {
        $capturedTimeout = null;

        Http::fake([
            'api.github.com/*' => function ($request, $options) use (&$capturedTimeout) {
                $capturedTimeout = $options['timeout'] ?? null;

                return Http::response($this->advisoriesJson(), 200);
            },
        ]);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertSame(10, $capturedTimeout);
    }

    public function test_sends_accept_header(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->advisoriesJson(), 200)]);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/vnd.github+json'));
    }

    /**
     * The comma-joined affects string must pass through untouched as a single
     * query value — never exploded into one request per package.
     */
    public function test_comma_joined_affects_passes_through_as_single_request(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->advisoriesJson(), 200)]);

        (new GitHubAdvisoryFetcher)->fetch(
            $this->githubSource('npm', '@angular/core,@angular/router,@angular/common'),
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['ecosystem'] === 'npm'
            && $request['affects'] === '@angular/core,@angular/router,@angular/common'
        );
    }

    /**
     * Defect 4: a declared Content-Length over the cap must throw before the
     * body is decoded. Mirrors GitHubAdvisoryFetcher::MAX_BODY_BYTES (2 MiB).
     */
    public function test_oversized_content_length_header_throws(): void
    {
        Http::fake(['api.github.com/*' => Http::response(
            json_encode($this->advisoriesJson()),
            200,
            ['Content-Length' => (string) ((2 * 1024 * 1024) + 1)],
        )]);

        $this->expectException(AlertFeedException::class);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
    }

    /**
     * Defect 4: when Content-Length is absent (or lying), the actual body
     * size must still be bounded.
     */
    public function test_oversized_actual_body_without_content_length_header_throws(): void
    {
        // A valid, parseable list-of-advisory-objects body padded past the
        // cap — this must be caught by the size guard, not incidentally by
        // the non-list-body guard (defect 2), which would prove nothing
        // about the size cap.
        $padding = str_repeat('a', (2 * 1024 * 1024) + 1);
        $items = $this->advisoriesJson();
        $items[0]['summary'] = $padding;

        Http::fake(['api.github.com/*' => Http::response(json_encode($items), 200)]);

        $this->expectException(AlertFeedException::class);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
    }

    /**
     * T2c: the byte cap must be enforced DURING the transfer by counting
     * bytes off the PSR-7 stream as they are read, not by buffering the
     * whole body into a string first and measuring strlen() afterwards (the
     * pre-T2c approach, which still threw for an over-limit body, but only
     * after the full body had already been materialized in memory — the
     * real OOM risk in the shared queue worker).
     *
     * infiniteStream() below never signals EOF and hands back an endless
     * supply of 64 KiB chunks, with no Content-Length header at all. A
     * correct implementation must throw AlertFeedException once the running
     * total exceeds the 2 MiB cap, after roughly 32 chunks. If the streaming
     * byte counter were removed — reverted to `while (!$stream->eof())` with
     * no in-loop size check, or to `$stream->getContents()`/`(string)
     * $stream` to materialize the body first — the read loop keeps pulling
     * chunks well past the cap and hits this fixture's 20 MiB safety
     * ceiling, which throws a plain RuntimeException instead of
     * AlertFeedException: expectException(AlertFeedException::class) below
     * does not catch a RuntimeException, so the test fails. Http::fake()
     * cannot represent a real socket that blocks forever without producing
     * bytes; this fixture stands in for that by generating bytes
     * synchronously but never declaring "the end", which is enough to tell
     * "stopped after N chunks" apart from "read everything".
     */
    public function test_unbounded_stream_without_content_length_is_aborted_mid_transfer(): void
    {
        Http::fake([
            'api.github.com/*' => fn () => \GuzzleHttp\Promise\Create::promiseFor(
                new \GuzzleHttp\Psr7\Response(200, [], $this->infiniteStream()),
            ),
        ]);

        $this->expectException(AlertFeedException::class);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
    }

    /**
     * A PSR-7 stream that never reaches EOF and yields 64 KiB of filler on
     * every read() call, throwing a plain RuntimeException (not
     * AlertFeedException) once more than 20 MiB has been pulled through it —
     * a safety ceiling far above the fetcher's 2 MiB cap, whose only purpose
     * is to stop a broken (un-bounded) implementation from looping forever,
     * never to assert anything about the fetcher itself.
     */
    private function infiniteStream(): \Psr\Http\Message\StreamInterface
    {
        $chunk = str_repeat('a', 64 * 1024);
        $bytesRead = 0;
        $safetyCeiling = 20 * 1024 * 1024;

        return new \GuzzleHttp\Psr7\FnStream([
            'eof' => fn () => false,
            'read' => function () use (&$bytesRead, $safetyCeiling, $chunk) {
                $bytesRead += strlen($chunk);

                if ($bytesRead > $safetyCeiling) {
                    throw new \RuntimeException(
                        'Safety ceiling exceeded: read past 20 MiB without aborting — the streaming byte counter is missing or broken.',
                    );
                }

                return $chunk;
            },
            'close' => fn () => null,
        ]);
    }

    /**
     * Defect 6: the sanitized transport-failure message must name the
     * source key and the endpoint host, and must never repeat the raw
     * exception message verbatim (which could embed query-string affects
     * values or other request detail).
     */
    public function test_transport_failure_message_is_sanitized_and_names_source_and_host(): void
    {
        Http::fake([
            'api.github.com/*' => function () {
                throw new ConnectionException(
                    'cURL error 6: Could not resolve host: api.github.com for https://api.github.com/advisories?ecosystem=composer&affects=super-secret-package',
                );
            },
        ]);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('laravel', $e->getMessage());
            $this->assertStringContainsString('api.github.com', $e->getMessage());
            $this->assertStringNotContainsString('super-secret-package', $e->getMessage());
        }
    }

    /**
     * Defect 10: a connection failure (the realistic production failure for
     * an hourly poller) must land in AlertFeedException, not escape as a
     * different exception type.
     */
    public function test_connection_exception_is_wrapped_in_alert_feed_exception(): void
    {
        Http::fake([
            'api.github.com/*' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->expectException(AlertFeedException::class);

        (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));
    }

    /**
     * Defect 9: a missing published_at must degrade to null without
     * dropping the item.
     */
    public function test_missing_published_at_yields_null_with_sibling_surviving(): void
    {
        $items = $this->advisoriesJson();
        unset($items[0]['published_at']);

        Http::fake(['api.github.com/*' => Http::response($items, 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertCount(2, $findings);
        $this->assertNull($findings[0]['published_at']);
        $this->assertNotNull($findings[1]['published_at']);
    }

    /**
     * Defect 9: a malformed published_at must degrade to null without
     * dropping the item.
     */
    public function test_malformed_published_at_yields_null_with_sibling_surviving(): void
    {
        $items = $this->advisoriesJson();
        $items[0]['published_at'] = '21/10/2026';

        Http::fake(['api.github.com/*' => Http::response($items, 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertCount(2, $findings);
        $this->assertNull($findings[0]['published_at']);
        $this->assertNotNull($findings[1]['published_at']);
    }

    /**
     * Defect 9: Carbon::parse() accepts "0000-00-00 00:00:00" loosely and
     * returns a confidently wrong date (year -1) rather than throwing. Pinned
     * decision: a parsed year before 1990 is treated as implausible and
     * degraded to null, consistent with RssFeedFetcher::parseDate().
     */
    public function test_implausible_zero_date_yields_null_published_at(): void
    {
        $items = $this->advisoriesJson();
        $items[0]['published_at'] = '0000-00-00 00:00:00';

        Http::fake(['api.github.com/*' => Http::response($items, 200)]);

        $findings = (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', 'laravel/framework'));

        $this->assertNull($findings[0]['published_at']);
    }

    /**
     * Defect 11: the fixed GitHub ENDPOINT constant means a pattern-drift
     * scenario (unlike RssFeedFetcher's database-configured URL) cannot be
     * safely reproduced here without a real outbound request to
     * api.github.com, which the project's NO real network calls in tests
     * rule forbids. Assert the harness-level guard is actually active
     * instead — this calls real Http facade state, not a tautology, and
     * would fail if setUp() stopped calling preventStrayRequests().
     */
    public function test_prevents_stray_requests_guard_is_active(): void
    {
        $this->assertTrue(Http::preventingStrayRequests());
    }

    public function test_missing_config_keys_throws_clearly(): void
    {
        Http::fake();

        $this->expectException(AlertFeedException::class);

        try {
            (new GitHubAdvisoryFetcher)->fetch($this->githubSource('composer', ''));
        } finally {
            Http::assertNothingSent();
        }
    }
}
