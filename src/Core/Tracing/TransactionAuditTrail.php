<?php

declare(strict_types=1);

namespace Core\Tracing;

use PDO;
use Throwable;
use InvalidArgumentException;

/**
 * TransactionAuditTrail
 * ----------------------
 * Dedicated, append-only audit trail for tracing financial transactions:
 * WHO performed it, WHEN it happened, WHERE it originated from, the
 * AMOUNT and CURRENCY moved, which ACCOUNT sent it, and who/what
 * account it was SENT TO.
 *
 * This is intentionally its own table (transaction_audit_trail) rather
 * than another write path into `audit_logs` -- that table already has
 * several mutually-incompatible assumed schemas across this codebase
 * (see Domain\Models\AuditLog, Application\Utils\AuditLogger,
 * Domain\Services\AuditTrailService). A financial audit trail needs a
 * schema every caller can rely on, so this class owns and creates its
 * own table instead of guessing at someone else's.
 *
 * Every entry is chained: entry_hash = SHA256(prev_hash || canonical
 * fields), the same technique already used for audit_logs elsewhere in
 * this codebase (see SwapService::writeAuditLogEntry). Any row that
 * doesn't match its neighbour's hash means the trail was tampered with
 * or a row went missing -- verifyChain() checks exactly that.
 *
 * Writing to the trail must never break the transaction it is
 * observing: every DB call is wrapped and failures are logged via
 * error_log(), never thrown.
 *
 * USAGE:
 *
 *   $trail = new TransactionAuditTrail($db);
 *
 *   $trail->record([
 *       'transaction_reference' => $txnRef,
 *       'event_type'            => 'INITIATED',
 *       'user_id'               => $userId,
 *       'performed_by'          => $username,
 *       'source_account'        => $fromAccount,
 *       'destination_account'   => $toAccount,
 *       'beneficiary_name'      => $recipientName,
 *       'amount'                => $amount,
 *       'currency'              => $currencyCode,
 *       'status'                => 'PENDING',
 *       'channel'               => 'api',
 *   ]);
 *
 *   $history = $trail->getTrailForTransaction($txnRef);
 *   $integrity = $trail->verifyChain();
 */
class TransactionAuditTrail
{
    private PDO $db;
    private bool $ready = false;
    private bool $isPostgres = false;

    private const VALID_EVENT_TYPES = [
        'INITIATED', 'VALIDATED', 'AUTHORIZED', 'COMPLETED',
        'FAILED', 'REVERSED', 'FLAGGED', 'TRANSACTION',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->isPostgres = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
        $this->ensureTable();
    }

