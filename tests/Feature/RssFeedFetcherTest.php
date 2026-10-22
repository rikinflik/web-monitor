<?php

namespace Tests\Feature;

use App\Models\AlertSource;
use App\Services\AlertFeeds\AlertFeedException;
use App\Services\AlertFeeds\RssFeedFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RssFeedFetcher must parse both Atom (php.net) and RSS 2.0 (drupal.org,
 * wordpress.org) shapes through the same entry point, normalizing each item
 * to [external_id, title, url, published_at, severity].
 */
#[Group('rss-feed-fetcher')]
class RssFeedFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Defect 11: Http::fake() with URL-keyed patterns falls through to
        // the REAL handler when nothing matches (preventStrayRequests
        // defaults to false). If a feed URL changes and a fixture pattern
        // stops matching, the suite would silently hit the real network.
        Http::preventStrayRequests();
    }

    /**
     * Real-shape fixture: the php.net Atom feed, most recent entry is the
     * security advisory described in the task brief.
     */
    private function atomFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>PHP: Hypertext Preprocessor</title>
  <entry>
    <id>https://www.php.net/archive/2026.php#id2026-10-21-1</id>
    <title>Security advisory: Critical remote code execution vulnerabilities, update now</title>
    <link rel="alternate" type="text/html" href="https://www.php.net/archive/2026.php#id2026-10-21-1"/>
    <published>2026-10-21T00:35:26+00:00</published>
  </entry>
  <entry>
    <id>https://www.php.net/releases/8.4.0</id>
    <title>PHP 8.4.0 Released!</title>
    <link rel="alternate" type="text/html" href="https://www.php.net/releases/8_4_0.php"/>
    <updated>2026-10-01T00:00:00+00:00</updated>
  </entry>
