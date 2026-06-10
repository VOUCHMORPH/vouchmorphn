<?php

namespace Infrastructure\Mojaloop;

use PDO;
use PDOException;

class IdempotencyService
{
    /**
     * Check if a key already exists
     */
    public static function check(PDO $db, string $key): ?array
    {
        $stmt = $db->prepare("
            SELECT result, created_at
            FROM idempotency_keys
            WHERE key = :key
            LIMIT 1
        ");

        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Return just the result (as SwapService expects)
        return json_decode($row['result'], true);
    }

    /**
     * Store result safely - MATCHES SwapService call signature
     * SwapService calls: IdempotencyService::store($this->swapDB, $key, $result)
     */
    public static function store(PDO $db, string $key, array $result): array
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO idempotency_keys (key, result, created_at)
                VALUES (:key, :result::jsonb, NOW())
                ON CONFLICT (key) DO UPDATE SET result = EXCLUDED.result, created_at = NOW()
                RETURNING key
            ");

            $stmt->execute([
                ':key'    => $key,
                ':result' => json_encode($result)
            ]);

            return $result;

        } catch (PDOException $e) {
            throw new \RuntimeException(
                "Idempotency storage failed: " . $e->getMessage()
            );
        }
    }

    /**
     * HARD SAFETY GUARD (bank-style usage pattern)
     *
     * Returns cached result OR null if new execution should proceed
     */
    public static function resolve(PDO $db, string $key): ?array
    {
        $existing = self::check($db, $key);

        if ($existing) {
            return $existing;
        }

        return null;
    }

    /**
     * Reserve key BEFORE execution (prevents double spend race condition)
     */
    public static function reserve(PDO $db, string $key): bool
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO idempotency_keys (key, result, created_at)
                VALUES (:key, '{}'::jsonb, NOW())
                ON CONFLICT (key) DO NOTHING
            ");

            $stmt->execute([':key' => $key]);

            // If row exists already, reservation failed
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            throw new \RuntimeException("Idempotency reserve failed: " . $e->getMessage());
        }
    }
}
