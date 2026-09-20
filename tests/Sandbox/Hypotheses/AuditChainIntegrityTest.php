<?php
declare(strict_types=1);

namespace Tests\Sandbox\Hypotheses;

use Tests\Sandbox\Support\SandboxTestCase;

/**
 * Evidence integrity (supports H2 reconciliation and H6 access control):
 * every audit_logs row is chained by
 *   entry_hash = SHA256(prev_hash || canonical)
 *   canonical  = json_encode({entity_type, entity_id, action, performed_at, performed_by_id})
 * (SwapService::writeAuditLogEntry). This proves the ledger of record is
 * tamper-evident: any silent edit to a historical row breaks the chain from
 * that row forward.
 *
 * The test builds its own rows in the real audit_logs table, recomputes the
 * chain exactly as the app does, then simulates tampering and shows the
 * verification detects it.
 */
final class AuditChainIntegrityTest extends SandboxTestCase
{
    private function canonical(array $r): string
    {
        return json_encode([
            'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'],
            'action' => $r['action'],
            'performed_at' => $r['performed_at'],
            'performed_by_id' => $r['performed_by_id'],
        ]);
    }

    /** entry_hash = SHA256(prev_hash || canonical), matching the app formula. */
    private function computeHash(?string $prev, array $r): string
    {
        return hash('sha256', ($prev ?? '') . $this->canonical($r));
    }

    public function testAuditLogHasHashChainColumns(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'audit_logs')) {
            $this->markTestSkipped('audit_logs not present');
        }
        $cols = $db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_name='audit_logs'"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('prev_hash', $cols, 'audit_logs must carry prev_hash');
        $this->assertContains('entry_hash', $cols, 'audit_logs must carry entry_hash');
        // entity_id must be textual so string swap references chain correctly
        $type = $db->query(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name='audit_logs' AND column_name='entity_id'"
        )->fetchColumn();
        $this->assertStringContainsStringIgnoringCase('char', (string) $type,
            'entity_id must be VARCHAR so swap references (SWAP_*) chain without a cast error');
    }

    public function testChainVerifiesAndDetectsTampering(): void
    {
        $db = $this->requireDb();
        if (!$this->tableExists($db, 'audit_logs')) {
            $this->markTestSkipped('audit_logs not present');
        }

        $db->beginTransaction();
        try {
            // Three chained entries, exactly as writeAuditLogEntry would create them.
            $prev = $db->query("SELECT entry_hash FROM audit_logs ORDER BY audit_id DESC LIMIT 1")
                ->fetchColumn() ?: null;

            $entries = [
                ['SWAP', 'SWAP_TESTCHAIN_1', 'SWAP_CREATED', 'transaction', '2026-09-20 10:00:00', 0],
                ['SWAP', 'SWAP_TESTCHAIN_1', 'HOLD_PLACED', 'transaction', '2026-09-20 10:00:05', 0],
                ['SWAP', 'SWAP_TESTCHAIN_1', 'CASHOUT_CONFIRMED', 'transaction', '2026-09-20 10:00:30', 0],
            ];
            $ins = $db->prepare(
                "INSERT INTO audit_logs
                   (entity_type, entity_id, action, category, performed_at, performed_by_id, prev_hash, entry_hash)
                 VALUES (?,?,?,?,?,?,?,?) RETURNING audit_id"
            );
            $ids = [];
            foreach ($entries as $e) {
                $row = [
                    'entity_type' => $e[0], 'entity_id' => $e[1], 'action' => $e[2],
                    'performed_at' => $e[4], 'performed_by_id' => $e[5],
                ];
                $hash = $this->computeHash($prev, $row);
                $ins->execute([$e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $prev, $hash]);
                $ids[] = $ins->fetchColumn();
                $prev = $hash;
            }

            // Verify the chain forward: each row's entry_hash must equal
            // SHA256(prev_hash || canonical) and its prev_hash must equal the
            // predecessor's entry_hash. performed_at is read back as the exact
            // 'Y-m-d H:i:s' string the app hashes (to_char), not the tz-suffixed
            // default render, so the recomputation matches writeAuditLogEntry.
            $rows = $db->query(
                "SELECT audit_id, entity_type, entity_id, action,
                        to_char(performed_at, 'YYYY-MM-DD HH24:MI:SS') AS performed_at,
                        performed_by_id, prev_hash, entry_hash
                 FROM audit_logs WHERE audit_id IN (" . implode(',', $ids) . ") ORDER BY audit_id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $priorHash = $rows[0]['prev_hash'];
            foreach ($rows as $r) {
                $this->assertSame($priorHash, $r['prev_hash'], 'prev_hash must equal predecessor entry_hash');
                $this->assertSame(
                    $this->computeHash($r['prev_hash'], $r),
                    $r['entry_hash'],
                    'entry_hash must equal SHA256(prev_hash || canonical) for a clean row'
                );
                $priorHash = $r['entry_hash'];
            }

            // Now attempt to tamper: change the action on the middle row
            // WITHOUT recomputing its hash, as a silent DB edit would. Two
            // acceptable outcomes, both of which the test accepts as
            // tamper-evidence:
            //   (a) the UPDATE succeeds -> the stored entry_hash no longer
            //       matches the recomputed hash (chain broken, detectable); or
            //   (b) the UPDATE is refused -> the table resists in-place edits.
            $tamperApplied = true;
            try {
                $db->prepare("UPDATE audit_logs SET action='HOLD_RELEASED' WHERE audit_id=?")
                   ->execute([$ids[1]]);
            } catch (\PDOException $e) {
                // FINDING F-004: an UPDATE trigger (fn_update_timestamp) fires on
                // audit_logs but the table has no updated_at column, so every
                // UPDATE errors out. That makes the table de-facto append-only
                // here (good for integrity) but by accident, not design, and it
                // will surface as an error to any legitimate writer that ever
                // updates a row. Recorded in FINDINGS.md.
                $tamperApplied = false;
                $this->addToAssertionCount(1);
            }

            if ($tamperApplied) {
                $tampered = $db->query(
                    "SELECT audit_id, entity_type, entity_id, action,
                            to_char(performed_at, 'YYYY-MM-DD HH24:MI:SS') AS performed_at,
                            performed_by_id, prev_hash, entry_hash
                     FROM audit_logs WHERE audit_id={$ids[1]}"
                )->fetch(\PDO::FETCH_ASSOC);
                $recomputed = $this->computeHash($tampered['prev_hash'], $tampered);
                $this->assertNotSame(
                    $tampered['entry_hash'],
                    $recomputed,
                    'A silent edit must make the stored entry_hash disagree with the recomputed hash'
                );
            } else {
                $this->assertTrue(true, 'audit_logs refused an in-place UPDATE (append-only in effect)');
            }
        } finally {
            $db->rollBack(); // leave the real table untouched
        }
    }
}
