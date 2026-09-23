<?php
// scripts/run_scheduled_jobs.php — every VouchMorph scheduled job, for one Railway cron service.
// Railway ignores the "cron" list in railway.json, so none of these ever ran. Schedule a
// service from this repo every 5 minutes with:
//   start command: php scripts/run_scheduled_jobs.php
// Each job runs in its own process: one failing never stops the others.
//
// Fixed here:
//   1. Heartbeat connection — DATABASE_URL is a URL, not a PDO DSN. PDO rejected it,
//      so every heartbeat insert failed silently and incident_monitor.php could never
//      tell whether the scheduler was alive. Now uses DBConnection, like the rest of
//      the codebase.
//   2. Overlapping runs — a pass slower than the schedule used to start a second copy
//      of every job alongside the first, letting two runs release the same hold. One
//      advisory lock now makes a pass skip while another is still working.
//   3. Hourly jobs — the old minute < 5 gate skipped the hour entirely whenever a pass
//      started late (a restart, a slow queue). Hourly jobs now run when the heartbeat
//      shows none in the last 55 minutes, with the minute gate as a fallback.
//   4. Two jobs that existed but were never scheduled.
//   5. A hung job no longer blocks everything behind it (per-job timeout).
//   6. The pass exits non-zero when a job fails, so Railway can alert.

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;

const JOB_TIMEOUT_SECONDS = 240;        // shorter than the 5-minute schedule
const HOURLY_GAP_MINUTES  = 55;

// The schedule lives in one file, shared with incident_monitor.php so a job
// cannot be scheduled without being watched, or watched without being run.
$schedule = require $root . '/src/Core/Config/scheduled_jobs.php';
$jobs = [];
foreach ($schedule as $name => $cfg) {
    $jobs[] = ["src/cron/{$name}", $cfg['when']];
}

$db = null;
try {
    $db = DBConnection::getConnection();
} catch (Throwable $e) {
    fwrite(STDOUT, "[jobs] no database: " . $e->getMessage() . " — running jobs without a heartbeat\n");
}

// ---- one pass at a time -------------------------------------------------
$lockKey = 862434001;   // fixed key for "vouchmorph scheduled jobs"
if ($db) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS scheduled_job_runs (
            id BIGSERIAL PRIMARY KEY,
            job VARCHAR(80) NOT NULL,
            started_at TIMESTAMPTZ NOT NULL,
            finished_at TIMESTAMPTZ NOT NULL DEFAULT now(),
            exit_code INT NOT NULL DEFAULT 0,
            seconds NUMERIC(10,2),
            release_ref VARCHAR(80))");
        $db->exec("CREATE INDEX IF NOT EXISTS scheduled_job_runs_job_idx ON scheduled_job_runs (job, finished_at DESC)");

        $held = $db->query("SELECT pg_try_advisory_lock($lockKey)")->fetchColumn();
        if (!$held || $held === 'f') {
            fwrite(STDOUT, "[jobs] previous pass still running — skipping this one\n");
            exit(0);
        }
    } catch (Throwable $e) {
        fwrite(STDOUT, "[jobs] heartbeat setup failed: " . $e->getMessage() . "\n");
        $db = null;
    }
}

// ---- are the hourly jobs due? ------------------------------------------
$hourlyDue = function (string $file) use ($db): bool {
    if ($db) {
        try {
            $q = $db->prepare("SELECT max(finished_at) FROM scheduled_job_runs WHERE job = ? AND exit_code = 0");
            $q->execute([basename($file)]);
            $last = $q->fetchColumn();
            return $last === null || (time() - strtotime((string)$last)) >= HOURLY_GAP_MINUTES * 60;
        } catch (Throwable $e) {
            // fall through to the clock
        }
    }
    return (int)gmdate('i') < 5;
};

$timeout = trim((string)shell_exec('command -v timeout')) !== '';
$failures = 0;

foreach ($jobs as [$file, $when]) {
    if ($when === 'hourly' && !$hourlyDue($file)) {
        continue;
    }
    if (!is_file("$root/$file")) {
        fwrite(STDOUT, "[jobs] missing $file\n");
        $failures++;                      // a missing job is a failure, not a shrug
        continue;
    }

    $t = microtime(true);
    $cmd = PHP_BINARY . ' ' . escapeshellarg("$root/$file") . ' 2>&1';
    if ($timeout) {
        $cmd = 'timeout ' . JOB_TIMEOUT_SECONDS . ' ' . $cmd;
    }
    passthru($cmd, $code);
    $seconds = round(microtime(true) - $t, 2);

    if ($code !== 0) {
        $failures++;
    }
    if ($code === 124) {
        fwrite(STDOUT, sprintf("[jobs] %s TIMED OUT after %ds\n", basename($file), JOB_TIMEOUT_SECONDS));
    }

    if ($db) {
        try {
            $db->prepare("INSERT INTO scheduled_job_runs (job, started_at, exit_code, seconds, release_ref)
                          VALUES (?, to_timestamp(?), ?, ?, ?)")
               ->execute([basename($file), $t, $code, $seconds, getenv('RAILWAY_GIT_COMMIT_SHA') ?: null]);
        } catch (Throwable $e) {
            error_log('[jobs] heartbeat failed: ' . $e->getMessage());
        }
    }

    fwrite(STDOUT, sprintf("[jobs] %s exit=%d %.1fs\n", basename($file), $code, $seconds));
}

if ($db) {
    try { $db->query("SELECT pg_advisory_unlock($lockKey)"); } catch (Throwable $e) {}
}

fwrite(STDOUT, sprintf("[jobs] pass finished, %d failure%s\n", $failures, $failures === 1 ? '' : 's'));
exit($failures > 0 ? 1 : 0);
