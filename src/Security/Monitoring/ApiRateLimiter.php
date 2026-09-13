<?php

namespace Security\Monitoring;

use PDO;

/**
 * Fixed-window request rate limiter backed by Postgres, the datastore
 * this application actually provisions everywhere (there is no Redis
 * service in docker-compose.yml or any deployment config - the earlier
 * Redis-based version connected to a service that has never existed in
 * this deployment, so its only real call site wrapped every use in a
 * try/catch that silently let the request through on failure, meaning
 * rate limiting was never actually active).
 */
class ApiRateLimiter
{
    private PDO $db;
    private int $maxRequests;
    private int $period;

    public function __construct(PDO $db, int $maxRequests = 100, int $period = 60)
    {
        $this->db = $db;
        $this->maxRequests = $maxRequests;
        $this->period = $period;
        $this->ensureTable();
    }

    public function check(string $clientId): bool
    {
        $windowStart = (int)(floor(time() / $this->period) * $this->period);

        $stmt = $this->db->prepare("
            INSERT INTO rate_limits (client_id, window_start, request_count)
            VALUES (:client_id, :window_start, 1)
            ON CONFLICT (client_id, window_start)
            DO UPDATE SET request_count = rate_limits.request_count + 1
            RETURNING request_count
        ");
        $stmt->execute([':client_id' => $clientId, ':window_start' => $windowStart]);
        $current = (int)$stmt->fetchColumn();

        // Opportunistic cleanup - no cron dependency, just bounds table
        // growth a little more each time a window rolls over.
        if (random_int(1, 50) === 1) {
            $this->db->prepare("DELETE FROM rate_limits WHERE window_start < :cutoff")
                ->execute([':cutoff' => time() - (10 * $this->period)]);
        }

        return $current <= $this->maxRequests;
    }

    private function ensureTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                client_id TEXT NOT NULL,
                window_start BIGINT NOT NULL,
                request_count INT NOT NULL DEFAULT 0,
                PRIMARY KEY (client_id, window_start)
            )
        ");
    }
}
