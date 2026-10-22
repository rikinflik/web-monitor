<?php

namespace Tests\Feature;

use App\Jobs\CheckAlertSourceJob;
use App\Models\AlertSource;
use App\Services\AlertCheckService;
use App\Services\AlertFeeds\AlertFeedFetcher;
use App\Services\AlertFeeds\GitHubAdvisoryFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * CheckAlertSourceJob is a thin ShouldQueue wrapper delegating to
 * AlertCheckService::check(), dispatched queued hourly (T4) and
 * synchronously via dispatchSync() from the manual re-check action (T5).
 */
#[Group('check-alert-source-job')]
class CheckAlertSourceJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * AlertCheckService is final, so delegation is proven behaviourally
     * rather than with a mock: a spy fetcher records which AlertSource it was
     * actually invoked with, and the job's constructor source is asserted to
     * be that same row — proving handle() calls check($this->alertSource),
     * not some other source or no call at all.
     */
    public function test_handle_delegates_to_the_check_service_with_its_source(): void
    {
        $source = AlertSource::factory()->create([
            'kind' => AlertSource::KIND_RSS,
            'config' => ['url' => 'https://example.test/feed.atom'],
        ]);

        $spyFetcher = new class implements AlertFeedFetcher
        {
            public array $seenSourceIds = [];

            public function fetch(AlertSource $source): array
            {
                $this->seenSourceIds[] = $source->id;

                return [];
            }
        };

        $service = new AlertCheckService($spyFetcher, new GitHubAdvisoryFetcher);

        $job = new CheckAlertSourceJob($source);
        $job->handle($service);

        $this->assertSame([$source->id], $spyFetcher->seenSourceIds);
    }

    public function test_job_carries_the_repo_wide_tries_and_backoff_convention(): void
    {
        $source = AlertSource::factory()->create();

        $job = new CheckAlertSourceJob($source);

        $this->assertSame(3, $job->tries);
        $this->assertSame(10, $job->backoff);
    }

    /**
     * dispatchSync() runs the job inline in the current process rather than
     * through a queue worker — T5's manual "check now" action relies on this
     * to show fresh results immediately. The job must not assume anything
     * queue-worker-specific (e.g. a different container binding) to work.
     */
    public function test_job_is_safe_under_dispatch_sync(): void
    {
        $source = AlertSource::factory()->create([
            'kind' => AlertSource::KIND_RSS,
            'config' => ['url' => 'https://example.test/feed.atom'],
        ]);

        Http::fake(['https://example.test/feed.atom' => Http::response(
            '<?xml version="1.0" encoding="utf-8"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Test</title></feed>',
            200
        )]);

        CheckAlertSourceJob::dispatchSync($source);

        $this->assertNotNull($source->fresh()->last_checked_at);
        $this->assertNotNull($source->fresh()->last_success_at);
    }
}
