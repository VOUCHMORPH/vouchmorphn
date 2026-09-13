<?php

declare(strict_types=1);

namespace DASHBOARD;

// FIX 1: Start output buffering at the VERY TOP
ob_start();

// FIX 2: Define country code FIRST - before anything else that uses it
$countryCode = $_GET['country'] ?? $_SESSION['country'] ?? 'BW';

// FIX 3: Dynamic root detection with error handling
if (!defined('APP_ROOT')) {
    define('APP_ROOT', rtrim(realpath(__DIR__ . '/../../'), '/') ?: '/var/www/html');
}

// FIX 4: Load Composer autoloader with error suppression
@include_once APP_ROOT . '/vendor/autoload.php';

// FIX 5: Load Dotenv safely
if (class_exists('\Dotenv\Dotenv')) {
    // Try root .env first
    if (file_exists(APP_ROOT . '/.env')) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(APP_ROOT);
            $dotenv->load();
        } catch (\Exception $e) {
            error_log("Dotenv root load failed: " . $e->getMessage());
        }
    }
    
    // Try country-specific .env
    $countryEnv = APP_ROOT . "/src/CORE_CONFIG/countries/{$countryCode}/.env_{$countryCode}";
    if (file_exists($countryEnv)) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(dirname($countryEnv), basename($countryEnv));
            $dotenv->load();
        } catch (\Exception $e) {
            error_log("Dotenv country load failed: " . $e->getMessage());
        }
    }
}

// ======================================================
// RAILWAY COMPATIBILITY FIXES
// ======================================================

// Fix 1: Set correct error logging for Railway
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Railway log path
$log_path = sys_get_temp_dir() . '/vouchmorph_errors.log';
ini_set('error_log', $log_path);
ini_set('log_errors', '1');

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [$errno]: $errstr in $errfile line $errline");
    return true;
});

// Fix 2: Database connection test
$db_status = '❌ Not Connected';
$db_error = null;
$db = null;

try {
    if (!class_exists('\Core\Database\DBConnection')) {
        throw new \Exception('DBConnection class not found - check autoloader');
    }
    
    $db = \Core\Database\DBConnection::getConnection();
    
    if ($db) {
        $db_status = '✅ Connected';
        $test = $db->query("SELECT 1 as test")->fetch();
        if ($test) {
            $db_status = '✅ Connected (Working)';
        }
    }
} catch (\Exception $e) {
    $db_error = $e->getMessage();
    error_log("Regulation demo DB error: " . $db_error);
}

// Fix 3: Environment info
$environment = getenv('APP_ENV') ?: 'production';
$railway_url = getenv('RAILWAY_PUBLIC_DOMAIN') ?: 'Not set';
$database_url_configured = getenv('DATABASE_URL') ? 'Yes' : 'No';

// Fix 4: Base URL
$base_url = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . 
            ($_SERVER['HTTP_HOST'] ?? 'localhost');

// Fix 5: Safe number formatting function
function safe_number_format($number, $decimals = 2) {
    if (is_bool($number) || $number === null || $number === '') {
        return '0.00';
    }
    return number_format((float)$number, $decimals, '.', '');
}

function debug_log($message) {
    error_log("[DEBUG] " . $message);
}

// ============================================================================
// PHONE NUMBER FORMATTING FUNCTION
// ============================================================================

function formatPhoneNumberForSwap($phoneNumber, $countryCode = 'BW') {
    // Remove any non-numeric characters
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    
    // Country code mappings
    $countryCodes = [
        'BW' => '267',  // Botswana
        'KE' => '254',  // Kenya
        'NG' => '234',  // Nigeria
    ];
    
    $code = $countryCodes[$countryCode] ?? '267';
    
    // If number already starts with country code
    if (substr($cleanNumber, 0, strlen($code)) === $code) {
        return '+' . $cleanNumber;
    }
    
    // If number starts with 0 (local format), remove the 0
    if (substr($cleanNumber, 0, 1) === '0') {
        $cleanNumber = substr($cleanNumber, 1);
    }
    
    // Add country code and plus sign
    return '+' . $code . $cleanNumber;
}

error_log("=== regulationdemo.php accessed at " . date('Y-m-d H:i:s') . " ===");