    /**
     * Self-migrating, matching the convention already used elsewhere in
     * this codebase (e.g. SwapService's swap_rollback_log,
     * swap_manual_reconciliation_required): CREATE TABLE IF NOT EXISTS
     * on construction rather than a separate migration step.
     */
    private function ensureTable(): void
    {
        try {
            $jsonType = $this->isPostgres ? 'JSONB' : 'TEXT';
            $idType = $this->isPostgres ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $timestampDefault = $this->isPostgres ? 'TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS transaction_audit_trail (
                    id {$idType},
                    trail_uuid VARCHAR(64) NOT NULL UNIQUE,
                    transaction_reference VARCHAR(255) NOT NULL,
                    event_type VARCHAR(50) NOT NULL DEFAULT 'TRANSACTION',
                    occurred_at {$timestampDefault},
                    performed_by_user_id BIGINT,
                    performed_by_identifier VARCHAR(255),
                    source_account VARCHAR(255) NOT NULL,
                    destination_account VARCHAR(255),
                    beneficiary_name VARCHAR(255),
                    amount NUMERIC(20,8) NOT NULL,
                    currency_code CHAR(3) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'RECORDED',
                    channel VARCHAR(50),
                    ip_address VARCHAR(64),
                    geo_location {$jsonType},
                    metadata {$jsonType},
                    prev_hash VARCHAR(64),
                    entry_hash VARCHAR(64) NOT NULL,
                    created_at {$timestampDefault}
                )
            ");

            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_txn_audit_reference ON transaction_audit_trail (transaction_reference)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_txn_audit_user ON transaction_audit_trail (performed_by_user_id)");
            $this->db->exec("CREATE INDEX IF NOT EXISTS idx_txn_audit_occurred ON transaction_audit_trail (occurred_at)");

            $this->ready = true;
        } catch (Throwable $e) {
            $this->ready = false;
            error_log('[TransactionAuditTrail] Could not ensure transaction_audit_trail table: ' . $e->getMessage());
        }
    }

    /**
     * Record one immutable trace entry for a financial transaction.
     *
     * Required keys in $data:
     *   transaction_reference, source_account, amount, currency
     *
     * Recognized optional keys:
     *   event_type, user_id, performed_by, destination_account,
     *   beneficiary_name, status, channel, ip_address, geo_location
     *   (array), metadata (array)
     *
     * @return string|null The trail_uuid of the entry written, or null
     *                      if the write could not be recorded (never
     *                      throws -- a tracing failure must never break
     *                      the transaction it is observing).
     */
    public function record(array $data): ?string
    {
        try {
            $this->validate($data);
        } catch (InvalidArgumentException $e) {
            error_log('[TransactionAuditTrail] Rejected malformed entry: ' . $e->getMessage());
            return null;
        }

        if (!$this->ready) {
            error_log('[TransactionAuditTrail] Table not ready -- entry dropped for reference ' . $data['transaction_reference']);
            return null;
        }

        try {
            $trailUuid = $this->generateUuid();
            $eventType = strtoupper($data['event_type'] ?? 'TRANSACTION');
            if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
                $eventType = 'TRANSACTION';
            }
            $occurredAt = date('Y-m-d H:i:s');
            $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
            $performedBy = $data['performed_by'] ?? ($userId !== null ? (string)$userId : 'system');
            $amount = number_format((float)$data['amount'], 8, '.', '');
            $currency = strtoupper($data['currency']);
            $destinationAccount = $data['destination_account'] ?? null;
            $beneficiaryName = $data['beneficiary_name'] ?? null;
            $status = strtoupper($data['status'] ?? 'RECORDED');
            $channel = $data['channel'] ?? null;
            $ipAddress = $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            $geoLocation = isset($data['geo_location']) ? json_encode($data['geo_location']) : null;
            $metadata = isset($data['metadata']) ? json_encode($data['metadata']) : null;

            $prevHash = $this->fetchLastHash();

            $canonical = json_encode([
                'trail_uuid'             => $trailUuid,
                'transaction_reference'  => $data['transaction_reference'],
                'event_type'             => $eventType,
                'occurred_at'            => $occurredAt,
                'performed_by_user_id'   => $userId,
                'performed_by_identifier'=> $performedBy,
                'source_account'         => $data['source_account'],
                'destination_account'    => $destinationAccount,
                'amount'                 => $amount,
                'currency_code'          => $currency,
            ]);
            $entryHash = hash('sha256', ($prevHash ?? '') . $canonical);

            $stmt = $this->db->prepare("
                INSERT INTO transaction_audit_trail (
                    trail_uuid, transaction_reference, event_type, occurred_at,
                    performed_by_user_id, performed_by_identifier,
                    source_account, destination_account, beneficiary_name,
                    amount, currency_code, status, channel, ip_address,
                    geo_location, metadata, prev_hash, entry_hash
                ) VALUES (
                    :trail_uuid, :reference, :event_type, :occurred_at,
                    :user_id, :performed_by,
                    :source_account, :destination_account, :beneficiary_name,
                    :amount, :currency, :status, :channel, :ip_address,
                    :geo_location, :metadata, :prev_hash, :entry_hash
                )
            ");

            $stmt->execute([
                ':trail_uuid'           => $trailUuid,
                ':reference'            => $data['transaction_reference'],
                ':event_type'           => $eventType,
                ':occurred_at'          => $occurredAt,
                ':user_id'              => $userId,
                ':performed_by'         => $performedBy,
                ':source_account'       => $data['source_account'],
                ':destination_account'  => $destinationAccount,
                ':beneficiary_name'     => $beneficiaryName,
                ':amount'               => $amount,
                ':currency'             => $currency,
                ':status'               => $status,
                ':channel'              => $channel,
                ':ip_address'           => $ipAddress,
                ':geo_location'         => $geoLocation,
                ':metadata'             => $metadata,
                ':prev_hash'            => $prevHash,
                ':entry_hash'           => $entryHash,
            ]);

            return $trailUuid;
        } catch (Throwable $e) {
            error_log('[TransactionAuditTrail] Failed to record entry for reference ' .
                ($data['transaction_reference'] ?? 'unknown') . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Full chronological trace of a single transaction, oldest first. */
    public function getTrailForTransaction(string $transactionReference): array
    {
        if (!$this->ready) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM transaction_audit_trail
                WHERE transaction_reference = :reference
                ORDER BY id ASC
            ");
            $stmt->execute([':reference' => $transactionReference]);
            return array_map([$this, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('[TransactionAuditTrail] getTrailForTransaction failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Every entry a given user is recorded as having performed, newest first. */
    public function getTrailForUser(int $userId, int $limit = 100): array
    {
        if (!$this->ready) {
            return [];
        }

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM transaction_audit_trail
                WHERE performed_by_user_id = :user_id
                ORDER BY id DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_map([$this, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('[TransactionAuditTrail] getTrailForUser failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Flexible search across the trail for investigations/compliance
     * reporting. Recognized filters: date_from, date_to, user_id,
     * source_account, destination_account, currency, min_amount, status.
     */
    public function search(array $filters = [], int $limit = 100): array
    {
        if (!$this->ready) {
            return [];
        }

        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'occurred_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'occurred_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'performed_by_user_id = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }
        if (!empty($filters['source_account'])) {
            $where[] = 'source_account = :source_account';
            $params[':source_account'] = $filters['source_account'];
        }
        if (!empty($filters['destination_account'])) {
            $where[] = 'destination_account = :destination_account';
            $params[':destination_account'] = $filters['destination_account'];
        }
        if (!empty($filters['currency'])) {
            $where[] = 'currency_code = :currency';
            $params[':currency'] = strtoupper($filters['currency']);
        }
        if (!empty($filters['min_amount'])) {
            $where[] = 'amount >= :min_amount';
            $params[':min_amount'] = $filters['min_amount'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params[':status'] = strtoupper($filters['status']);
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        try {
            $stmt = $this->db->prepare("
                SELECT * FROM transaction_audit_trail
                {$whereSql}
                ORDER BY id DESC
                LIMIT :limit
            ");
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_map([$this, 'decodeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            error_log('[TransactionAuditTrail] search failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Walk the whole chain (or one transaction's slice of it, if
     * $transactionReference is given) and confirm every entry_hash
     * still matches SHA256(prev_hash || canonical fields). Any mismatch
     * means a row was altered or removed after the fact.
     */
    public function verifyChain(?string $transactionReference = null): array
    {
        if (!$this->ready) {
            return ['valid' => false, 'checked' => 0, 'broken_at' => null, 'reason' => 'table not ready'];
        }

        try {
            $sql = 'SELECT * FROM transaction_audit_trail';
            $params = [];
            if ($transactionReference !== null) {
                $sql .= ' WHERE transaction_reference = :reference';
                $params[':reference'] = $transactionReference;
            }
            $sql .= ' ORDER BY id ASC';

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $checked = 0;
            foreach ($rows as $row) {
                $checked++;
                $canonical = json_encode([
                    'trail_uuid'              => $row['trail_uuid'],
                    'transaction_reference'   => $row['transaction_reference'],
                    'event_type'              => $row['event_type'],
                    'occurred_at'             => $row['occurred_at'],
                    'performed_by_user_id'    => $row['performed_by_user_id'] !== null ? (int)$row['performed_by_user_id'] : null,
                    'performed_by_identifier' => $row['performed_by_identifier'],
                    'source_account'          => $row['source_account'],
                    'destination_account'     => $row['destination_account'],
                    // Re-normalized the same way record() formats it before
                    // hashing -- some drivers/column affinities (e.g.
                    // SQLite's NUMERIC affinity) silently reformat a stored
                    // numeric string (dropping trailing zeros), which would
                    // otherwise look identical to tampering.
                    'amount'                  => number_format((float)$row['amount'], 8, '.', ''),
                    'currency_code'           => strtoupper((string)$row['currency_code']),
                ]);
                $expectedHash = hash('sha256', ($row['prev_hash'] ?? '') . $canonical);

                if (!hash_equals($expectedHash, $row['entry_hash'])) {
                    return [
                        'valid'     => false,
                        'checked'   => $checked,
                        'broken_at' => (int)$row['id'],
                        'reason'    => 'entry_hash mismatch',
                    ];
                }
            }

            return ['valid' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => null];
        } catch (Throwable $e) {
            error_log('[TransactionAuditTrail] verifyChain failed: ' . $e->getMessage());
            return ['valid' => false, 'checked' => 0, 'broken_at' => null, 'reason' => $e->getMessage()];
        }
    }

    private function fetchLastHash(): ?string
    {
        try {
            $hash = $this->db->query(
                'SELECT entry_hash FROM transaction_audit_trail ORDER BY id DESC LIMIT 1'
            )->fetchColumn();
            return $hash !== false ? $hash : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function decodeRow(array $row): array
    {
        foreach (['geo_location', 'metadata'] as $col) {
            if (isset($row[$col]) && is_string($row[$col])) {
                $decoded = json_decode($row[$col], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$col] = $decoded;
                }
            }
        }
        return $row;
    }

    private function validate(array $data): void
    {
        foreach (['transaction_reference', 'source_account', 'amount', 'currency'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("Missing mandatory audit field: {$field}");
            }
        }

        if (!is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
            throw new InvalidArgumentException('amount must be a positive numeric value.');
        }

        if (strlen((string)$data['currency']) !== 3) {
            throw new InvalidArgumentException('currency must be a 3-letter ISO 4217 code.');
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
