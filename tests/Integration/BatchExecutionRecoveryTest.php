<?php

use PHPUnit\Framework\TestCase;
use Domain\Services\BatchExecutionQueueService;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Domain/Services/BatchExecutionQueueService.php';
require_once __DIR__ . '/../../public/admin/enterprise/partials/batch_display.php';

/**
 * Two things an executed enterprise batch depends on, against a real
 * PostgreSQL server:
 *
 * - BatchExecutionQueueService::recoverStuckJobs(), which stuck_job_recovery.php
 *   called but which did not exist, and which worker.php now runs once a
 *   minute: a job a dead worker left claimed must go back to the queue, give
 *   up after its last attempt, or complete when its destination already paid.
 * - The role status lists, compared case-insensitively on the server.
 *
 * Same throwaway-schema pattern and variable as the other integration tests:
 *
 *     CREDENTIALS_MIGRATION_TEST_DATABASE_URL=postgresql://user@host:5432/scratch \
 *         vendor/bin/phpunit tests/Integration/BatchExecutionRecoveryTest.php
 */
class BatchExecutionRecoveryTest extends TestCase
{
    private const SCHEMA = 'batch_execution_recovery';

    private static ?PDO $db = null;
    private static array $connection = [];

    public static function setUpBeforeClass(): void
    {
        $url = getenv('CREDENTIALS_MIGRATION_TEST_DATABASE_URL');
        if (!$url) {
            return;
        }
        $parts = parse_url($url);
        self::$connection = [
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $parts['host'] ?? 'localhost', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '', '/')),
            $parts['user'] ?? 'postgres',
            $parts['pass'] ?? '',
        ];
        self::$db = self::connect();
        self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
        self::$db->exec('CREATE SCHEMA ' . self::SCHEMA);
        self::$db->exec('SET search_path TO ' . self::SCHEMA);
        // Only the columns the queue and the pages touch. Timestamps are
        // plain TIMESTAMP on purpose: the comparisons must hold for zone-less
        // columns too, since not every table here is timestamptz.
        self::$db->exec("
            CREATE TABLE disbursement_batches (
                id BIGSERIAL PRIMARY KEY, organization_id BIGINT NOT NULL DEFAULT 1,
                batch_reference TEXT, status TEXT NOT NULL,
                completed_at TIMESTAMP, created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW());
            CREATE TABLE disbursement_destinations (
                id BIGSERIAL PRIMARY KEY, batch_id BIGINT NOT NULL, destination_index INT NOT NULL,
                institution TEXT, identifier TEXT, amount NUMERIC(18,2) DEFAULT 10,
                status TEXT DEFAULT 'PENDING', error_message TEXT);
            CREATE TABLE batch_execution_jobs (
                id BIGSERIAL PRIMARY KEY, batch_id BIGINT NOT NULL, destination_id BIGINT NOT NULL,
                institution TEXT, idempotency_key TEXT UNIQUE, status TEXT NOT NULL DEFAULT 'pending',
                claimed_by TEXT, claimed_at TIMESTAMP, attempt_count INT NOT NULL DEFAULT 0,
                max_attempts INT NOT NULL DEFAULT 3, last_error TEXT, completed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW(), updated_at TIMESTAMP DEFAULT NOW());
        ");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->exec('DROP SCHEMA IF EXISTS ' . self::SCHEMA . ' CASCADE');
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            $this->markTestSkipped('Set CREDENTIALS_MIGRATION_TEST_DATABASE_URL to a throwaway PostgreSQL database to run this.');
        }
        self::$db->exec('TRUNCATE disbursement_batches, disbursement_destinations, batch_execution_jobs RESTART IDENTITY');
    }

    public function testAFreshClaimIsLeftAlone(): void
    {
        $job = $this->job('claimed', claimedSecondsAgo: 60);

        $this->assertSame([], $this->queue()->recoverStuckJobs(600));
        $this->assertSame('claimed', $this->jobRow($job)['status']);
    }

    public function testAStaleClaimGoesBackToTheQueue(): void
    {
        $job = $this->job('claimed', claimedSecondsAgo: 1200);

        $recovered = $this->queue()->recoverStuckJobs(600);

        $this->assertCount(1, $recovered);
        $this->assertSame('pending', $recovered[0]['outcome']);
        $this->assertSame('worker-7', $recovered[0]['claimed_by']);
        $this->assertGreaterThanOrEqual(1200, $recovered[0]['age_seconds']);
        $row = $this->jobRow($job);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int)$row['attempt_count'], 'a crash costs an attempt, like any failure');
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['claimed_at']);
        $this->assertStringContainsString('worker-7', $row['last_error']);
        $this->assertSame('PENDING', $this->destinationRow($job)['status'], 'a retry is queued: the destination is not failed');
        $this->assertSame('executing', $this->batchStatus(), 'a job going back to the queue leaves the batch in flight');
    }

    public function testTheLastAttemptFailsPermanentlyAndFinishesTheBatch(): void
    {
        $this->job('completed', claimedSecondsAgo: null, destinationStatus: 'SUCCESS');
        $job = $this->job('processing', claimedSecondsAgo: 1200, attempts: 2);

        $recovered = $this->queue()->recoverStuckJobs(600);

        $this->assertSame('permanently_failed', $recovered[0]['outcome']);
        $this->assertSame('permanently_failed', $this->jobRow($job)['status']);
        $destination = $this->destinationRow($job);
        $this->assertSame('FAILED', $destination['status']);
        $this->assertStringContainsString('confirm with the institution', $destination['error_message'], 'whether it paid is unknown, and the row says so');
        $this->assertSame('partially_completed', $this->batchStatus());
    }

    public function testAJobWhoseDestinationAlreadyPaidIsCompletedNotRetried(): void
    {
        // The worker died between paying and recording the job as done.
        $job = $this->job('processing', claimedSecondsAgo: 1200, destinationStatus: 'SUCCESS');

        $recovered = $this->queue()->recoverStuckJobs(600);

        $this->assertSame('completed', $recovered[0]['outcome']);
        $this->assertSame('completed', $this->jobRow($job)['status']);
        $this->assertSame(0, (int)$this->jobRow($job)['attempt_count']);
        $this->assertSame('SUCCESS', $this->destinationRow($job)['status']);
        $this->assertSame('completed', $this->batchStatus(), 'its only job is done, so the batch is');
    }

    public function testARowAnotherSessionHoldsIsSkipped(): void
    {
        $job = $this->job('processing', claimedSecondsAgo: 1200);
        $other = self::connect();
        $other->exec('SET search_path TO ' . self::SCHEMA);
        $other->beginTransaction();
        $other->exec("SELECT id FROM batch_execution_jobs WHERE id = {$job} FOR UPDATE");

        try {
            $this->assertSame([], $this->queue()->recoverStuckJobs(600), 'skipped, not waited on');
        } finally {
            $other->rollBack();
        }
        $this->assertSame('pending', $this->queue()->recoverStuckJobs(600)[0]['outcome'], 'recovered once released');
    }

    public function testRoleStatusListsMatchAnyCaseOnTheServer(): void
    {
        foreach (['DRAFT', 'pending_approval', 'approved', 'executing', 'partially_completed', 'completed', 'rejected'] as $status) {
            self::$db->prepare('INSERT INTO disbursement_batches (batch_reference, status) VALUES (:r, :s)')
                ->execute([':r' => 'B-' . $status, ':s' => $status]);
        }

        $approver = array_merge(vm_batch_statuses('before_approval'), vm_batch_statuses('approved_onward'));
        $this->assertSame(
            ['DRAFT', 'approved', 'completed', 'executing', 'partially_completed', 'pending_approval'],
            $this->statusesWhere(vm_batch_status_in($approver)),
            'an approver keeps the batch through execution, and sees the capital-letter draft'
        );
        $this->assertSame(
            ['completed', 'executing', 'partially_completed'],
            $this->statusesWhere(vm_batch_status_in(vm_batch_statuses('released'))),
            'auditors and viewers see everything released for payment'
        );
    }

    // ------------------------------------------------------------------

    private static function connect(): PDO
    {
        [$dsn, $user, $pass] = self::$connection;
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function queue(): BatchExecutionQueueService
    {
        return new BatchExecutionQueueService(self::$db);
    }

    /** One batch per test; each call adds a destination and its job to it. */
    private function job(string $status, ?int $claimedSecondsAgo, int $attempts = 0, string $destinationStatus = 'PENDING'): int
    {
        $batchId = self::$db->query('SELECT id FROM disbursement_batches ORDER BY id LIMIT 1')->fetchColumn();
        if (!$batchId) {
            $batchId = self::$db->query("INSERT INTO disbursement_batches (batch_reference, status) VALUES ('B-1', 'executing') RETURNING id")->fetchColumn();
        }
        $index = 1 + (int)self::$db->query("SELECT COUNT(*) FROM disbursement_destinations WHERE batch_id = {$batchId}")->fetchColumn();
        $stmt = self::$db->prepare("INSERT INTO disbursement_destinations (batch_id, destination_index, institution, identifier, status)
                                    VALUES (:b, :i, 'ZURUBANK', '71000000', :s) RETURNING id");
        $stmt->execute([':b' => $batchId, ':i' => $index, ':s' => $destinationStatus]);
        $destinationId = $stmt->fetchColumn();

        $stmt = self::$db->prepare("
            INSERT INTO batch_execution_jobs (batch_id, destination_id, institution, idempotency_key, status, claimed_by, claimed_at, attempt_count)
            VALUES (:b, :d, 'ZURUBANK', :k, :s, :who, CASE WHEN CAST(:ago AS INT) IS NULL THEN NULL ELSE NOW() - make_interval(secs => CAST(:ago AS INT)) END, :a)
            RETURNING id
        ");
        $stmt->execute([
            ':b' => $batchId, ':d' => $destinationId, ':k' => "BATCHJOB_{$batchId}_{$index}", ':s' => $status,
            ':who' => $claimedSecondsAgo === null ? null : 'worker-7', ':ago' => $claimedSecondsAgo, ':a' => $attempts,
        ]);
        return (int)$stmt->fetchColumn();
    }

    private function jobRow(int $jobId): array
    {
        return self::$db->query("SELECT * FROM batch_execution_jobs WHERE id = {$jobId}")->fetch();
    }

    private function destinationRow(int $jobId): array
    {
        return self::$db->query("SELECT d.* FROM disbursement_destinations d JOIN batch_execution_jobs j ON j.destination_id = d.id WHERE j.id = {$jobId}")->fetch();
    }

    private function batchStatus(): string
    {
        return self::$db->query('SELECT status FROM disbursement_batches ORDER BY id LIMIT 1')->fetchColumn();
    }

    private function statusesWhere(string $condition): array
    {
        return self::$db->query("SELECT status FROM disbursement_batches WHERE {$condition} ORDER BY status")->fetchAll(PDO::FETCH_COLUMN);
    }
}
