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
            SELECT operation, result, created_at
            FROM idempotency_keys
            WHERE key = :key
            LIMIT 1
        ");

        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'operation'  => $row['operation'],
            'result'     => json_decode($row['result'], true),
            'created_at' => $row['created_at']
        ];
    }

    /**
     * Store result safely (BANK-GRADE ATOMIC INSERT)
     * Uses PostgreSQL ON CONFLICT for idempotency safety
     */
    public static function store(PDO $db, string $key, string $operation, array $response): array
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO idempotency_keys (key, operation, result, created_at)
                VALUES (:key, :operation, :result::jsonb, NOW())
                ON CONFLICT (key) DO NOTHING
                RETURNING key
            ");

            $stmt->execute([
                ':key'       => $key,
                ':operation' => $operation,
                ':result'    => json_encode($response)
            ]);

            $inserted = $stmt->fetchColumn();

            // If another process already inserted it
            if (!$inserted) {
                return self::check($db, $key);
            }

            return [
                'operation'  => $operation,
                'result'     => $response,
                'created_at' => date('Y-m-d H:i:s')
            ];

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
            return $existing['result'];
        }

        return null;
    }

    /**
     * Reserve key BEFORE execution (prevents double spend race condition)
     */
    public static function reserve(PDO $db, string $key, string $operation): bool
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO idempotency_keys (key, operation, result, created_at)
                VALUES (:key, :operation, '{}'::jsonb, NOW())
                ON CONFLICT (key) DO NOTHING
            ");

            $stmt->execute([
                ':key'       => $key,
                ':operation' => $operation
            ]);

            // If row exists already, reservation failed
            return $stmt->rowCount() > 0;

        } catch (PDOException $e) {
            throw new \RuntimeException("Idempotency reserve failed: " . $e->getMessage());
        }
    }
}
