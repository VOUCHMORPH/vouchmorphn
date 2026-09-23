<?php
/**
 * The schedule, in one place.
 *
 * scripts/run_scheduled_jobs.php runs these. src/cron/incident_monitor.php
 * checks that each one actually ran. Both read this file, so a job cannot be
 * scheduled without being watched, or watched without being scheduled.
 *
 *   'every'  — every pass (the service runs every 5 minutes)
 *   'hourly' — once an hour
 *
 * stale_after_minutes is how long silence is acceptable before the monitor
 * raises JOBS_STALLED. Keep it comfortably above the job's normal gap.
 */
declare(strict_types=1);

return [
    'release_expired_holds.php'               => ['when' => 'every',  'stale_after_minutes' => 20],
    'release_expired_card_hooks.php'          => ['when' => 'every',  'stale_after_minutes' => 20],
    'consolidate_identity_reservations.php'   => ['when' => 'every',  'stale_after_minutes' => 20],
    'cancel_expired_hooks.php'                => ['when' => 'every',  'stale_after_minutes' => 20],
    'ExpireContributionSessions.php'          => ['when' => 'every',  'stale_after_minutes' => 20],
    'sweep_reservation_account_remainders.php'=> ['when' => 'every',  'stale_after_minutes' => 20],
    'dispatch_settlement_advices.php'         => ['when' => 'every',  'stale_after_minutes' => 20],
    'poll_settlement_confirmations.php'       => ['when' => 'every',  'stale_after_minutes' => 20],
    'settlement_confirmation_worker.php'      => ['when' => 'every',  'stale_after_minutes' => 20],
    'incident_monitor.php'                    => ['when' => 'every',  'stale_after_minutes' => 20],
    'reconcile_settlement_obligations.php'    => ['when' => 'hourly', 'stale_after_minutes' => 90],
    'swap_integrity_reconciler.php'           => ['when' => 'hourly', 'stale_after_minutes' => 90],
];
