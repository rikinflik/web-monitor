<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\MonitorLog;
use App\Notifications\MonitorStatusChanged;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitoringService
{
    public function __construct(protected Client $client) {}

    public function check(Monitor $monitor): void
    {
        $startTime = microtime(true);
        $status = 'down';
        $statusCode = null;
        $responseTime = 0;

        try {
            $response = $this->client->get($monitor->url, [
                'timeout' => $monitor->timeout,
                'http_errors' => false,
            ]);

            $statusCode = $response->getStatusCode();
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $body = (string) $response->getBody();

            $isCorrectStatus = $statusCode === $monitor->expected_status_code;
            $hasKeyword = $monitor->keyword ? str_contains($body, $monitor->keyword) : true;

            if ($isCorrectStatus && $hasKeyword) {
                $status = 'up';
            }
        } catch (GuzzleException $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            Log::error("Monitor check failed for {$monitor->url}: " . $e->getMessage());
        }

        $this->recordLog($monitor, $status, $responseTime, $statusCode);
        $this->updateStatus($monitor, $status);
    }

    protected function recordLog(Monitor $monitor, string $status, int $responseTime, ?int $statusCode): void
    {
        $monitor->logs()->create([
            'status' => $status,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'checked_at' => now(),
        ]);
    }

    protected function updateStatus(Monitor $monitor, string $newStatus): void
    {
        $oldStatus = $monitor->status;
        $monitor->update([
            'status' => $newStatus,
            'last_checked_at' => now(),
        ]);

        if ($oldStatus !== $newStatus) {
            $this->notifyStatusChange($monitor, $newStatus);
            $this->triggerWebhook($monitor, $newStatus);
        }
    }

    protected function notifyStatusChange(Monitor $monitor, string $status): void
    {
        $monitor->user->notify(new MonitorStatusChanged($monitor, $status));
    }

    protected function triggerWebhook(Monitor $monitor, string $status): void
    {
        if ($monitor->webhook_url) {
            try {
                Http::post($monitor->webhook_url, [
                    'monitor_id' => $monitor->id,
                    'url' => $monitor->url,
                    'status' => $status,
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Exception $e) {
                Log::error("Webhook failed for Monitor {$monitor->id}: " . $e->getMessage());
            }
        }
    }
}
