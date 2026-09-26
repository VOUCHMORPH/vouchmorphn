<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../public/admin/enterprise/partials/batch_display.php';

/**
 * A batch vanished from every enterprise view but the Owner's the moment it
 * was executed: each role's status list stopped at 'approved'/'completed',
 * and asynchronous execution moves a batch through 'executing' to
 * 'completed' or 'partially_completed'. partials/batch_display.php now owns
 * the lists; these tests hold them to every status the code writes.
 */
class EnterpriseBatchDisplayTest extends TestCase
{
    /** Statuses a batch can end in without ever being approved. */
    private const TERMINAL_WITHOUT_APPROVAL = ['rejected', 'cancelled'];

    public function testEveryStatusTheCodeWritesBelongsToAGroup(): void
    {
        $written = $this->statusesWrittenToDisbursementBatches();
        $this->assertContains('executing', $written, 'the scan must see the execution queue');
        $this->assertContains('partially_completed', $written, 'the scan must see batch finalisation');

        $known = array_merge(
            vm_batch_statuses('before_approval'),
            vm_batch_statuses('approved_onward'),
            self::TERMINAL_WITHOUT_APPROVAL
        );
        foreach ($written as $status) {
            $this->assertContains($status, $known, "disbursement_batches.status '{$status}' is written somewhere but no role list knows it");
        }
    }

    public function testEverythingAfterApprovalStaysVisibleToWhoeverSawItApproved(): void
    {
        $approvedOnward = vm_batch_statuses('approved_onward');
        foreach (['executing', 'completed', 'partially_completed'] as $status) {
            $this->assertContains($status, $approvedOnward);
            $this->assertContains($status, vm_batch_statuses('released'), 'oversight roles see money that has been released');
        }
        $this->assertNotContains('approved', vm_batch_statuses('released'), 'approved is not yet released');
        $this->assertContains('partially_completed', vm_batch_statuses('finished'), 'the Completed tab holds a batch that finished with failures');
    }

    public function testStatusSqlIsCaseInsensitive(): void
    {
        $this->assertSame(
            "LOWER(status) IN ('draft', 'pending_approval')",
            vm_batch_status_in(['DRAFT', 'pending_approval', 'Draft'])
        );
        $this->assertSame("LOWER(b.status) IN ('approved')", vm_batch_status_in(['approved'], 'b.status'));
        $this->assertSame('1=0', vm_batch_status_in([]), 'no statuses means no rows, not every row');
    }

    public function testStatusSqlEscapesQuotes(): void
    {
        $this->assertSame("LOWER(status) IN ('it''s')", vm_batch_status_in(["it's"]));
    }

    public function testUnknownGroupIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        vm_batch_statuses('whatever');
    }

    public function testDestinationSummaryNamesWhereTheMoneyGoes(): void
    {
        $this->assertSame('ZURUBANK · 1 recipient', vm_destination_summary('ZURUBANK', 1));
        $this->assertSame('SACCUSSALIS, ZURUBANK · 12 recipients', vm_destination_summary('SACCUSSALIS,ZURUBANK', '12'));
        $this->assertSame('ID claim, ZURUBANK · 2 recipients', vm_destination_summary('IDENTITY_RECIPIENT,ZURUBANK', 2));
        $this->assertSame('No recipients yet', vm_destination_summary(null, 0));
        $this->assertSame('Unknown · 3 recipients', vm_destination_summary(null, 3), 'rows with no institution recorded');
    }

    public function testDestinationLabel(): void
    {
        $this->assertSame('ID claim', vm_destination_label('IDENTITY_RECIPIENT'));
        $this->assertSame('ZURUBANK', vm_destination_label(' ZURUBANK '));
        $this->assertSame('Unknown', vm_destination_label(null));
    }

    /** Every literal status any UPDATE/INSERT writes into disbursement_batches, lowercased. */
    private function statusesWrittenToDisbursementBatches(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [$root . '/src/Domain/Services/BatchExecutionQueueService.php', $root . '/worker.php'];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/admin/enterprise', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $statuses = [];
        foreach ($files as $path) {
            $source = file_get_contents($path);
            // UPDATE disbursement_batches SET ... status = 'x' ... WHERE
            preg_match_all("/UPDATE\\s+disbursement_batches\\s+SET(.*?)WHERE/is", $source, $updates);
            foreach ($updates[1] as $set) {
                preg_match_all("/\\bstatus\\s*=\\s*'([A-Za-z_]+)'/", $set, $m);
                $statuses = array_merge($statuses, $m[1]);
            }
            // INSERT INTO disbursement_batches (...) VALUES (... 'x' ...) - the status literal.
            preg_match_all("/INSERT\\s+INTO\\s+disbursement_batches\\s*\\((.*?)\\)\\s*VALUES\\s*\\((.*?)\\)/is", $source, $inserts, PREG_SET_ORDER);
            foreach ($inserts as [, $columns, $values]) {
                $columns = array_map('trim', explode(',', $columns));
                $values = array_map('trim', explode(',', $values));
                $i = array_search('status', $columns, true);
                if ($i !== false && isset($values[$i]) && preg_match("/^'([A-Za-z_]+)'$/", $values[$i], $v)) {
                    $statuses[] = $v[1];
                }
            }
        }
        // BatchExecutionQueueService::maybeFinalizeBatch() binds its status.
        $service = file_get_contents($root . '/src/Domain/Services/BatchExecutionQueueService.php');
        preg_match("/\\\$finalStatus\\s*=.*?\\?\\s*'([a-z_]+)'\\s*:\\s*'([a-z_]+)'/", $service, $final);
        $statuses = array_merge($statuses, array_slice($final, 1));

        return array_values(array_unique(array_map('strtolower', $statuses)));
    }
}
