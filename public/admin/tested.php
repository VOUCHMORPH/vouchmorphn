<?php
// tested.php - Full flow test for identity swap split

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

header('Content-Type: application/json');

echo "=== TEST REMAINDER FLOW ===\n\n";

try {
    $db = DBConnection::getConnection();
    $country = 'Botswana';
    $swapService = new SwapService($db, LoadCountry::getConfig(), $country);

    // ============================================================
    // STEP 1: Create identity swap
    // ============================================================
    echo "STEP 1: Creating identity swap...\n";
    
    $testId = 'TEST_ID_' . time();
    $swapPayload = [
        'swap_type' => 'IDENTITY',
        'reference' => 'TEST_SWAP_' . time(),
        'amount' => 1500.00,
        'currency' => 'BWP',
        'destination_currency' => 'BWP',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'source_identifier' => 'SAV00000018',
        'source_identifier_type' => 'account',
        'asset_type' => 'ACCOUNT',
        'user_id' => 1,
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'identity_type' => 'national_id',
        'identity_value' => $testId,
        'notification_phone' => '+26770000000',
        'beneficiary_phone' => '+26770000000'
    ];

    $result = $swapService->executeAtomicSwap($swapPayload);
    echo "Identity swap created:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

    $swapReference = $result['swap_reference'];
    echo "Swap Reference: $swapReference\n";
    echo "Identity: $testId\n\n";

    // Get the identity swap record to find the OTP
    $identitySwap = $swapService->getIdentitySwapByReference($swapReference);
    
    // ============================================================
    // STEP 2: Get OTP PIN from message_outbox
    // ============================================================
    $pin = null;
    
    if ($identitySwap && $identitySwap['claim_type'] === 'otp_pin') {
        echo "========================================\n";
        echo "OTP PIN GENERATED\n";
        echo "========================================\n";
        echo "Sent to: " . $identitySwap['otp_pin_sent_to'] . "\n";
        echo "OTP Hash: " . $identitySwap['otp_pin_hash'] . "\n";
        
        // Try to find the actual PIN from message_outbox
        $stmt = $db->prepare("
            SELECT payload FROM message_outbox 
            WHERE destination = :phone 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([':phone' => $identitySwap['otp_pin_sent_to']]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($message) {
            $payload = json_decode($message['payload'], true);
            if (isset($payload['message'])) {
                // Extract PIN from message
                preg_match('/PIN: (\d{6})/', $payload['message'], $matches);
                if (isset($matches[1])) {
                    echo "Found OTP PIN from message_outbox: " . $matches[1] . "\n";
                    $pin = $matches[1];
                }
            }
        }
        
        if (!$pin) {
            echo "\n⚠️ Could not extract PIN from message_outbox.\n";
            echo "Please check the SMS sent to +26770000000\n";
            echo "The SMS should contain a 6-digit PIN\n\n";
            
            // Try to get from the SMS logs
            $logFile = '/var/log/php_errors.log';
            if (file_exists($logFile)) {
                $logs = shell_exec("tail -100 $logFile | grep -i 'PIN:'");
                if ($logs) {
                    echo "Found in logs:\n$logs\n";
                    preg_match('/PIN: (\d{6})/', $logs, $matches);
                    if (isset($matches[1])) {
                        $pin = $matches[1];
                        echo "PIN from logs: $pin\n";
                    }
                }
            }
        }
        
        // If still no PIN, we'll use a default for testing
        if (!$pin) {
            echo "\n⚠️ No PIN found. Using default '123456' for testing.\n";
            echo "WARNING: This will likely fail if the OTP doesn't match.\n";
            $pin = '123456';
        }
        echo "\n";
    } else {
        echo "No OTP PIN generated (claim_type: " . ($identitySwap['claim_type'] ?? 'unknown') . ")\n";
        die("Cannot proceed without PIN\n");
    }

    // ============================================================
    // STEP 3: Get agent destination accounts
    // ============================================================
    echo "\nSTEP 3: Getting agent destination accounts...\n";
    $agentDestinations = $swapService->getApprovedAgentDestinations(12);
    echo "Agent destinations:\n";
    echo json_encode($agentDestinations, JSON_PRETTY_PRINT) . "\n\n";

    if (empty($agentDestinations)) {
        die("No agent destination accounts found for user 12\n");
    }

    $destinationAccountId = $agentDestinations[0]['id'];
    echo "Using destination account: $destinationAccountId\n\n";

    // ============================================================
    // STEP 4: Test finalizeIdentityClaimSplit with remainder
    // ============================================================
    echo "STEP 4: Testing finalizeIdentityClaimSplit with remainder...\n";
    echo "Swap Reference: $swapReference\n";
    echo "PIN: $pin\n";
    echo "Destination Account ID: $destinationAccountId\n";
    echo "Cash Now Amount: 1000\n";
    echo "Remainder should be: 500\n\n";

    try {
        $result = $swapService->finalizeIdentityClaimSplit(
            $swapReference,
            $pin,
            'agent',
            12,
            $destinationAccountId,
            1000.00,
            12
        );

        echo "finalizeIdentityClaimSplit result:\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";

        // ============================================================
        // STEP 5: Check if remainder was created
        // ============================================================
        echo "STEP 5: Checking if remainder swap was created...\n";
        
        $stmt = $db->prepare("
            SELECT * FROM identity_swap_holds 
            WHERE swap_reference LIKE :pattern
            ORDER BY hold_id DESC
        ");
        $stmt->execute([':pattern' => $swapReference . '%']);
        $allSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "All swaps for this reference:\n";
        echo json_encode($allSwaps, JSON_PRETTY_PRINT) . "\n\n";

        // Check for remainder swap
        $remainderSwaps = array_filter($allSwaps, function($s) use ($swapReference) {
            return strpos($s['swap_reference'], '_REMAIN_') !== false;
        });

        if (!empty($remainderSwaps)) {
            echo "✅ REMAINDER SWAP CREATED SUCCESSFULLY!\n";
            echo "Remainder swap details:\n";
            echo json_encode($remainderSwaps, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "❌ NO REMAINDER SWAP FOUND!\n";
            echo "The remainder was not automatically swapped back to identity.\n";
        }

        // ============================================================
        // STEP 6: Check the final status
        // ============================================================
        echo "\nSTEP 6: Checking final status...\n";
        $stmt = $db->prepare("SELECT * FROM identity_swap_holds WHERE swap_reference = :swap_ref");
        $stmt->execute([':swap_ref' => $swapReference]);
        $finalStatus = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "Final status of original swap:\n";
        echo json_encode($finalStatus, JSON_PRETTY_PRINT) . "\n";

    } catch (Exception $e) {
        echo "❌ ERROR in finalizeIdentityClaimSplit:\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    }

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
