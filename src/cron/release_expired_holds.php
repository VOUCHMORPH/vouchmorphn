<?php
// src/cron/release_expired_holds.php
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

// FIX: every one of these was one directory level short. The paths are
// written from the repository root ('/../src/...', '/../vendor/...'),
// which only resolves if this file sits at <root>/cron/ -- and the header
// comment above still says exactly that. It actually lives at
// <root>/src/cron/, so __DIR__ . '/../src/...' resolved to
// <root>/src/src/... and __DIR__ . '/../vendor/...' to <root>/src/vendor/,
// none of which exist. Nothing caught it because nothing ever ran this
// script: it is not scheduled in railway.json, the Dockerfile installs no
// cron daemon, and there is no pg_cron. The first scheduled run would have
// fatalled on the first require.
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

    // 1. Single-swap cashouts (expired after 6 hours)
    $cashoutResults = $swapService->cancelExpiredCashouts(6);
    error_log("[CRON release_expired_holds] Cashouts: " . json_encode($cashoutResults));

    // 2. Single-swap identity claims (expired after 24 hours)
    $identityResults = $swapService->cancelExpiredIdentitySwaps();
    error_log("[CRON release_expired_holds] Identity swaps: " . json_encode($identityResults));

    // 3. Pool cashouts (expired after 6 hours)
    $poolCashoutResults = $swapService->cancelExpiredPoolCashouts(6);
    error_log("[CRON release_expired_holds] Pool cashouts: " . json_encode($poolCashoutResults));

    // 4. Pool identity claims (expired after 24 hours)
    $poolIdentityResults = $swapService->cancelExpiredPoolIdentityClaims();
    error_log("[CRON release_expired_holds] Pool identity claims: " . json_encode($poolIdentityResults));

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
