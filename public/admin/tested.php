<?php
// test_zoom_real_tracking.php
// Calls the REAL SwapService directly (no subclass, no private-method
// override trickery) and checks the actual database rows for the exact
// reference used. This is immune to the two bugs found in the previous
// diagnostic script:
//   1. Private methods in PHP are not polymorphic - a subclass "override"
//      of a private method is never actually called by the parent's own
//      code, so any test relying on that pattern always reports false.
//   2. `$result ? 'success' : 'failed'` checks array truthiness, not the
//      actual 'status' key - it will print 'success' for ANY non-empty
//      result array, including status=pending/failed/etc.

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

$testConfig = [
    'source_institution' => 'ZURUBANK',
    'source_identifier' => '10000001',
    'source_asset_type' => 'ACCOUNT',
    'destination_institution' => 'SACCUSSALIS',
    'beneficiary_phone' => '+26770000000',
    'amount' => 1000,
    'currency' => 'BWP',
    'swap_type' => 'CASHOUT',
    'user_id' => 12,
];

echo "========================================\n";
echo "ZOOM TEST: real SwapService, real DB check\n";
echo "========================================\n\n";

$pdo = DBConnection::getConnection();
if (!$pdo) {
    die("❌ Failed to connect to database\n");
}
echo "✅ Database connected\n\n";

// ============================================================
// Capture PHP's error_log output for this request so we can see
// what SwapService's internal logger actually wrote, without
// needing to modify SwapService or subclass anything.
// ============================================================
$logCapturePath = sys_get_temp_dir() . '/swap_zoom_test_' . uniqid() . '.log';
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $logCapturePath);
echo "📝 Capturing error_log to: {$logCapturePath}\n\n";

$countryConfig = LoadCountry::getConfig();
if (empty($countryConfig)) {
    die("❌ Failed to load country config\n");
}

$reference = 'SWAP_ZOOM_' . time() . '_' . bin2hex(random_bytes(4));

$payload = [
    'swap_type' => $testConfig['swap_type'],
    'reference' => $reference,
    'idempotency_key' => 'IDEMP_' . $reference,
    'user_id' => $testConfig['user_id'],
    'from_institution' => $testConfig['source_institution'],
    'source_institution' => $testConfig['source_institution'],
    'asset_type' => $testConfig['source_asset_type'],
    'amount' => $testConfig['amount'],
    'currency' => $testConfig['currency'],
    'source_identifier' => $testConfig['source_identifier'],
    'source_identifier_type' => 'auto',
    'to_institution' => $testConfig['destination_institution'],
    'destination_institution' => $testConfig['destination_institution'],
    'beneficiary_phone' => $testConfig['beneficiary_phone'],
    'client_phone' => $testConfig['beneficiary_phone'],
    'delivery_method' => 'ATM',
    'destination_currency' => $testConfig['currency'],
];

echo "Reference: {$reference}\n";
echo "swap_type in payload: {$payload['swap_type']}\n\n";

// ============================================================
// Call the REAL SwapService directly - no subclass
// ============================================================
$swapService = new SwapService($pdo, $countryConfig, 'Botswana', null);

$result = null;
$exceptionThrown = null;

try {
    $result = $swapService->executeAtomicSwap($payload);
} catch (\Throwable $e) {
    $exceptionThrown = $e;
}

echo "============================================================\n";
echo "🔬 WHAT executeAtomicSwap() ACTUALLY RETURNED\n";
echo "============================================================\n";

if ($exceptionThrown) {
    echo "❌ Threw: " . get_class($exceptionThrown) . ": " . $exceptionThrown->getMessage() . "\n";
} else {
    echo "status field         : " . ($result['status'] ?? 'MISSING') . "\n";
    echo "reference field      : " . ($result['reference'] ?? 'MISSING') . "\n";
    echo "atomic_commit.status : " . ($result['atomic_commit']['status'] ?? 'MISSING') . "\n";
    echo "auth_id              : " . ($result['auth_id'] ?? 'none') . "\n";
    echo "hold_reference       : " . ($result['hold_reference'] ?? 'none') . "\n";

    // THIS is the check the old test got wrong - inspect the actual
    // status value, don't just test array truthiness.
    if (($result['status'] ?? null) === 'pending') {
        echo "\n✅ status=pending confirms this went through executeSignedCashout()\n";
        echo "   (CASHOUT always returns 'pending', never 'success')\n";
    } elseif (($result['status'] ?? null) === 'success') {
        echo "\n⚠️  status=success — this did NOT go through executeSignedCashout()!\n";
        echo "   CASHOUT never returns 'success'. This means executeAtomicSwap()\n";
        echo "   routed the payload to a DIFFERENT branch (most likely the\n";
        echo "   'default => executeSignedStandardSwap()' arm of the match()),\n";
        echo "   despite swap_type='CASHOUT' being set in the payload.\n";
        echo "   → Check whether the payload actually reaches executeAtomicSwap()\n";
        echo "     unmodified, and whether \$swapType is being reassigned anywhere\n";
        echo "     before the match() statement runs (e.g. by the multi-source /\n";
        echo "     multi-destination detection block above it).\n";
    }
}

