<?php

namespace Security\Monitoring;

class ApiRateLimiter
{
    private int $maxRequests;
    private int $period;

    public function __construct(int $maxRequests = 100, int $period = 60)
    {
        $this->maxRequests = $maxRequests;
        $this->period = $period;
    }

    public function check(string $clientId): bool
    {
        $redis = new \Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'localhost');

        $key = "rate_limit:{$clientId}:" . floor(time() / $this->period);
        $current = $redis->incr($key);

        if ($current === 1) {
            $redis->expire($key, $this->period);
        }

        return $current <= $this->maxRequests;
    }
}
