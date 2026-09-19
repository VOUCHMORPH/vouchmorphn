<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use RuntimeException;

/**
 * Finds and closes out identity-swap holds that were debited at the source
 * bank but whose claim never completed.
 *
 * WHY THESE EXIST
 * ---------------
 * Claims used to debit each hold at the source BEFORE paying the
 * beneficiary. When delivery then failed, the debited amount was credited
 * straight back to the customer -- but nothing marked the hold itself
 * finished, so it stayed 'pending' and kept being offered as claimable.
 * Retrying could not work: the source bank's hold was already spent, so the
 * bank reported the reference as unknown (ZURUBANK words this "Voucher not
 * found for hold reference: <ref>") rather than anything about the real
 * problem.
 *
 * Claims no longer work that way -- the beneficiary is paid first and the
 * hold is only debited once that succeeds -- so this exists for holds
 * stranded by the old order, not for anything new.
 *
 * Shared deliberately by the CLI script and the browser page, so the SQL
 * that decides which holds to close exists exactly once. Two copies of
 * money-touching logic drift, and this codebase has been bitten by that.
 */
final class StuckIdentityClaimReconciler
{
    public const DEFAULT_MIN_AGE_MINUTES = 60;

    public function __construct(private PDO $db)
    {
    }

    /**
     * Holds that were debited at the bank but never closed out locally.
     *
     * Split into two groups, because they need opposite treatment:
     *   'cancellable' -- no unresolved manual-reconciliation record, so the
     *                    compensating credit is believed to have landed and
     *                    the hold is safe to close.
     *   'needs_human' -- an unresolved record exists, meaning the
     *                    compensation ALSO failed. The customer may still be
     *                    out of pocket, so these are reported and never
     *                    touched: closing one would bury a case where real
     *                    money is missing.
     *
     * @return array{cancellable: array<int, array<string, mixed>>, needs_human: array<int, array<string, mixed>>}
     */
    public function find(int $minAgeMinutes = self::DEFAULT_MIN_AGE_MINUTES, ?int $onlyHoldId = null): array
    {
        if ($minAgeMinutes < 1) {
            throw new RuntimeException('Minimum age must be at least 1 minute — a claim in flight must not be touched.');
        }

        $sql = "
            SELECT ish.hold_id,
                   ish.swap_reference,
                   ish.hold_reference,
                   ish.source_institution,
                   ish.amount,
                   ish.currency,
                   ish.identity_type,
                   ish.created_at,
                   ht.status    AS bank_hold_status,
                   ht.debited_at,
                   recon.id     AS reconciliation_id,
                   recon.reason AS reconciliation_reason
            FROM identity_swap_holds ish
            JOIN hold_transactions ht ON ht.hold_id = ish.hold_id
            LEFT JOIN swap_manual_reconciliation_required recon
                   ON recon.swap_reference = ish.swap_reference
                  AND recon.resolved_at IS NULL
            WHERE ish.status = 'pending'
              AND ht.status = 'DEBITED'
              AND ht.debited_at IS NOT NULL
              AND ht.debited_at < NOW() - (:min_age || ' minutes')::interval
        ";

        $params = [':min_age' => (string)$minAgeMinutes];
        if ($onlyHoldId !== null) {
            $sql .= " AND ish.hold_id = :hold_id";
            $params[':hold_id'] = $onlyHoldId;
        }
        $sql .= " ORDER BY ht.debited_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = ['cancellable' => [], 'needs_human' => []];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[$row['reconciliation_id'] !== null ? 'needs_human' : 'cancellable'][] = $row;
        }

        return $out;
    }

    /**
     * Closes out the given holds. The UPDATE re-checks status = 'pending',
     * so a hold claimed between find() and here is left alone rather than
     * cancelled out from under a claim that succeeded.
     *
     * @param array<int, array<string, mixed>> $rows from find()['cancellable']
     * @return array{cancelled: array<int, int>, skipped: array<int, int>}
     */
    public function cancel(array $rows): array
    {
        $update = $this->db->prepare("
            UPDATE identity_swap_holds
            SET status = 'cancelled',
                metadata = COALESCE(metadata, '{}'::jsonb) || :meta::jsonb
            WHERE hold_id = :hold_id
              AND status = 'pending'
        ");

        $result = ['cancelled' => [], 'skipped' => []];

        foreach ($rows as $row) {
            $holdId = (int)$row['hold_id'];
            $update->execute([
                ':meta' => json_encode([
                    'compensated' => true,
                    'reason' => 'Payout failed after debit; amount credited back to source. Closed out by StuckIdentityClaimReconciler',
                    'reconciled_at' => date('c'),
                ]),
                ':hold_id' => $holdId,
            ]);

            $result[$update->rowCount() === 1 ? 'cancelled' : 'skipped'][] = $holdId;
        }

        return $result;
    }
}
