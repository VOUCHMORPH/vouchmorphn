<?php

namespace Security\Monitoring;

class IntrusionDetection
{
    private array $suspiciousPatterns = [
        '/DROP\s+TABLE/i',
        '/UNION\s+SELECT/i',
        '/<script>/i'
    ];

    public function scanInput(string $input): bool
    {
        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) return true;
        }
        return false;
    }

    public function logIntrusion(string $input, string $ip): void
    {
        $dir = STORAGE_PATH . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/Intrusion.log',
            date('Y-m-d H:i:s') . " - $ip - $input\n",
            FILE_APPEND
        );
    }
}
