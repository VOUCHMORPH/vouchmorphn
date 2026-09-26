<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../public/admin/enterprise/partials/local_time.php';

/**
 * The enterprise pages printed times in UTC, two hours behind Botswana: PHP
 * runs in UTC on the web tier and the database answers in UTC. The helpers
 * in partials/local_time.php read a database value the way the database
 * meant it (its own offset when it has one, UTC when it has none) and show
 * it in the operating zone.
 */
class EnterpriseLocalTimeTest extends TestCase
{
    private const ZONE_KEYS = ['VM_TZ', 'APP_TIMEZONE', 'TIMEZONE'];

    public static function setUpBeforeClass(): void
    {
        // vm_local_tz() caches its first answer: make that answer the default.
        foreach (self::ZONE_KEYS as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    public function testDefaultsToBotswanaTime(): void
    {
        $this->assertSame('Africa/Gaborone', vm_local_tz()->getName());
    }

    public function testAZonelessTimestampIsReadAsUtc(): void
    {
        // A plain timestamp column, written by a UTC session.
        $this->assertSame('2026-09-26 14:20', vm_local_time('2026-09-26 12:20:33.123456'));
    }

    public function testATimestamptzKeepsItsOwnOffset(): void
    {
        $this->assertSame('2026-09-26 14:20', vm_local_time('2026-09-26 12:20:33.123456+00'));
        $this->assertSame('2026-09-26 11:41', vm_local_time('2026-09-26 11:41:31+02'), 'already local: unchanged');
    }

    public function testCrossingMidnightMovesTheDate(): void
    {
        $this->assertSame('2026-10-01 00:30', vm_local_time('2026-09-30 22:30:00+00'));
    }

    public function testFormatIsTheCallersChoice(): void
    {
        $this->assertSame('14:20 CAT', vm_local_time('2026-09-26 12:20:00', 'H:i T'));
        $this->assertSame('26 Sep 2026', vm_local_time('2026-09-26 12:20:00', 'd M Y'));
    }

    public function testMissingValuesShowAPlaceholderNotTheCurrentTime(): void
    {
        // The pages used strtotime($x ?? 'now'): a batch with no timestamp
        // showed the moment the page loaded, as if it had just happened.
        $this->assertSame('—', vm_local_time(null));
        $this->assertSame('—', vm_local_time(''));
        $this->assertSame('n/a', vm_local_time(null, 'Y-m-d', 'n/a'));
    }

    public function testUnparseableValuesAreShownAsTheyAre(): void
    {
        $this->assertSame('not a time', vm_local_time('not a time'));
    }

    public function testEpochSecondsWork(): void
    {
        $this->assertSame('2026-09-26 14:20', vm_local_time('@' . gmmktime(12, 20, 0, 9, 26, 2026)));
    }

    public function testNowIsLocal(): void
    {
        $expected = (new DateTimeImmutable('now', new DateTimeZone('Africa/Gaborone')))->format('Y-m-d H');
        $this->assertSame($expected, vm_local_now('Y-m-d H'));
        $this->assertSame('CAT', vm_local_now('T'));
    }

    public function testLocalDayStartsAreUtcBoundsWithAnExplicitOffset(): void
    {
        $this->assertSame('2026-08-31 22:00:00+00', vm_local_day_start_utc('2026-09-01'));
        $this->assertSame('2026-09-26 22:00:00+00', vm_local_day_start_utc('2026-09-26', 1), 'the end bound is the start of the next day');
        $this->assertSame('2026-12-30 22:00:00+00', vm_local_day_start_utc('2026-12-31'));
        $this->assertSame('2026-12-31 22:00:00+00', vm_local_day_start_utc('2026-12-31', 1), 'across the year end');
    }

    public function testLocalDayStartRejectsWhatIsNotADate(): void
    {
        $this->assertNull(vm_local_day_start_utc('2026-02-30'));
        $this->assertNull(vm_local_day_start_utc('yesterday'));
        $this->assertNull(vm_local_day_start_utc('2026-09-01 10:00'));
        $this->assertNull(vm_local_day_start_utc(''));
    }

    public function testVmTzChoosesTheZoneAndAnUnknownOneFallsBack(): void
    {
        // Each in its own process: the zone is cached for the life of a request.
        $this->assertSame('2026-09-26 12:20 UTC', $this->formatUnder(['VM_TZ' => 'UTC']));
        $this->assertSame('2026-09-26 13:20 BST', $this->formatUnder(['VM_TZ' => 'Europe/London']));
        $this->assertSame('2026-09-26 13:20 BST', $this->formatUnder(['APP_TIMEZONE' => 'Europe/London']));
        $this->assertSame('2026-09-26 14:20 CAT', $this->formatUnder(['VM_TZ' => 'Mars/Olympus_Mons']));
    }

    private function formatUnder(array $env): string
    {
        $code = 'require ' . var_export(realpath(__DIR__ . '/../../public/admin/enterprise/partials/local_time.php'), true) . ';'
            . ' echo vm_local_time("2026-09-26 12:20:00", "Y-m-d H:i T");';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH')]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($process);
        $this->assertSame('', $err);
        return $out;
    }
}
