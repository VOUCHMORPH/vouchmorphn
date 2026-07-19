<?php
// cron/release_expired_holds.php
// Run every 15-30 minutes via system cron / scheduled task.
// Releases holds for:
//   1. Cashout codes unredeemed 6h past their code_expiry
//      (withholds generate-code fee + levy, releases the rest)
//   2. Identity swaps unclaimed 24h after initiation
//      (withholds levy only, releases the rest)
//
// Both release paths are idempotent - safe to run this on overlapping
// schedules or re-run after a failure; already-completed/already-
// released records are skipped, not double-processed.

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

$startedAt = microtime(true);
error_log("[CRON release_expired_holds] Starting run");

try {
    $db = DBConnection::getConnection();
    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    $cashoutResults = $swapService->cancelExpiredCashouts(6);
    error_log("[CRON release_expired_holds] Cashouts: " . json_encode($cashoutResults));

    $identityResults = $swapService->cancelExpiredIdentitySwaps();
    error_log("[CRON release_expired_holds] Identity swaps: " . json_encode($identityResults));

    $elapsed = round(microtime(true) - $startedAt, 2);
    error_log("[CRON release_expired_holds] Completed in {$elapsed}s - "
        . "cashouts released: {$cashoutResults['released']}, errors: {$cashoutResults['errors']}; "
        . "identity swaps released: {$identityResults['cancelled']}, errors: {$identityResults['errors']}");

    // Exit non-zero if anything errored, so cron monitoring/alerting
    // (e.g. a healthcheck ping service) can catch silent failures.
    if (($cashoutResults['errors'] ?? 0) > 0 || ($identityResults['errors'] ?? 0) > 0) {
        exit(1);
    }
    exit(0);

} catch (\Throwable $e) {
    error_log("[CRON release_expired_holds] FATAL: " . $e->getMessage());
    error_log($e->getTraceAsString());
    exit(2);
}
