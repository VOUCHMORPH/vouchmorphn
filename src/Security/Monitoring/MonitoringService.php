<?php

namespace Security\Monitoring;

use DateTime;

class MonitoringService
{
    private string $logDir;
    private array $endpoints = [
        '/swap' => ['maxResponseTime' => 0.2, 'successRate' => 0.999],
        '/cashout' => ['maxResponseTime' => 0.3, 'successRate' => 0.995],
    ];

    private array $metrics = [];

    public function __construct(?string $logDir = null)
    {
        $this->logDir = rtrim($logDir ?? STORAGE_PATH . '/logs', '/') . '/';
    }

    public function trackRequest(string $endpoint, float $responseTime, bool $success, ?string $clientId = null): void
    {
        $date = (new DateTime())->format('Y-m-d');

        if (!isset($this->metrics[$date][$endpoint])) {
            $this->metrics[$date][$endpoint] = [
                'totalRequests' => 0,
                'successCount' => 0,
                'failCount' => 0,
                'responseTimes' => [],
            ];
        }

        $this->metrics[$date][$endpoint]['totalRequests']++;
        $this->metrics[$date][$endpoint]['responseTimes'][] = $responseTime;

        if ($success) {
            $this->metrics[$date][$endpoint]['successCount']++;
        } else {
            $this->metrics[$date][$endpoint]['failCount']++;
        }

        $this->checkSLA($endpoint, $responseTime, $success, $clientId);
    }

    private function checkSLA(string $endpoint, float $responseTime, bool $success, ?string $clientId): void
    {
        if (!isset($this->endpoints[$endpoint])) return;

        $threshold = $this->endpoints[$endpoint];
        $date = (new DateTime())->format('Y-m-d');
        $alertMsg = null;

        if ($responseTime > $threshold['maxResponseTime']) {
            $alertMsg = "SLA breach: $endpoint response time {$responseTime}s exceeds max {$threshold['maxResponseTime']}s";
        }

        $total = $this->metrics[$date][$endpoint]['totalRequests'];
        if (!$success && $total > 0 &&
            ($this->metrics[$date][$endpoint]['successCount'] / $total) < $threshold['successRate']) {
            $alertMsg = "SLA breach: $endpoint success rate below required {$threshold['successRate']}";
        }

        if ($alertMsg) {
            if ($clientId) $alertMsg .= " | Client: $clientId";
            $this->alertAdmin($alertMsg);
        }
    }

    public function alertAdmin(string $message): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $logFile = $this->logDir . 'ThreatAlerts.log';
        $line = (new DateTime())->format('Y-m-d H:i:s') . " - ALERT - $message\n";
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    public function logDailyMetrics(): void
    {
        $date = (new DateTime())->format('Y-m-d');
        if (!isset($this->metrics[$date])) return;

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $logFile = $this->logDir . 'daily_reconciliations.log';

        foreach ($this->metrics[$date] as $endpoint => $data) {
            $avgResp = count($data['responseTimes']) ? array_sum($data['responseTimes']) / count($data['responseTimes']) : 0;
            $line = sprintf(
                "%s | Endpoint: %s | Total: %d | Success: %d | Fail: %d | AvgRespTime: %.3fs\n",
                $date, $endpoint, $data['totalRequests'], $data['successCount'], $data['failCount'], $avgResp
            );
            file_put_contents($logFile, $line, FILE_APPEND);
        }
    }

    public function logAggregateMetrics(string $period = 'weekly'): void
    {
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $logFile = $this->logDir . "{$period}_reconciliations.log";

        foreach ($this->metrics as $date => $endpoints) {
            foreach ($endpoints as $endpoint => $data) {
                $avgResp = count($data['responseTimes']) ? array_sum($data['responseTimes']) / count($data['responseTimes']) : 0;
                $line = sprintf(
                    "%s | Endpoint: %s | Total: %d | Success: %d | Fail: %d | AvgRespTime: %.3fs\n",
                    $date, $endpoint, $data['totalRequests'], $data['successCount'], $data['failCount'], $avgResp
                );
                file_put_contents($logFile, $line, FILE_APPEND);
            }
        }
    }
}
