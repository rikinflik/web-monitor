<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\MonitorLog;
use App\Notifications\MonitorStatusChanged;
use App\Services\MonitoringService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * @group monitoring-service
 */
class MonitoringServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(MockHandler $mock): MonitoringService
    {
        $stack = HandlerStack::create($mock);
        return new MonitoringService(new Client(['handler' => $stack]));
    }

    private function makeServiceWithHistory(MockHandler $mock, array &$history): MonitoringService
    {
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        return new MonitoringService(new Client(['handler' => $stack]));
    }

    private function connectException(string $message): ConnectException
    {
        return new ConnectException($message, new GuzzleRequest('GET', 'https://example.com'));
    }

    // -------------------------------------------------------------------------
    // Status resolution
    // -------------------------------------------------------------------------

    public function test_status_becomes_up_when_response_matches_expected_code(): void
    {
        $monitor = Monitor::factory()->down()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(200)]));

        $service->check($monitor);

        $this->assertEquals('up', $monitor->fresh()->status);
    }

    public function test_status_becomes_down_when_response_code_does_not_match(): void
    {
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(301)]));

        $service->check($monitor);

        $this->assertEquals('down', $monitor->fresh()->status);
    }

    public function test_error_message_includes_received_and_expected_codes_on_mismatch(): void
    {
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(503)]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsString('503', $log->error_message);
        $this->assertStringContainsString('200', $log->error_message);
    }

    public function test_status_is_up_when_keyword_is_found_in_body(): void
    {
        $monitor = Monitor::factory()->down()->withKeyword('Welcome')->create();
        $service = $this->makeService(new MockHandler([new Response(200, [], 'Welcome to our site')]));

        $service->check($monitor);

        $this->assertEquals('up', $monitor->fresh()->status);
    }

    public function test_status_is_down_when_keyword_is_missing_from_body(): void
    {
        $monitor = Monitor::factory()->withKeyword('Welcome')->create();
        $service = $this->makeService(new MockHandler([new Response(200, [], 'Page not found')]));

        $service->check($monitor);

        $this->assertEquals('down', $monitor->fresh()->status);
    }

    public function test_error_message_names_the_missing_keyword(): void
    {
        $monitor = Monitor::factory()->withKeyword('Dashboard')->create();
        $service = $this->makeService(new MockHandler([new Response(200, [], 'Other content')]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsString('Dashboard', $log->error_message);
    }

    public function test_check_without_keyword_ignores_body_content(): void
    {
        $monitor = Monitor::factory()->down()->create(['keyword' => null]);
        $service = $this->makeService(new MockHandler([new Response(200, [], 'Any body')]));

        $service->check($monitor);

        $this->assertEquals('up', $monitor->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Network / connection errors
    // -------------------------------------------------------------------------

    public function test_status_is_down_on_connection_error(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([
            $this->connectException('Connection refused'),
        ]));

        $service->check($monitor);

        $this->assertEquals('down', $monitor->fresh()->status);
    }

    public function test_timeout_error_is_detected_and_reported(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([
            $this->connectException('cURL error 28: Operation timed out'),
        ]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsStringIgnoringCase('timeout', $log->error_message);
    }

    public function test_dns_error_is_detected_and_reported(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([
            $this->connectException('cURL error 6: Could not resolve host: example.com'),
        ]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsStringIgnoringCase('DNS', $log->error_message);
    }

    public function test_ssl_error_is_detected_and_reported(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([
            $this->connectException('cURL error 60: SSL certificate problem'),
        ]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsStringIgnoringCase('SSL', $log->error_message);
    }

    public function test_connection_refused_error_is_reported(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([
            $this->connectException('cURL error 7: Connection refused'),
        ]));

        $service->check($monitor);

        $log = MonitorLog::latest('checked_at')->first();
        $this->assertStringContainsStringIgnoringCase('rebutjada', $log->error_message);
    }

    // -------------------------------------------------------------------------
    // Basic Auth
    // -------------------------------------------------------------------------

    public function test_basic_auth_credentials_are_sent_when_configured(): void
    {
        $history = [];
        $monitor = Monitor::factory()->down()->withBasicAuth('user', 'secret')->create();
        $service = $this->makeServiceWithHistory(
            new MockHandler([new Response(200)]),
            $history,
        );

        $service->check($monitor);

        $this->assertCount(1, $history);
        $authHeader = $history[0]['request']->getHeaderLine('Authorization');
        $this->assertStringStartsWith('Basic ', $authHeader);
        $this->assertEquals(base64_encode('user:secret'), substr($authHeader, 6));
    }

    public function test_no_auth_header_sent_when_credentials_not_set(): void
    {
        $history = [];
        $monitor = Monitor::factory()->down()->create([
            'basic_auth_user' => null,
            'basic_auth_password' => null,
        ]);
        $service = $this->makeServiceWithHistory(
            new MockHandler([new Response(200)]),
            $history,
        );

        $service->check($monitor);

        $this->assertEmpty($history[0]['request']->getHeaderLine('Authorization'));
    }

    // -------------------------------------------------------------------------
    // Log deduplication
    // -------------------------------------------------------------------------

    public function test_log_is_created_on_first_check(): void
    {
        $monitor = Monitor::factory()->create();
        $service = $this->makeService(new MockHandler([new Response(200)]));

        $this->assertEquals(0, $monitor->logs()->count());

        $service->check($monitor);

        $this->assertEquals(1, $monitor->logs()->count());
    }

    public function test_duplicate_log_is_not_created_when_status_and_code_unchanged(): void
    {
        $monitor = Monitor::factory()->create(['expected_status_code' => 301]);
        $service = $this->makeService(new MockHandler([
            new Response(301),
            new Response(301),
        ]));

        $service->check($monitor);
        $service->check($monitor);

        $this->assertEquals(1, $monitor->logs()->count());
    }

    public function test_new_log_is_created_when_status_changes(): void
    {
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([
            new Response(500),
            new Response(200),
        ]));

        $service->check($monitor);
        $service->check($monitor);

        $this->assertEquals(2, $monitor->logs()->count());
    }

    public function test_new_log_is_created_when_status_code_changes(): void
    {
        // Both 301 and 302 are "down" (expected 200), but the code changed
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([
            new Response(301),
            new Response(302),
        ]));

        $service->check($monitor);
        $service->check($monitor);

        $this->assertEquals(2, $monitor->logs()->count());
    }

    // -------------------------------------------------------------------------
    // Notifications
    // -------------------------------------------------------------------------

    public function test_notification_is_sent_when_monitor_goes_down(): void
    {
        Notification::fake();

        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(500)]));

        $service->check($monitor);

        Notification::assertSentTo(
            $monitor->user,
            MonitorStatusChanged::class,
            fn ($n) => $n->status === 'down',
        );
    }

    public function test_notification_is_sent_when_monitor_recovers(): void
    {
        Notification::fake();

        $monitor = Monitor::factory()->down()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(200)]));

        $service->check($monitor);

        Notification::assertSentTo(
            $monitor->user,
            MonitorStatusChanged::class,
            fn ($n) => $n->status === 'up',
        );
    }

    public function test_no_notification_when_status_does_not_change(): void
    {
        Notification::fake();

        // Monitor starts 'up', check returns 200 → stays 'up'
        $monitor = Monitor::factory()->create(['expected_status_code' => 200]);
        $service = $this->makeService(new MockHandler([new Response(200)]));

        $service->check($monitor);

        Notification::assertNothingSentTo($monitor->user);
    }

    // -------------------------------------------------------------------------
    // Webhooks
    // -------------------------------------------------------------------------

    public function test_webhook_is_fired_when_status_changes(): void
    {
        Http::fake(['https://hooks.example.com/*' => Http::response('ok', 200)]);

        $monitor = Monitor::factory()->down()
            ->withWebhook('https://hooks.example.com/notify')
            ->create(['expected_status_code' => 200]);

        $service = $this->makeService(new MockHandler([new Response(200)]));
        $service->check($monitor);

        Http::assertSent(fn ($req) => $req->url() === 'https://hooks.example.com/notify'
            && $req['status'] === 'up'
        );
    }

    public function test_webhook_is_not_fired_when_status_does_not_change(): void
    {
        Http::fake();

        // Already 'up', check returns 200 → no change
        $monitor = Monitor::factory()
            ->withWebhook('https://hooks.example.com/notify')
            ->create(['expected_status_code' => 200]);

        $service = $this->makeService(new MockHandler([new Response(200)]));
        $service->check($monitor);

        Http::assertNothingSent();
    }

    public function test_no_webhook_attempt_when_url_is_not_set(): void
    {
        Http::fake();

        $monitor = Monitor::factory()->down()->create([
            'webhook_url' => null,
            'expected_status_code' => 200,
        ]);

        $service = $this->makeService(new MockHandler([new Response(200)]));
        $service->check($monitor);

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // last_checked_at is always updated
    // -------------------------------------------------------------------------

    public function test_last_checked_at_is_updated_after_every_check(): void
    {
        $monitor = Monitor::factory()->create(['last_checked_at' => null]);
        $service = $this->makeService(new MockHandler([new Response(200)]));

        $this->assertNull($monitor->last_checked_at);

        $service->check($monitor);

        $this->assertNotNull($monitor->fresh()->last_checked_at);
    }
}
