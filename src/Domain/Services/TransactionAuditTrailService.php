<?php
declare(strict_types=1);

namespace Domain\Services;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Tracks the full lifecycle of a transaction for audit/compliance purposes:
 * where it began, who sent it, who received it, the amount moved, and the
 * timestamps it started and ended at.
 *
 * Schema: src/Core/Database/sql/transaction_audit_trail.sql
 *
 * Usage:
 *   $trail = new TransactionAuditTrailService($db);
 *   $trailId = $trail->beginTransaction([
 *       'transaction_reference' => $swapReference,
 *       'transaction_type'      => 'SWAP',
 *       'origin'                => 'USSD',
 *       'sender_name'           => $senderName,
 *       'sender_account'        => $senderAccountMask,
 *       'receiver_name'         => $receiverName,
 *       'receiver_account'      => $receiverAccountMask,
 *       'amount'                => $amount,
 *       'currency_code'         => 'BWP',
 *   ]);
 *   // ... perform the transfer ...
 *   $trail->completeTransaction($trailId, 'COMPLETED');
 */
class TransactionAuditTrailService
{
    private const VALID_STATUSES = ['STARTED', 'COMPLETED', 'FAILED', 'REVERSED'];

    private PDO $db;
    private $logger;

    public function __construct(PDO $db, $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?? new class () {
            public function warning($msg): void
            {
                error_log('[WARNING] ' . (is_string($msg) ? $msg : json_encode($msg)));
            }
            public function error($msg): void
            {
                error_log('[ERROR] ' . (is_string($msg) ? $msg : json_encode($msg)));
            }
        };
    }

