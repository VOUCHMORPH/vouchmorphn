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
    // Kept short because this runs synchronously inside a user-facing claim
    // request (claim_identity.php et al) — falling back to the pooled
    // holding account for THIS claim is always safe, so there's no reason
    // to tie up a web worker waiting long for the common case (the winner
    // of the race calls the bank immediately, so it usually resolves in
    // well under a second).
    private const DEFAULT_CREATE_POLL_ATTEMPTS = 3;
    private const DEFAULT_CREATE_POLL_DELAY_SECONDS = 1;

    // A 'pending' row whose creating process never even got a response
    // from the bank (response_payload still NULL) after this long is
    // presumed orphaned by a crash between the INSERT and the HTTP call
    // (OOM kill, deploy restart, fatal error) rather than a legitimately
    // slow/async bank -- a genuine async-pending row always has
    // response_payload set the moment the bank acknowledges the request,
    // so it's never touched by this reclaim regardless of age. Set well
    // above any plausible worst-case synchronous call duration (configured
    // per-institution timeout_ms is 5-10s, retry_policy adds at most a
    // couple of retries on top of that) so a reclaim can never race a
    // genuinely still-alive, still-working process.
    private const STALE_PENDING_SECONDS = 600;

    // A position stuck in 'sweeping' (claimed but never finished -- the
    // worker died mid-flight) this long is presumed orphaned, for the same
    // reason and with the same safety margin as STALE_PENDING_SECONDS above.
    private const STALE_SWEEPING_SECONDS = 600;

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
                $reclaimed = $this->reclaimRow((int)$existing['id'], self::STATUS_FAILED, null);
                if ($reclaimed) {
                    return $this->createAtBank((int)$reclaimed['id'], $ownerUserId, $institution, $currency, $reclaimed['bank_reference']);
                }
                // Someone else reclaimed it between our read and write — keep polling.
            } elseif ($existing['status'] === self::STATUS_PENDING
                && empty($existing['response_payload'])
                && $this->isStale($existing['requested_at'])
            ) {
                // Bank was never actually reached (no response_payload) and
                // this has sat 'pending' too long — the process that won
                // the create race almost certainly died before or during
                // the HTTP call. A genuine async-pending row always has
                // response_payload set, so this never fires for one.
                $reclaimed = $this->reclaimRow((int)$existing['id'], self::STATUS_PENDING, $existing['requested_at']);
                if ($reclaimed) {
                    $this->log('warning', 'ReservationAccountService: reclaiming orphaned pending reservation account', [
                        'id' => $existing['id'], 'institution' => $institution, 'user_id' => $ownerUserId,
                    ]);
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

    /**
     * Compare-and-swap reclaim: resets a row back to 'pending' (so the
     * caller can retry createAtBank against it) only if it's still in
     * exactly the state we last observed. $expectedRequestedAt is required
     * for a 'pending' -> 'pending' reclaim (staleness path) since the
     * status alone doesn't change and so can't serialize concurrent
     * reclaimers on its own — two callers racing to reclaim the same
     * stale row would otherwise BOTH succeed and BOTH call the bank. Not
     * needed for a 'failed' -> 'pending' reclaim, where the status
     * transition itself is already a one-winner compare-and-swap.
     */
    private function reclaimRow(int $id, string $expectedStatus, ?string $expectedRequestedAt): ?array
    {
        if ($expectedRequestedAt !== null) {
            $stmt = $this->db->prepare("
                UPDATE reservation_accounts SET status = 'pending', requested_at = now(), updated_at = now()
                WHERE id = :id AND status = :status AND requested_at = :requested_at
                RETURNING id, bank_reference
            ");
            $stmt->execute([':id' => $id, ':status' => $expectedStatus, ':requested_at' => $expectedRequestedAt]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE reservation_accounts SET status = 'pending', requested_at = now(), updated_at = now()
                WHERE id = :id AND status = :status
                RETURNING id, bank_reference
            ");
            $stmt->execute([':id' => $id, ':status' => $expectedStatus]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isStale(?string $requestedAt): bool
    {
        return $this->isOlderThan($requestedAt, self::STALE_PENDING_SECONDS);
    }

    private function isOlderThan(?string $timestamp, int $thresholdSeconds): bool
    {
        if ($timestamp === null || $timestamp === '') {
            return true;
        }
        $epoch = strtotime($timestamp);
        if ($epoch === false) {
            return true;
        }
        return (time() - $epoch) > $thresholdSeconds;
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
     * Sweeps every open (or previously-failed) identity_holding_positions
     * row belonging to this reservation account's (user, institution,
     * currency) into the account, releasing each pooled hold first.
     *
     * Safe to call repeatedly, including concurrently with itself (the
     * reservation_account_confirmed callback and the cron safety net can
     * both reach this for the same account): each position is atomically
     * claimed (status -> 'sweeping') before any bank call is made, so only
     * one caller ever actually processes a given position — a second
     * caller finds nothing left to claim and just skips it.
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
            WHERE owner_user_id = :u AND institution = :i AND currency = :c AND status IN ('open', 'sweep_failed')
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
            if (!$this->claimPositionForSweep((int)$position['id'])) {
                // Another concurrent sweep (callback vs. cron) already
                // claimed this exact position — not this run's to count.
                continue;
            }

            try {
                $this->sweepPosition($position, $account);
                $swept++;
            } catch (Throwable $e) {
                $this->log('error', 'ReservationAccountService: sweep failed for position', [
                    'position_id' => $position['id'], 'error' => $e->getMessage(),
                ]);
                $this->db->prepare("UPDATE identity_holding_positions SET status = 'sweep_failed' WHERE id = :id")
                    ->execute([':id' => $position['id']]);
                $failed++;
            }
        }

        return ['swept' => $swept, 'failed' => $failed];
    }

    /**
     * Atomically transitions one position from open/sweep_failed into
     * 'sweeping' so exactly one concurrent caller wins the right to
     * process it. Returns false if another caller already claimed it
     * (or it was already swept) between the SELECT and this UPDATE.
     */
    private function claimPositionForSweep(int $positionId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE identity_holding_positions SET status = 'sweeping', sweep_claimed_at = now()
            WHERE id = :id AND status IN ('open', 'sweep_failed')
        ");
        $stmt->execute([':id' => $positionId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Recovers positions stuck in 'sweeping' because the worker that
     * claimed them died before finishing (crash, OOM, deploy restart) --
     * without this, such a position is invisible to every other query
     * (both the sweep candidate query and the cron discovery query only
     * look at 'open'/'sweep_failed') and would otherwise never be retried.
     * Resets each one to 'sweep_failed' via a compare-and-swap on
     * sweep_claimed_at, so this can never race a worker that's still
     * genuinely alive and about to finish. Intended to be called
     * periodically by the cron safety net.
     */
    public function reclaimStaleSweepingPositions(int $limit = 50): int
    {
        $stmt = $this->db->prepare("
            SELECT id, sweep_claimed_at FROM identity_holding_positions
            WHERE status = 'sweeping'
            ORDER BY sweep_claimed_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reclaimed = 0;
        foreach ($candidates as $candidate) {
            if (!$this->isOlderThan($candidate['sweep_claimed_at'], self::STALE_SWEEPING_SECONDS)) {
                continue;
            }

            $reclaimStmt = $this->db->prepare("
                UPDATE identity_holding_positions SET status = 'sweep_failed'
                WHERE id = :id AND status = 'sweeping' AND sweep_claimed_at = :claimed_at
            ");
            $reclaimStmt->execute([':id' => $candidate['id'], ':claimed_at' => $candidate['sweep_claimed_at']]);
            if ($reclaimStmt->rowCount() > 0) {
                $reclaimed++;
                $this->log('warning', 'ReservationAccountService: reclaimed a position stuck in sweeping', [
                    'position_id' => $candidate['id'],
                ]);
            }
        }

        return $reclaimed;
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
            WHERE ihp.status IN ('open', 'sweep_failed') AND ra.status = 'active'
            ORDER BY ra.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    /**
     * Releases the pooled hold (if not already released by a prior failed
     * attempt — see hold_released_at) then deposits into the reservation
     * account. Split into two DB-committed steps rather than one so a
     * failure between them (deposit fails after release already
     * succeeded) doesn't cause a retry to re-release an already-released
     * hold_reference, which most bank APIs reject.
     */
    private function sweepPosition(array $position, array $account): void
    {
        $institution = $position['institution'];
        $adapter = $this->adapterFactory->getAdapter($institution);

        if (!empty($position['hold_reference']) && empty($position['hold_released_at'])) {
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

            $this->db->prepare("UPDATE identity_holding_positions SET hold_released_at = now() WHERE id = :id")
                ->execute([':id' => $position['id']]);
        }

        // Deterministic (no random suffix) so a retry after an
        // ambiguous/failed attempt reuses the exact same reference,
        // matching the same idempotent-retry pattern createAtBank() uses
        // for bank_reference — a bank that dedupes on reference then
        // recognizes this as the same request rather than a new deposit.
        $this->depositToReservationAccount(
            $institution,
            $position['currency'],
            (float)$position['amount'],
            $account['account_identifier'],
            $account['account_identifier_type'] ?? 'account_number',
            'SWEEP_' . $position['id']
        );

        $updateStmt = $this->db->prepare("
            UPDATE identity_holding_positions
            SET status = 'swept', swept_to_reservation_account_id = :acc_id, swept_at = now()
            WHERE id = :id
        ");
        $updateStmt->execute([':acc_id' => $account['id'], ':id' => $position['id']]);
    }
}