echo "\n============================================================\n";
echo "🔍 REAL DATABASE STATE FOR THIS EXACT REFERENCE\n";
echo "============================================================\n";

$checks = [
    'swap_requests'            => "SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = :ref",
    'swap_transactions'        => "SELECT COUNT(*) FROM swap_transactions st JOIN swap_requests sr ON st.swap_id = sr.swap_id WHERE sr.swap_uuid = :ref",
    'hold_transactions'        => "SELECT COUNT(*) FROM hold_transactions WHERE swap_reference = :ref",
    'cashout_authorizations'   => "SELECT COUNT(*) FROM cashout_authorizations WHERE swap_reference = :ref",
    'audit_logs'                => "SELECT COUNT(*) FROM audit_logs WHERE entity_id = :ref",
];

foreach ($checks as $table => $sql) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':ref' => $reference]);
        $count = (int)$stmt->fetchColumn();
        echo ($count > 0 ? "✅ " : "❌ ") . str_pad($table, 25) . ": {$count} row(s)\n";
    } catch (\Throwable $e) {
        echo "⚠️  {$table}: query failed - " . $e->getMessage() . "\n";
    }
}

echo "\n============================================================\n";
echo "📝 CAPTURED error_log OUTPUT (SwapService's own logger)\n";
echo "============================================================\n";

// Restore error_log before reading, then dump anything relevant
ini_set('error_log', $previousErrorLog);

if (file_exists($logCapturePath)) {
    $logContent = file_get_contents($logCapturePath);
    $relevantLines = array_filter(
        explode("\n", $logContent),
        function ($line) use ($reference) {
            return stripos($line, 'tracking') !== false
                || stripos($line, 'populate') !== false
                || stripos($line, $reference) !== false
                || stripos($line, 'executeSignedCashout') !== false
                || stripos($line, 'DIAG') !== false;
        }
    );

    if (empty($relevantLines)) {
        echo "(no lines mentioning tracking/populate/DIAG/this reference were logged)\n";
        echo "Full captured log size: " . strlen($logContent) . " bytes — dumping last 3000 chars:\n";
        echo substr($logContent, -3000) . "\n";
    } else {
        foreach ($relevantLines as $line) {
            echo $line . "\n";
        }
    }

    @unlink($logCapturePath);
} else {
    echo "⚠️  No log file was created — error_log may be going to a different\n";
    echo "    sink than a plain file (e.g. syslog, stderr) on this environment.\n";
    echo "    If so, check your platform's log viewer for entries containing\n";
    echo "    '{$reference}' or 'tracking tables' around this timestamp.\n";
}

echo "\n============================================================\n";
echo "🏁 VERDICT\n";
echo "============================================================\n";

$dbHasData = false;
foreach (['swap_requests', 'hold_transactions'] as $t) {
    $stmt = $pdo->prepare(str_replace('swap_requests', $t, "SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = :ref"));
    // (re-run the two cheap checks directly rather than reusing $checks array keys)
}
$stmt = $pdo->prepare("SELECT COUNT(*) FROM swap_requests WHERE swap_uuid = :ref");
$stmt->execute([':ref' => $reference]);
$dbHasData = ((int)$stmt->fetchColumn()) > 0;

if ($exceptionThrown) {
    echo "❌ Swap threw an exception — nothing should be persisted, and that's correct/expected.\n";
} elseif ($dbHasData) {
    echo "✅ populateTrackingTables() DID run and DID persist data for this exact call.\n";
    echo "   If your earlier proxy-based test said otherwise, that test's detection\n";
    echo "   method was broken (see explanation above) — not the underlying code.\n";
} else {
    echo "❌ No swap_requests row exists for this reference — tracking genuinely did not persist.\n";
    echo "   Given status was '" . ($result['status'] ?? '?') . "', check:\n";
    echo "   - if status=success: this call never reached executeSignedCashout() at all\n";
    echo "   - if status=pending: populateTrackingTables() ran but its work was rolled\n";
    echo "     back — re-check for any unprotected write between the hold placement\n";
    echo "     and the commit that could still be poisoning the transaction\n";
}

echo "\n========================================\n";
echo "TEST COMPLETE\n";
echo "========================================\n";
