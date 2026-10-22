<?php

namespace Tests\Feature;

use App\Models\AlertFinding;
use App\Models\AlertSource;
use App\Services\AlertCheckService;
use App\Services\AlertFeeds\AlertFeedException;
use App\Services\AlertFeeds\AlertFeedFetcher;
use App\Services\AlertFeeds\GitHubAdvisoryFetcher;
use App\Services\AlertFeeds\RssFeedFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * AlertCheckService resolves the fetcher for an AlertSource's kind, inserts
 * only findings not already recorded (keyed on alert_source_id + external_id),
 * and records the outcome of the attempt (last_checked_at/last_success_at/
 * last_error) without ever touching previously recorded findings.
 */
#[Group('alert-check-service')]
class AlertCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function service(): AlertCheckService
    {
        return new AlertCheckService(new RssFeedFetcher, new GitHubAdvisoryFetcher);
    }

    /**
     * Build a persisted RSS-kind source. The factory default generates a
     * unique `key`, deliberately left untouched here: the alert_sources
     * migration installs the six fixed ecosystem rows on every
     * RefreshDatabase run, so overriding to a real ecosystem key (e.g.
     * "php") would collide with the seeded row instead of exercising
     * anything this test covers.
     */
    private function rssSource(array $overrides = []): AlertSource
    {
        return AlertSource::factory()->create(array_merge([
            'kind' => AlertSource::KIND_RSS,
            'config' => ['url' => 'https://www.php.net/feed.atom'],
        ], $overrides));
    }

    private function atomFeed(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>PHP</title>';

        foreach ($entries as $entry) {
            $xml .= '<entry>'
                .'<id>'.$entry['id'].'</id>'
                .'<title>'.$entry['title'].'</title>'
                .'<link rel="alternate" href="'.$entry['id'].'"/>'
                .'<published>2026-10-21T00:00:00+00:00</published>'
                .'</entry>';
        }

        return $xml.'</feed>';
    }

    private function threeEntryFeed(): array
    {
        return [
            ['id' => 'https://www.php.net/a1', 'title' => 'Advisory 1'],
            ['id' => 'https://www.php.net/a2', 'title' => 'Advisory 2'],
            ['id' => 'https://www.php.net/a3', 'title' => 'Advisory 3'],
        ];
    }

    // -------------------------------------------------------------------------
    // Core AC: dedup across two checks
    // -------------------------------------------------------------------------

    public function test_second_check_over_same_feed_plus_one_new_item_inserts_exactly_one_new_row(): void
    {
        $source = $this->rssSource();

        $fourEntries = array_merge($this->threeEntryFeed(), [
            ['id' => 'https://www.php.net/a4', 'title' => 'Advisory 4'],
        ]);

        // Http::fake() called twice for the same URL within one test replaces
        // the stub rather than queuing it (the second call wins outright),
        // so the two distinct responses needed across the two check() calls
        // below are queued explicitly via a sequence instead.
        Http::fake(['https://www.php.net/feed.atom' => Http::sequence()
            ->push($this->atomFeed($this->threeEntryFeed()), 200)
            ->push($this->atomFeed($fourEntries), 200)]);

        $this->service()->check($source);

        $this->assertSame(3, AlertFinding::query()->where('alert_source_id', $source->id)->count());

        $this->service()->check($source);

        $this->assertSame(4, AlertFinding::query()->where('alert_source_id', $source->id)->count());
        $this->assertSame(
            1,
            AlertFinding::query()->where('alert_source_id', $source->id)->where('external_id', 'https://www.php.net/a4')->count()
        );
        foreach ($this->threeEntryFeed() as $entry) {
            $this->assertSame(
                1,
                AlertFinding::query()->where('alert_source_id', $source->id)->where('external_id', $entry['id'])->count(),
                "Expected exactly one row for {$entry['id']}, no duplicate from the second check."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Fetcher resolution
    // -------------------------------------------------------------------------

    public function test_unknown_kind_throws_without_attempting_any_fetch(): void
    {
        $source = AlertSource::factory()->create(['kind' => 'carrier-pigeon']);

        // Caught rather than declared via expectException(): under that helper
        // the throwing call ends the test method, so a no-fetch assertion
        // placed after it would never run and the second half of this test's
        // name would go unverified.
        $thrown = null;

        try {
            $this->service()->check($source);
        } catch (InvalidArgumentException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $thrown);
        Http::assertNothingSent();
    }

    public function test_github_advisory_kind_is_fetched_via_the_github_fetcher(): void
    {
        $source = AlertSource::factory()->githubAdvisory()->create();

        Http::fake(['https://api.github.com/advisories*' => Http::response(json_encode([
            ['ghsa_id' => 'GHSA-aaaa-bbbb-cccc', 'summary' => 'A Laravel advisory', 'html_url' => 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc', 'published_at' => '2026-10-21T00:00:00Z', 'severity' => 'high'],
        ]), 200)]);

        $this->service()->check($source);

        $this->assertDatabaseHas('alert_findings', [
            'alert_source_id' => $source->id,
            'external_id' => 'GHSA-aaaa-bbbb-cccc',
            'severity' => 'high',
        ]);
    }

    // -------------------------------------------------------------------------
    // Success bookkeeping
    // -------------------------------------------------------------------------

    public function test_successful_check_clears_a_previously_set_last_error(): void
    {
        $source = $this->rssSource(['last_error' => 'some stale error from a previous broken run']);

        Http::fake(['https://www.php.net/feed.atom' => Http::response($this->atomFeed([]), 200)]);

        $this->service()->check($source);

        $this->assertNull($source->fresh()->last_error);
    }

    public function test_successful_check_sets_last_checked_at_and_last_success_at(): void
    {
        $source = $this->rssSource();
        $this->assertNull($source->last_checked_at);
        $this->assertNull($source->last_success_at);

        Http::fake(['https://www.php.net/feed.atom' => Http::response($this->atomFeed([]), 200)]);

        $this->service()->check($source);

        $fresh = $source->fresh();
        $this->assertNotNull($fresh->last_checked_at);
        $this->assertNotNull($fresh->last_success_at);
    }

    // -------------------------------------------------------------------------
    // Failure bookkeeping — AC: a broken feed must never look like
    // "the advisories went away".
    // -------------------------------------------------------------------------

    public function test_failing_fetch_records_last_error_without_wiping_findings_or_advancing_last_success_at(): void
    {
        $oldSuccess = Carbon::parse('2026-01-01 00:00:00');
        $source = $this->rssSource(['last_success_at' => $oldSuccess]);

        AlertFinding::factory()->create([
            'alert_source_id' => $source->id,
            'external_id' => 'pre-existing-finding',
        ]);

        Http::fake(['https://www.php.net/feed.atom' => Http::response('Service Unavailable', 503)]);

        $this->service()->check($source);

        $fresh = $source->fresh();
        $this->assertTrue($oldSuccess->equalTo($fresh->last_success_at));
        $this->assertNotNull($fresh->last_error);
        $this->assertNotNull($fresh->last_checked_at);
        $this->assertDatabaseHas('alert_findings', [
            'alert_source_id' => $source->id,
            'external_id' => 'pre-existing-finding',
        ]);
        $this->assertSame(1, AlertFinding::query()->where('alert_source_id', $source->id)->count());
    }

    public function test_transient_rate_limit_and_permanent_404_produce_recognisably_different_last_error_text(): void
    {
        $rateLimited = $this->rssSource();
        Http::fake(['https://www.php.net/feed.atom' => Http::response('', 429, ['Retry-After' => '120'])]);
        $this->service()->check($rateLimited);

        $notFound = $this->rssSource(['config' => ['url' => 'https://www.php.net/missing.atom']]);
        Http::fake(['https://www.php.net/missing.atom' => Http::response('Not Found', 404)]);
        $this->service()->check($notFound);

        $rateLimitedError = $rateLimited->fresh()->last_error;
        $notFoundError = $notFound->fresh()->last_error;

        $this->assertNotNull($rateLimitedError);
        $this->assertNotNull($notFoundError);
        $this->assertNotSame($rateLimitedError, $notFoundError);
        $this->assertStringContainsString('recover automatically', $rateLimitedError);
        $this->assertStringContainsString('needs attention', $notFoundError);
        $this->assertStringNotContainsString('recover automatically', $notFoundError);
        $this->assertStringNotContainsString('needs attention', $rateLimitedError);

        // The ~120s is the upstream's own retry-after; the next scheduled
        // check is up to an hour away. Both facts must be present, but never
        // spliced into one timescale that promises a recovery confirmed
        // "in ~120s" by a check that will not actually run for another hour.
        $this->assertStringContainsString('~120s', $rateLimitedError);
        $this->assertStringContainsString('1 hour', $rateLimitedError);
        $this->assertStringNotContainsString('~120s on the next scheduled check', $rateLimitedError);
        $this->assertStringNotContainsString('automatically in ~120s', $rateLimitedError);
    }

    // -------------------------------------------------------------------------
    // Exception classification — only AlertFeedException is a feed error
    // -------------------------------------------------------------------------

    public function test_unexpected_exception_propagates_rather_than_being_recorded_as_a_feed_error(): void
    {
        $source = $this->rssSource();

        $buggyFetcher = new class implements AlertFeedFetcher
        {
            public function fetch(AlertSource $source): array
            {
                throw new \RuntimeException('a real bug, not a feed problem');
            }
        };

        $service = new AlertCheckService($buggyFetcher, new GitHubAdvisoryFetcher);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a real bug, not a feed problem');

        $service->check($source);
    }

    // -------------------------------------------------------------------------
    // Concurrent-insert robustness
    // -------------------------------------------------------------------------

    /**
     * Two entries sharing one external_id within a single fetch result
     * exercise exactly the insert-level race the (alert_source_id,
     * external_id) unique index guards against: the pre-fetch existence
     * check cannot have seen the second entry coming (it was computed before
     * either insert happened), so the second insert attempt hits the same
     * unique-constraint violation a genuinely concurrent process racing this
     * check would trigger — this must be tolerated, not left to escape as an
     * uncaught QueryException.
     */
    public function test_duplicate_external_id_within_a_single_fetch_is_tolerated_without_throwing(): void
    {
        $source = $this->rssSource();

        $duplicatingFetcher = new class implements AlertFeedFetcher
        {
            public function fetch(AlertSource $source): array
            {
                $finding = [
                    'external_id' => 'dup-id',
                    'title' => 'Duplicated within one fetch',
                    'url' => 'https://example.test/dup',
                    'published_at' => null,
                    'severity' => null,
                ];

                return [$finding, $finding];
            }
        };

        $service = new AlertCheckService($duplicatingFetcher, new GitHubAdvisoryFetcher);

        $service->check($source);

        $this->assertSame(
            1,
            AlertFinding::query()->where('alert_source_id', $source->id)->where('external_id', 'dup-id')->count()
        );
        $this->assertNull($source->fresh()->last_error);
    }

    // -------------------------------------------------------------------------
    // first_seen_at
    // -------------------------------------------------------------------------

    public function test_first_seen_at_is_set_on_insert_and_unchanged_on_a_later_check_of_the_same_item(): void
    {
        $source = $this->rssSource();

        // Same feed body on both checks — Http::fake() called twice for the
        // same URL replaces rather than queues the stub, so both responses
        // are supplied through a sequence (see the core AC test above).
        Http::fake(['https://www.php.net/feed.atom' => Http::sequence()
            ->push($this->atomFeed($this->threeEntryFeed()), 200)
            ->push($this->atomFeed($this->threeEntryFeed()), 200)]);

        $this->service()->check($source);

        $original = AlertFinding::query()
            ->where('alert_source_id', $source->id)
            ->where('external_id', 'https://www.php.net/a1')
            ->firstOrFail();
        $originalFirstSeenAt = $original->first_seen_at;
        $this->assertNotNull($originalFirstSeenAt);

        $this->travel(1)->day();

        $this->service()->check($source);

        $again = $original->fresh();
        $this->assertTrue($originalFirstSeenAt->equalTo($again->first_seen_at));
    }
}
