<?php
// scripts/run_scheduled_jobs.php - every VouchMorph scheduled job, for one Railway cron service.
// Railway ignores the "cron" list in railway.json, so none of these ever ran. Schedule a
// service from this repo every 5 minutes with:
//   start command: php scripts/run_scheduled_jobs.php
// Each job runs in its own process: one failing never stops the others.
// Jobs marked hourly run on the first pass of each hour.
$root = dirname(__DIR__);
$minute = (int)gmdate('i');
$jobs = [
    ['src/cron/release_expired_holds.php', 'every'],        // 24-hour rule, cash-out and identity expiry
    ['src/cron/release_expired_card_hooks.php', 'every'],
    ['src/cron/consolidate_identity_reservations.php', 'every'], // unified identities: balances move to the canonical identity's account   // expired card-pool hooks (nothing released them before)
    ['src/cron/cancel_expired_hooks.php', 'every'],         // contribution sessions
    ['src/cron/ExpireContributionSessions.php', 'every'],
    ['src/cron/dispatch_settlement_advices.php', 'every'],  // creates advices only after each cycle time
    ['src/cron/poll_settlement_confirmations.php', 'every'],
    ['src/cron/incident_monitor.php', 'every'],             // alarms, incidents, deadlines
    ['src/cron/reconcile_settlement_obligations.php', 'hourly'],
    ['src/cron/swap_integrity_reconciler.php', 'hourly'],
];
foreach ($jobs as [$file, $when]) {
    if ($when === 'hourly' && $minute >= 5) continue;
    if (!is_file("$root/$file")) { fwrite(STDOUT, "[jobs] missing $file\n"); continue; }
    $t = microtime(true);
    passthru(PHP_BINARY . ' ' . escapeshellarg("$root/$file") . ' 2>&1', $code);
    fwrite(STDOUT, sprintf("[jobs] %s exit=%d %.1fs\n", basename($file), $code, microtime(true) - $t));
}
