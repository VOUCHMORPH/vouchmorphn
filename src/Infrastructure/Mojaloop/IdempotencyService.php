<?php

declare(strict_types=1);

namespace Infrastructure\Mojaloop;

use PDO;
use PDOException;
use RuntimeException;

class IdempotencyService
{
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
        return json_decode($row['result'], true);
    }

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
                ':key' => $key,
                ':result' => json_encode($result),
            ]);
            return $result;
        } catch (PDOException $e) {
            throw new RuntimeException('Idempotency storage failed: ' . $e->getMessage());
        }
    }

    public static function resolve(PDO $db, string $key): ?array
    {
        return self::check($db, $key);
    }

    public static function reserve(PDO $db, string $key): bool
    {
        try {
            $stmt = $db->prepare("
                INSERT INTO idempotency_keys (key, result, created_at)
                VALUES (:key, '{}'::jsonb, NOW())
                ON CONFLICT (key) DO NOTHING
            ");
            $stmt->execute([':key' => $key]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new RuntimeException('Idempotency reserve failed: ' . $e->getMessage());
        }
    }
}
