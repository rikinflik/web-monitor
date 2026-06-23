<?php

namespace App\Services;

use App\Models\Monitor;
use App\Notifications\MonitorStatusChanged;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonitoringService
{
    // Caps body reads to prevent memory exhaustion on large responses
    private const MAX_BODY_BYTES = 524288; // 512 KB

    public function __construct(protected Client $client) {}

    public function check(Monitor $monitor): void
    {
        $startTime = microtime(true);
        $status = 'down';
        $statusCode = null;
        $responseTime = 0;
        $errorMessage = null;

        try {
            $options = [
                'timeout' => $monitor->timeout,
                'http_errors' => false,
                'stream' => true,
                'verify' => false,
            ];

            if ($monitor->basic_auth_user && $monitor->basic_auth_password) {
                $options['auth'] = [$monitor->basic_auth_user, $monitor->basic_auth_password];
            }

            $response = $this->client->get($monitor->url, $options);

            $statusCode = $response->getStatusCode();
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $body = $response->getBody()->read(self::MAX_BODY_BYTES);

            $isCorrectStatus = $statusCode === $monitor->expected_status_code;
            $hasKeyword = $monitor->keyword ? str_contains($body, $monitor->keyword) : true;

            if ($isCorrectStatus && $hasKeyword) {
                $status = 'up';
            } elseif (!$isCorrectStatus) {
                $errorMessage = "Codi HTTP inesperat: rebut {$statusCode}, esperat {$monitor->expected_status_code}";
            } elseif (!$hasKeyword) {
                $errorMessage = "Paraula clau '{$monitor->keyword}' no trobada a la resposta";
            }
        } catch (GuzzleException $e) {
            $responseTime = (int) ((microtime(true) - $startTime) * 1000);
            $errorMessage = $this->parseGuzzleError($e);
            Log::error("Monitor check failed for {$monitor->url}: " . $e->getMessage());
        }

        $lastLog = $monitor->logs()->latest('checked_at')->first();
        if (!$lastLog || $lastLog->status !== $status || $lastLog->status_code !== $statusCode) {
            $this->recordLog($monitor, $status, $responseTime, $statusCode, $errorMessage);
        }

        $this->updateStatus($monitor, $status);
    }

    private function parseGuzzleError(GuzzleException $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, 'timed out') || str_contains($message, 'Operation timed out')) {
            return 'Timeout: el servidor no va respondre a temps';
        }
        if (str_contains($message, 'Could not resolve host')) {
            return 'DNS: no s\'ha pogut resoldre el domini';
        }
        if (str_contains($message, 'Connection refused')) {
            return 'Connexió rebutjada pel servidor';
        }
        if (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
            return 'Error SSL/TLS: certificat invàlid o caducat';
        }

        return 'Error de connexió: ' . substr($message, 0, 120);
    }

    protected function recordLog(Monitor $monitor, string $status, int $responseTime, ?int $statusCode, ?string $errorMessage = null): void
    {
        $monitor->logs()->create([
            'status' => $status,
            'response_time' => $responseTime,
            'status_code' => $statusCode,
            'error_message' => $errorMessage,
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
        if (!$monitor->webhook_url) {
            return;
        }

        try {
            Http::timeout(5)->post($monitor->webhook_url, [
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
