<?php
/**
 * test.php — run this on the server (same environment SwapService runs
 * in), then paste the full output back. Exercises:
 *   1. Single-hold claim still works after the atomic-commit fix
 *   2. Partial claim + remainder re-swap (the original Problem 1)
 *   3. Aggregated multi-source claim (Problem 3)
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// ---- BOOTSTRAP ----
$db = DBConnection::getConnection();
$country = 'Botswana';
$config = LoadCountry::getConfig();
$swapService = new SwapService($db, $config, $country);
// ---------------------------------------------------

$results = [];

function record(array &$results, string $name, bool $pass, $detail = null): void {
    $results[] = ['test' => $name, 'pass' => $pass, 'detail' => $detail];
    echo ($pass ? "[PASS] " : "[FAIL] ") . $name . "\n";
    if ($detail) echo "        " . (is_string($detail) ? $detail : json_encode($detail)) . "\n";
}

// ---------------------------------------------------------------
// TEST 1: Single identity swap, full claim, no atomic-swap leak
// ---------------------------------------------------------------
try {
    $swapRef1 = 'TEST_SINGLE_' . time();
    $initResult = $swapService->executeAtomicSwap([
        'swap_type' => 'IDENTITY',
        'reference' => $swapRef1,
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'source_identifier' => 'SAV00000018',
        'asset_type' => 'ACCOUNT',
        'amount' => 500,
        'currency' => 'BWP',
        'identity_type' => 'national_id',
        'identity_value' => 'TEST_ID_001',
        'notification_phone' => '+26770000000',
    ]);
    record($results, 'Test 1a: initiate single identity swap', $initResult['status'] === 'pending_identity_confirmation', $initResult);

    // Fetch the OTP hash directly from DB for the test (real flow reads SMS)
    $stmt = $db->prepare("SELECT otp_pin_hash FROM identity_swap_holds WHERE swap_reference = :ref");
    // NOTE: can't reverse a hash - in a real test environment, seed a known
    // PIN via a test-only code path, or intercept the SMS. Placeholder:
    $testPin = getenv('TEST_KNOWN_OTP') ?: '000000';

    $finalizeResult = $swapService->confirmAndFinalizeIdentitySwap([
        'swap_reference' => $swapRef1,
        'pin' => $testPin,
        'confirmed_by_type' => 'agent',
        'confirmed_by_id' => 1,
        'identity_document_verified' => true,
        'destination_type' => 'DEPOSIT',
        'destination_institution' => 'ZURUBANK',
        'destination_identifier' => 'SAV00000018',
        'destination_identifier_type' => 'account',
    ]);
    record($results, 'Test 1b: finalize completes without "Already in atomic swap"', $finalizeResult['status'] === 'completed', $finalizeResult);
} catch (Exception $e) {
    record($results, 'Test 1: single claim', false, $e->getMessage());
}

// ---------------------------------------------------------------
// TEST 2: Partial claim via finalizeIdentityClaimSplit - remainder
// must actually re-swap (this was the original bug)
// ---------------------------------------------------------------
try {
    $swapRef2 = 'TEST_PARTIAL_' . time();
    $swapService->executeAtomicSwap([
        'swap_type' => 'IDENTITY',
        'reference' => $swapRef2,
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'source_identifier' => 'SAV00000018',
        'asset_type' => 'ACCOUNT',
        'amount' => 500,
        'currency' => 'BWP',
        'identity_type' => 'national_id',
        'identity_value' => 'TEST_ID_002',
        'notification_phone' => '+26770000000',
    ]);

    $testPin = getenv('TEST_KNOWN_OTP') ?: '000000';
    $splitResult = $swapService->finalizeIdentityClaimSplit(
        $swapRef2, $testPin, 'agent', 1,
        /* destinationAccountId */ 1, // adjust to a real agent_destination_accounts.id
        /* cashNowAmount */ 300.0
    );

    $remainderOk = ($splitResult['remainder_reswap']['status'] ?? null) === 'completed';
    record($results, 'Test 2: partial claim remainder actually re-swaps', $remainderOk, $splitResult['remainder_reswap'] ?? 'no remainder_reswap key');
} catch (Exception $e) {
    record($results, 'Test 2: partial claim', false, $e->getMessage());
}

// ---------------------------------------------------------------
// TEST 3: Aggregated multi-source claim
// ---------------------------------------------------------------
try {
    $identityValue = 'TEST_ID_003';
    $swapService->executeAtomicSwap([
        'swap_type' => 'IDENTITY', 'reference' => 'TEST_AGG_A_' . time(),
        'from_institution' => 'ZURUBANK', 'source_institution' => 'ZURUBANK',
        'source_identifier' => 'SAV00000018', 'asset_type' => 'ACCOUNT',
        'amount' => 500, 'currency' => 'BWP',
        'identity_type' => 'national_id', 'identity_value' => $identityValue,
        'notification_phone' => '+26770000000',
    ]);
    sleep(1);
    $swapService->executeAtomicSwap([
        'swap_type' => 'IDENTITY', 'reference' => 'TEST_AGG_B_' . time(),
        'from_institution' => 'ZURUBANK', 'source_institution' => 'ZURUBANK',
        'source_identifier' => 'SAV00000018', 'asset_type' => 'ACCOUNT',
        'amount' => 600, 'currency' => 'BWP',
        'identity_type' => 'national_id', 'identity_value' => $identityValue,
        'notification_phone' => '+26770000000',
    ]);

    $agg = $swapService->getAggregatedIdentityBalance('national_id', $identityValue);
    record($results, 'Test 3a: aggregate shows 1100 across 2 swaps', $agg && (float)$agg['total_amount'] === 1100.0 && (int)$agg['swap_count'] === 2, $agg);

    $testPin = getenv('TEST_KNOWN_OTP') ?: '000000';
    $claimResult = $swapService->finalizeAggregatedIdentityClaim(
        'national_id', $identityValue, $testPin, 'agent', 1,
        /* destinationAccountId */ 1,
        /* cashNowAmount */ 1100.0
    );
    record($results, 'Test 3b: aggregated claim deposits both sources', ($claimResult['status'] ?? null) === 'success' && count($claimResult['successful_deposits']) === 2, $claimResult);
} catch (Exception $e) {
    record($results, 'Test 3: aggregated claim', false, $e->getMessage());
}

echo "\n=== SUMMARY ===\n";
$passed = count(array_filter($results, fn($r) => $r['pass']));
echo "{$passed}/" . count($results) . " passed\n";