    /**
     * Opens a new audit trail entry the moment a transaction begins.
     *
     * Required $data keys: transaction_reference, origin, sender_name,
     * receiver_name, amount, currency_code.
     * Optional: transaction_type, sender_account, sender_institution,
     * receiver_account, receiver_institution, metadata (array), ip_address.
     *
     * @return int|null The trail id (pass to completeTransaction/failTransaction), or null if it could not be recorded.
     */
    public function beginTransaction(array $data): ?int
    {
        foreach (['transaction_reference', 'origin', 'sender_name', 'receiver_name', 'amount', 'currency_code'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->checkTableReady()) {
            error_log('[TXN_AUDIT_FALLBACK] START ' . json_encode($data));
            return null;
        }

        $now = $this->now();

        $params = [
            ':transaction_reference' => (string)$data['transaction_reference'],
            ':transaction_type'      => (string)($data['transaction_type'] ?? 'TRANSFER'),
            ':origin'                => (string)$data['origin'],
            ':sender_name'           => (string)$data['sender_name'],
            ':sender_account'        => $data['sender_account'] ?? null,
            ':sender_institution'    => $data['sender_institution'] ?? null,
            ':receiver_name'         => (string)$data['receiver_name'],
            ':receiver_account'      => $data['receiver_account'] ?? null,
            ':receiver_institution'  => $data['receiver_institution'] ?? null,
            ':amount'                => $data['amount'],
            ':currency_code'         => strtoupper((string)$data['currency_code']),
            ':started_at'            => $now,
            ':metadata'              => isset($data['metadata']) ? json_encode($data['metadata']) : null,
            ':ip_address'            => $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
            ':created_at'            => $now,
            ':updated_at'            => $now,
        ];

        $sql = "INSERT INTO transaction_audit_trail (
                    transaction_reference, transaction_type, origin,
                    sender_name, sender_account, sender_institution,
                    receiver_name, receiver_account, receiver_institution,
                    amount, currency_code, status,
                    started_at, metadata, ip_address, created_at, updated_at
                ) VALUES (
                    :transaction_reference, :transaction_type, :origin,
                    :sender_name, :sender_account, :sender_institution,
                    :receiver_name, :receiver_account, :receiver_institution,
                    :amount, :currency_code, 'STARTED',
                    :started_at, :metadata, :ip_address, :created_at, :updated_at
                )";

        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
                $stmt = $this->db->prepare($sql . ' RETURNING id');
                $stmt->execute($params);
                return (int) $stmt->fetchColumn();
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            $this->logger->error('Failed to open transaction audit trail: ' . $e->getMessage());
            error_log('[TXN_AUDIT_FALLBACK] START ' . json_encode($data));
            return null;
        }
    }

    /**
     * Closes out an audit trail entry once the transaction finishes,
     * recording the end time and how long it took.
     */
    public function completeTransaction(int $trailId, string $status = 'COMPLETED', ?array $metadata = null): bool
    {
        return $this->closeTransaction($trailId, $status, $metadata);
    }

    /**
     * Convenience wrapper for marking a transaction as failed.
     */
    public function failTransaction(int $trailId, ?string $reason = null): bool
    {
        return $this->closeTransaction($trailId, 'FAILED', $reason !== null ? ['failure_reason' => $reason] : null);
    }

    private function closeTransaction(int $trailId, string $status, ?array $metadata): bool
    {
        if (!$this->checkTableReady()) {
            return false;
        }

        $status = $this->normalizeStatus($status);

        try {
            $stmt = $this->db->prepare('SELECT started_at FROM transaction_audit_trail WHERE id = :id');
            $stmt->execute([':id' => $trailId]);
            $startedAt = $stmt->fetchColumn();

            if ($startedAt === false) {
                $this->logger->warning("No transaction audit trail entry found for id {$trailId}");
                return false;
            }

            $endedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $durationMs = (int) round(
                ((float)$endedAt->format('U.u') - (float)(new DateTimeImmutable($startedAt))->format('U.u')) * 1000
            );

            $stmt = $this->db->prepare('
                UPDATE transaction_audit_trail
                SET status = :status,
                    ended_at = :ended_at,
                    duration_ms = :duration_ms,
                    metadata = COALESCE(:metadata, metadata),
                    updated_at = :updated_at
                WHERE id = :id
            ');

            $stmt->execute([
                ':status'      => $status,
                ':ended_at'    => $endedAt->format('Y-m-d H:i:s.uP'),
                ':duration_ms' => $durationMs,
                ':metadata'    => $metadata !== null ? json_encode($metadata) : null,
                ':updated_at'  => $endedAt->format('Y-m-d H:i:s.uP'),
                ':id'          => $trailId,
            ]);

            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            $this->logger->error("Failed to close transaction audit trail {$trailId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Records a transaction that has already completed, in one call.
     * Useful for backfilling or logging synchronous transfers where
     * begin/complete would otherwise be called back to back.
     */
    public function recordCompletedTransaction(array $data, string $status = 'COMPLETED'): ?int
    {
        $trailId = $this->beginTransaction($data);
        if ($trailId === null) {
            return null;
        }

        $this->completeTransaction($trailId, $status, $data['metadata'] ?? null);
        return $trailId;
    }

    public function getTrail(int $trailId): ?array
    {
        if (!$this->checkTableReady()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM transaction_audit_trail WHERE id = :id');
        $stmt->execute([':id' => $trailId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function getHistoryForReference(string $transactionReference): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        $stmt = $this->db->prepare('
            SELECT * FROM transaction_audit_trail
            WHERE transaction_reference = :ref
            ORDER BY started_at ASC
        ');
        $stmt->execute([':ref' => $transactionReference]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search the audit trail with optional filters:
     * sender_account, receiver_account, transaction_type, status,
     * currency_code, min_amount, max_amount, date_from, date_to.
     */
    public function search(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        if (!$this->checkTableReady()) {
            return [];
        }

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['sender_account'])) {
            $where[] = 'sender_account = :sender_account';
            $params[':sender_account'] = $filters['sender_account'];
        }
        if (!empty($filters['receiver_account'])) {
            $where[] = 'receiver_account = :receiver_account';
            $params[':receiver_account'] = $filters['receiver_account'];
        }
        if (!empty($filters['transaction_type'])) {
            $where[] = 'transaction_type = :transaction_type';
            $params[':transaction_type'] = $filters['transaction_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = $this->normalizeStatus($filters['status']);
        }
        if (!empty($filters['currency_code'])) {
            $where[] = 'currency_code = :currency_code';
            $params[':currency_code'] = strtoupper($filters['currency_code']);
        }
        if (isset($filters['min_amount'])) {
            $where[] = 'amount >= :min_amount';
            $params[':min_amount'] = $filters['min_amount'];
        }
        if (isset($filters['max_amount'])) {
            $where[] = 'amount <= :max_amount';
            $params[':max_amount'] = $filters['max_amount'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'started_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'started_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $sql = 'SELECT * FROM transaction_audit_trail WHERE ' . implode(' AND ', $where)
             . ' ORDER BY started_at DESC LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $this->logger->error('Transaction audit trail search failed: ' . $e->getMessage());
            return [];
        }
    }

    private function normalizeStatus(string $status): string
    {
        $upper = strtoupper($status);
        if (in_array($upper, self::VALID_STATUSES, true)) {
            return $upper;
        }
        $this->logger->warning("Invalid transaction audit status '{$status}' normalized to COMPLETED");
        return 'COMPLETED';
    }

    private function checkTableReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM transaction_audit_trail LIMIT 1');
            return true;
        } catch (Throwable $e) {
            $this->logger->warning('transaction_audit_trail table not ready: ' . $e->getMessage());
            return false;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.uP');
    }
}
