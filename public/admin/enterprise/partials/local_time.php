<?php
/**
 * partials/local_time.php — every time the enterprise pages show a person.
 *
 * The web tier runs PHP in UTC: the image ships no php.ini, and these pages
 * never load src/bootstrap.php, the one place that sets a zone. The database
 * answers them in UTC as well — timestamptz columns come back with a "+00"
 * suffix, plain timestamp columns come back with no zone at all but were
 * written by these same UTC sessions. So date('Y-m-d H:i', strtotime(...))
 * printed UTC, two hours behind Botswana, on every enterprise screen.
 *
 * These helpers read a value the way the database meant it (its own offset
 * when it carries one, UTC when it does not) and show it in the operating
 * country's zone: VM_TZ, which Railway already sets, then APP_TIMEZONE /
 * TIMEZONE, then Africa/Gaborone. PHP's own default zone is left alone on
 * purpose: code elsewhere compares strtotime() of zone-less columns against
 * time(), and moving the default would shift those comparisons by two hours.
 */

if (!function_exists('vm_local_tz')) {
    function vm_local_tz(): DateTimeZone
    {
        static $tz = null;
        if ($tz === null) {
            $tz = new DateTimeZone('Africa/Gaborone');
            foreach (['VM_TZ', 'APP_TIMEZONE', 'TIMEZONE'] as $key) {
                $name = $_ENV[$key] ?? getenv($key);
                if (is_string($name) && in_array($name, timezone_identifiers_list(), true)) {
                    $tz = new DateTimeZone($name);
                    break;
                }
            }
        }
        return $tz;
    }

    /** A database timestamp in local time, or $empty when there is none. */
    function vm_local_time($value, string $format = 'Y-m-d H:i', string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }
        try {
            // The UTC zone applies only when the value carries no offset of its own.
            return (new DateTimeImmutable((string)$value, new DateTimeZone('UTC')))
                ->setTimezone(vm_local_tz())
                ->format($format);
        } catch (Exception $e) {
            return (string)$value;
        }
    }

    function vm_local_now(string $format = 'Y-m-d H:i'): string
    {
        return (new DateTimeImmutable('now', vm_local_tz()))->format($format);
    }

    /**
     * The UTC instant a local calendar day starts, as a bound to compare
     * database timestamps against: 2026-09-01 in Gaborone starts at
     * "2026-08-31 22:00:00+00". The explicit offset keeps the bound right
     * whatever the session zone; a timestamp-without-zone column ignores it
     * and compares the UTC wall clock it was written in. Null when $ymd is
     * not a real Y-m-d date.
     */
    function vm_local_day_start_utc(string $ymd, int $plusDays = 0): ?string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, vm_local_tz());
        if ($day === false || $day->format('Y-m-d') !== $ymd) {
            return null;
        }
        return $day->modify(sprintf('%+d days', $plusDays))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s') . '+00';
    }
}
