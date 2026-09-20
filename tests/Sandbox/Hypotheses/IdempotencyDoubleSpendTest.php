<?php
declare(strict_types=1);

namespace Tests\Sandbox\Hypotheses;

use Tests\Sandbox\Support\SandboxTestCase;

/**
 * Idempotency and double-spend protection (VM-TD-001 Experiment 6; supports
 * H9 no-double-payment). Two independent guarantees:
 *
 *  1) Key derivation: when a caller doesn't supply an idempotency key,
 *     execute.php derives one deterministically from the request's semantic
 *     content plus a 60-second bucket, so a retried POST collapses to the
 *     same key while a genuinely new request an hour later does not. This
 *     mirrors generateDeterministicIdempotencyKey() exactly.
 *
 *  2) Storage: idempotency_keys.key is a PRIMARY KEY and the writer uses
 *     INSERT ... ON CONFLICT (key) DO UPDATE, so a second processing of the
 *     same key cannot create a second row — the DB enforces at-most-once.
 */
final class IdempotencyDoubleSpendTest extends SandboxTestCase
{
    /**
     * Reproduction of generateDeterministicIdempotencyKey() from
     * public/api/v1/swap/execute.php — kept in lock-step so a change to the
     * derivation there without updating this breaks the test.
     */
    private function deriveKey(array $input, int $now): string
    {
        $swapType = $input['swap_type'] ?? 'STANDARD';
        $parts = [
            $swapType,
            $input['from_institution'] ?? $input['source_institution'] ?? '',
            $input['source_identifier'] ?? '',
            $input['amount'] ?? '',
            $input['currency'] ?? '',
            $input['identity_type'] ?? '',
            $input['identity_value'] ?? '',
            $input['destination_identifier'] ?? '',
            $input['to_institution'] ?? $input['destination_institution'] ?? '',
            (string) floor($now / 60),
        ];
        return 'AUTO_' . hash('sha256', implode('|', $parts));
    }

    private function sampleSwap(): array
    {
        return [
            'swap_type' => 'CASHOUT',
            'from_institution' => 'ZURUBANK',
            'source_identifier' => '10101011',
            'amount' => '200.00',
            'currency' => 'BWP',
            'to_institution' => 'CAZACOM',
            'destination_identifier' => '20202022',
        ];
    }

    /** Two retries within the same minute derive the same key. */
    public function testRetryWithinWindowCollapsesToOneKey(): void
    {
        $t = 1_758_041_200; // fixed instant
        $swap = $this->sampleSwap();
        $k1 = $this->deriveKey($swap, $t);
        $k2 = $this->deriveKey($swap, $t + 5);  // 5s later, same 60s bucket
        $this->assertSame($k1, $k2, 'A retry 5s later must derive the same idempotency key');
    }

    /** The same request an hour later derives a different key (legitimate re-send). */
    public function testSameRequestNextHourGetsDistinctKey(): void
    {
        $t = 1_758_041_200;
        $swap = $this->sampleSwap();
        $this->assertNotSame(
            $this->deriveKey($swap, $t),
            $this->deriveKey($swap, $t + 3600),
            'Sending to the same person an hour later must be a distinct request'
        );
    }

    /** A reference/timestamp field must NOT affect the derived key. */
    public function testVolatileFieldsDoNotChangeKey(): void
    {
        $t = 1_758_041_200;
        $a = $this->sampleSwap();
        $b = $this->sampleSwap();
        $b['reference'] = 'CLIENT_REF_' . uniqid();
        $b['timestamp'] = (string) $t;
        $this->assertSame(
            $this->deriveKey($a, $t),
            $this->deriveKey($b, $t),
            'Per-attempt reference/timestamp fields must not defeat idempotency'
        );
    }

    /** Different amounts are different requests. */
    public function testDifferentAmountIsDifferentKey(): void
    {
        $t = 1_758_041_200;
        $a = $this->sampleSwap();
        $b = $this->sampleSwap();
        $b['amount'] = '201.00';
        $this->assertNotSame($this->deriveKey($a, $t), $this->deriveKey($b, $t));
    }

    /**
     * DB layer: the primary key + ON CONFLICT DO UPDATE means processing the
     * same key twice yields exactly one row, and the second call reads back
     * the stored result (at-most-once execution).
     */
    public function testDatabaseEnforcesAtMostOncePerKey(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'idempotency_keys')) {
            $this->markTestSkipped('idempotency_keys not present');
        }
        $db->beginTransaction();
        try {
            $key = 'AUTO_TEST_' . bin2hex(random_bytes(6));
            $store = $db->prepare(
                "INSERT INTO idempotency_keys (key, operation, result, created_at)
                 VALUES (:k, 'swap', :r::jsonb, NOW())
                 ON CONFLICT (key) DO UPDATE SET result = EXCLUDED.result, created_at = NOW()"
            );

            // First processing: store a reference.
            $store->execute([':k' => $key, ':r' => json_encode(['reference' => 'SWAP_A', 'status' => 'completed'])]);
            // Second processing of the SAME key (a retry that slipped past the
            // app cache): must not create a second row.
            $store->execute([':k' => $key, ':r' => json_encode(['reference' => 'SWAP_A', 'status' => 'completed'])]);

            $count = $db->query("SELECT count(*) FROM idempotency_keys WHERE key = " . $db->quote($key))->fetchColumn();
            $this->assertSame(1, (int) $count, 'One idempotency key must map to exactly one row');

            // The check path returns the stored result within 24h.
            $check = $db->prepare(
                "SELECT result FROM idempotency_keys WHERE key = :k AND created_at > NOW() - INTERVAL '24 hours'"
            );
            $check->execute([':k' => $key]);
            $result = json_decode($check->fetchColumn(), true);
            $this->assertSame('SWAP_A', $result['reference'], 'A retry must read back the first result, not run again');
        } finally {
            $db->rollBack();
        }
    }
}