// Fix 6: Load bootstrap with correct path
$bootstrapPath = __DIR__ . '/../../src/bootstrap.php';
if (file_exists($bootstrapPath)) {
    require_once $bootstrapPath;
} else {
    error_log("CRITICAL: bootstrap.php not found at " . $bootstrapPath);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fix 7: AJAX detection
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
          && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ============================================================================
// DYNAMIC CONFIGURATION LOADING
// ============================================================================

$selectedView = $_GET['view'] ?? 'dashboard';
$regulatorView = $_GET['regulator_view'] ?? 'supervisory';
$_SESSION['country'] = $countryCode;

$configPath = __DIR__ . "/../../src/CORE_CONFIG/countries/{$countryCode}/";

// ============================================================================
// LOAD PARTICIPANTS FROM JSON FILE
// ============================================================================

$participantsFile = $configPath . "participants_{$countryCode}.json";
$participantsData = [];

if (file_exists($participantsFile)) {
    $jsonContent = file_get_contents($participantsFile);
    $data = json_decode($jsonContent, true);
    
    if (json_last_error() === JSON_ERROR_NONE && isset($data['participants'])) {
        $participantsData = $data['participants'];
    }
}

$participants = [];
$participantsByWalletType = [];

$fieldRequirements = [
    'source' => [
        'account' => ['account_number'],
        'wallet' => ['phone', 'pin'],
        'e-wallet' => ['phone'],
        'card' => ['card_number', 'pin'],
        'voucher' => ['phone', 'voucher_number', 'pin']
    ],
    'destination' => [
        'cashout' => [
            'atm' => ['phone'],
            'agent' => ['phone']
        ],
        'deposit' => [
            'account' => ['account_number'],
            'wallet' => ['phone'],
            'card' => ['card_number'],
            'e-wallet' => ['phone']
        ]
    ]
];

foreach ($participantsData as $key => $participant) {
    if (!isset($participant['type'])) continue;

    $participantInfo = [
        'participant_id' => $key,
        'participant_name' => $key,
        'type' => $participant['type'] ?? 'UNKNOWN',
        'category' => $participant['category'] ?? 'UNKNOWN',
        'provider_code' => $participant['provider_code'] ?? '',
        'status' => $participant['status'] ?? 'ACTIVE',
        'capabilities' => $participant['capabilities'] ?? []
    ];
    
    $participants[] = $participantInfo;
    
    $walletTypes = $participant['capabilities']['wallet_types'] ?? ['ACCOUNT'];
    foreach ($walletTypes as $type) {
        $typeLower = strtolower($type);
        if (!isset($participantsByWalletType[$typeLower])) {
            $participantsByWalletType[$typeLower] = [];
        }
        $participantsByWalletType[$typeLower][] = $participantInfo;
    }
}

if (isset($participantsByWalletType['e-wallet']) || isset($participantsByWalletType['ewallet'])) {
    $participantsByWalletType['e-wallet'] = array_merge(
        $participantsByWalletType['e-wallet'] ?? [],
        $participantsByWalletType['ewallet'] ?? []
    );
}

foreach (['account', 'wallet', 'e-wallet', 'card', 'atm', 'agent', 'voucher'] as $type) {
    if (!isset($participantsByWalletType[$type])) {
        $participantsByWalletType[$type] = [];
    }
}

// ============================================================================
// DATABASE QUERIES - Enhanced for settlement reporting
// ============================================================================

$heartbeat = ['status' => 'ACTIVE', 'latency_ms' => 45, 'system_load' => 0.23, 'created_at' => date('Y-m-d H:i:s')];
$netPositions = [];
$pendingSettlements = [];
$recentSwaps = [];
$activeVouchers = [];
$totalVoucherAmount = 0;
$settlementQueue = [];
$messageOutbox = [];
$ledgerBalances = [];
$recentAudits = [];
$regReports = [];
$totalExposure = 0;
$totalFeesCollected = 0;
$feesByType = [];
$feeCollections = [];
$settlementSummary = [];
$weeklySettlements = [];
$monthlySettlements = [];

if ($db) {
    try {
        debug_log("Database connected, running queries");
        
        $tableExists = function($tableName) use ($db) {
            try {
                $stmt = $db->prepare("
                    SELECT EXISTS (
                        SELECT 1 
                        FROM information_schema.tables 
                        WHERE table_schema = 'public' 
                        AND table_name = ?
                    )
                ");
                $stmt->execute([$tableName]);
                return $stmt->fetchColumn();
            } catch (\Exception $e) {
                return false;
            }
        };
        
        if ($tableExists('supervisory_heartbeat')) {
            $stmt = $db->query("SELECT status, latency_ms, system_load, created_at FROM supervisory_heartbeat ORDER BY created_at DESC LIMIT 1");
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result) $heartbeat = $result;
        }

        // NET POSITIONS - For settlement netting
        if ($tableExists('settlement_queue')) {
            $stmt = $db->query("
                SELECT 
                    debtor, 
                    creditor, 
                    SUM(amount) as amount,
                    COUNT(*) as transaction_count
                FROM settlement_queue 
                WHERE amount > 0 
                GROUP BY debtor, creditor 
                ORDER BY amount DESC
            ");
            $netPositions = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalExposure = array_sum(array_column($netPositions, 'amount'));
        }

        // PENDING SETTLEMENTS
        if ($tableExists('settlement_messages')) {
            $stmt = $db->query("
                SELECT 
                    message_id, 
                    transaction_id, 
                    from_participant, 
                    to_participant, 
                    amount, 
                    type, 
                    status, 
                    created_at, 
                    metadata 
                FROM settlement_messages 
                WHERE status = 'PENDING' 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $pendingSettlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // RECENT SWAPS
        if ($tableExists('swap_requests')) {
            $stmt = $db->query("
                SELECT 
                    swap_id, 
                    swap_uuid::text as swap_uuid, 
                    amount, 
                    status, 
                    created_at,
                    metadata->>'source_institution' as source_institution,
                    metadata->>'destination_institution' as destination_institution
                FROM swap_requests 
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            $recentSwaps = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // ACTIVE VOUCHERS
        if ($tableExists('swap_vouchers')) {
            $stmt = $db->query("
                SELECT 
                    voucher_id, 
                    code_suffix, 
                    amount, 
                    claimant_phone, 
                    expiry_at, 
                    status 
                FROM swap_vouchers 
                WHERE status = 'ACTIVE' AND expiry_at > NOW() 
                ORDER BY created_at DESC
            ");
            $activeVouchers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalVoucherAmount = array_sum(array_column($activeVouchers, 'amount'));
        }

        // SETTLEMENT QUEUE
        if ($tableExists('settlement_queue')) {
            $stmt = $db->query("
                SELECT 
                    id, 
                    debtor, 
                    creditor, 
                    amount, 
                    created_at 
                FROM settlement_queue 
                WHERE amount > 0 
                ORDER BY created_at ASC 
                LIMIT 50
            ");
            $queueItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($queueItems as $item) {
                $settlementQueue[] = [
                    'queue_id' => $item['id'],
                    'debtor_name' => $item['debtor'],
                    'creditor_name' => $item['creditor'],
                    'amount' => $item['amount'],
                    'created_at' => $item['created_at']
                ];
            }
        }

        // MESSAGE OUTBOX
        if ($tableExists('message_outbox')) {
            $stmt = $db->query("
                SELECT 
                    message_id, 
                    channel, 
                    destination, 
                    status, 
                    created_at 
                FROM message_outbox 
                WHERE status IN ('PENDING', 'SENT') 
                ORDER BY created_at ASC 
                LIMIT 50
            ");
            $messageOutbox = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // AUDIT LOGS
        if ($tableExists('audit_logs')) {
            $stmt = $db->query("
                SELECT 
                    entity_type, 
                    action, 
                    category, 
                    severity, 
                    performed_at 
                FROM audit_logs 
                ORDER BY performed_at DESC 
                LIMIT 20
            ");
            $recentAudits = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // REGULATOR OUTBOX
        if ($tableExists('regulator_outbox')) {
            $stmt = $db->query("
                SELECT 
                    id, 
                    report_id, 
                    status, 
                    attempts, 
                    integrity_hash, 
                    created_at, 
                    last_attempt 
                FROM regulator_outbox 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            $regReports = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // FEE COLLECTIONS
        if ($tableExists('swap_fee_collections')) {
            $stmt = $db->query("
                SELECT 
                    fee_id, 
                    fee_type, 
                    total_amount, 
                    source_institution, 
                    destination_institution, 
                    split_config, 
                    vat_amount, 
                    status, 
                    collected_at 
                FROM swap_fee_collections 
                WHERE status = 'COLLECTED' 
                ORDER BY collected_at DESC 
                LIMIT 50
            ");
            $feeCollections = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalFeesCollected = array_sum(array_column($feeCollections, 'total_amount'));
            foreach ($feeCollections as $fee) {
                $type = $fee['fee_type'];
                if (!isset($feesByType[$type])) $feesByType[$type] = 0;
                $feesByType[$type] += $fee['total_amount'];
            }
        }

        // SETTLEMENT SUMMARY - For reports
        if ($tableExists('settlement_queue')) {
            // Weekly summary
            $stmt = $db->query("
                SELECT 
                    DATE_TRUNC('week', created_at) as week_start,
                    COUNT(*) as transaction_count,
                    SUM(amount) as total_amount,
                    COUNT(DISTINCT debtor) as debtor_count,
                    COUNT(DISTINCT creditor) as creditor_count
                FROM settlement_queue
                WHERE created_at >= NOW() - INTERVAL '30 days'
                GROUP BY DATE_TRUNC('week', created_at)
                ORDER BY week_start DESC
            ");
            $weeklySettlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Monthly summary
            $stmt = $db->query("
                SELECT 
                    DATE_TRUNC('month', created_at) as month_start,
                    COUNT(*) as transaction_count,
                    SUM(amount) as total_amount,
                    COUNT(DISTINCT debtor) as debtor_count,
                    COUNT(DISTINCT creditor) as creditor_count
                FROM settlement_queue
                WHERE created_at >= NOW() - INTERVAL '90 days'
                GROUP BY DATE_TRUNC('month', created_at)
                ORDER BY month_start DESC
            ");
            $monthlySettlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Net settlement positions (netted)
            $stmt = $db->query("
                WITH netted AS (
                    SELECT 
                        debtor,
                        creditor,
                        SUM(amount) as gross_amount
                    FROM settlement_queue
                    WHERE created_at >= NOW() - INTERVAL '7 days'
                    GROUP BY debtor, creditor
                )
                SELECT 
                    debtor,
                    creditor,
                    gross_amount,
                    CASE 
                        WHEN gross_amount > 0 THEN 'PAYABLE'
                        ELSE 'RECEIVABLE'
                    END as net_position
                FROM netted
                ORDER BY gross_amount DESC
            ");
            $settlementSummary = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

    } catch (\Exception $e) {
        debug_log("Database error: " . $e->getMessage());
    }
}

// ============================================================================
// AJAX SWAP HANDLER - WITH PHONE NUMBER FORMATTING
// ============================================================================

if (!isset($isAjax)) {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || 
              (isset($_POST['ajax']) && $_POST['ajax'] === 'true');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['execute_swap']) && $isAjax) {

    // Clean output buffers for JSON response
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache, must-revalidate');

    $debug_log = [];

    try {
        $debug_log[] = "1. Starting swap execution";

        // Check if KeyVault class exists
        if (!class_exists('\Security\Encryption\KeyVault')) {
            throw new \Exception('KeyVault class not found');
        }
        
        $keyVault = \Security\Encryption\KeyVault::getInstance();
        $encryptionKey = $keyVault->getEncryptionKey();
        $debug_log[] = "2. KeyVault initialized";

        // Check if SwapService class exists
        if (!class_exists('\Domain\Services\SwapService')) {
            throw new \Exception('SwapService class not found');
        }
        
        $swapService = new \Domain\Services\SwapService($db, [], $countryCode);
        $debug_log[] = "3. SwapService initialized";

        $swapType     = $_POST['swap_type'] ?? 'self';
        $deliveryMode = $_POST['delivery_mode'] ?? 'deposit';
        $toType       = $_POST['to_type'] ?? 'account';
        $fromType     = strtolower($_POST['from_type'] ?? '');
        $amount       = (float)($_POST['amount'] ?? 0);
        $debug_log[]  = "4. Form data: swapType=$swapType, deliveryMode=$deliveryMode, toType=$toType";

        // Get source phone/account - FORMAT THE PHONE NUMBER
        $phoneNumber = '';
        switch ($fromType) {
            case 'e-wallet':
                $phoneNumber = $_POST['ewallet_phone'] ?? $_POST['wallet_phone'] ?? '';
                break;
            case 'wallet':
                $phoneNumber = $_POST['wallet_phone'] ?? '';
                break;
            case 'voucher':
                $phoneNumber = $_POST['wallet_phone'] ?? $_POST['ewallet_phone'] ?? '';
                break;
            default:
                $phoneNumber = $_POST['account_number'] ?? '';
        }
        
        // FORMAT THE PHONE NUMBER WITH COUNTRY CODE
        if (!empty($phoneNumber)) {
            $phoneNumber = formatPhoneNumberForSwap($phoneNumber, $countryCode);
        }
        $debug_log[] = "5. Source phone/account (formatted): $phoneNumber";
        
        // Validate phone number format
        if (!empty($phoneNumber) && !preg_match('/^\+\d{10,15}$/', $phoneNumber)) {
            throw new \Exception('Invalid phone number format. Expected format: +267XXXXXXXXX');
        }

        // Build source in the format your API expects
        $source = [
            'institution' => $_POST['from_institution'] ?? '',
            'asset_type' => $fromType,
            'amount' => $amount
        ];

        // Add source details based on type
        if ($fromType === 'e-wallet') {
            $source['ewallet'] = [
                'ewallet_phone' => $phoneNumber
            ];
        } elseif ($fromType === 'wallet') {
            $source['wallet'] = [
                'wallet_phone' => $phoneNumber,
                'wallet_pin' => $_POST['pin'] ?? null
            ];
        } elseif ($fromType === 'account') {
            $source['account'] = [
                'account_number' => $phoneNumber
            ];
        } elseif ($fromType === 'card') {
            $source['card'] = [
                'card_number' => $_POST['card_number'] ?? '',
                'card_pin' => $_POST['pin'] ?? null
            ];
        } elseif ($fromType === 'voucher') {
            $source['voucher'] = [
                'voucher_number' => $_POST['voucher_number'] ?? '',
                'claimant_phone' => $phoneNumber,
                'voucher_pin' => $_POST['pin'] ?? null
            ];
        }

        // Filter out null values
        $source = array_filter($source);

        // Build destination in the EXACT format your API expects
        if ($swapType === 'business' && !empty($_POST['recipients']) && is_array($_POST['recipients'])) {
            // Business case - multiple destinations
            $destinations = [];
            foreach ($_POST['recipients'] as $recipient) {
                $recipientPhone = $recipient['phone'] ?? '';
                // FORMAT THE RECIPIENT PHONE NUMBER
                if (!empty($recipientPhone)) {
                    $recipientPhone = formatPhoneNumberForSwap($recipientPhone, $countryCode);
                }
                
                $dest = [
                    'institution' => $recipient['institution'] ?? '',
                    'delivery_mode' => $deliveryMode,
                    'amount' => (float)($recipient['amount'] ?? 0)
                ];
                
                if ($deliveryMode === 'cashout') {
                    $dest['beneficiary_phone'] = $recipientPhone;
                } else {
                    $dest['beneficiary_account'] = $recipientPhone;
                }
                
                $destinations[] = array_filter($dest);
            }
            $destination = $destinations; // Array of destinations for business
        } else {
            // Personal case - single destination
            $destPhone = $deliveryMode === 'cashout'
                ? $_POST['destination_phone'] ?? ''
                : $_POST['destination_account'] ?? $_POST['destination_card'] ?? '';
            
            // FORMAT THE DESTINATION PHONE NUMBER
            if (!empty($destPhone)) {
                $destPhone = formatPhoneNumberForSwap($destPhone, $countryCode);
            }
            $debug_log[] = "6. Destination phone (formatted): $destPhone";
            
            $destination = [
                'institution' => $_POST['to_institution'] ?? '',
                'delivery_mode' => $deliveryMode,
                'amount' => $amount
            ];

            if ($deliveryMode === 'cashout') {
                $destination['beneficiary_phone'] = $destPhone;
            } else {
                $destination['beneficiary_account'] = $destPhone;
            }
            
            // Add asset_type if needed (for deposit to specific account types)
            if ($deliveryMode === 'deposit' && !empty($toType)) {
                $destination['asset_type'] = strtoupper($toType);
            }
        }

        // Build final payload matching your working API exactly
        $payload = [
            'currency' => 'BWP',
            'source' => $source,
            'destination' => $destination
        ];

        // Add swap_type only if needed (some APIs expect it)
        if ($swapType !== 'self') {
            $payload['swap_type'] = $swapType;
        }
        
        $debug_log[] = "7. Payload prepared";
        error_log("DASHBOARD PAYLOAD: " . json_encode($payload));

        // Execute swap
        $swapResult = $swapService->executeSwap($payload);
        $debug_log[] = "8. Swap executed with status: " . ($swapResult['status'] ?? 'unknown');

        $isSuccess = (($swapResult['status'] ?? '') === 'success');
        
        if ($isSuccess) {
            $debug_log[] = "9. Swap successful";
            
            // Insert audit log
            if ($db) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO audit_logs 
                        (entity_type, action, category, severity, performed_by_type, performed_at) 
                        VALUES ('SWAP', 'EXECUTE', 'TRANSACTION', 'info', 'user', NOW())
                    ");
                    $stmt->execute();
                    
                    // Also insert into settlement queue for netting
                    $stmt = $db->prepare("
                        INSERT INTO settlement_queue (debtor, creditor, amount, created_at)
                        VALUES (
                            (SELECT name FROM participants WHERE participant_id = ?),
                            (SELECT name FROM participants WHERE participant_id = ?),
                            ?,
                            NOW()
                        )
                        ON CONFLICT (debtor, creditor) 
                        DO UPDATE SET amount = settlement_queue.amount + EXCLUDED.amount
                    ");
                    
                    // Get participant IDs
                    $sourceParticipant = $_POST['from_institution'] ?? '';
                    $destParticipant = $_POST['to_institution'] ?? '';
                    
                    if ($sourceParticipant && $destParticipant) {
                        $stmt->execute([$sourceParticipant, $destParticipant, $amount]);
                    }
                    
                    $debug_log[] = "10. Audit log and settlement queue updated";
                } catch (\Exception $e) {
                    $debug_log[] = "10. Audit log error: " . $e->getMessage();
                }
            }

            // Build response
            $response = [
                'status' => 'success',
                'swap_reference' => $swapResult['swap_reference'] ?? $swapResult['reference'] ?? 'SWAP-' . uniqid(),
                'transaction_id' => $swapResult['transaction_id'] ?? null,
                'timestamp' => date('Y-m-d H:i:s'),
                'amount' => $amount,
                'delivery_mode' => $deliveryMode,
                'source' => $source,
                'destination' => $destination,
                'hold_reference' => $swapResult['hold_reference'] ?? null,
                'dispensed_notes' => $swapResult['dispensed_notes'] ?? [],
                'fee' => $swapResult['fee'] ?? ($deliveryMode === 'cashout' ? 10.00 : 6.00),
                'net_amount' => $amount - ($swapResult['fee'] ?? ($deliveryMode === 'cashout' ? 10.00 : 6.00)),
                'debug' => $debug_log
            ];

            // Get voucher details for cashouts
            if ($deliveryMode === 'cashout' && $db && isset($response['swap_reference'])) {
                try {
                    $stmt = $db->prepare("
                        SELECT 
                            code_suffix, 
                            expiry_at,
                            amount
                        FROM swap_vouchers 
                        WHERE swap_id = (
                            SELECT swap_id 
                            FROM swap_requests 
                            WHERE swap_uuid::text = ? 
                            LIMIT 1
                        ) 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$response['swap_reference']]);
                    $voucher = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if ($voucher) {
                        $response['voucher'] = [
                            'code_suffix' => $voucher['code_suffix'],
                            'expiry' => $voucher['expiry_at'],
                            'amount' => $voucher['amount']
                        ];
                        $debug_log[] = "11. Voucher fetched";
                    }
                } catch (\Exception $e) {
                    $debug_log[] = "11. Voucher fetch error: " . $e->getMessage();
                }
            }
        } else {
            $response = [
                'status' => 'error',
                'message' => $swapResult['message'] ?? 'Swap execution failed',
                'error_code' => $swapResult['error_code'] ?? null,
                'debug' => $debug_log
            ];
        }

    } catch (\Exception $e) {
        error_log("SWAP EXCEPTION: " . $e->getMessage());
        $response = [
            'status' => 'error',
            'message' => $e->getMessage(),
            'debug' => array_merge($debug_log, ["EXCEPTION: " . $e->getMessage()])
        ];
    }

    // Clean output and send JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ============================================================================
// SETTLEMENT REPORT GENERATION
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_settlement_report'])) {
    $reportType = $_POST['report_type'] ?? 'daily';
    $reportDate = $_POST['report_date'] ?? date('Y-m-d');
    
    header('Content-Type: application/json');
    
    try {
        if (!$db) {
            throw new \Exception('Database connection not available');
        }
        
        if ($reportType === 'daily') {
            // Daily settlement report
            $stmt = $db->prepare("
                WITH daily_settlements AS (
                    SELECT 
                        debtor,
                        creditor,
                        SUM(amount) as total_amount,
                        COUNT(*) as transaction_count,
                        MIN(created_at) as first_transaction,
                        MAX(created_at) as last_transaction
                    FROM settlement_queue
                    WHERE DATE(created_at) = ?
                    GROUP BY debtor, creditor
                )
                SELECT 
                    ds.*,
                    p1.name as debtor_name,
                    p1.settlement_account as debtor_account,
                    p2.name as creditor_name,
                    p2.settlement_account as creditor_account
                FROM daily_settlements ds
                LEFT JOIN participants p1 ON ds.debtor = p1.name
                LEFT JOIN participants p2 ON ds.creditor = p2.name
                ORDER BY total_amount DESC
            ");
            $stmt->execute([$reportDate]);
            $settlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Calculate net positions
            $netPositions = [];
            foreach ($settlements as $s) {
                $key = $s['debtor'] . '|' . $s['creditor'];
                $netPositions[$key] = $s;
            }
            
            $response = [
                'success' => true,
                'report_date' => $reportDate,
                'report_type' => 'daily',
                'total_settlements' => count($settlements),
                'total_amount' => array_sum(array_column($settlements, 'total_amount')),
                'settlements' => $settlements,
                'net_positions' => $netPositions
            ];
            
        } elseif ($reportType === 'weekly') {
            // Weekly settlement report with netting
            $startDate = date('Y-m-d', strtotime($reportDate . ' -6 days'));
            $endDate = $reportDate;
            
            $stmt = $db->prepare("
                WITH weekly_settlements AS (
                    SELECT 
                        debtor,
                        creditor,
                        SUM(amount) as gross_amount,
                        COUNT(*) as transaction_count
                    FROM settlement_queue
                    WHERE DATE(created_at) BETWEEN ? AND ?
                    GROUP BY debtor, creditor
                ),
                netted_positions AS (
                    SELECT 
                        debtor,
                        creditor,
                        gross_amount,
                        CASE 
                            WHEN gross_amount > 0 THEN 'PAYABLE'
                            ELSE 'RECEIVABLE'
                        END as position_type
                    FROM weekly_settlements
                )
                SELECT 
                    np.*,
                    p1.name as debtor_name,
                    p1.settlement_account as debtor_account,
                    p2.name as creditor_name,
                    p2.settlement_account as creditor_account
                FROM netted_positions np
                LEFT JOIN participants p1 ON np.debtor = p1.name
                LEFT JOIN participants p2 ON np.creditor = p2.name
                ORDER BY gross_amount DESC
            ");
            $stmt->execute([$startDate, $endDate]);
            $settlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Calculate net settlement for each participant
            $participantNet = [];
            foreach ($settlements as $s) {
                if (!isset($participantNet[$s['debtor']])) {
                    $participantNet[$s['debtor']] = ['payable' => 0, 'receivable' => 0];
                }
                if (!isset($participantNet[$s['creditor']])) {
                    $participantNet[$s['creditor']] = ['payable' => 0, 'receivable' => 0];
                }
                
                $participantNet[$s['debtor']]['payable'] += $s['gross_amount'];
                $participantNet[$s['creditor']]['receivable'] += $s['gross_amount'];
            }
            
            $response = [
                'success' => true,
                'report_date' => $reportDate,
                'report_type' => 'weekly',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_settlements' => count($settlements),
                'total_gross_amount' => array_sum(array_column($settlements, 'gross_amount')),
                'settlements' => $settlements,
                'participant_net_positions' => $participantNet
            ];
            
        } elseif ($reportType === 'monthly') {
            // Monthly settlement report
            $year = date('Y', strtotime($reportDate));
            $month = date('m', strtotime($reportDate));
            
            $stmt = $db->prepare("
                WITH monthly_settlements AS (
                    SELECT 
                        debtor,
                        creditor,
                        SUM(amount) as total_amount,
                        COUNT(*) as transaction_count,
                        DATE_TRUNC('month', created_at) as settlement_month
                    FROM settlement_queue
                    WHERE EXTRACT(YEAR FROM created_at) = ?
                    AND EXTRACT(MONTH FROM created_at) = ?
                    GROUP BY debtor, creditor, DATE_TRUNC('month', created_at)
                )
                SELECT 
                    ms.*,
                    p1.name as debtor_name,
                    p1.settlement_account as debtor_account,
                    p2.name as creditor_name,
                    p2.settlement_account as creditor_account
                FROM monthly_settlements ms
                LEFT JOIN participants p1 ON ms.debtor = p1.name
                LEFT JOIN participants p2 ON ms.creditor = p2.name
                ORDER BY total_amount DESC
            ");
            $stmt->execute([$year, $month]);
            $settlements = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Group by week for detailed view
            $weeklyBreakdown = [];
            foreach ($settlements as $s) {
                $week = date('W', strtotime($s['settlement_month']));
                if (!isset($weeklyBreakdown[$week])) {
                    $weeklyBreakdown[$week] = [
                        'total' => 0,
                        'count' => 0,
                        'settlements' => []
                    ];
                }
                $weeklyBreakdown[$week]['total'] += $s['total_amount'];
                $weeklyBreakdown[$week]['count'] += $s['transaction_count'];
                $weeklyBreakdown[$week]['settlements'][] = $s;
            }
            
            $response = [
                'success' => true,
                'report_date' => $reportDate,
                'report_type' => 'monthly',
                'year' => $year,
                'month' => $month,
                'total_settlements' => count($settlements),
                'total_amount' => array_sum(array_column($settlements, 'total_amount')),
                'settlements' => $settlements,
                'weekly_breakdown' => $weeklyBreakdown
            ];
        }
        
        // Log report generation
        $stmt = $db->prepare("
            INSERT INTO audit_logs 
            (entity_type, action, category, severity, performed_by_type, metadata, performed_at) 
            VALUES ('REPORT', 'GENERATE', 'SETTLEMENT', 'info', 'system', ?, NOW())
        ");
        $stmt->execute([json_encode(['report_type' => $reportType, 'date' => $reportDate])]);
        
        echo json_encode($response);
        
    } catch (\Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

$error = '';
$swapResult = null;
$showMoneyFlow = false;
$fieldRequirementsJson = json_encode($fieldRequirements);

// Clean output buffer before sending HTML
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · REGULATORY SWITCH · <?php echo $countryCode; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #0a0a0a;
            font-family: 'Inter', 'Helvetica Neue', -apple-system, sans-serif;
            color: #ffffff;
            line-height: 1.4;
            font-weight: 300;
            letter-spacing: 0.02em;
        }

        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* WELCOME BANNER */
        .welcome-banner {
            background: #000;
            border: 2px solid #0f0;
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(0, 255, 0, 0.1) 50%, transparent 70%);
            animation: scan 3s linear infinite;
        }

        @keyframes scan {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 200;
            text-transform: uppercase;
            letter-spacing: 0.3em;
            color: #0f0;
            margin-bottom: 0.5rem;
            position: relative;
        }

        .welcome-subtitle {
            font-size: 1rem;
            color: #666;
            letter-spacing: 0.2em;
            position: relative;
        }

        .version-badge {
            display: inline-block;
            padding: 0.25rem 1rem;
            background: #0f0;
            color: #000;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            margin-top: 1rem;
        }

        /* TYPOGRAPHY */
        h1, h2, h3, h4 {
            font-weight: 300;
            letter-spacing: 0.05em;
        }

        .title {
            font-size: 1.8rem;
            font-weight: 200;
            text-transform: uppercase;
            border-bottom: 2px solid #333;
            padding-bottom: 1rem;
            margin-bottom: 2rem;
            color: #fff;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 1.5rem;
            color: #888;
        }

        /* HEADER */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 2px solid #222;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: #fff;
        }

        .logo span {
            color: #888;
            font-size: 0.8rem;
            margin-left: 1rem;
        }

        .country-selector {
            display: flex;
            gap: 0.5rem;
        }

        .country-btn {
            padding: 0.5rem 1.2rem;
            background: transparent;
            border: 1px solid #333;
            color: #666;
            text-decoration: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s;
        }

        .country-btn.active {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        .country-btn:hover {
            border-color: #fff;
            color: #fff;
        }

        .view-selector {
            display: flex;
            gap: 2rem;
        }

        .view-btn {
            padding: 0.5rem 0;
            background: transparent;
            border: none;
            border-bottom: 2px solid transparent;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            transition: all 0.2s;
        }

        .view-btn.active {
            color: #fff;
            border-bottom-color: #0f0;
        }

        .view-btn:hover {
            color: #fff;
        }

        .status-badge {
            padding: 0.5rem 1.5rem;
            background: #111;
            border: 1px solid #333;
            color: #0f0;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        /* GRID SYSTEMS */
        .grid-2, .grid-3, .grid-4 {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }

        /* CARDS */
        .card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #222;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 300;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #fff;
        }

        .card-badge {
            padding: 0.25rem 1rem;
            background: #000;
            border: 1px solid #333;
            color: #888;
            font-size: 0.7rem;
            text-transform: uppercase;
        }

        /* HEALTH METRICS */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .health-item {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .health-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .health-value {
            font-size: 1.8rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
        }

        .health-value.ok { color: #0f0; }
        .health-value.warning { color: #ff0; }
        .health-value.critical { color: #f00; }

        /* SUMMARY STATS */
        .stat-card {
            background: #111;
            border: 2px solid #222;
            padding: 1.5rem;
        }

        .stat-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #666;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 200;
            font-family: 'Courier New', monospace;
            line-height: 1.2;
        }

        .positive { color: #0f0; }
        .negative { color: #f00; }

        /* TABLES */
        .table-responsive {
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #222;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        th {
            text-align: left;
            padding: 1rem;
            background: #000;
            color: #888;
            font-weight: 300;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.1em;
            border-bottom: 2px solid #333;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #222;
            color: #ccc;
            font-family: 'Courier New', monospace;
        }

        tr:hover {
            background: #1a1a1a;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* STATUS BADGES */
        .status {
            display: inline-block;
            padding: 0.25rem 1rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border: 1px solid;
        }

        .status-success {
            background: transparent;
            color: #0f0;
            border-color: #0f0;
        }

        .status-pending {
            background: transparent;
            color: #ff0;
            border-color: #ff0;
        }

        .status-error {
            background: transparent;
            color: #f00;
            border-color: #f00;
        }

        .status-retry {
            background: transparent;
            color: #00f;
            border-color: #00f;
        }

        /* PARTICIPANT BADGES */
        .participant-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            background: #000;
            border: 1px solid #333;
            color: #888;
            font-size: 0.6rem;
            text-transform: uppercase;
            margin-right: 0.25rem;
        }

        .atm-badge {
            border-color: #f00;
            color: #f00;
        }

        .agent-badge {
            border-color: #00f;
            color: #00f;
        }

        /* FEE DISPLAY */
        .fee-container {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #222;
            font-family: 'Courier New', monospace;
        }

        .fee-total {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            margin-top: 0.5rem;
            background: #0a0a0a;
            font-weight: 400;
            border-top: 2px solid #333;
            color: #0f0;
        }

        /* FORMS */
        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #888;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        select, input {
            width: 100%;
            padding: 1rem;
            background: #000;
            border: 2px solid #222;
            color: #fff;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }

        select:focus, input:focus {
            outline: none;
            border-color: #0f0;
        }

        .radio-group {
            display: flex;
            gap: 2rem;
            padding: 1rem 0;
        }

        .radio-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-item input[type="radio"] {
            width: auto;
        }

        .btn {
            padding: 1rem 2rem;
            background: transparent;
            border: 2px solid #0f0;
            color: #0f0;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }

        .btn:hover {
            background: #0f0;
            color: #000;
        }

        .btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }

        .btn-add {
            border: 2px dashed #333;
            color: #666;
            margin-top: 1rem;
        }

        .btn-remove {
            padding: 0.5rem;
            background: transparent;
            border: 1px solid #f00;
            color: #f00;
            font-size: 0.7rem;
            cursor: pointer;
        }

        .delivery-mode-selector {
            background: #000;
            border: 2px solid #333;
            padding: 1.5rem;
            margin: 2rem 0;
        }

        .hidden {
            display: none;
        }

        .recipient-row {
            background: #000;
            border: 2px solid #222;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        /* REPORT */
        .report-container {
            margin-top: 2rem;
            border: 2px solid #0f0;
            display: none;
        }

        .report-container.visible {
            display: block;
        }

        /* FOOTER */
        .footer {
            margin-top: 4rem;
            padding-top: 2rem;
            border-top: 2px solid #222;
            text-align: center;
            color: #444;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
        }

        /* MISC */
        .text-muted {
            color: #444;
        }

        .mono {
            font-family: 'Courier New', monospace;
        }

        hr {
            border: none;
            border-top: 2px solid #222;
            margin: 2rem 0;
        }

        /* DASHBOARD SPECIFIC */
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
        }

        .recent-swaps {
            margin-top: 2rem;
        }

        /* SETTLEMENT REPORTS */
        .report-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            align-items: flex-end;
        }

        .report-btn {
            padding: 0.75rem 1.5rem;
            background: #000;
            border: 1px solid #0f0;
            color: #0f0;
            cursor: pointer;
        }

        .report-btn:hover {
            background: #0f0;
            color: #000;
        }

        .settlement-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-item {
            background: #111;
            border: 1px solid #333;
            padding: 1rem;
            text-align: center;
        }

        .summary-label {
            font-size: 0.7rem;
            color: #888;
            text-transform: uppercase;
        }

        .summary-value {
            font-size: 1.5rem;
            color: #0f0;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-title">WELCOME TO VOUCHMORPH</div>
            <div class="welcome-subtitle">REGULATORY SWITCH · PRODUCTION ENVIRONMENT</div>
            <div class="version-badge">VMOR 1.0 – CORE EDITION</div>
        </div>

        <!-- HEADER -->
        <div class="header">
            <div class="header-left">
                <div class="logo">
                    VOUCHMORPH <span>REGULATORY SWITCH</span>
                </div>
                <div class="country-selector">
                    <a href="?country=BW&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'BW' ? 'active' : ''; ?>">BW</a>
                    <a href="?country=KE&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'KE' ? 'active' : ''; ?>">KE</a>
                    <a href="?country=NG&view=<?php echo $selectedView; ?>&regulator_view=<?php echo $regulatorView; ?>" class="country-btn <?php echo $countryCode === 'NG' ? 'active' : ''; ?>">NG</a>
                </div>
            </div>
            <div class="view-selector">
                <a href="?view=dashboard&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'dashboard' ? 'active' : ''; ?>">DASHBOARD</a>
                <a href="?view=swap&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'swap' ? 'active' : ''; ?>">EXECUTE</a>
                <a href="?view=settlements&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'settlements' ? 'active' : ''; ?>">SETTLEMENTS</a>
                <a href="?view=reports&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'reports' ? 'active' : ''; ?>">REPORTS</a>
                <a href="?view=regulatory&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'regulatory' ? 'active' : ''; ?>">REGULATORY</a>
                <a href="?view=audit&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" class="view-btn <?php echo $selectedView === 'audit' ? 'active' : ''; ?>">AUDIT</a>
            </div>
            <div class="status-badge">
                <?php echo $countryCode; ?> · REAL-TIME
            </div>
        </div>

        <!-- SYSTEM HEALTH - Always visible -->
        <div class="health-grid">
            <div class="health-item">
                <div class="health-label">SYSTEM STATUS</div>
                <div class="health-value ok"><?php echo $heartbeat['status'] ?? 'ACTIVE'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">LATENCY</div>
                <div class="health-value"><?php echo isset($heartbeat['latency_ms']) ? number_format($heartbeat['latency_ms'], 0) . 'ms' : '45ms'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">LOAD</div>
                <div class="health-value"><?php echo isset($heartbeat['system_load']) ? number_format($heartbeat['system_load'] * 100, 0) . '%' : '23%'; ?></div>
            </div>
            <div class="health-item">
                <div class="health-label">HEARTBEAT</div>
                <div class="health-value"><?php echo isset($heartbeat['created_at']) ? date('H:i:s', strtotime($heartbeat['created_at'])) : date('H:i:s'); ?></div>
            </div>
        </div>

        <!-- SUMMARY STATS - Always visible -->
        <div class="grid-4">
            <div class="stat-card">
                <div class="stat-label">NET EXPOSURE</div>
                <div class="stat-value <?php echo $totalExposure >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo safe_number_format($totalExposure); ?>
                </div>
                <div class="stat-label" style="margin-top: 0.5rem;">BWP</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">PENDING SETTLEMENTS</div>
                <div class="stat-value"><?php echo count($pendingSettlements); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">MESSAGES</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">FEES COLLECTED</div>
                <div class="stat-value positive"><?php echo safe_number_format($totalFeesCollected); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">BWP</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">ACTIVE VOUCHERS</div>
                <div class="stat-value"><?php echo count($activeVouchers); ?></div>
                <div class="stat-label" style="margin-top: 0.5rem;">WORTH <?php echo safe_number_format($totalVoucherAmount); ?> BWP</div>
            </div>
        </div>

        <!-- DASHBOARD VIEW -->
        <?php if ($selectedView === 'dashboard'): ?>
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">NET POSITIONS (FOR SETTLEMENT)</div>
                    <div class="card-badge">real-time</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>DEBTOR</th>
                                <th>CREDITOR</th>
                                <th class="text-right">AMOUNT</th>
                                <th>TRANSACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($netPositions)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO POSITIONS —</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($netPositions, 0, 5) as $pos): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pos['debtor']); ?></td>
                                    <td><?php echo htmlspecialchars($pos['creditor']); ?></td>
                                    <td class="text-right <?php echo $pos['amount'] >= 0 ? 'positive' : 'negative'; ?>">
                                        <?php echo safe_number_format($pos['amount']); ?>
                                    </td>
                                    <td><?php echo $pos['transaction_count'] ?? 1; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="card-title">RECENT SWAPS</div>
                    <div class="card-badge">last 10</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>REFERENCE</th>
                                <th class="text-right">AMOUNT</th>
                                <th>STATUS</th>
                                <th>TIME</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSwaps)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO SWAPS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentSwaps as $swap): ?>
                                <tr>
                                    <td class="mono"><?php echo substr($swap['swap_uuid'], 0, 8); ?>…</td>
                                    <td class="text-right positive"><?php echo safe_number_format($swap['amount']); ?> BWP</td>
                                    <td><span class="status status-<?php echo $swap['status']; ?>"><?php echo $swap['status']; ?></span></td>
                                    <td><?php echo date('H:i', strtotime($swap['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- FEE COLLECTIONS ON DASHBOARD -->
        <?php if (!empty($feeCollections)): ?>
        <div class="fee-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div style="font-size: 1rem; text-transform: uppercase; letter-spacing: 0.1em;">FEE COLLECTIONS</div>
                <div style="padding: 0.25rem 1rem; border: 1px solid #333; color: #888; font-size: 0.7rem;">swap_fee_collections</div>
            </div>
            <?php foreach (array_slice($feeCollections, 0, 3) as $fee): ?>
            <div class="fee-item">
                <span>
                    <strong style="color: #fff;"><?php echo htmlspecialchars($fee['fee_type']); ?></strong>
                    <span style="color: #444; margin-left: 1rem;">
                        <?php echo htmlspecialchars($fee['source_institution']); ?> → <?php echo htmlspecialchars($fee['destination_institution']); ?>
                    </span>
                </span>
                <span style="color: #0f0;"><?php echo safe_number_format($fee['total_amount']); ?> BWP</span>
            </div>
            <?php endforeach; ?>
            <div class="fee-total">
                <span>TOTAL FEES</span>
                <span><?php echo safe_number_format($totalFeesCollected); ?> BWP</span>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($selectedView === 'swap'): ?>
        <!-- SWAP EXECUTION VIEW -->
        <div class="grid-2">
            <!-- FORM -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">EXECUTE SWAP</div>
                    <div class="card-badge">SwapService</div>
                </div>
                
                <form method="POST" action="?view=swap&country=<?php echo $countryCode; ?>&regulator_view=<?php echo $regulatorView; ?>" id="swapForm">
                    <input type="hidden" name="execute_swap" value="1">
                    
                    <div class="form-group">
                        <label>SWAP TYPE</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" name="swap_type" value="self" id="swap_self" checked>
                                <label for="swap_self">PERSONAL</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" name="swap_type" value="business" id="swap_business">
                                <label for="swap_business">BUSINESS</label>
                            </div>
                        </div>
                    </div>
                    
                    <h3 style="color: #666; margin: 2rem 0 1rem; font-size: 0.9rem;">SOURCE</h3>
                    
                    <div class="form-group">
                        <label>ASSET TYPE</label>
                        <select name="from_type" id="from_type" required>
                            <option value="">SELECT</option>
                            <option value="account">BANK ACCOUNT</option>
                            <option value="wallet">MOBILE WALLET</option>
                            <option value="e-wallet">E-WALLET</option>
                            <option value="card">CARD</option>
                            <option value="voucher">VOUCHER</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>INSTITUTION</label>
                        <select name="from_institution" id="from_institution" required>
                            <option value="">SELECT</option>
                        </select>
                    </div>
                    
                    <div id="source_dynamic_fields"></div>
                    
                    <div class="delivery-mode-selector">
                        <div class="form-group">
                            <label>DELIVERY MODE</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" name="delivery_mode" value="cashout" id="mode_cashout" checked>
                                    <label for="mode_cashout">CASH OUT</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" name="delivery_mode" value="deposit" id="mode_deposit">
                                    <label for="mode_deposit">DEPOSIT</label>
                                </div>
                            </div>
                        </div>
                        <div style="margin-top: 1rem; padding: 0.5rem; border: 1px solid #333; color: #888; font-size: 0.8rem;">
                            FEE: CASHOUT 10 BWP · DEPOSIT 6 BWP
                        </div>
                    </div>
                    
                    <h3 style="color: #666; margin: 2rem 0 1rem; font-size: 0.9rem;">DESTINATION</h3>
                    
                    <div class="form-group">
                        <label>ASSET TYPE</label>
                        <select name="to_type" id="to_type" required></select>
                    </div>
                    
                    <div id="personalDestinationFields">
                        <div class="form-group">
                            <label>INSTITUTION</label>
                            <select name="to_institution" id="to_institution" required>
                                <option value="">SELECT</option>
                            </select>
                        </div>
                        
                        <div id="destination_dynamic_fields"></div>
                    </div>
                    
                    <div id="businessDestinationFields" class="hidden">
                        <div id="recipientsContainer"></div>
                        <button type="button" class="btn btn-add" id="addRecipientBtn">+ ADD RECIPIENT</button>
                    </div>
                    
                    <div class="form-group">
                        <label>AMOUNT (BWP)</label>
                        <input type="number" name="amount" id="totalAmount" step="0.01" min="10" placeholder="0.00" required>
                    </div>
                    
                    <div id="validationErrors" style="color: #f00; margin: 1rem 0;"></div>
                    
                    <button type="submit" class="btn" id="executeSwapBtn">EXECUTE</button>
                </form>
            </div>
            
            <!-- PARTICIPANTS -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">ACTIVE PARTICIPANTS</div>
                    <div class="card-badge"><?php echo count($participants); ?></div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>NAME</th>
                                <th>TYPE</th>
                                <th>CAPABILITIES</th>
                                <th>STATUS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($participants)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO PARTICIPANTS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($participants as $p): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($p['participant_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($p['category'] ?? $p['type']); ?></td>
                                    <td>
                                        <?php 
                                        $walletTypes = $p['capabilities']['wallet_types'] ?? [];
                                        foreach ($walletTypes as $wt) {
                                            $class = 'participant-badge';
                                            if ($wt === 'ATM') $class .= ' atm-badge';
                                            elseif ($wt === 'AGENT') $class .= ' agent-badge';
                                            echo '<span class="' . $class . '">' . strtolower($wt) . '</span> ';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="status status-success"><?php echo $p['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- REPORT -->
        <div id="swapReportContainer" class="report-container card">
            <div class="card-header">
                <div class="card-title">EXECUTION REPORT</div>
                <div class="card-badge">SUCCESS</div>
            </div>
            <div id="swapReportContent"></div>
        </div>

        <?php elseif ($selectedView === 'settlements'): ?>
        <!-- SETTLEMENTS VIEW WITH NETTING -->
        <div class="report-controls">
            <div>
                <label>GENERATE SETTLEMENT REPORT</label>
                <select id="reportType">
                    <option value="daily">Daily</option>
                    <option value="weekly">Weekly (Netting)</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div>
                <label>DATE</label>
                <input type="date" id="reportDate" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <button class="report-btn" onclick="generateSettlementReport()">GENERATE</button>
            </div>
        </div>

        <div id="settlementReport" style="display: none;">
            <!-- Dynamic report content will be inserted here -->
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">MESSAGE OUTBOX</div>
                    <div class="card-badge">message_outbox</div>
                </div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>CHANNEL</th>
                                <th>DESTINATION</th>
                                <th>STATUS</th>
                                <th>CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($messageOutbox)): ?>
                            <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO MESSAGES —</td></tr>
                            <?php else: ?>
                                <?php foreach ($messageOutbox as $msg): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($msg['channel'] ?? 'SMS'); ?></td>
                                    <td><?php echo htmlspecialchars(substr($msg['destination'] ?? '', 0, 15)); ?></td>
                                    <td><span class="status status-<?php echo strtolower($msg['status']); ?>"><?php echo $msg['status']; ?></span></td>
                                    <td><?php echo date('H:i', strtotime($msg['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-title">SETTLEMENT QUEUE (NETTING)</div>
                    <div class="card-badge">settlement_queue</div>
                </div>
                <div class="table-responsive">
                    </table>
                        <thead>
                            <tr>
                                <th>DEBTOR</th>
                                <th>CREDITOR</th>
                                <th class="text-right">AMOUNT</th>
                                <th>CREATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($settlementQueue)): ?>
                            <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO ITEMS —</td></tr>
                            <?php else: ?>
                                <?php foreach ($settlementQueue as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['debtor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($item['creditor_name']); ?></span></td>
                                    <td class="text-right positive"><?php echo safe_number_format($item['amount']); ?></td>
                                    <td><?php echo date('H:i', strtotime($item['created_at'])); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">PENDING SETTLEMENTS</div>
                <div class="card-badge">settlement_messages</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>FROM</th>
                            <th>TO</th>
                            <th class="text-right">AMOUNT</th>
                            <th>TYPE</th>
                            <th>STATUS</th>
                            <th>CREATED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingSettlements)): ?>
                        <tr><td colspan="6" class="text-center" style="color: #444; padding: 2rem;">— NO PENDING SETTLEMENTS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingSettlements as $settlement): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($settlement['from_participant'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($settlement['to_participant'] ?? '-'); ?></span></td>
                                <td class="text-right"><?php echo safe_number_format($settlement['amount']); ?></span></td>
                                <td><?php echo htmlspecialchars($settlement['type'] ?? '-'); ?></span></td>
                                <td><span class="status status-pending"><?php echo $settlement['status']; ?></span></span></td>
                                <td><?php echo date('H:i', strtotime($settlement['created_at'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($selectedView === 'reports'): ?>
        <!-- REPORTS VIEW -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">WEEKLY SETTLEMENT SUMMARY</div>
                <div class="card-badge">netted</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>WEEK</th>
                            <th class="text-right">TRANSACTIONS</th>
                            <th class="text-right">TOTAL AMOUNT</th>
                            <th>DEBTORS</th>
                            <th>CREDITORS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($weeklySettlements)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO WEEKLY DATA —</td></tr>
                        <?php else: ?>
                            <?php foreach ($weeklySettlements as $week): ?>
                            <tr>
                                <td><?php echo date('Y-m-d', strtotime($week['week_start'])); ?></span></td>
                                <td class="text-right"><?php echo $week['transaction_count']; ?></span></td>
                                <td class="text-right positive"><?php echo safe_number_format($week['total_amount']); ?> BWP</span></td>
                                <td><?php echo $week['debtor_count']; ?></span></td>
                                <td><?php echo $week['creditor_count']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">MONTHLY SETTLEMENT SUMMARY</div>
                <div class="card-badge">netted</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>MONTH</th>
                            <th class="text-right">TRANSACTIONS</th>
                            <th class="text-right">TOTAL AMOUNT</th>
                            <th>DEBTORS</th>
                            <th>CREDITORS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthlySettlements)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO MONTHLY DATA —</td></tr>
                        <?php else: ?>
                            <?php foreach ($monthlySettlements as $month): ?>
                            <tr>
                                <td><?php echo date('Y-m', strtotime($month['month_start'])); ?></span></td>
                                <td class="text-right"><?php echo $month['transaction_count']; ?></span></td>
                                <td class="text-right positive"><?php echo safe_number_format($month['total_amount']); ?> BWP</span></td>
                                <td><?php echo $month['debtor_count']; ?></span></td>
                                <td><?php echo $month['creditor_count']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title">NET SETTLEMENT POSITIONS (LAST 7 DAYS)</div>
                <div class="card-badge">post-netting</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>DEBTOR</th>
                            <th>CREDITOR</th>
                            <th class="text-right">GROSS AMOUNT</th>
                            <th>POSITION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($settlementSummary)): ?>
                        <tr><td colspan="4" class="text-center" style="color: #444; padding: 2rem;">— NO SETTLEMENT POSITIONS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($settlementSummary as $summary): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($summary['debtor']); ?></td>
                                <td><?php echo htmlspecialchars($summary['creditor']); ?></span></td>
                                <td class="text-right <?php echo $summary['gross_amount'] >= 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo safe_number_format($summary['gross_amount']); ?>
                                 </span></td>
                                <td><span class="status status-<?php echo $summary['net_position'] === 'PAYABLE' ? 'pending' : 'success'; ?>"><?php echo $summary['net_position']; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($selectedView === 'regulatory'): ?>
        <!-- REGULATORY VIEW -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">REGULATOR OUTBOX</div>
                <div class="card-badge">regulator_outbox</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>REPORT ID</th>
                            <th>STATUS</th>
                            <th>ATTEMPTS</th>
                            <th>HASH</th>
                            <th>CREATED</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($regReports)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO REPORTS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($regReports as $report): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(substr($report['report_id'], 0, 16)); ?>…</span></td>
                                <td><span class="status status-<?php echo strtolower($report['status']); ?>"><?php echo $report['status']; ?></span></td>
                                <td><?php echo $report['attempts']; ?></span></td>
                                <td><span class="mono"><?php echo substr($report['integrity_hash'] ?? '', 0, 8); ?>…</span></td>
                                <td><?php echo date('H:i', strtotime($report['created_at'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php elseif ($selectedView === 'audit'): ?>
        <!-- AUDIT VIEW -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">AUDIT LOG</div>
                <div class="card-badge">audit_logs</div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ENTITY_TYPE</th>
                            <th>ACTION</th>
                            <th>CATEGORY</th>
                            <th>SEVERITY</th>
                            <th>TIME</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentAudits)): ?>
                        <tr><td colspan="5" class="text-center" style="color: #444; padding: 2rem;">— NO AUDIT RECORDS —</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentAudits as $audit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($audit['entity_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($audit['action'] ?? 'N/A'); ?></span></td>
                                <td><?php echo htmlspecialchars($audit['category'] ?? 'N/A'); ?></span></td>
                                <td><span class="status status-<?php echo $audit['severity'] ?? 'info'; ?>"><?php echo $audit['severity'] ?? 'info'; ?></span></span></td>
                                <td><?php echo date('H:i:s', strtotime($audit['performed_at'])); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="footer">
            <p>VOUCHMORPH · <?php echo $countryCode; ?> · DOUBLE-ENTRY VERIFIED</p>
            <p style="margin-top: 0.5rem;">LEDGER: <?php echo count($ledgerBalances); ?> · MESSAGES: <?php echo count($messageOutbox); ?> · NET: <?php echo safe_number_format($totalExposure); ?> BWP · FEES: <?php echo safe_number_format($totalFeesCollected); ?> BWP</p>
        </div>
    </div>

    <script>
        // CONFIG
        const participantsByWalletType = <?php echo json_encode($participantsByWalletType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const fieldRequirements = <?php echo $fieldRequirementsJson; ?>;
        const currentView = '<?php echo $selectedView; ?>';

        const destinationOptions = {
            'cashout': [
                { value: 'atm', label: 'ATM' },
                { value: 'agent', label: 'AGENT' }
            ],
            'deposit': [
                { value: 'account', label: 'BANK ACCOUNT' },
                { value: 'wallet', label: 'MOBILE WALLET' },
                { value: 'card', label: 'CARD' },
                { value: 'e-wallet', label: 'E-WALLET' }
            ]
        };

        let recipientCount = 0;

        function updateDeliveryMode() {
            const mode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'cashout';
            const toTypeSelect = document.getElementById('to_type');
            if (!toTypeSelect) return;
            
            toTypeSelect.innerHTML = '';
            destinationOptions[mode].forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                toTypeSelect.appendChild(option);
            });
            updateDestinationFields();
        }

        function updateSourceFields() {
            const fromType = document.getElementById('from_type');
            if (!fromType) return;
            
            const type = fromType.value;
            if (!type) {
                document.getElementById('source_dynamic_fields').innerHTML = '';
                return;
            }
            
            updateSourceInstitutions(type);
            renderDynamicFields('source', type);
        }

        function updateSourceInstitutions(type) {
            const select = document.getElementById('from_institution');
            if (!select) return;
            
            const institutions = participantsByWalletType[type] || [];
            select.innerHTML = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                const option = document.createElement('option');
                option.value = inst.participant_id;
                option.textContent = inst.participant_name;
                select.appendChild(option);
            });
        }

        function renderDynamicFields(side, type) {
            const container = document.getElementById(side + '_dynamic_fields');
            if (!container) return;
            
            const mode = side === 'destination' ? document.querySelector('input[name="delivery_mode"]:checked')?.value : null;
            
            let requirements = [];
            if (side === 'source') {
                requirements = fieldRequirements.source[type] || [];
            } else {
                requirements = fieldRequirements.destination[mode]?.[type] || [];
            }
            
            let html = '';
            requirements.forEach(field => {
                let fieldName = side === 'source' ? field : 'destination_' + field;
                
                if (field === 'pin') {
                    html += `
                        <div class="form-group">
                            <label>PIN</label>
                            <input type="password" name="${fieldName}" placeholder="••••" required>
                        </div>
                    `;
                } else if (field === 'phone') {
                    const label = (mode === 'cashout' && side === 'destination') ? 'RECIPIENT PHONE' : 'PHONE';
                    html += `
                        <div class="form-group">
                            <label>${label}</label>
                            <input type="tel" name="${fieldName}" placeholder="+267XXXXXXXXX" required>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="form-group">
                            <label>${field.replace('_', ' ').toUpperCase()}</label>
                            <input type="text" name="${fieldName}" placeholder="ENTER ${field.toUpperCase()}" required>
                        </div>
                    `;
                }
            });
            container.innerHTML = html;
        }

        function updateDestinationFields() {
            const toType = document.getElementById('to_type');
            if (!toType) return;
            
            const type = toType.value;
            if (!type) {
                document.getElementById('destination_dynamic_fields').innerHTML = '';
                return;
            }
            
            updateDestinationInstitutions(type);
            renderDynamicFields('destination', type);
        }

        function updateDestinationInstitutions(type) {
            const select = document.getElementById('to_institution');
            if (!select) return;
            
            const institutions = participantsByWalletType[type] || [];
            select.innerHTML = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                const option = document.createElement('option');
                option.value = inst.participant_id;
                option.textContent = inst.participant_name;
                select.appendChild(option);
            });
        }

        function toggleBusinessFields() {
            const swapType = document.querySelector('input[name="swap_type"]:checked')?.value;
            const personal = document.getElementById('personalDestinationFields');
            const business = document.getElementById('businessDestinationFields');
            
            if (!personal || !business) return;
            
            if (swapType === 'business') {
                personal.classList.add('hidden');
                business.classList.remove('hidden');
                if (recipientCount === 0) addRecipientRow();
            } else {
                personal.classList.remove('hidden');
                business.classList.add('hidden');
            }
        }

        function addRecipientRow() {
            recipientCount++;
            const container = document.getElementById('recipientsContainer');
            if (!container) return;
            
            const mode = document.querySelector('input[name="delivery_mode"]:checked')?.value || 'cashout';
            const toType = document.getElementById('to_type')?.value || 'atm';
            const institutions = participantsByWalletType[toType] || [];
            
            let institutionOptions = '<option value="">SELECT</option>';
            institutions.forEach(inst => {
                institutionOptions += `<option value="${inst.participant_id}">${inst.participant_name}</option>`;
            });
            
            const row = document.createElement('div');
            row.className = 'recipient-row';
            row.id = `recipient_${recipientCount}`;
            row.innerHTML = `
                <h4 style="color: #888; margin-bottom: 1rem;">RECIPIENT ${recipientCount}</h4>
                <div class="form-group">
                    <label>INSTITUTION</label>
                    <select name="recipients[${recipientCount}][institution]" required>${institutionOptions}</select>
                </div>
                <div class="form-group">
                    <label>${mode === 'cashout' ? 'PHONE (SMS)' : 'PHONE'}</label>
                    <input type="text" name="recipients[${recipientCount}][phone]" required>
                </div>
                <div class="form-group">
                    <label>AMOUNT (BWP)</label>
                    <input type="number" name="recipients[${recipientCount}][amount]" step="0.01" required>
                </div>
                <button type="button" class="btn-remove" onclick="removeRecipientRow(${recipientCount})">REMOVE</button>
            `;
            container.appendChild(row);
        }

        function removeRecipientRow(id) {
            const row = document.getElementById(`recipient_${id}`);
            if (row) row.remove();
        }

        function validateForm() {
            const errors = [];
            const swapType = document.querySelector('input[name="swap_type"]:checked')?.value;
            
            if (!document.getElementById('from_type').value) errors.push("SELECT SOURCE TYPE");
            if (!document.getElementById('from_institution').value) errors.push("SELECT SOURCE INSTITUTION");
            
            if (swapType === 'self') {
                if (!document.getElementById('to_type').value) errors.push("SELECT DESTINATION TYPE");
                if (!document.getElementById('to_institution').value) errors.push("SELECT DESTINATION INSTITUTION");
            }
            
            document.getElementById('validationErrors').innerHTML = errors.join('<br>');
            return errors.length === 0;
        }

        async function executeSwap(formData) {
            const btn = document.getElementById('executeSwapBtn');
            btn.disabled = true;
            btn.textContent = 'PROCESSING...';
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                const text = await response.text();
                const jsonMatch = text.match(/\{.*\}/s);
                const data = jsonMatch ? JSON.parse(jsonMatch[0]) : null;
                
                if (data?.status === 'success') {
                    const container = document.getElementById('swapReportContainer');
                    const content = document.getElementById('swapReportContent');
                    
                    let feeInfo = '';
                    if (data.delivery_mode === 'cashout') {
                        feeInfo = `<tr><td>FEE:</td><td class="positive">-10.00 BWP</td></tr><tr><td>NET:</td><td class="positive">${(data.amount - 10).toFixed(2)} BWP</td></tr>`;
                    } else {
                        feeInfo = `<tr><td>FEE:</td><td class="positive">-6.00 BWP</td></tr><tr><td>NET:</td><td class="positive">${(data.amount - 6).toFixed(2)} BWP</td></tr>`;
                    }
                    
                    content.innerHTML = `
                        <div style="padding: 1.5rem;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                                <div>
                                    <h4 style="color: #888; margin-bottom: 1rem;">TRANSACTION</h4>
                                    <table style="width: 100%;">
                                        <tr><td>REFERENCE:</td><td class="mono">${data.swap_reference.substring(0, 16)}…</td></tr>
                                        <tr><td>TRANSACTION ID:</td><td class="mono">${data.transaction_id || 'N/A'}</td></tr>
                                        <tr><td>AMOUNT:</td><td class="positive">${data.amount.toFixed(2)} BWP</td></tr>
                                        ${feeInfo}
                                        <tr><td>HOLD REF:</td><td class="mono">${data.hold_reference ? data.hold_reference.substring(0, 8) + '…' : 'N/A'}</td></tr>
                                    </table>
                                </div>
                                ${data.dispensed_notes && Object.keys(data.dispensed_notes).length > 0 ? `
                                <div>
                                    <h4 style="color: #888; margin-bottom: 1rem;">ATM DISPENSE</h4>
                                    <div style="background: #000; padding: 1rem;">
                                        ${Object.entries(data.dispensed_notes).map(([note, count]) => 
                                            `<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                                <span>${count} × BWP ${note}</span>
                                                <span class="positive">${(count * note).toFixed(2)} BWP</span>
                                            </div>`
                                        ).join('')}
                                        <div style="border-top: 1px solid #333; margin-top: 0.5rem; padding-top: 0.5rem; display: flex; justify-content: space-between;">
                                            <span>TOTAL</span>
                                            <span class="positive">${data.amount.toFixed(2)} BWP</span>
                                        </div>
                                    </div>
                                </div>
                                ` : ''}
                                ${data.voucher ? `
                                <div>
                                    <h4 style="color: #888; margin-bottom: 1rem;">VOUCHER DETAILS</h4>
                                    <div style="background: #000; padding: 1rem;">
                                        <div>CODE: ****-${data.voucher.code_suffix}</div>
                                        <div>EXPIRY: ${data.voucher.expiry}</div>
                                        <div>AMOUNT: ${data.voucher.amount} BWP</div>
                                    </div>
                                </div>
                                ` : ''}
                            </div>
                            <div style="margin-top: 1rem; padding: 1rem; background: #000; border: 1px solid #333;">
                                <div style="color: #0f0;">✓ Added to settlement queue for netting</div>
                            </div>
                        </div>
                    `;
                    container.style.display = 'block';
                    container.scrollIntoView({ behavior: 'smooth' });
                } else {
                    alert('FAILED: ' + (data?.message || 'UNKNOWN ERROR'));
                }
            } catch (error) {
                alert('ERROR: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.textContent = 'EXECUTE';
            }
        }

        async function generateSettlementReport() {
            const reportType = document.getElementById('reportType').value;
            const reportDate = document.getElementById('reportDate').value;
            
            const formData = new FormData();
            formData.append('generate_settlement_report', '1');
            formData.append('report_type', reportType);
            formData.append('report_date', reportDate);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    displaySettlementReport(data);
                } else {
                    alert('Report generation failed: ' + data.error);
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }

        function displaySettlementReport(data) {
            const container = document.getElementById('settlementReport');
            container.style.display = 'block';
            
            let html = `
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">${data.report_type.toUpperCase()} SETTLEMENT REPORT - ${data.report_date}</div>
                        <div class="card-badge">NETTED</div>
                    </div>
                    <div class="settlement-summary">
                        <div class="summary-item">
                            <div class="summary-label">TOTAL SETTLEMENTS</div>
                            <div class="summary-value">${data.total_settlements}</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-label">TOTAL AMOUNT</div>
                            <div class="summary-value">${data.total_amount?.toFixed(2) || data.total_gross_amount?.toFixed(2)} BWP</div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>DEBTOR</th>
                                    <th>CREDITOR</th>
                                    <th class="text-right">AMOUNT</th>
                                    <th>POSITION</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            if (data.settlements) {
                data.settlements.forEach(s => {
                    html += `
                        <tr>
                            <td>${s.debtor_name || s.debtor}</td>
                            <td>${s.creditor_name || s.creditor}</td>
                            <td class="text-right positive">${(s.total_amount || s.gross_amount).toFixed(2)} BWP</td>
                            <td><span class="status status-pending">SETTLE</span></td>
                        </tr>
                    `;
                });
            }
            
            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
        }

        // INIT
        document.addEventListener('DOMContentLoaded', function() {
            if (currentView === 'swap') {
                updateDeliveryMode();
                updateSourceFields();
                updateDestinationFields();
                toggleBusinessFields();
                
                document.getElementById('from_type')?.addEventListener('change', updateSourceFields);
                document.getElementById('to_type')?.addEventListener('change', updateDestinationFields);
                
                document.querySelectorAll('input[name="swap_type"]').forEach(radio => {
                    radio.addEventListener('change', toggleBusinessFields);
                });
                
                document.querySelectorAll('input[name="delivery_mode"]').forEach(radio => {
                    radio.addEventListener('change', updateDeliveryMode);
                });
                
                document.getElementById('addRecipientBtn')?.addEventListener('click', addRecipientRow);
                
                document.getElementById('swapForm')?.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    if (validateForm()) {
                        await executeSwap(new FormData(this));
                    }
                });
            }
        });
    </script>
</body>
</html>
