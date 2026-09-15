<?php
declare(strict_types=1);
// cron/sweep_reservation_account_remainders.php — run every few minutes.
//
// Safety net for public/api/callback/reservation_account_confirmed.php:
// that handler already sweeps a reservation account's pooled remainder
// the moment it activates, but an account that activated via polling
// (getReservationAccountStatus) rather than a callback, or a sweep attempt
// that failed transiently, would otherwise leave money stranded in
// identity_holding_positions indefinitely. Finds every ACTIVE reservation
// account that still has an OPEN pooled position for its (user,
// institution, currency) and re-runs the sweep for it.

require_once __DIR__ . '/../Core/Database/DBConnection.php';
require_once __DIR__ . '/../Core/Config/LoadCountry.php';
require_once __DIR__ . '/../Infrastructure/Adapters/InstitutionAdapterFactory.php';
require_once __DIR__ . '/../Domain/Services/ReservationAccountService.php';

use Core\Config\LoadCountry;
use Infrastructure\Adapters\InstitutionAdapterFactory;
use Domain\Services\ReservationAccountService;

$db = \Core\Database\DBConnection::getConnection();
$countryConfig = LoadCountry::getConfig();
$participants = $countryConfig['participants'] ?? [];

$logger = new class {
    public function info($m, $c = []) { error_log("[SWEEP_RESACC] INFO: {$m} " . json_encode($c)); }
    public function error($m, $c = []) { error_log("[SWEEP_RESACC] ERROR: {$m} " . json_encode($c)); }
    public function warning($m, $c = []) { error_log("[SWEEP_RESACC] WARNING: {$m} " . json_encode($c)); }
    public function debug($m, $c = []) {}
    public function log($l, $m, $c = []) {}
};

$adapterFactory = new InstitutionAdapterFactory($participants, $logger);
$reservationAccountService = new ReservationAccountService($db, $participants, $adapterFactory, $logger);

// Recover any position left stuck in 'sweeping' by a worker that died
// mid-sweep (crash, OOM, deploy restart) before it could mark 'swept' or
// 'sweep_failed' — otherwise such a position is invisible to every query
// below and would never be retried.
$reclaimedSweeping = $reservationAccountService->reclaimStaleSweepingPositions(50);
if ($reclaimedSweeping > 0) {
    error_log("[SWEEP_RESACC] Reclaimed {$reclaimedSweeping} position(s) stuck in 'sweeping'");
}

$accountIds = $reservationAccountService->findActiveAccountIdsWithOpenPositions(50);

$totalSwept = 0;
$totalFailed = 0;

foreach ($accountIds as $accountId) {
    try {
        $result = $reservationAccountService->sweepOpenPositionsFor($accountId);
        $totalSwept += $result['swept'];
        $totalFailed += $result['failed'];

        if ($result['swept'] > 0 || $result['failed'] > 0) {
            error_log("[SWEEP_RESACC] reservation_account {$accountId}: swept={$result['swept']} failed={$result['failed']}");
        }
    } catch (\Throwable $e) {
        error_log("[SWEEP_RESACC] Sweep failed for reservation_account {$accountId}: " . $e->getMessage());
        $totalFailed++;
    }
}

error_log("[SWEEP_RESACC] Run complete: accounts_checked=" . count($accountIds) . " swept={$totalSwept} failed={$totalFailed}");
