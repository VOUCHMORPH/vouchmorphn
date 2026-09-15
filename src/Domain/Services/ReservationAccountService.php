<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use RuntimeException;
use Throwable;
use Infrastructure\Adapters\InstitutionAdapterFactory;

/**
 * Resolves a beneficiary (user_id) + institution + currency to a single,
 * dedicated, bank-controlled reservation account, creating one at the bank
 * on first use. Used by SwapService to hold the unclaimed remainder of an
 * identity swap in a real account instead of the shared pooled
 * identity_accounts.holding_identifier account.
 *
 * Keying by user_id rather than the identity value that triggered a claim
 * is the whole point: a person with several claimable identities (phone,
 * email, national_id, ...) must land on the SAME reservation account per
 * institution no matter which identity brought the money in — see the
 * UNIQUE(user_id, institution, currency) constraint on reservation_accounts.
 */
class ReservationAccountService
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_FAILED = 'failed';

    // Bounded wait for a concurrent creation already in flight to resolve,
    // rather than holding a DB transaction open across the bank's HTTP call.
    private const DEFAULT_CREATE_POLL_ATTEMPTS = 5;
    private const DEFAULT_CREATE_POLL_DELAY_SECONDS = 2;

    private PDO $db;
    private array $participants;
    private InstitutionAdapterFactory $adapterFactory;
    private $logger;
    private int $createPollAttempts;
    private int $createPollDelaySeconds;

    // Postgres needs an explicit ::jsonb cast when binding a text parameter
    // into a jsonb column (same convention already used elsewhere in
    // SwapService, e.g. placeHoldOnHoldingRemainder's :source_holds::jsonb).
    // Kept driver-conditional (rather than hardcoded) so this class also
    // runs against the sqlite PDO driver used by ReservationAccountServiceTest,
    // which has no jsonb type and no cast syntax for it.
    private string $jsonCast;

    public function __construct(
        PDO $db,
        array $participants,
        InstitutionAdapterFactory $adapterFactory,
        $logger = null,
        ?int $createPollAttempts = null,
        ?int $createPollDelaySeconds = null
    ) {
        $this->db = $db;
        $this->participants = $participants;
        $this->adapterFactory = $adapterFactory;
        $this->logger = $logger;
        $this->jsonCast = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '::jsonb' : '';
        $this->createPollAttempts = $createPollAttempts ?? self::DEFAULT_CREATE_POLL_ATTEMPTS;
        $this->createPollDelaySeconds = $createPollDelaySeconds ?? self::DEFAULT_CREATE_POLL_DELAY_SECONDS;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message, $context);
        }
    }

    public function isSupported(string $institution): bool
    {
        $participant = $this->participants[$institution] ?? $this->participants[strtoupper($institution)] ?? null;
        return (bool)($participant['capabilities']['reservation_accounts'] ?? false);
    }

    /**
     * Get-or-create a reservation account for this (user, institution, currency).
     *
     * Returns:
     *   ['supported' => false]                                                     — institution not onboarded, caller falls back to pooled holding
     *   ['supported' => true, 'status' => 'active', 'account_identifier' => ..., 'account_identifier_type' => ..., 'id' => ...]
     *   ['supported' => true, 'status' => 'pending']                               — creation in flight/async, caller falls back to pooled holding for THIS claim
     *   ['supported' => true, 'status' => 'failed']                                — bank rejected/errored, caller falls back to pooled holding
     */
    public function resolveOrCreateReservationAccount(int $ownerUserId, string $institution, string $currency): array
    {
        if (!$this->isSupported($institution)) {
            return ['supported' => false];
        }

        $existing = $this->findAccount($ownerUserId, $institution, $currency);
        if ($existing && $existing['status'] === self::STATUS_ACTIVE) {
            return $this->toResult($existing);
        }

        $bankReference = 'RESACC_' . $ownerUserId . '_' . $institution . '_' . $currency . '_' . bin2hex(random_bytes(4));

        $stmt = $this->db->prepare("
            INSERT INTO reservation_accounts (user_id, institution, currency, status, bank_reference, requested_at)
            VALUES (:u, :i, :c, 'pending', :ref, now())
            ON CONFLICT (user_id, institution, currency) DO NOTHING
            RETURNING id
        ");
        $stmt->execute([':u' => $ownerUserId, ':i' => $institution, ':c' => $currency, ':ref' => $bankReference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // We won the race to create it.
            return $this->createAtBank((int)$row['id'], $ownerUserId, $institution, $currency, $bankReference);
        }

        // Someone else is already creating one (or it exists in a non-active
        // state) — wait for it, or reclaim it if it previously failed.
        return $this->waitOrReclaim($ownerUserId, $institution, $currency);
    }

    private function waitOrReclaim(int $ownerUserId, string $institution, string $currency): array
    {
        for ($attempt = 0; $attempt < $this->createPollAttempts; $attempt++) {
            $existing = $this->findAccount($ownerUserId, $institution, $currency);

            if ($existing === null) {
                break;
            }

            if ($existing['status'] === self::STATUS_ACTIVE) {
                return $this->toResult($existing);
            }

            if ($existing['status'] === self::STATUS_FAILED) {
                $reclaimStmt = $this->db->prepare("
                    UPDATE reservation_accounts SET status = 'pending', requested_at = now(), updated_at = now()
                    WHERE id = :id AND status = 'failed'
                    RETURNING id, bank_reference
                ");
                $reclaimStmt->execute([':id' => $existing['id']]);
                $reclaimed = $reclaimStmt->fetch(PDO::FETCH_ASSOC);
                if ($reclaimed) {
                    return $this->createAtBank((int)$reclaimed['id'], $ownerUserId, $institution, $currency, $reclaimed['bank_reference']);
                }
                // Someone else reclaimed it between our read and write — keep polling.
            }

            if ($attempt < $this->createPollAttempts - 1) {
                usleep($this->createPollDelaySeconds * 1_000_000);
            }
        }

        // Gave up waiting for the concurrent creation to resolve — report
        // whatever state it's currently in so the caller can fall back.
        $existing = $this->findAccount($ownerUserId, $institution, $currency);
        return $existing ? $this->toResult($existing) : ['supported' => true, 'status' => self::STATUS_PENDING];
    }

    private function createAtBank(int $id, int $ownerUserId, string $institution, string $currency, string $bankReference): array
    {
        try {
            $adapter = $this->adapterFactory->getAdapter($institution);
            $result = $adapter->createReservationAccount([
                'action' => 'CREATE_RESERVATION_ACCOUNT',
                'reference' => $bankReference,
                'bank_reference' => $bankReference,
                'user_id' => $ownerUserId,
                'currency' => $currency,
                'timestamp' => time(),
            ], [
                'institution' => $institution,
                'bank_reference' => $bankReference,
            ]);
        } catch (Throwable $e) {
            $this->log('error', 'ReservationAccountService: bank call failed', [
                'institution' => $institution, 'user_id' => $ownerUserId, 'error' => $e->getMessage(),
            ]);
            $this->markFailed($id, ['error' => $e->getMessage()]);
            return ['supported' => true, 'status' => self::STATUS_FAILED];
        }

        if (!($result['success'] ?? false)) {
            $this->log('warning', 'ReservationAccountService: bank declined reservation account', [
                'institution' => $institution, 'user_id' => $ownerUserId, 'message' => $result['message'] ?? null,
            ]);
            $this->markFailed($id, $result);
            return ['supported' => true, 'status' => self::STATUS_FAILED];
        }

        $accountIdentifier = $result['account_identifier'] ?? null;
        $status = $result['status'] ?? ($accountIdentifier ? self::STATUS_ACTIVE : self::STATUS_PENDING);

        if ($status === self::STATUS_ACTIVE && !empty($accountIdentifier)) {
            $accountIdentifierType = $result['account_identifier_type'] ?? 'account_number';
            $this->markActive($id, $accountIdentifier, $accountIdentifierType, $result);
            return [
                'supported' => true,
                'status' => self::STATUS_ACTIVE,
                'account_identifier' => $accountIdentifier,
                'account_identifier_type' => $accountIdentifierType,
                'id' => $id,
            ];
        }

        // Accepted but async — leave pending, keep the response for the record.
        $this->storeResponsePayload($id, $result);
        return ['supported' => true, 'status' => self::STATUS_PENDING, 'id' => $id];
    }

    /**
     * Called by the reservation_account_confirmed webhook handler once the
     * bank confirms an account that was created asynchronously. Returns the
     * reservation_accounts.id that was activated, or null if bank_reference
     * didn't match any row.
     */
    public function confirmActivation(
        string $bankReference,
        string $accountIdentifier,
        string $accountIdentifierType = 'account_number',
        array $rawPayload = []
    ): ?int {
        $stmt = $this->db->prepare("
            UPDATE reservation_accounts
            SET status = 'active', account_identifier = :ident, account_identifier_type = :type,
                response_payload = :resp{$this->jsonCast}, activated_at = now(), updated_at = now()
            WHERE bank_reference = :ref
            RETURNING id
        ");
        $stmt->execute([
            ':ident' => $accountIdentifier,
            ':type' => $accountIdentifierType,
            ':resp' => json_encode($rawPayload),
            ':ref' => $bankReference,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    private function findAccount(int $ownerUserId, string $institution, string $currency): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM reservation_accounts WHERE user_id = :u AND institution = :i AND currency = :c
        ");
        $stmt->execute([':u' => $ownerUserId, ':i' => $institution, ':c' => $currency]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function toResult(array $row): array
    {
        return [
            'supported' => true,
            'status' => $row['status'],
            'account_identifier' => $row['account_identifier'],
            'account_identifier_type' => $row['account_identifier_type'] ?? 'account_number',
            'id' => (int)$row['id'],
        ];
    }

    private function markActive(int $id, string $accountIdentifier, string $accountIdentifierType, array $response): void
    {
        $stmt = $this->db->prepare("
            UPDATE reservation_accounts
            SET status = 'active', account_identifier = :ident, account_identifier_type = :type,
                response_payload = :resp{$this->jsonCast}, activated_at = now(), updated_at = now()
            WHERE id = :id
        ");
        $stmt->execute([
            ':ident' => $accountIdentifier,
            ':type' => $accountIdentifierType,
            ':resp' => json_encode($response),
            ':id' => $id,
        ]);
    }

    private function markFailed(int $id, array $response): void
    {
        $stmt = $this->db->prepare("
            UPDATE reservation_accounts SET status = 'failed', response_payload = :resp{$this->jsonCast}, updated_at = now()
            WHERE id = :id
        ");
        $stmt->execute([':resp' => json_encode($response), ':id' => $id]);
    }

    private function storeResponsePayload(int $id, array $response): void
    {
        $stmt = $this->db->prepare("
            UPDATE reservation_accounts SET response_payload = :resp{$this->jsonCast}, updated_at = now() WHERE id = :id
        ");
        $stmt->execute([':resp' => json_encode($response), ':id' => $id]);
    }

    // ============================================================
    // MONEY MOVEMENT — shared by the direct claim-time payout and the
    // sweep of a previously pooled remainder (see sweepOpenPositionsFor()).
    // ============================================================

    /**
     * Credits amount into a beneficiary's reservation account. Mirrors
     * SwapService::payHoldingToMerchant() but the destination is always the
     * beneficiary's own bank-controlled reservation account, never an
     * externally-supplied identifier.
     */
    public function depositToReservationAccount(
        string $institution,
        string $currency,
        float $amount,
        string $accountIdentifier,
        string $accountIdentifierType,
        string $reference
    ): array {
        $payload = [
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'destination_identifier' => $accountIdentifier,
            'destination_identifier_type' => $accountIdentifierType,
            'destination_asset_type' => 'ACCOUNT',
            'account_number' => $accountIdentifier,
            'destination_account' => $accountIdentifier,
            'to_institution' => $institution,
            'destination_institution' => $institution,
            'from_institution' => $institution,
            'source_institution' => $institution,
            'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
            'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        ];

        $adapter = $this->adapterFactory->getAdapter($institution);
        $result = $adapter->credit($payload, [
            'destination_institution' => $institution,
            'destination_identifier' => $accountIdentifier,
            'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
        ]);

        if (!($result['credited'] ?? false)) {
            throw new RuntimeException(
                "Failed to deposit into reservation account {$accountIdentifier} at {$institution}: " . ($result['message'] ?? 'Unknown error')
            );
        }

        return [
            'success' => true,
            'transaction_reference' => $result['transaction_reference'] ?? null,
        ];
    }

    /**
     * Sweeps every open identity_holding_positions row belonging to this
     * reservation account's (user, institution, currency) into the account,
     * releasing each pooled hold first. Safe to call repeatedly — only
     * status='open' rows are picked up, and each swept row flips to
     * 'swept' before the next candidate is considered.
     */
    public function sweepOpenPositionsFor(int $reservationAccountId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM reservation_accounts WHERE id = :id AND status = 'active'");
        $stmt->execute([':id' => $reservationAccountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            return ['swept' => 0, 'failed' => 0];
        }

        $posStmt = $this->db->prepare("
            SELECT * FROM identity_holding_positions
            WHERE owner_user_id = :u AND institution = :i AND currency = :c AND status = 'open'
            ORDER BY created_at ASC
            LIMIT 50
        ");
        $posStmt->execute([
            ':u' => $account['user_id'],
            ':i' => $account['institution'],
            ':c' => $account['currency'],
        ]);
        $positions = $posStmt->fetchAll(PDO::FETCH_ASSOC);

        $swept = 0;
        $failed = 0;
        foreach ($positions as $position) {
            try {
                $this->sweepPosition($position, $account);
                $swept++;
            } catch (Throwable $e) {
                $this->log('error', 'ReservationAccountService: sweep failed for position', [
                    'position_id' => $position['id'], 'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }

        return ['swept' => $swept, 'failed' => $failed];
    }

    /**
     * Reservation accounts that are active and have at least one open
     * pooled position still waiting to be swept in — used by the cron
     * safety net (src/cron/sweep_reservation_account_remainders.php) to
     * find work without needing per-row bookkeeping of its own.
     */
    public function findActiveAccountIdsWithOpenPositions(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ra.id
            FROM identity_holding_positions ihp
            JOIN reservation_accounts ra
              ON ra.user_id = ihp.owner_user_id
             AND ra.institution = ihp.institution
             AND ra.currency = ihp.currency
            WHERE ihp.status = 'open' AND ra.status = 'active'
            ORDER BY ra.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    private function sweepPosition(array $position, array $account): void
    {
        $institution = $position['institution'];
        $adapter = $this->adapterFactory->getAdapter($institution);

        if (!empty($position['hold_reference'])) {
            $releaseResult = $adapter->releaseHold([
                'hold_reference' => $position['hold_reference'],
                'reason' => 'Swept into reservation account ' . $account['account_identifier'],
            ], [
                'institution' => $institution,
                'source_type' => 'IDENTITY_HOLDING_ACCOUNT',
            ]);

            if (!($releaseResult['released'] ?? false)) {
                throw new RuntimeException(
                    "Failed to release pooled hold for position {$position['id']}: " . ($releaseResult['message'] ?? 'Unknown error')
                );
            }
        }

        $this->depositToReservationAccount(
            $institution,
            $position['currency'],
            (float)$position['amount'],
            $account['account_identifier'],
            $account['account_identifier_type'] ?? 'account_number',
            'SWEEP_' . $position['id'] . '_' . bin2hex(random_bytes(4))
        );

        $updateStmt = $this->db->prepare("
            UPDATE identity_holding_positions
            SET status = 'swept', swept_to_reservation_account_id = :acc_id, swept_at = now()
            WHERE id = :id
        ");
        $updateStmt->execute([':acc_id' => $account['id'], ':id' => $position['id']]);
    }
}
