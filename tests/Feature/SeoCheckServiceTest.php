<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\SeoCheck;
use App\Services\SeoCheckService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Redirect dimensions are probed against the OPPOSITE variant of the stored
 * URL, in the order www / https / trailing slash, followed by robots.txt and
 * sitemap.xml. Each MockHandler queue below mirrors that request order.
 */
#[Group('seo-check-service')]
class SeoCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(MockHandler $mock): SeoCheckService
    {
        $stack = HandlerStack::create($mock);
        return new SeoCheckService(new Client(['handler' => $stack]));
    }

    private function makeServiceWithHistory(MockHandler $mock, array &$history): SeoCheckService
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new SeoCheckService(new Client(['handler' => $stack]));
    }

    /**
     * Build a monitor with the given URL and return its auto-created SeoCheck
     * (per design §4.12 — never call SeoCheck::factory()->create() directly).
     */
    private function seoCheckForUrl(string $url): SeoCheck
    {
        return Monitor::factory()->create(['url' => $url])->seoCheck;
    }

    private function connectException(string $message): ConnectException
    {
        return new ConnectException($message, new GuzzleRequest('GET', 'https://example.com'));
    }

    /**
     * Full request queue in probe order: three redirect probes (www, https,
     * trailing slash) followed by robots + sitemap. Any argument left null
     * defaults to a plain 200 (no redirect / reachable).
     */
    private function queue(
        ?Response $www = null,
        ?Response $https = null,
        ?Response $slash = null,
        ?Response $robots = null,
        ?Response $sitemap = null,
    ): MockHandler {
        return new MockHandler([
            $www ?? new Response(200),
            $https ?? new Response(200),
            $slash ?? new Response(200),
            $robots ?? new Response(200),
            $sitemap ?? new Response(200),
        ]);
    }

    private function redirect(string $location): Response
    {
        return new Response(301, ['Location' => $location]);
    }

    // -------------------------------------------------------------------------
    // Request options + method
    // -------------------------------------------------------------------------

    public function test_probe_request_disables_redirects_and_http_errors(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertFalse($history[0]['options']['http_errors']);
    }

    public function test_redirect_probes_use_head_method(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        // First three requests are the redirect probes and must be HEAD.
        $this->assertSame('HEAD', $history[0]['request']->getMethod());
        $this->assertSame('HEAD', $history[1]['request']->getMethod());
        $this->assertSame('HEAD', $history[2]['request']->getMethod());
    }

    // -------------------------------------------------------------------------
    // Opposite-variant targets
    // -------------------------------------------------------------------------

    public function test_probes_target_the_opposite_variant_of_each_dimension(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/page');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        $probed = array_map(fn ($e) => (string) $e['request']->getUri(), $history);

        $this->assertSame('https://example.com/page', $probed[0]);       // www stripped
        $this->assertSame('http://www.example.com/page', $probed[1]);    // scheme flipped
        $this->assertSame('https://www.example.com/page/', $probed[2]);  // slash added
    }

    public function test_counterpart_preserves_non_standard_port(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('http://example.com:8080/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        $probed = array_map(fn ($e) => (string) $e['request']->getUri(), $history);
        // https counterpart (2nd probe) must keep the :8080 port.
        $this->assertSame('https://example.com:8080/', $probed[1]);
    }

    // -------------------------------------------------------------------------
    // Redirect classification vocabularies
    // -------------------------------------------------------------------------

    public function test_no_www_redirecting_to_www_is_classified(): void
    {
        // Stored canonical is www; the non-www counterpart redirects to it.
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        $service = $this->makeService($this->queue(
            www: $this->redirect('https://www.example.com/'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::NO_WWW_TO_WWW, $seoCheck->fresh()->www_redirect);
    }

    public function test_www_redirecting_to_no_www_is_classified(): void
    {
        // Stored canonical is non-www; the www counterpart redirects to it.
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue(
            www: $this->redirect('https://example.com/'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::WWW_TO_NO_WWW, $seoCheck->fresh()->www_redirect);
    }

    public function test_http_upgrading_to_https_is_classified(): void
    {
        // Stored canonical is https; the http counterpart redirects to https.
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue(
            https: $this->redirect('https://example.com/'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::HTTP_TO_HTTPS, $seoCheck->fresh()->https_redirect);
    }

    public function test_https_downgrading_to_http_is_classified(): void
    {
        // Stored URL is http; the https counterpart redirects back to http.
        $seoCheck = $this->seoCheckForUrl('http://example.com/');
        $service = $this->makeService($this->queue(
            https: $this->redirect('http://example.com/'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::HTTPS_TO_HTTP, $seoCheck->fresh()->https_redirect);
    }

    public function test_site_enforcing_trailing_slash_is_classified(): void
    {
        // Stored canonical has a trailing slash; the no-slash counterpart
        // redirects to add it.
        $seoCheck = $this->seoCheckForUrl('https://example.com/page/');
        $service = $this->makeService($this->queue(
            slash: $this->redirect('https://example.com/page/'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::WITH_SLASH, $seoCheck->fresh()->trailing_slash_redirect);
    }

    public function test_site_enforcing_no_trailing_slash_is_classified(): void
    {
        // Stored canonical has no trailing slash; the slash counterpart
        // redirects to strip it.
        $seoCheck = $this->seoCheckForUrl('https://example.com/page');
        $service = $this->makeService($this->queue(
            slash: $this->redirect('https://example.com/page'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::WITHOUT_SLASH, $seoCheck->fresh()->trailing_slash_redirect);
    }

    public function test_root_url_detects_site_wide_slash_stripping_via_synthetic_probe(): void
    {
        // A root URL never redirects onto its own slash variant, so the probe
        // targets a synthetic sub-path. A site that strips trailing slashes
        // redirects "/_seo-slash-probe/" to "/_seo-slash-probe".
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        $service = $this->makeService($this->queue(
            slash: $this->redirect('https://www.example.com/_seo-slash-probe'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::WITHOUT_SLASH, $seoCheck->fresh()->trailing_slash_redirect);
    }

    public function test_root_url_probes_the_synthetic_slash_path(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        // Third request is the trailing-slash probe.
        $this->assertSame(
            'https://www.example.com/_seo-slash-probe/',
            (string) $history[2]['request']->getUri(),
        );
    }

    public function test_root_url_with_empty_path_also_uses_the_synthetic_probe(): void
    {
        // Stored without a trailing slash, so parse_url() yields no path key at
        // all. The empty-path root must be treated identically to "/".
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://www.example.com');
        $service = $this->makeServiceWithHistory($this->queue(
            slash: $this->redirect('https://www.example.com/_seo-slash-probe'),
        ), $history);

        $service->check($seoCheck);

        $this->assertSame(
            'https://www.example.com/_seo-slash-probe/',
            (string) $history[2]['request']->getUri(),
        );
        $this->assertEquals(SeoCheck::WITHOUT_SLASH, $seoCheck->fresh()->trailing_slash_redirect);
    }

    public function test_root_url_without_slash_policy_yields_none(): void
    {
        // The synthetic probe returns 200 (no redirect): the site has no
        // trailing-slash policy, so the dimension stays NONE.
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        $service = $this->makeService($this->queue());

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::NONE, $seoCheck->fresh()->trailing_slash_redirect);
    }

    public function test_no_variant_redirects_yields_none_for_all_three(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue());

        $service->check($seoCheck);

        $fresh = $seoCheck->fresh();
        $this->assertEquals(SeoCheck::NONE, $fresh->www_redirect);
        $this->assertEquals(SeoCheck::NONE, $fresh->https_redirect);
        $this->assertEquals(SeoCheck::NONE, $fresh->trailing_slash_redirect);
    }

    public function test_redirect_changing_an_unrelated_dimension_yields_none(): void
    {
        // The www counterpart (www.example.com) redirects but only changes the
        // path, keeping the same host — so the www dimension stays NONE.
        $seoCheck = $this->seoCheckForUrl('https://example.com/old');
        $service = $this->makeService($this->queue(
            www: $this->redirect('https://www.example.com/new'),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::NONE, $seoCheck->fresh()->www_redirect);
    }

    public function test_redirect_columns_are_persisted(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        $service = $this->makeService($this->queue(
            www: $this->redirect('https://www.example.com/'),
        ));

        $service->check($seoCheck);

        $this->assertDatabaseHas('seo_checks', [
            'id' => $seoCheck->id,
            'www_redirect' => SeoCheck::NO_WWW_TO_WWW,
        ]);
    }

    // -------------------------------------------------------------------------
    // Triangulation — probe edge cases degrade gracefully
    // -------------------------------------------------------------------------

    public function test_redirect_without_location_header_yields_none(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue(
            www: new Response(301),
        ));

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::NONE, $seoCheck->fresh()->www_redirect);
    }

    public function test_probe_connection_error_degrades_to_none(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            $this->connectException('Connection refused'),
            $this->connectException('Connection refused'),
            $this->connectException('Connection refused'),
            new Response(200),
            new Response(200),
        ]));

        $service->check($seoCheck);

        $fresh = $seoCheck->fresh();
        $this->assertEquals(SeoCheck::NONE, $fresh->www_redirect);
        $this->assertEquals(SeoCheck::NONE, $fresh->https_redirect);
        $this->assertEquals(SeoCheck::NONE, $fresh->trailing_slash_redirect);
    }

    public function test_head_rejected_falls_back_to_get_and_still_classifies(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://www.example.com/');
        // www probe: HEAD -> 405, then GET -> 301 to the canonical www form.
        $service = $this->makeServiceWithHistory(new MockHandler([
            new Response(405),
            $this->redirect('https://www.example.com/'),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
        ]), $history);

        $service->check($seoCheck);

        $this->assertEquals(SeoCheck::NO_WWW_TO_WWW, $seoCheck->fresh()->www_redirect);
        $this->assertSame('HEAD', $history[0]['request']->getMethod());
        $this->assertSame('GET', $history[1]['request']->getMethod());
    }

    // -------------------------------------------------------------------------
    // robots.txt reachability
    // -------------------------------------------------------------------------

    public function test_robots_ok_true_when_robots_txt_returns_200(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue(
            robots: new Response(200),
        ));

        $service->check($seoCheck);

        $this->assertTrue($seoCheck->fresh()->robots_ok);
    }

    public function test_robots_ok_false_when_robots_txt_returns_404(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService($this->queue(
            robots: new Response(404),
        ));

        $service->check($seoCheck);

        $this->assertFalse($seoCheck->fresh()->robots_ok);
    }

    public function test_robots_ok_false_when_robots_txt_throws(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            $this->connectException('Connection refused'),
            new Response(200),
        ]));

        $service->check($seoCheck);

        $this->assertFalse($seoCheck->fresh()->robots_ok);
    }

    // -------------------------------------------------------------------------
    // sitemap reachability with fallback
    // -------------------------------------------------------------------------

    public function test_sitemap_ok_true_when_sitemap_xml_returns_200_without_fallback(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(
            sitemap: new Response(200),
        ), $history);

        $service->check($seoCheck);

        $this->assertTrue($seoCheck->fresh()->sitemap_ok);
        $urls = array_map(fn ($e) => (string) $e['request']->getUri(), $history);
        $this->assertNotContains('https://example.com/sitemap_index.xml', $urls);
    }

    public function test_sitemap_ok_true_via_index_fallback(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(404),
            new Response(200),
        ]));

        $service->check($seoCheck);

        $this->assertTrue($seoCheck->fresh()->sitemap_ok);
    }

    public function test_sitemap_ok_false_when_both_urls_non_200(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(404),
            new Response(500),
        ]));

        $service->check($seoCheck);

        $this->assertFalse($seoCheck->fresh()->sitemap_ok);
    }

    // -------------------------------------------------------------------------
    // Bounded outbound request count
    // -------------------------------------------------------------------------

    public function test_common_case_makes_exactly_five_requests(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        $this->assertCount(5, $history);
    }

    public function test_worst_case_makes_exactly_six_requests(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory(new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(200),
            new Response(404),
            new Response(200),
        ]), $history);

        $service->check($seoCheck);

        $this->assertCount(6, $history);
    }

    public function test_outbound_request_order_is_probes_then_robots_then_sitemap(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        $urls = array_map(fn ($e) => (string) $e['request']->getUri(), $history);
        $this->assertSame([
            'https://www.example.com/',                  // www counterpart
            'http://example.com/',                       // https counterpart
            'https://example.com/_seo-slash-probe/',     // trailing-slash counterpart (root -> synthetic path)
            'https://example.com/robots.txt',
            'https://example.com/sitemap.xml',
        ], $urls);
    }

    public function test_every_request_carries_the_bounded_timeout(): void
    {
        $history = [];
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeServiceWithHistory($this->queue(), $history);

        $service->check($seoCheck);

        foreach ($history as $entry) {
            $this->assertSame(10, $entry['options']['timeout']);
        }
    }

    // -------------------------------------------------------------------------
    // History dedup + last_checked_at
    // -------------------------------------------------------------------------

    public function test_identical_consecutive_checks_write_one_history_row(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
        ]));

        $service->check($seoCheck);
        $service->check($seoCheck);

        $this->assertEquals(1, $seoCheck->logs()->count());
    }

    public function test_differing_check_writes_a_second_history_row(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $service = $this->makeService(new MockHandler([
            new Response(200), new Response(200), new Response(200), new Response(200), new Response(200),
            new Response(200), new Response(200), new Response(200), new Response(404), new Response(200),
        ]));

        $service->check($seoCheck);
        $service->check($seoCheck);

        $this->assertEquals(2, $seoCheck->logs()->count());
    }

    public function test_last_checked_at_is_updated_after_check(): void
    {
        $seoCheck = $this->seoCheckForUrl('https://example.com/');
        $this->assertNull($seoCheck->last_checked_at);

        $service = $this->makeService($this->queue());

        $service->check($seoCheck);

        $this->assertNotNull($seoCheck->fresh()->last_checked_at);
    }
}
