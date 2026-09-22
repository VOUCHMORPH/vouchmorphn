<?php
// cron/release_expired_holds.php
// Run every 15-30 minutes via system cron / scheduled task.
// Releases holds for:
//   1. Cashout codes unredeemed 6h past their code_expiry
//      (withholds generate-code fee + levy, releases the rest)
//   2. Identity swaps unclaimed 24h after initiation
//      (withholds levy only, releases the rest)
//   3. Pool cashout codes unredeemed 6h past their code_expiry
//      (releases all source holds, reverses the pool)
//   4. Pool identity claims unclaimed 24h after initiation
//      (releases all source holds, reverses the pool)
//
// Both release paths are idempotent - safe to run this on overlapping
// schedules or re-run after a failure; already-completed/already-
// released records are skipped, not double-processed.

declare(strict_types=1);

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

$startedAt = microtime(true);
error_log("[CRON release_expired_holds] Starting run");

try {
    $db = DBConnection::getConnection();
    $country = getenv('VOUCHMORPH_COUNTRY') ?: 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    // FIX (2026-09-22): each step runs on its own, so one failing step no
    // longer stops the rest (a missing pool method once skipped step 4).
    $step = function (string $label, callable $fn, array $empty) {
        try {
            $r = $fn();
            error_log("[CRON release_expired_holds] {$label}: " . json_encode($r));
            return $r;
        } catch (\Throwable $e) {
            error_log("[CRON release_expired_holds] {$label} FAILED: " . $e->getMessage());
            return $empty + ['errors' => 1];
        }
    };
    // 1. Single-swap cashouts (expired after 6 hours)
    $cashoutResults = $step('Cashouts', fn() => $swapService->cancelExpiredCashouts(6), ['released' => 0]);
    // 2. Single-swap identity claims (expired after 24 hours)
    $identityResults = $step('Identity swaps', fn() => $swapService->cancelExpiredIdentitySwaps(), ['cancelled' => 0]);
    // 3. Pool cashouts (expired after 6 hours)
    $poolCashoutResults = $step('Pool cashouts', fn() => $swapService->cancelExpiredPoolCashouts(6), ['released' => 0]);
    // 4. Pool identity claims (expired after 24 hours)
    $poolIdentityResults = $step('Pool identity claims', fn() => $swapService->cancelExpiredPoolIdentityClaims(), ['cancelled' => 0]);

    $elapsed = round(microtime(true) - $startedAt, 2);
    error_log("[CRON release_expired_holds] Completed in {$elapsed}s - "
        . "cashouts released: {$cashoutResults['released']}, errors: {$cashoutResults['errors']}; "
        . "identity swaps cancelled: {$identityResults['cancelled']}, errors: {$identityResults['errors']}; "
        . "pool cashouts released: {$poolCashoutResults['released']}, errors: {$poolCashoutResults['errors']}; "
        . "pool identity claims cancelled: {$poolIdentityResults['cancelled']}, errors: {$poolIdentityResults['errors']}");

    // Exit non-zero if anything errored, so cron monitoring/alerting
    // (e.g. a healthcheck ping service) can catch silent failures.
    if (($cashoutResults['errors'] ?? 0) > 0
        || ($identityResults['errors'] ?? 0) > 0
        || ($poolCashoutResults['errors'] ?? 0) > 0
        || ($poolIdentityResults['errors'] ?? 0) > 0) {
        exit(1);
    }
    exit(0);

} catch (\Throwable $e) {
    error_log("[CRON release_expired_holds] FATAL: " . $e->getMessage());
    error_log($e->getTraceAsString());
    exit(2);
}
