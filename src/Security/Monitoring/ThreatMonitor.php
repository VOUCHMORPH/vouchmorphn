<?php

namespace Security\Monitoring;

class ThreatMonitor
{
    public function scanLogs(array $logs): array
    {
        return array_filter($logs, fn($log) => strpos(strtolower($log['message']), 'error') !== false);
    }

    public function alertAdmin(string $message): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/ThreatAlerts.log',
            date('Y-m-d H:i:s') . " - $message\n",
            FILE_APPEND
        );
    }
}