</feed>
XML;
    }

    /**
     * Real-shape fixture: the drupal.org RSS 2.0 security feed.
     */
    private function rssFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>Drupal core - Moderately critical - Cross-site scripting - SA-CORE-2026-016</title>
    <link>https://www.drupal.org/sa-core-2026-016</link>
    <guid isPermaLink="false">SA-CORE-2026-016</guid>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;
    }

    private function emptyAtomFixture(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>PHP: Hypertext Preprocessor</title>
</feed>
XML;
    }

    private function rssSource(string $url): AlertSource
    {
        return AlertSource::factory()->make([
            'key' => 'php',
            'kind' => AlertSource::KIND_RSS,
            'config' => ['url' => $url],
        ]);
    }

    public function test_parses_atom_feed_into_normalized_findings(): void
    {
        Http::fake(['https://www.php.net/feed.atom' => Http::response($this->atomFixture(), 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.php.net/feed.atom'));

        $this->assertCount(2, $findings);

        $advisory = $findings[0];
        $this->assertSame('https://www.php.net/archive/2026.php#id2026-10-21-1', $advisory['external_id']);
        $this->assertSame('Security advisory: Critical remote code execution vulnerabilities, update now', $advisory['title']);
        $this->assertSame('https://www.php.net/archive/2026.php#id2026-10-21-1', $advisory['url']);
        $this->assertTrue($advisory['published_at']->equalTo(Carbon::parse('2026-10-21T00:35:26+00:00')));
        $this->assertNull($advisory['severity']);

        // Defect 8: the second entry has only <updated>, no <published> — this
        // pins the fallback ('date' => $entry->published ?: $entry->updated).
        // A regression dropping the fallback would null out published_at
        // here while test_parses_atom_feed_into_normalized_findings's
        // assertCount(2, ...) would still pass.
        $second = $findings[1];
        $this->assertSame('https://www.php.net/releases/8.4.0', $second['external_id']);
        $this->assertSame('PHP 8.4.0 Released!', $second['title']);
        $this->assertNotNull($second['published_at']);
        $this->assertTrue($second['published_at']->equalTo(Carbon::parse('2026-10-01T00:00:00+00:00')));
    }

    public function test_parses_rss_two_feed_into_normalized_findings(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($this->rssFixture(), 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(1, $findings);

        $advisory = $findings[0];
        $this->assertSame('SA-CORE-2026-016', $advisory['external_id']);
        $this->assertSame('Drupal core - Moderately critical - Cross-site scripting - SA-CORE-2026-016', $advisory['title']);
        $this->assertSame('https://www.drupal.org/sa-core-2026-016', $advisory['url']);
        $this->assertTrue($advisory['published_at']->equalTo(Carbon::parse('Wed, 21 Oct 2026 14:00:00 +0000')));
        $this->assertNull($advisory['severity']);
    }

    public function test_valid_empty_feed_yields_empty_array(): void
    {
        Http::fake(['https://www.php.net/feed.atom' => Http::response($this->emptyAtomFixture(), 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.php.net/feed.atom'));

        $this->assertSame([], $findings);
    }

    public function test_non_200_response_throws_alert_feed_exception(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response('Service Unavailable', 503)]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
    }

    /**
     * Defect 3: a 404/410 is a permanent failure, not a self-healing
     * rate-limit — the structured exception must say so so a future
     * rate-aware retry policy can tell the two apart.
     */
    public function test_404_response_marks_exception_permanent(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response('Not Found', 404)]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertSame(404, $e->status);
            $this->assertFalse($e->transient);
            $this->assertNull($e->retryAfterSeconds);
        }
    }

    public function test_malformed_body_throws_alert_feed_exception(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response('<rss><channel><item>', 200)]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
    }

    /**
     * An item whose guid AND link are both empty cannot yield a usable
     * external_id and must be skipped, while a sibling item is kept.
     */
    public function test_item_without_usable_id_is_skipped_but_siblings_kept(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>No id and no link item</title>
    <guid></guid>
  </item>
  <item>
    <title>Drupal core - Moderately critical - Cross-site scripting - SA-CORE-2026-016</title>
    <link>https://www.drupal.org/sa-core-2026-016</link>
    <guid isPermaLink="false">SA-CORE-2026-016</guid>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(1, $findings);
        $this->assertSame('SA-CORE-2026-016', $findings[0]['external_id']);
    }

    /**
     * Defect: wordpress.org's WAF returns HTTP 403 for Guzzle's bare default
     * User-Agent ("GuzzleHttp/7"). Every outbound request must instead carry
     * an identifying User-Agent naming the application and a contact-ish
     * identifier, shared with GitHubAdvisoryFetcher via
     * AlertFeedFetcher::USER_AGENT.
     */
    public function test_sends_identifying_user_agent_header(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($this->rssFixture(), 200)]);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        Http::assertSent(fn ($request) => $request->hasHeader(
            'User-Agent',
            'WebMonitor-AlertBot/1.0 (+https://github.com/rikinflik/web-monitor)'
        ));
    }

    public function test_request_uses_bounded_timeout(): void
    {
        $capturedTimeout = null;

        Http::fake([
            'https://www.drupal.org/security/rss.xml' => function ($request, $options) use (&$capturedTimeout) {
                $capturedTimeout = $options['timeout'] ?? null;

                return Http::response($this->rssFixture(), 200);
            },
        ]);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertSame(10, $capturedTimeout);
    }

    /**
     * Defect 4: a declared Content-Length over the cap must throw before the
     * (much larger, once parsed into a SimpleXML tree) body is processed.
     * Mirrors RssFeedFetcher::MAX_BODY_BYTES (2 MiB).
     */
    public function test_oversized_content_length_header_throws(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response(
            $this->rssFixture(),
            200,
            ['Content-Length' => (string) ((2 * 1024 * 1024) + 1)],
        )]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
    }

    /**
     * Defect 4: when Content-Length is absent (or lying), the actual body
     * size must still be bounded — a missing/incorrect header must not
     * bypass the limit.
     */
    public function test_oversized_actual_body_without_content_length_header_throws(): void
    {
        // A valid, parseable RSS document padded past the cap — this must be
        // caught by the size guard, not incidentally by the malformed-XML
        // guard (which a non-XML oversized string would trigger instead,
        // proving nothing about the size cap).
        $padding = str_repeat('a', (2 * 1024 * 1024) + 1);
        $oversized = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Large</title>'
            .'<item><title>t</title><link>https://example.org/1</link><guid>G1</guid>'
            .'<pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>'
            ."<description><![CDATA[{$padding}]]></description></item></channel></rss>";

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($oversized, 200)]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
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
            'https://www.drupal.org/security/rss.xml' => fn () => \GuzzleHttp\Promise\Create::promiseFor(
                new \GuzzleHttp\Psr7\Response(200, [], $this->infiniteStream()),
            ),
        ]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
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
     * Defect 4: a hard cap on item count collected per feed poll, bounding
     * the findings array even once the body-size guard has been satisfied.
     */
    public function test_item_count_is_capped(): void
    {
        $items = '';
        for ($i = 1; $i <= 510; $i++) {
            $items .= "<item><title>Item {$i}</title><link>https://example.org/{$i}</link><guid>GUID-{$i}</guid><pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate></item>";
        }

        $rss = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Many items</title>'.$items.'</channel></rss>';

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(500, $findings);
    }

    /**
     * Defect 5: the configured URL comes straight from the database and the
     * scheme guard only validates it, not any redirect target — a 302 to an
     * RFC1918 address or a cloud metadata endpoint must not be followed.
     * Mirrors SeoCheckService's "never follow redirects" convention.
     */
    public function test_disables_redirect_following(): void
    {
        $capturedOptions = null;

        Http::fake([
            'https://www.drupal.org/security/rss.xml' => function ($request, $options) use (&$capturedOptions) {
                $capturedOptions = $options;

                return Http::response($this->rssFixture(), 200);
            },
        ]);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertSame(false, $capturedOptions['allow_redirects'] ?? null);
    }

    /**
     * Defect 5 + 1: with redirects disabled, a 3xx is a non-2xx response and
     * must throw a loud "moved" error (naming only the redirect target's
     * host, never the full Location, which could carry a query string)
     * instead of silently being followed into a landing page that then
     * parses "successfully" as an empty feed.
     */
    public function test_redirect_response_throws_moved_message_with_target_host(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response(
            '',
            302,
            ['Location' => 'http://169.254.169.254/latest/meta-data/?secret=xyz'],
        )]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('moved', $e->getMessage());
            $this->assertStringContainsString('302', $e->getMessage());
            $this->assertStringContainsString('169.254.169.254', $e->getMessage());
            $this->assertStringNotContainsString('secret=xyz', $e->getMessage());
        }
    }

    /**
     * Defect 6: a raw Guzzle/Laravel transport-exception message embeds the
     * full effective request URI, including userinfo credentials present in
     * config.url. T3 persists this exception's message verbatim into
     * alert_sources.last_error, which T5 renders — so credentials must never
     * reach it. The sanitized message must instead name the source key and
     * host (never credentials, query string, or the raw transport message).
     */
    public function test_transport_failure_message_is_sanitized_and_names_source_and_host(): void
    {
        $url = 'https://user:sekret-pw@www.drupal.org/security/rss.xml';

        Http::fake([
            $url => function () {
                throw new ConnectionException(
                    'cURL error 6: Could not resolve host: www.drupal.org (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for '.
                    'https://user:sekret-pw@www.drupal.org/security/rss.xml?token=super-secret',
                );
            },
        ]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource($url));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('php', $e->getMessage());
            $this->assertStringContainsString('www.drupal.org', $e->getMessage());
            $this->assertStringNotContainsString('sekret-pw', $e->getMessage());
            $this->assertStringNotContainsString('user:', $e->getMessage());
            $this->assertStringNotContainsString('token=super-secret', $e->getMessage());
        }
    }

    /**
     * Defect 10: a connection failure (the realistic production failure for
     * an hourly poller — DNS failure or timeout) must land in
     * AlertFeedException, the clause T3 depends on most, rather than
     * escaping as a different exception type.
     */
    public function test_connection_exception_is_wrapped_in_alert_feed_exception(): void
    {
        Http::fake([
            'https://www.drupal.org/security/rss.xml' => function () {
                throw new ConnectionException('Connection timed out');
            },
        ]);

        $this->expectException(AlertFeedException::class);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
    }

    /**
     * Defect 7: libxml_set_external_entity_loader() sets process-global
     * state. parseXml() sets a no-op loader to block XXE but never restores
     * it, so in a long-lived queue worker every feed poll after the first
     * permanently blocks external entity resolution for the whole process.
     *
     * Proven by a probe that has no other way to observe libxml's global
     * loader state (PHP exposes no getter): after fetch() returns, parsing a
     * trusted local document that defines a SYSTEM external entity must
     * resolve it exactly as libxml's own default loader would — proving the
     * fetcher's loader was reset, not left permanently attached.
     */
    public function test_external_entity_loader_is_restored_after_parsing(): void
    {
        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($this->rssFixture(), 200)]);

        (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $tmpFile = tempnam(sys_get_temp_dir(), 'xxe-probe');
        file_put_contents($tmpFile, 'PROBE-VALUE');

        $xxePayload = <<<XML
<?xml version="1.0"?>
<!DOCTYPE root [<!ENTITY probe SYSTEM "file://{$tmpFile}">]>
<root>&probe;</root>
XML;

        $previousUseErrors = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xxePayload, \SimpleXMLElement::class, LIBXML_NOENT | LIBXML_DTDLOAD);
        libxml_use_internal_errors($previousUseErrors);

        @unlink($tmpFile);

        $this->assertNotFalse($doc, 'Expected the trusted local probe document to parse.');
        $this->assertSame('PROBE-VALUE', trim((string) $doc));
    }

    /**
     * Defect 8: php.net, drupal.org and wordpress.org each rely in the wild
     * on the guid->link fallback for items that carry a <link> but no usable
     * <guid>. No existing fixture exercised this — the nearby skip test gives
     * an item neither, which only pins the skip. Pin the fallback directly.
     */
    public function test_rss_item_falls_back_to_link_when_guid_is_absent(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>Item with no guid, only a link</title>
    <link>https://www.drupal.org/sa-core-2026-017</link>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(1, $findings);
        $this->assertSame('https://www.drupal.org/sa-core-2026-017', $findings[0]['external_id']);
    }

    /**
     * Defect 9: a missing date element must degrade to published_at = null
     * without dropping the item — unasserted previously, so a regression
     * that instead threw (losing the whole item) would still pass the suite.
     */
    public function test_missing_date_element_yields_null_published_at_with_sibling_surviving(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>No pubDate at all</title>
    <link>https://www.drupal.org/sa-core-2026-018</link>
    <guid>SA-CORE-2026-018</guid>
  </item>
  <item>
    <title>Drupal core - Moderately critical - Cross-site scripting - SA-CORE-2026-016</title>
    <link>https://www.drupal.org/sa-core-2026-016</link>
    <guid isPermaLink="false">SA-CORE-2026-016</guid>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(2, $findings);
        $this->assertSame('SA-CORE-2026-018', $findings[0]['external_id']);
        $this->assertNull($findings[0]['published_at']);
        $this->assertNotNull($findings[1]['published_at']);
    }

    /**
     * Defect 9: a malformed <pubDate> must degrade to null without dropping
     * the item.
     */
    public function test_malformed_pub_date_yields_null_published_at_with_sibling_surviving(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>Malformed pubDate</title>
    <link>https://www.drupal.org/sa-core-2026-019</link>
    <guid>SA-CORE-2026-019</guid>
    <pubDate>21/10/2026</pubDate>
  </item>
  <item>
    <title>Drupal core - Moderately critical - Cross-site scripting - SA-CORE-2026-016</title>
    <link>https://www.drupal.org/sa-core-2026-016</link>
    <guid isPermaLink="false">SA-CORE-2026-016</guid>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(2, $findings);
        $this->assertSame('SA-CORE-2026-019', $findings[0]['external_id']);
        $this->assertNull($findings[0]['published_at']);
        $this->assertNotNull($findings[1]['published_at']);
    }

    /**
     * Defect 9: Carbon::parse() accepts "0000-00-00 00:00:00" loosely — a
     * common artefact of badly generated feeds — and returns a confidently
     * wrong date (year -1) instead of throwing. Since published_at orders
     * the dashboard, a silently wrong date buries a critical advisory at the
     * bottom instead of surfacing the parse failure. Pinned decision: any
     * parsed year before 1990 (decades before any of the six monitored
     * ecosystems existed) is treated as implausible and degraded to null,
     * the same outcome as an unparseable date.
     */
    public function test_implausible_zero_date_yields_null_published_at(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
<channel>
  <title>Drupal.org security advisories</title>
  <item>
    <title>Zero-date artefact</title>
    <link>https://www.drupal.org/sa-core-2026-020</link>
    <guid>SA-CORE-2026-020</guid>
    <pubDate>0000-00-00 00:00:00</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]['published_at']);
    }

    /**
     * Defect 11: a fixture pattern that no longer matches the requested URL
     * (e.g. after the configured feed URL changes) must be flagged loudly by
     * the test harness (StrayRequestException) rather than silently falling
     * through to a real outbound request.
     */
    public function test_prevents_stray_requests_when_fake_pattern_does_not_match(): void
    {
        Http::fake(['https://unrelated.example.test/*' => Http::response('', 200)]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('http://127.0.0.1:1/feed'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            // request()'s own catch(Throwable) wraps the harness's
            // StrayRequestException — the assertion that matters is that no
            // real connection was attempted (curl errno 7, "connection
            // refused", would appear instead if protection were off).
            $this->assertStringContainsString('StrayRequestException', $e->getMessage());
        }
    }

    /**
     * Defect 13: real feeds carry quirks the baseline fixtures never
     * exercised — wordpress.org wraps <title> in CDATA, drupal.org titles
     * carry HTML entities (&amp;, &#039;), and both declare dc:/content:
     * namespaces on <rss>. XML entity/CDATA decoding is libxml's own
     * behaviour, not application logic to sabotage — this pins an
     * assumption that was previously just assumed to hold, rather than
     * testing a branch of our own code.
     */
    public function test_rss_title_quirks_cdata_entities_and_namespaces_are_handled(): void
    {
        $rss = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>Mixed quirks feed</title>
  <item>
    <title><![CDATA[WordPress 6.9 Security & Maintenance Release]]></title>
    <link>https://wordpress.org/news/2026/10/wordpress-6-9/</link>
    <guid>https://wordpress.org/news/2026/10/wordpress-6-9/</guid>
    <pubDate>Wed, 21 Oct 2026 14:00:00 +0000</pubDate>
    <dc:creator>WordPress News</dc:creator>
    <content:encoded><![CDATA[<p>Full content</p>]]></content:encoded>
  </item>
  <item>
    <title>Drupal core &amp; contrib - Don&#039;t trust input - SA-CORE-2026-021</title>
    <link>https://www.drupal.org/sa-core-2026-021</link>
    <guid isPermaLink="false">SA-CORE-2026-021</guid>
    <pubDate>Wed, 21 Oct 2026 15:00:00 +0000</pubDate>
  </item>
</channel>
</rss>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rss, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));

        $this->assertCount(2, $findings);
        $this->assertSame('WordPress 6.9 Security & Maintenance Release', $findings[0]['title']);
        $this->assertSame("Drupal core & contrib - Don't trust input - SA-CORE-2026-021", $findings[1]['title']);
    }

    /**
     * Defect 13: the baseline Atom fixture never gives an entry both a
     * rel="self" and a rel="alternate" link — only ever a single link per
     * entry — so the rel-preference branch in atomLink() was only ever
     * exercised against one link. Pin that it prefers rel="alternate" over
     * rel="self" when both are present, as php.net's real feed does.
     */
    public function test_atom_entry_prefers_alternate_link_over_self_link(): void
    {
        $atom = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>PHP: Hypertext Preprocessor</title>
  <entry>
    <id>https://www.php.net/archive/2026.php#id2026-10-22-1</id>
    <title>Another advisory</title>
    <link rel="self" type="application/atom+xml" href="https://www.php.net/feed.atom"/>
    <link rel="alternate" type="text/html" href="https://www.php.net/archive/2026.php#id2026-10-22-1"/>
    <published>2026-10-22T00:00:00+00:00</published>
  </entry>
</feed>
XML;

        Http::fake(['https://www.php.net/feed.atom' => Http::response($atom, 200)]);

        $findings = (new RssFeedFetcher)->fetch($this->rssSource('https://www.php.net/feed.atom'));

        $this->assertCount(1, $findings);
        $this->assertSame('https://www.php.net/archive/2026.php#id2026-10-22-1', $findings[0]['url']);
    }

    public function test_invalid_scheme_throws_without_sending_request(): void
    {
        Http::fake();

        $this->expectException(AlertFeedException::class);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('file:///etc/passwd'));
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * Defect 1: a well-formed but non-feed XHTML page (e.g. a maintenance
     * page served at the feed URL) must throw, naming the root element found,
     * rather than silently parsing as an empty RSS 2.0 feed.
     */
    public function test_well_formed_non_feed_xhtml_throws_naming_root_element(): void
    {
        $xhtml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<html xmlns="http://www.w3.org/1999/xhtml">
  <head><title>Scheduled Maintenance</title></head>
  <body><p>We will be back soon.</p></body>
</html>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($xhtml, 200)]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('html', $e->getMessage());
        }
    }

    /**
     * Defect 1: RSS 1.0 / rdf:RDF documents place items as siblings of
     * <channel>, not children of it — the RSS 2.0 walk silently yields zero
     * items. Must throw instead of silently returning [].
     */
    public function test_rss_one_rdf_feed_throws_naming_root_element(): void
    {
        $rdf = <<<'XML'
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/">
  <channel rdf:about="https://example.org/">
    <title>Example RSS 1.0 feed</title>
  </channel>
  <item rdf:about="https://example.org/item1">
    <title>Item One</title>
    <link>https://example.org/item1</link>
  </item>
</rdf:RDF>
XML;

        Http::fake(['https://www.drupal.org/security/rss.xml' => Http::response($rdf, 200)]);

        try {
            (new RssFeedFetcher)->fetch($this->rssSource('https://www.drupal.org/security/rss.xml'));
            $this->fail('Expected an AlertFeedException.');
        } catch (AlertFeedException $e) {
            $this->assertStringContainsString('RDF', $e->getMessage());
        }
    }
}
