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
    // Heartbeat: one row per run, so the monitor can raise an alarm when the
    // scheduler stops (a stopped service or a lapsed subscription).
    try {
        static $hb = null;
        if ($hb === null) {
            $dsn = getenv('DATABASE_URL');
            $hb = $dsn ? new PDO($dsn) : null;
            if ($hb) $hb->exec("CREATE TABLE IF NOT EXISTS scheduled_job_runs (id BIGSERIAL PRIMARY KEY, job VARCHAR(80) NOT NULL, started_at TIMESTAMPTZ NOT NULL, finished_at TIMESTAMPTZ NOT NULL DEFAULT now(), exit_code INT NOT NULL DEFAULT 0, seconds NUMERIC(10,2), release_ref VARCHAR(80))");
        }
        if ($hb) {
            $hb->prepare("INSERT INTO scheduled_job_runs (job, started_at, exit_code, seconds, release_ref) VALUES (?, to_timestamp(?), ?, ?, ?)")
               ->execute([basename($file), $t, $code, round(microtime(true) - $t, 2), getenv('RAILWAY_GIT_COMMIT_SHA') ?: null]);
        }
    } catch (Throwable $e) { error_log('[jobs] heartbeat failed: ' . $e->getMessage()); }
    fwrite(STDOUT, sprintf("[jobs] %s exit=%d %.1fs\n", basename($file), $code, microtime(true) - $t));
}
