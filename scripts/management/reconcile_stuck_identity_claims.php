<?php
/**
 * Finds identity-swap holds that were debited at the source bank but whose
 * claim never completed, and closes them out.
 *
 * WHY THESE EXIST
 * ---------------
 * A claim debits each hold at the source bank, then delivers the money to the
 * beneficiary. When delivery failed, the debited amount was credited straight
 * back to the customer -- but nothing marked the hold itself finished, so it
 * stayed 'pending' and kept being offered as claimable. Retrying it can't
 * work: the source bank's hold is already spent, so the bank reports the hold
 * reference as unknown (ZURUBANK words this as "Voucher not found for hold
 * reference: <ref>") rather than anything about the real problem.
 *
 * Claims no longer work this way: the beneficiary is now paid at the
 * destination FIRST and the hold is only debited once that succeeds, so a
 * failed claim leaves the hold untouched and nothing to clean up. This
 * exists for holds stranded by the old debit-first order.
 *
 * WHAT IT WILL AND WON'T TOUCH
 * ----------------------------
 * Only holds where ALL of the following are true:
 *   - identity_swap_holds.status = 'pending'          (we still offer it)
 *   - hold_transactions.status   = 'DEBITED'          (the bank already spent it)
 *   - it was debited more than --min-age-minutes ago  (not a live claim)
 *   - no unresolved swap_manual_reconciliation_required row references it
 *
 * That last rule matters: a manual-reconciliation row means the compensating
 * credit ALSO failed, so the customer may still be out of pocket. Those are
 * reported and deliberately left alone -- cancelling one would bury a case
 * where real money is genuinely missing.
 *
 * USAGE
 *   php scripts/management/reconcile_stuck_identity_claims.php               # dry run
 *   php scripts/management/reconcile_stuck_identity_claims.php --apply
 *   php scripts/management/reconcile_stuck_identity_claims.php --min-age-minutes=120
 *   php scripts/management/reconcile_stuck_identity_claims.php --hold-id=2386 --apply
 *
 * Dry run is the default and prints exactly what --apply would change.
 * Verify a couple of the listed holds against the source bank's own records
 * (the money should be back in the customer's account) before applying.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

$options = getopt('', ['apply', 'min-age-minutes::', 'hold-id::', 'help']);

if (isset($options['help'])) {
    echo file_get_contents(__FILE__, false, null, 0, 2100), PHP_EOL;
    exit(0);
}

$apply = isset($options['apply']);
$minAgeMinutes = (int)($options['min-age-minutes'] ?? 60);
$onlyHoldId = isset($options['hold-id']) ? (int)$options['hold-id'] : null;

if ($minAgeMinutes < 1) {
    fwrite(STDERR, "--min-age-minutes must be at least 1 (a claim in flight must not be touched).\n");
    exit(1);
}

$db = DBConnection::getConnection();
if (!$db) {
    fwrite(STDERR, "No database connection (is DATABASE_URL set?).\n");
    exit(1);
}

$sql = "
    SELECT ish.hold_id,
           ish.swap_reference,
           ish.hold_reference,
           ish.source_institution,
           ish.amount,
           ish.currency,
           ish.identity_type,
           ish.identity_value,
           ish.created_at,
           ht.status       AS bank_hold_status,
           ht.debited_at,
           recon.id        AS reconciliation_id,
           recon.reason    AS reconciliation_reason
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

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "No stuck identity claims found";
    echo $onlyHoldId !== null ? " for hold {$onlyHoldId}.\n" : ".\n";
    exit(0);
}

$cancellable = [];
$needsHuman = [];
foreach ($rows as $row) {
    if ($row['reconciliation_id'] !== null) {
        $needsHuman[] = $row;
    } else {
        $cancellable[] = $row;
    }
}

echo $apply ? "APPLYING changes.\n\n" : "DRY RUN -- nothing will be changed. Re-run with --apply to commit.\n\n";

if (!empty($needsHuman)) {
    echo "LEFT ALONE -- flagged for manual reconciliation, the money may NOT be back with the customer:\n";
    foreach ($needsHuman as $row) {
        printf(
            "  hold %-8s %s  %s %s  (%s)\n      reason: %s\n",
            $row['hold_id'],
            $row['swap_reference'],
            $row['currency'],
            $row['amount'],
            $row['source_institution'],
            trim((string)$row['reconciliation_reason'])
        );
    }
    echo "\n";
}

if (empty($cancellable)) {
    echo "Nothing to cancel.\n";
    exit(0);
}

echo ($apply ? "CANCELLING" : "WOULD CANCEL") . " -- debited at the bank, credited back to the customer, never closed out:\n";
foreach ($cancellable as $row) {
    printf(
        "  hold %-8s %s  %s %s  %s -> %s=%s  debited %s\n",
        $row['hold_id'],
        $row['swap_reference'],
        $row['currency'],
        $row['amount'],
        $row['source_institution'],
        $row['identity_type'],
        $row['identity_value'],
        $row['debited_at']
    );
}
echo "\n";

if (!$apply) {
    printf("%d hold(s) would be cancelled, %d left for a human.\n", count($cancellable), count($needsHuman));
    exit(0);
}

$update = $db->prepare("
    UPDATE identity_swap_holds
    SET status = 'cancelled',
        metadata = COALESCE(metadata, '{}'::jsonb) || :meta::jsonb
    WHERE hold_id = :hold_id
      AND status = 'pending'
");

$cancelled = 0;
$skipped = 0;
foreach ($cancellable as $row) {
    $meta = json_encode([
        'compensated' => true,
        'reason' => 'Payout failed after debit; amount credited back to source. Closed out by reconcile_stuck_identity_claims.php',
        'reconciled_at' => date('c'),
    ]);

    $update->execute([':meta' => $meta, ':hold_id' => (int)$row['hold_id']]);

    // The status guard in the UPDATE means a hold claimed between the SELECT
    // and now is left untouched rather than cancelled out from under a
    // successful claim.
    if ($update->rowCount() === 1) {
        $cancelled++;
        echo "  cancelled hold {$row['hold_id']}\n";
    } else {
        $skipped++;
        echo "  SKIPPED hold {$row['hold_id']} -- no longer 'pending' (claimed or changed since this script started)\n";
    }
}

printf("\nDone. %d cancelled, %d skipped, %d left for a human.\n", $cancelled, $skipped, count($needsHuman));
