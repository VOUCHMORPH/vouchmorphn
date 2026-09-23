<?php
declare(strict_types=1);

namespace Application\Incident;

use PDO;

/**
 * Is every scheduled job actually running?
 *
 * The old check asked the heartbeat table which jobs had gone quiet. A job
 * that had NEVER run — newly added, renamed, misspelled in the list, or
 * failing before it could write a heartbeat — had no rows at all, so it never
 * appeared, and the monitor reported everything healthy. This compares the
 * heartbeat against the schedule itself, so a job missing entirely is the
 * loudest case, not the invisible one.
 */
final class ScheduledJobHealth
{
    /**
     * @param array<string, array{when: string, stale_after_minutes: int}> $schedule
     * @return list<array{job: string, state: string, last_run: ?string, minutes: ?int, last_exit: ?int}>
     */
    public static function problems(PDO $db, array $schedule): array
    {
        $stmt = $db->query(
            "SELECT job,
                    max(finished_at) FILTER (WHERE exit_code = 0)          AS last_ok,
                    max(finished_at)                                        AS last_any,
                    (array_agg(exit_code ORDER BY finished_at DESC))[1]     AS last_exit
               FROM scheduled_job_runs
              GROUP BY job"
        );
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seen[$r['job']] = $r;
        }

        $out = [];
        foreach ($schedule as $job => $cfg) {
            $limit = (int)($cfg['stale_after_minutes'] ?? 20);
            $row = $seen[$job] ?? null;

            if ($row === null) {
                $out[] = ['job' => $job, 'state' => 'NEVER_RUN', 'last_run' => null, 'minutes' => null, 'last_exit' => null];
                continue;
            }
            if ($row['last_ok'] === null) {
                $out[] = ['job' => $job, 'state' => 'NEVER_SUCCEEDED', 'last_run' => $row['last_any'],
                          'minutes' => self::minutesSince($row['last_any']), 'last_exit' => (int)$row['last_exit']];
                continue;
            }
            $mins = self::minutesSince($row['last_ok']);
            if ($mins > $limit) {
                $out[] = ['job' => $job, 'state' => 'STALLED', 'last_run' => $row['last_ok'],
                          'minutes' => $mins, 'last_exit' => (int)$row['last_exit']];
            }
        }

        // A job writing heartbeats that nobody schedules is also worth knowing about.
        foreach ($seen as $job => $row) {
            if (!isset($schedule[$job])) {
                $out[] = ['job' => $job, 'state' => 'NOT_IN_SCHEDULE', 'last_run' => $row['last_any'],
                          'minutes' => self::minutesSince($row['last_any']), 'last_exit' => (int)$row['last_exit']];
            }
        }

        return $out;
    }

    public static function describe(array $p): string
    {
        return match ($p['state']) {
            'NEVER_RUN'       => "Scheduled job {$p['job']} has never run — it is in the schedule but no run was ever recorded",
            'NEVER_SUCCEEDED' => "Scheduled job {$p['job']} has never succeeded — last attempt {$p['last_run']} exited {$p['last_exit']}",
            'STALLED'         => "Scheduled job {$p['job']} has not succeeded since {$p['last_run']} ({$p['minutes']} minutes ago)",
            'NOT_IN_SCHEDULE' => "Job {$p['job']} is writing heartbeats but is not in the schedule",
            default           => "Scheduled job {$p['job']}: {$p['state']}",
        };
    }

    private static function minutesSince(?string $ts): ?int
    {
        return $ts === null ? null : (int)round((time() - strtotime($ts)) / 60);
    }
}
