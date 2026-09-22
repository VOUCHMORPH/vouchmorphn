<?php
declare(strict_types=1);
/**
 * cron/consolidate_identity_reservations.php
 *
 * Identity resolution for reservation (virtual) accounts. An identity opens
 * its own account (e.g. a phone number, before it is resolved). Once a person
 * unifies their identities (verified in user_identities), the highest-priority
 * identity - national ID, then passport, other government IDs, phone, email -
 * becomes the active account: every balance in the person's other identities'
 * accounts moves into it at the same institution, and the old account is
 * marked 'merged' (pointing at its successor).
 *
 * Runs every 5 minutes from scripts/run_scheduled_jobs.php. A claim also pulls
 * in every account of the person, so nothing waits on this job.
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
$container = require_once ROOT_PATH . '/src/bootstrap.php';
$db = $container->get(PDO::class);
if (!$db->query("SELECT pg_try_advisory_lock(7743013)")->fetchColumn()) { exit(0); }
$swapService = $container->get('Domain\\Services\\SwapService');
$ras = new ReflectionProperty($swapService, 'reservationAccountService');
$ras->setAccessible(true);
$reservations = $ras->getValue($swapService);

// Identities with an open account that belong to a person (verified in user_identities).
$candidates = $db->query("
    SELECT DISTINCT r.identity_type, r.identity_value
    FROM reservation_accounts r
    JOIN user_identities u ON u.identity_type = r.identity_type AND u.status = 'verified'
    WHERE r.status = 'active' AND r.identity_type IS NOT NULL
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$stats = ['identities_checked' => 0, 'moves' => 0, 'moved_amount' => 0.0, 'errors' => 0];
$done = [];
foreach ($candidates as $c) {
    $canon = $reservations->canonicalIdentity($c['identity_type'], $c['identity_value']);
    $key = $canon['type'] . ':' . $canon['value'];
    if (isset($done[$key])) continue;          // one pass per person
    $done[$key] = true;
    $stats['identities_checked']++;
    if ($canon['type'] === strtolower($c['identity_type']) && $canon['value'] === $c['identity_value'] && count($reservations->personIdentities($c['identity_type'], $c['identity_value'])) === 1) continue;
    foreach ($swapService->consolidateIdentityReservations($canon['type'], $canon['value']) as $m) {
        if (isset($m['error'])) { $stats['errors']++; fwrite(STDOUT, "[identity-merge] account {$m['from']} at {$m['institution']}: {$m['error']}\n"); continue; }
        $stats['moves']++;
        $stats['moved_amount'] += (float)$m['amount'];
        fwrite(STDOUT, "[identity-merge] {$m['from_identity']} account {$m['from']} -> {$m['to_identity']} account {$m['to']} at {$m['institution']}: P{$m['amount']}\n");
    }
}
$db->query("SELECT pg_advisory_unlock(7743013)");
echo json_encode($stats) . PHP_EOL;
