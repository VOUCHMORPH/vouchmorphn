<?php
// public/user/user_dashboard.php - COMPLETE FIXED VERSION

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

const VM_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
function vm_json($value) { return json_encode($value, VM_JSON_FLAGS); }
function vm_json_response($value) { while (ob_get_level() > 0) ob_end_clean(); header('Content-Type: application/json; charset=utf-8'); echo vm_json($value); exit; }
function vm_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    if ($isAjax) { http_response_code(401); vm_json_response(['status' => 'error', 'message' => 'Session expired']); }
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';
$hasTransactionPin = $user['has_transaction_pin'] ?? false;

define('CONFIG_BASE_PATH', __DIR__ . '/../../src/Core/Config/Countries/');

function loadCountryConfiguration($countryName) {
    $countryPath = CONFIG_BASE_PATH . $countryName . '/';
    
    if (!is_dir($countryPath)) {
        error_log("[Dashboard] Country directory not found: " . $countryPath);
        return null;
    }
    
    $config = [
        'participants' => [],
        'fees' => [],
        'settings' => [],
        'database' => [],
        'currency' => 'BWP',
        'currency_symbol' => 'P',
        'dial_code' => '+267'
    ];
    
    $participantsPath = $countryPath . 'participants.json';
    if (file_exists($participantsPath)) {
        $content = file_get_contents($participantsPath);
        $config['participants_raw'] = json_decode($content, true);
        if (isset($config['participants_raw']['participants'])) {
            $config['participants'] = $config['participants_raw']['participants'];
        }
    }
    
    $feesPath = $countryPath . 'fees.json';
    if (file_exists($feesPath)) {
        $content = file_get_contents($feesPath);
        $feesData = json_decode($content, true);
        if ($feesData) {
            $config['fees'] = $feesData;
            $config['currency'] = $feesData['currency'] ?? $config['currency'];
        }
    }
    
    $configPath = $countryPath . 'config.php';
    if (file_exists($configPath)) {
        $phpConfig = require $configPath;
        if (is_array($phpConfig)) {
            $config['settings'] = $phpConfig;
            $config['currency'] = $phpConfig['currency'] ?? $config['currency'];
            $config['currency_symbol'] = $phpConfig['currency_symbol'] ?? $config['currency_symbol'];
            $config['dial_code'] = $phpConfig['dial_code'] ?? $config['dial_code'];
        }
    }
    
    $dbPath = $countryPath . 'database.php';
    if (file_exists($dbPath)) {
        $config['database'] = require $dbPath;
    }
    
    $banksPath = $countryPath . 'banks.json';
    if (file_exists($banksPath)) {
        $content = file_get_contents($banksPath);
        $config['banks'] = json_decode($content, true);
    }
    
    return $config;
}

$countryConfig = loadCountryConfiguration($userCountry);

$allParticipants = [];
$sourceParticipants = [];
$destinationCountries = [];
$allAssetTypes = [];

function getAssetIcon($type) {
    $icons = [
        'ACCOUNT' => '🏦', 'VOUCHER' => '🎫', 'ATM' => '🏧', 'E-WALLET' => '📱',
        'WALLET' => '👛', 'CARD' => '💳', 'BANK_ACCOUNT' => '🏦', 'MOBILE_WALLET' => '📱',
        'MNO-WALLET' => '📱', 'BANK-WALLET' => '🏦', 'CASHOUT-VOUCHER' => '🎫', 'AIRTIME' => '📞'
    ];
    return $icons[$type] ?? '📄';
}

function getIdentificationMethods($assetType) {
    $methods = [
        'ACCOUNT' => [
            ['field' => 'account_number', 'label' => 'Account Number', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter account number'],
            ['field' => 'account_pin', 'label' => 'Account PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'MNO-WALLET' => [
            ['field' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'placeholder' => 'Enter mobile number'],
            ['field' => 'pin', 'label' => 'Wallet PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'BANK-WALLET' => [
            ['field' => 'wallet_id', 'label' => 'Wallet ID', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter wallet ID'],
            ['field' => 'wallet_pin', 'label' => 'Wallet PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'CASHOUT-VOUCHER' => [
            ['field' => 'voucher_code', 'label' => 'Voucher Code', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter voucher code'],
            ['field' => 'voucher_pin', 'label' => 'Voucher PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'AIRTIME' => [
            ['field' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'placeholder' => 'Enter mobile number']
        ],
        'ATM' => [
            ['field' => 'card_number', 'label' => 'ATM Card Number', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter card number'],
            ['field' => 'atm_pin', 'label' => 'ATM PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'E-WALLET' => [
            ['field' => 'wallet_phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'placeholder' => 'Enter mobile number'],
            ['field' => 'wallet_pin', 'label' => 'Wallet PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'VOUCHER' => [
            ['field' => 'voucher_number', 'label' => 'Voucher Number', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter voucher code'],
            ['field' => 'voucher_pin', 'label' => 'Voucher PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ],
        'CARD' => [
            ['field' => 'card_number', 'label' => 'Card Number', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter card number'],
            ['field' => 'card_pin', 'label' => 'Card PIN', 'type' => 'password', 'required' => true, 'placeholder' => 'Enter PIN']
        ]
    ];
    return $methods[$assetType] ?? [
        ['field' => 'identifier', 'label' => 'Identifier', 'type' => 'text', 'required' => true, 'placeholder' => 'Enter identifier']
    ];
}

if ($countryConfig && !empty($countryConfig['participants'])) {
    foreach ($countryConfig['participants'] as $code => $participantData) {
        $assetTypes = [];
        
        $capabilities = $participantData['capabilities'] ?? [];
        $rawAssetTypes = $capabilities['asset_types'] ?? [];
        
        foreach ($rawAssetTypes as $assetType) {
            $assetTypes[] = [
                'type' => $assetType,
                'name' => ucfirst(strtolower(str_replace('_', ' ', $assetType))),
                'icon' => getAssetIcon($assetType),
                'supports_oauth' => isset($participantData['security']['oauth2']),
                'identification_methods' => getIdentificationMethods($assetType)
            ];
            
            if (!isset($allAssetTypes[$assetType])) {
                $allAssetTypes[$assetType] = [
                    'type' => $assetType,
                    'name' => ucfirst(strtolower(str_replace('_', ' ', $assetType))),
                    'icon' => getAssetIcon($assetType),
                    'institutions' => []
                ];
            }
            if (!in_array($code, $allAssetTypes[$assetType]['institutions'])) {
                $allAssetTypes[$assetType]['institutions'][] = $code;
            }
        }
        
        $participantCountry = $participantData['country'] ?? $userCountry;
        
        $participant = [
            'code' => $code,
            'name' => $participantData['name'] ?? $participantData['provider_code'] ?? $code,
            'country' => $participantCountry,
            'currency' => $participantData['settlement']['currency'] ?? $countryConfig['currency'],
            'asset_types' => $assetTypes,
            'status' => $participantData['status'] ?? 'ACTIVE',
            'base_url' => $participantData['base_url'] ?? '',
            'resource_endpoints' => $participantData['resource_endpoints'] ?? [],
            'oauth_config' => $participantData['security']['oauth2'] ?? null,
            'type' => $participantData['type'] ?? 'FINANCIAL_INSTITUTION',
            'category' => $participantData['category'] ?? 'BANK'
        ];
        
        $allParticipants[$code] = $participant;
        
        if ($participantCountry === $userCountry && $participant['status'] === 'ACTIVE') {
            $sourceParticipants[$code] = $participant;
        }
        
        $destinationCountries[$participantCountry] = true;
    }
}

$destinationCountries = array_keys($destinationCountries);
$userCurrency = $countryConfig['currency'] ?? 'BWP';
$userCurrencySymbol = $countryConfig['currency_symbol'] ?? 'P';
$dialCode = $countryConfig['dial_code'] ?? '+267';

try {
    $dbConfig = $countryConfig['database']['swap'] ?? $countryConfig['database'] ?? null;
    if ($dbConfig && is_array($dbConfig) && isset($dbConfig['host'])) {
        $db = DBConnection::getInstance($dbConfig);
    } else {
        $db = DBConnection::getInstance();
    }
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
} catch (\Throwable $e) {
    error_log("[Dashboard] DB Error: " . $e->getMessage());
    $db = null;
}

// ============================================================
// PIN REFRESH - MOVED HERE AFTER DATABASE IS CONNECTED
// ============================================================
if ($db && $userId) {
    try {
        $stmt = $db->prepare("SELECT has_transaction_pin FROM users WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $dbPinStatus = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($dbPinStatus) {
            $hasTransactionPin = (bool)$dbPinStatus['has_transaction_pin'];
            // Update session to match database
            $user['has_transaction_pin'] = $hasTransactionPin;
            SessionManager::setUser($user);
        }
    } catch (\Throwable $e) {
        error_log("Failed to refresh PIN status: " . $e->getMessage());
    }
}

$fundingSources = [];
$bankConnections = [];

if ($db && $userId) {
    try {
        $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE user_id = :user_id AND status = 'ACTIVE' ORDER BY created_at DESC");
        $stmt->execute([':user_id' => $userId]);
        $fundingSources = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
    
    try {
        $stmt = $db->prepare("SELECT * FROM user_bank_connections WHERE user_id = :user_id AND status = 'ACTIVE'");
        $stmt->execute([':user_id' => $userId]);
        $bankConnections = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

function userHasTransactionPin($db, $userId) { 
    if (!$db) return false;
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); 
    $stmt->execute([':user_id' => $userId]); 
    $result = $stmt->fetch(\PDO::FETCH_ASSOC); 
    return !empty($result['transaction_pin_hash']); 
}

function verifyTransactionPin($db, $userId, $pin) { 
    if (!$db) return false;
    $stmt = $db->prepare("SELECT transaction_pin_hash FROM users WHERE user_id = :user_id LIMIT 1"); 
    $stmt->execute([':user_id' => $userId]); 
    $result = $stmt->fetch(\PDO::FETCH_ASSOC); 
    if (!$result || empty($result['transaction_pin_hash'])) return false; 
    return password_verify($pin, $result['transaction_pin_hash']); 
}

function setTransactionPin($db, $userId, $pin) { 
    if (!$db) return false;
    $hash = password_hash($pin, PASSWORD_DEFAULT); 
    $stmt = $db->prepare("UPDATE users SET transaction_pin_hash = :hash, has_transaction_pin = true WHERE user_id = :user_id"); 
    return $stmt->execute([':hash' => $hash, ':user_id' => $userId]); 
}

function callParticipantApi($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        return ['status' => 'error', 'message' => "CURL error: {$curlError}"];
    }
    if ($httpCode !== 200) {
        return ['status' => 'error', 'message' => "HTTP {$httpCode}"];
    }
    
    $decoded = json_decode($response, true);
    return $decoded ?: ['status' => 'error', 'message' => 'Invalid response'];
}

if ($isAjax) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if ($action === 'check_transaction_pin') { 
        vm_json_response(['has_pin' => userHasTransactionPin($db, $userId)]); 
    }
    
    if ($action === 'set_transaction_pin') {
        try { 
            $pin = trim($_POST['pin'] ?? ''); 
            $confirmPin = trim($_POST['confirm_pin'] ?? ''); 
            if (strlen($pin) !== 6 || !ctype_digit($pin)) throw new Exception('PIN must be 6 digits'); 
            if ($pin !== $confirmPin) throw new Exception('PINs do not match'); 
            if (setTransactionPin($db, $userId, $pin)) { 
                $user['has_transaction_pin'] = true; 
                SessionManager::setUser($user); 
                vm_json_response(['status' => 'success', 'message' => 'Transaction PIN set successfully']); 
            } else throw new Exception('Failed to set PIN'); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'verify_transaction_pin') {
        try { 
            $pin = trim($_POST['pin'] ?? ''); 
            $operation = $_POST['operation'] ?? 'swap'; 
            if (empty($pin)) throw new Exception('PIN is required'); 
            if (!verifyTransactionPin($db, $userId, $pin)) throw new Exception('Invalid transaction PIN'); 
            $consentToken = bin2hex(random_bytes(32)); 
            $_SESSION['consent_token_' . $operation] = ['token' => $consentToken, 'expires' => time() + 300]; 
            vm_json_response(['status' => 'success', 'message' => 'PIN verified', 'consent_token' => $consentToken]); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'change_password') {
        try { 
            $currentPassword = $_POST['current_password'] ?? ''; 
            $newPassword = $_POST['new_password'] ?? ''; 
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = :user_id"); 
            $stmt->execute([':user_id' => $userId]); 
            $userData = $stmt->fetch(\PDO::FETCH_ASSOC); 
            if (!password_verify($currentPassword, $userData['password_hash'])) throw new Exception('Current password is incorrect'); 
            if (strlen($newPassword) < 6) throw new Exception('Password must be at least 6 characters'); 
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT); 
            $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE user_id = :user_id"); 
            $stmt->execute([':hash' => $newHash, ':user_id' => $userId]); 
            vm_json_response(['status' => 'success', 'message' => 'Password changed successfully']); 
        } catch (Exception $e) { vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); }
    }
    
    if ($action === 'get_destination_institutions') { 
        $country = $_POST['country'] ?? $_GET['country'] ?? ''; 
        $instList = []; 
        foreach ($allParticipants as $code => $p) { 
            if ($p['country'] === $country && ($p['status'] ?? 'ACTIVE') === 'ACTIVE') { 
                $instList[] = ['code' => $code, 'name' => $p['name'] ?? $code]; 
            } 
        } 
        vm_json_response(['success' => true, 'institutions' => $instList]); 
    }
    
    if ($action === 'save_manual_source') {
        try { 
            $consentToken = $_POST['consent_token'] ?? ''; 
            $operation = 'save_source'; 
            if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $institutionCode = trim($_POST['institution_code'] ?? ''); 
            $assetType = trim($_POST['asset_type'] ?? ''); 
            $identificationData = json_decode($_POST['identification_data'] ?? '{}', true);
            
            $participant = $allParticipants[$institutionCode] ?? null;
            if (!$participant) throw new Exception("Institution not found");
            
            $identifierParts = [];
            foreach ($identificationData as $key => $value) {
                if ($value) $identifierParts[] = "$key:" . substr($value, -4);
            }
            $maskedId = implode('|', $identifierParts);
            $institutionName = $participant['name'] ?? $institutionCode;
            
            $stmt = $db->prepare("INSERT INTO user_funding_sources (user_id, institution_code, institution_name, institution_country, source_type, masked_identifier, linked_phone, metadata, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', NOW())");
            $stmt->execute([
                $userId, $institutionCode, $institutionName, $participant['country'] ?? $userCountry,
                $assetType, $maskedId, $userPhone, json_encode($identificationData)
            ]);
            
            unset($_SESSION['consent_token_' . $operation]);
            vm_json_response(['status' => 'success', 'message' => 'Source saved successfully!']);
        } catch (Exception $e) { 
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); 
        }
    }
    
    if ($action === 'swap_linked') {
        try { 
            $consentToken = $_POST['consent_token'] ?? ''; 
            $operation = 'swap'; 
            if (!isset($_SESSION['consent_token_' . $operation]) || $_SESSION['consent_token_' . $operation]['token'] !== $consentToken || $_SESSION['consent_token_' . $operation]['expires'] < time()) {
                throw new Exception('Transaction authorization required or expired');
            }
            
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $destCountry = trim($_POST['dest_country'] ?? '');
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destIdentifier = trim($_POST['dest_identifier'] ?? '');
            
            if ($amount < 10) throw new Exception('Minimum amount is ' . $userCurrencySymbol . '10.00');
            if (!$sourceId) throw new Exception('Source required');
            if (!$destInstitution) throw new Exception('Destination institution required');
            if (!$destIdentifier) throw new Exception('Destination identifier required');
            
            $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE id = :id AND user_id = :user_id AND status = 'ACTIVE'");
            $stmt->execute(['id' => $sourceId, 'user_id' => $userId]);
            $source = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$source) throw new Exception('Source not found');
            
            $sourceParticipant = $allParticipants[$source['institution_code']] ?? null;
            $destParticipant = $allParticipants[$destInstitution] ?? null;
            if (!$sourceParticipant) throw new Exception('Source institution not configured');
            if (!$destParticipant) throw new Exception('Destination institution not configured');
            
            $storedCredentials = json_decode($source['metadata'], true);
            
            $verifyPayload = [
                'reference' => 'VM-LINKED-' . time(),
                'asset_type' => $source['source_type'],
                'amount' => $amount,
                'credentials' => $storedCredentials
            ];
            
            $verifyResult = callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['verify_asset'] ?? '/api/v1/verify_asset.php'), $verifyPayload);
            if (!$verifyResult['verified']) throw new Exception('Verification failed: ' . ($verifyResult['message'] ?? 'Unknown error'));
            
            $holdPayload = [
                'reference' => 'VM-HOLD-' . time(),
                'asset_id' => $verifyResult['asset_id'],
                'amount' => $amount,
                'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ];
            $holdResult = callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['place_hold'] ?? '/api/v1/hold.php'), $holdPayload);
            if (!$holdResult['hold_placed']) throw new Exception('Hold failed: ' . ($holdResult['message'] ?? 'Unknown error'));
            
            $depositPayload = [
                'reference' => 'VM-DEP-' . time(),
                'source_institution' => $source['institution_code'],
                'destination_type' => $destParticipant['asset_types'][0]['type'] ?? 'ACCOUNT',
                'destination_id' => $destIdentifier,
                'amount' => $amount,
                'source_hold_reference' => $holdResult['hold_reference']
            ];
            $depositResult = callParticipantApi($destParticipant['base_url'] . ($destParticipant['resource_endpoints']['process_deposit'] ?? '/api/v1/deposit/direct.php'), $depositPayload);
            
            if (!$depositResult['processed']) {
                callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['release_hold'] ?? '/api/v1/hold.php'), ['hold_reference' => $holdResult['hold_reference'], 'reason' => 'Deposit failed']);
                throw new Exception('Deposit failed: ' . ($depositResult['message'] ?? 'Unknown error'));
            }
            
            $debitPayload = ['hold_reference' => $holdResult['hold_reference'], 'amount' => $amount, 'destination_details' => ['institution' => $destInstitution, 'identifier' => $destIdentifier]];
            callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['debit_funds'] ?? '/api/v1/notify_debit.php'), $debitPayload);
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            
            $stmt = $db->prepare("INSERT INTO swap_transactions (swap_reference, user_id, source_institution, source_asset_type, destination_institution, destination_identifier, amount, status, is_linked, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 1, NOW())");
            $stmt->execute([$swapReference, $userId, $source['institution_code'], $source['source_type'], $destInstitution, $destIdentifier, $amount]);
            
            $stmt = $db->prepare("INSERT INTO settlement_obligations (reference, from_participant, to_participant, amount, status, swap_reference, created_at) VALUES (?, ?, ?, ?, 'PENDING', ?, NOW())");
            $stmt->execute(['SETTLE-' . $swapReference, $source['institution_code'], $destInstitution, $amount, $swapReference]);
            
            unset($_SESSION['consent_token_' . $operation]);
            
            vm_json_response(['status' => 'success', 'message' => 'Swap completed successfully', 'swap_reference' => $swapReference, 'transaction_reference' => $depositResult['transaction_reference'] ?? null]);
        } catch (Exception $e) { 
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); 
        }
    }
    
    if ($action === 'swap_adhoc') {
        try { 
            $sourceInstitution = trim($_POST['source_institution'] ?? '');
            $assetType = trim($_POST['asset_type'] ?? '');
            $credentials = json_decode($_POST['credentials'] ?? '{}', true);
            $amount = (float)($_POST['amount'] ?? 0);
            $destCountry = trim($_POST['dest_country'] ?? '');
            $destInstitution = trim($_POST['dest_institution'] ?? '');
            $destIdentifier = trim($_POST['dest_identifier'] ?? '');
            
            if ($amount < 10) throw new Exception('Minimum amount is ' . $userCurrencySymbol . '10.00');
            if (empty($sourceInstitution)) throw new Exception('Source institution required');
            if (empty($destInstitution)) throw new Exception('Destination institution required');
            if (empty($destIdentifier)) throw new Exception('Destination identifier required');
            if (empty($credentials)) throw new Exception('Institution credentials required');
            
            $sourceParticipant = $allParticipants[$sourceInstitution] ?? null;
            $destParticipant = $allParticipants[$destInstitution] ?? null;
            if (!$sourceParticipant) throw new Exception('Source institution not configured');
            if (!$destParticipant) throw new Exception('Destination institution not configured');
            
            $verifyPayload = [
                'reference' => 'VM-ADHOC-' . time(),
                'asset_type' => $assetType,
                'amount' => $amount,
                'credentials' => $credentials
            ];
            $verifyResult = callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['verify_asset'] ?? '/api/v1/verify_asset.php'), $verifyPayload);
            if (!$verifyResult['verified']) throw new Exception('Verification failed: ' . ($verifyResult['message'] ?? 'Invalid credentials'));
            
            $holdPayload = [
                'reference' => 'VM-HOLD-' . time(),
                'asset_id' => $verifyResult['asset_id'],
                'amount' => $amount,
                'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ];
            $holdResult = callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['place_hold'] ?? '/api/v1/hold.php'), $holdPayload);
            if (!$holdResult['hold_placed']) throw new Exception('Hold failed: ' . ($holdResult['message'] ?? 'Unknown error'));
            
            $depositPayload = [
                'reference' => 'VM-DEP-' . time(),
                'source_institution' => $sourceInstitution,
                'destination_type' => $destParticipant['asset_types'][0]['type'] ?? 'ACCOUNT',
                'destination_id' => $destIdentifier,
                'amount' => $amount,
                'source_hold_reference' => $holdResult['hold_reference']
            ];
            $depositResult = callParticipantApi($destParticipant['base_url'] . ($destParticipant['resource_endpoints']['process_deposit'] ?? '/api/v1/deposit/direct.php'), $depositPayload);
            
            if (!$depositResult['processed']) {
                callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['release_hold'] ?? '/api/v1/hold.php'), ['hold_reference' => $holdResult['hold_reference'], 'reason' => 'Deposit failed']);
                throw new Exception('Deposit failed: ' . ($depositResult['message'] ?? 'Unknown error'));
            }
            
            $debitPayload = ['hold_reference' => $holdResult['hold_reference'], 'amount' => $amount, 'destination_details' => ['institution' => $destInstitution, 'identifier' => $destIdentifier]];
            callParticipantApi($sourceParticipant['base_url'] . ($sourceParticipant['resource_endpoints']['debit_funds'] ?? '/api/v1/notify_debit.php'), $debitPayload);
            
            $swapReference = 'VM-' . strtoupper(bin2hex(random_bytes(4))) . '-' . date('His');
            
            $stmt = $db->prepare("INSERT INTO swap_transactions (swap_reference, user_id, source_institution, source_asset_type, destination_institution, destination_identifier, amount, status, is_linked, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed', 0, NOW())");
            $stmt->execute([$swapReference, $userId, $sourceInstitution, $assetType, $destInstitution, $destIdentifier, $amount]);
            
            $stmt = $db->prepare("INSERT INTO settlement_obligations (reference, from_participant, to_participant, amount, status, swap_reference, created_at) VALUES (?, ?, ?, ?, 'PENDING', ?, NOW())");
            $stmt->execute(['SETTLE-' . $swapReference, $sourceInstitution, $destInstitution, $amount, $swapReference]);
            
            vm_json_response(['status' => 'success', 'message' => 'Swap completed successfully', 'swap_reference' => $swapReference, 'transaction_reference' => $depositResult['transaction_reference'] ?? null]);
        } catch (Exception $e) { 
            vm_json_response(['status' => 'error', 'message' => $e->getMessage()]); 
        }
    }
    
    vm_json_response(['status' => 'error', 'message' => 'Invalid action']);
}

$participantsForJs = [];
foreach ($allParticipants as $code => $p) {
    $participantsForJs[] = [
        'code' => $code,
        'name' => $p['name'],
        'country' => $p['country'],
        'currency' => $p['currency'],
        'asset_types' => $p['asset_types'],
        'base_url' => $p['base_url'],
        'resource_endpoints' => $p['resource_endpoints'],
        'has_oauth' => !empty($p['oauth_config']),
        'type' => $p['type'],
        'category' => $p['category']
    ];
}

$fundingSourcesForJs = [];
foreach ($fundingSources as $fs) {
    $fundingSourcesForJs[] = [
        'id' => $fs['id'],
        'code' => $fs['institution_code'],
        'type' => $fs['source_type'],
        'name' => $fs['institution_name'],
        'masked' => $fs['masked_identifier']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VOUCHMORPH | DASHBOARD</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #000000; font-family: 'Space Grotesk', monospace; color: #FFFFFF; letter-spacing: -0.02em; line-height: 1; }
    .app { position: fixed; top: 0; left: 0; right: 0; bottom: 0; display: grid; grid-template-columns: 80px 1fr 420px; grid-template-rows: 80px 1fr; }
    .nav-rail { grid-row: 1 / 3; grid-column: 1; border-right: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; justify-content: space-between; padding: 24px 0 32px; }
    .nav-logo { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 12px; font-weight: 400; letter-spacing: 4px; color: rgba(255,255,255,0.3); text-align: center; }
    .nav-bottom { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.15); text-align: center; }
    .top-bar { grid-column: 2 / 4; grid-row: 1; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; justify-content: flex-end; align-items: center; padding: 0 32px; gap: 24px; }
    .top-stat { font-size: 11px; letter-spacing: 1px; color: rgba(255,255,255,0.3); }
    .top-stat strong { color: #FFFFFF; font-weight: 500; margin-left: 8px; }
    .user-badge { width: 32px; height: 32px; border: 1px solid rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; cursor: pointer; transition: all 0.1s ease; }
    .user-badge:hover { border-color: #FFFFFF; background: #FFFFFF; color: #000000; }
    .main-content { grid-column: 2; grid-row: 2; overflow-y: auto; padding: 32px; }
    .action-panel { grid-column: 3; grid-row: 2; border-left: 1px solid rgba(255,255,255,0.08); overflow-y: auto; background: #000000; }
    .stat-block { margin-bottom: 48px; }
    .stat-label { font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.25); text-transform: uppercase; margin-bottom: 8px; }
    .stat-value { font-size: 64px; font-weight: 500; letter-spacing: -0.04em; line-height: 1; }
    .currency-display { font-size: 11px; color: rgba(255,255,255,0.3); margin-left: 8px; }
    .option-grid { display: none; grid-template-columns: 1fr 1fr; gap: 1px; background: rgba(255,255,255,0.08); margin-bottom: 48px; }
    .option-grid.active { display: grid; }
    .option-btn { background: #000000; padding: 32px 24px; border: none; text-align: left; cursor: pointer; transition: all 0.1s ease; }
    .option-btn:hover { background: #FFFFFF; }
    .option-btn:hover .option-title, .option-btn:hover .option-desc { color: #000000; }
    .option-title { font-size: 14px; font-weight: 500; letter-spacing: 1px; color: #FFFFFF; margin-bottom: 4px; text-transform: uppercase; }
    .option-desc { font-size: 10px; color: rgba(255,255,255,0.3); letter-spacing: 0.5px; }
    .trigger-btn { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.15); padding: 20px 24px; font-family: 'Space Grotesk', monospace; font-size: 13px; font-weight: 400; letter-spacing: 2px; color: #FFFFFF; cursor: pointer; text-align: left; transition: all 0.1s ease; margin-bottom: 16px; }
    .trigger-btn:hover { border-color: #FFFFFF; background: #FFFFFF; color: #000000; }
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.98); z-index: 1000; display: none; align-items: center; justify-content: center; }
    .modal { width: 480px; max-width: 90%; background: #000000; border: 1px solid rgba(255,255,255,0.15); }
    .modal-header { padding: 28px 32px 16px 32px; border-bottom: 1px solid rgba(255,255,255,0.08); font-size: 18px; font-weight: 500; letter-spacing: 1px; text-transform: uppercase; color: #FFFFFF; }
    .modal-body { padding: 32px; }
    .modal-footer { padding: 20px 32px 32px 32px; border-top: 1px solid rgba(255,255,255,0.08); display: flex; gap: 12px; justify-content: flex-end; }
    .sharp-confirm { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.98); z-index: 1100; display: none; align-items: center; justify-content: center; }
    .sharp-confirm .modal { width: 400px; }
    .sharp-confirm .modal-body { text-align: center; font-size: 14px; letter-spacing: 0.5px; padding: 40px 32px; }
    .institution-list, .asset-list { max-height: 400px; overflow-y: auto; }
    .institution-item, .asset-item { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.06); cursor: pointer; transition: all 0.1s ease; text-align: left; }
    .institution-item:hover, .asset-item:hover { background: rgba(255,255,255,0.03); border-left: 2px solid #FFFFFF; padding-left: 22px; }
    .institution-name, .asset-name { font-weight: 500; font-size: 14px; letter-spacing: 0.5px; }
    .institution-country, .asset-icon { font-size: 10px; color: rgba(255,255,255,0.3); margin-top: 4px; letter-spacing: 0.5px; }
    .oauth-badge { font-size: 9px; color: #FFFFFF; border: 1px solid rgba(255,255,255,0.3); padding: 4px 8px; letter-spacing: 1px; }
    .form-field { margin-bottom: 20px; text-align: left; }
    .form-label { font-size: 10px; letter-spacing: 1px; color: rgba(255,255,255,0.5); margin-bottom: 8px; display: block; text-transform: uppercase; }
    .form-input { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.1); padding: 14px 16px; font-family: 'Space Grotesk', monospace; font-size: 13px; color: #FFFFFF; transition: all 0.1s ease; }
    .form-input:focus { outline: none; border-color: #FFFFFF; }
    .pin-dots { display: flex; justify-content: center; gap: 16px; margin: 32px 0 40px 0; }
    .pin-dot { width: 12px; height: 12px; border: 1px solid rgba(255,255,255,0.3); transition: all 0.1s ease; }
    .pin-dot.filled { background: #FFFFFF; border-color: #FFFFFF; }
    .pin-numpad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 24px; }
    .numpad-btn { background: transparent; border: 1px solid rgba(255,255,255,0.1); padding: 18px; font-size: 20px; font-family: 'Space Grotesk', monospace; font-weight: 400; color: #FFFFFF; cursor: pointer; transition: all 0.05s linear; }
    .numpad-btn:active { background: #FFFFFF; color: #000000; }
    .modal-btn { background: transparent; border: 1px solid rgba(255,255,255,0.2); padding: 12px 24px; font-family: 'Space Grotesk', monospace; font-size: 10px; letter-spacing: 2px; color: rgba(255,255,255,0.6); cursor: pointer; transition: all 0.1s ease; }
    .modal-btn:hover { border-color: #FFFFFF; color: #FFFFFF; }
    .modal-btn-primary { background: #FFFFFF; border: 1px solid #FFFFFF; color: #000000; }
    .modal-btn-primary:hover { opacity: 0.9; }
    .error-text { color: #ff4444; font-size: 11px; letter-spacing: 0.5px; margin-top: 12px; text-align: center; }
    .source-item { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 12px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; }
    .source-item:hover { background: rgba(255,255,255,0.03); }
    .bank-connection { background: rgba(255,255,255,0.03); padding: 12px; margin-bottom: 8px; border-left: 2px solid #FFFFFF; cursor: pointer; }
    .bank-connection:hover { background: rgba(255,255,255,0.08); }
    .swap-select { width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.1); padding: 14px 16px; font-family: 'Space Grotesk', monospace; font-size: 13px; color: #FFFFFF; margin-bottom: 16px; cursor: pointer; }
    .swap-select option { background: #000000; }
    .swap-row { display: flex; gap: 12px; margin-bottom: 16px; }
    .swap-input { flex: 1; background: transparent; border: 1px solid rgba(255,255,255,0.1); padding: 14px 16px; font-family: 'Space Grotesk', monospace; font-size: 13px; color: #FFFFFF; }
    .swap-input:focus { outline: none; border-color: #FFFFFF; }
    .panel-label { font-size: 9px; letter-spacing: 2px; color: rgba(255,255,255,0.2); text-transform: uppercase; margin-bottom: 16px; }
    .execute-btn { width: 100%; background: #FFFFFF; border: none; padding: 16px; font-family: 'Space Grotesk', monospace; font-size: 12px; font-weight: 500; letter-spacing: 2px; color: #000000; cursor: pointer; margin-top: 16px; transition: all 0.1s ease; }
    .execute-btn:hover { opacity: 0.9; }
    .mode-badge { font-size: 9px; padding: 4px 8px; border-radius: 0; margin-left: 8px; }
    .badge-linked { background: rgba(76, 175, 80, 0.2); border: 1px solid #4CAF50; color: #4CAF50; }
    .badge-adhoc { background: rgba(255, 152, 0, 0.2); border: 1px solid #FF9800; color: #FF9800; }
    .section-divider { margin: 24px 0 16px 0; padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.08); }
    ::-webkit-scrollbar { width: 0; background: transparent; }
    @media (max-width: 1024px) { .app { grid-template-columns: 60px 1fr; } .action-panel { position: fixed; right: -100%; width: 100%; max-width: 420px; transition: right 0.2s ease; z-index: 100; } .action-panel.open { right: 0; } }
</style>
</head>
<body>

<div class="app">
    <div class="nav-rail">
        <div class="nav-logo">VOUCHMORPH</div>
        <div class="nav-bottom">ARCHITECT v1.0</div>
    </div>

    <div class="top-bar">
        <div class="top-stat">COUNTRY <strong><?= vm_h($userCountry) ?></strong></div>
        <div class="top-stat">CURRENCY <strong><?= vm_h($userCurrency) ?></strong></div>
        <div class="top-stat">SOURCES <strong><?= count($fundingSources) + count($bankConnections) ?></strong></div>
        <div class="user-badge" id="userBadge"><?= vm_h(strtoupper(substr($userPhone, -4))) ?></div>
    </div>

    <div class="main-content">
        <div class="stat-block">
            <div class="stat-label">AVAILABLE BALANCE</div>
            <div class="stat-value">0.00 <span class="currency-display"><?= vm_h($userCurrency) ?></span></div>
        </div>

        <button class="trigger-btn" id="swapTrigger">⟡ INITIATE SWAP</button>
        <button class="trigger-btn" id="sourceTrigger">⟡ LINK SOURCE</button>
        <button class="trigger-btn" id="securityTrigger">⟡ SECURITY</button>

        <div id="swapOptions" class="option-grid">
            <button class="option-btn" onclick="showLinkedSwap()"><div class="option-title">LINKED SWAP</div><div class="option-desc">Use saved source • VM PIN only</div></button>
            <button class="option-btn" onclick="showAdhocSwap()"><div class="option-title">AD-HOC SWAP</div><div class="option-desc">No linking • Institution PIN</div></button>
            <button class="option-btn" onclick="startMultiSource()"><div class="option-title">MULTI-SOURCE</div><div class="option-desc">Combine balances</div></button>
            <button class="option-btn" onclick="startCashout()"><div class="option-title">CASEOUT</div><div class="option-desc">ATM / Agent withdrawal</div></button>
        </div>

        <div id="sourceOptions" class="option-grid">
            <button class="option-btn" onclick="showLinkInstitutions()"><div class="option-title">LINK SOURCE</div><div class="option-desc">Bank / Wallet / Card</div></button>
            <button class="option-btn" onclick="viewLinkedSources()"><div class="option-title">VIEW ALL</div><div class="option-desc"><?= count($fundingSources) + count($bankConnections) ?> connected</div></button>
            <button class="option-btn" onclick="manageTokens()"><div class="option-title">CONSENT TOKENS</div><div class="option-desc">Active authorizations</div></button>
        </div>

        <div id="securityOptions" class="option-grid">
            <button class="option-btn" onclick="changePin()"><div class="option-title">CHANGE PIN</div><div class="option-desc">Update transaction PIN</div></button>
            <button class="option-btn" onclick="changePassword()"><div class="option-title">CHANGE PASSWORD</div><div class="option-desc">Update login credentials</div></button>
            <button class="option-btn" onclick="viewSession()"><div class="option-title">ACTIVE SESSION</div><div class="option-desc">Device management</div></button>
            <button class="option-btn" onclick="logout()"><div class="option-title">TERMINATE</div><div class="option-desc">End session</div></button>
        </div>
    </div>

    <div class="action-panel" id="actionPanel">
        <div style="padding: 32px;">
            <div class="panel-label">🔗 LINKED SOURCES <span class="mode-badge badge-linked">VM PIN ONLY</span></div>
            <div id="sourcesList">
                <?php if (empty($fundingSources) && empty($bankConnections)): ?>
                <div class="source-item">— no sources linked —</div>
                <?php else: ?>
                <?php foreach ($bankConnections as $bc): ?>
                <div class="bank-connection" onclick="selectLinkedSource(<?= $bc['id'] ?? 0 ?>, '<?= vm_h($bc['institution_name']) ?>')">
                    <div class="source-name">🔐 <?= vm_h($bc['institution_name']) ?></div>
                    <div class="source-type">OAuth • <?= vm_h(date('M d, Y', strtotime($bc['created_at']))) ?></div>
                </div>
                <?php endforeach; ?>
                <?php foreach ($fundingSources as $fs): ?>
                <div class="source-item" onclick="selectLinkedSource(<?= $fs['id'] ?>, '<?= vm_h($fs['institution_name']) ?>')">
                    <span class="source-name"><?= vm_h($fs['institution_name']) ?></span>
                    <span class="source-type"><?= vm_h($fs['source_type']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="linkedSwapForm" style="display: none; padding: 32px; border-top: 1px solid rgba(255,255,255,0.06);">
            <div class="panel-label">⟡ LINKED SWAP <span class="mode-badge badge-linked">VM PIN ONLY</span></div>
            <div id="selectedSourceInfo" class="source-item" style="margin-bottom: 16px; background: rgba(255,255,255,0.03);"></div>
            <div class="swap-row">
                <input type="number" id="linkedAmount" class="swap-input" placeholder="AMOUNT" step="0.01" min="10">
                <div class="swap-input" style="width: 80px; text-align: center;"><?= vm_h($userCurrency) ?></div>
            </div>
            <select id="linkedDestCountry" class="swap-select"><option value="">Destination country</option></select>
            <select id="linkedDestInstitution" class="swap-select"><option value="">Destination institution</option></select>
            <input type="text" id="linkedDestIdentifier" class="swap-input" placeholder="Destination account / wallet / phone">
            <button class="execute-btn" onclick="executeLinkedSwap()">EXECUTE SWAP (VM PIN) →</button>
        </div>
        
        <div style="padding: 32px; border-top: 1px solid rgba(255,255,255,0.06);">
            <div class="panel-label">⚡ AD-HOC SWAP <span class="mode-badge badge-adhoc">INSTITUTION PIN ONLY</span></div>
            <div class="section-divider"></div>
            
            <select id="adhocSourceInstitution" class="swap-select">
                <option value="">Select Source Institution</option>
            </select>
            
            <select id="adhocAssetType" class="swap-select">
                <option value="">Select Asset Type</option>
            </select>
            
            <div id="adhocIdentifierFields" style="margin-bottom: 16px;"></div>
            
            <div class="swap-row">
                <input type="number" id="adhocAmount" class="swap-input" placeholder="AMOUNT" step="0.01" min="10">
                <div class="swap-input" style="width: 80px; text-align: center;"><?= vm_h($userCurrency) ?></div>
            </div>
            
            <select id="adhocDestCountry" class="swap-select">
                <option value="">Destination country</option>
            </select>
            
            <select id="adhocDestInstitution" class="swap-select">
                <option value="">Destination institution</option>
            </select>
            
            <input type="text" id="adhocDestIdentifier" class="swap-input" placeholder="Destination account / wallet / phone">
            
            <button class="execute-btn" onclick="executeAdhocSwap()">EXECUTE AD-HOC SWAP →</button>
        </div>
    </div>
</div>

<div id="institutionModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">SELECT INSTITUTION</div>
        <div id="institutionList" class="institution-list"><div style="text-align: center; padding: 40px;">LOADING...</div></div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closeInstitutionModal()">CANCEL</button>
        </div>
    </div>
</div>

<div id="assetModal" class="modal-overlay">
    <div class="modal">
        <div id="assetModalHeader" class="modal-header">SELECT ASSET TYPE</div>
        <div id="assetList" class="asset-list"></div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closeAssetModal()">CANCEL</button>
        </div>
    </div>
</div>

<div id="idFormModal" class="modal-overlay">
    <div class="modal">
        <div id="formModalHeader" class="modal-header">ENTER DETAILS</div>
        <div class="modal-body">
            <div id="formFields"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closeIdFormModal()">CANCEL</button>
            <button class="modal-btn modal-btn-primary" onclick="submitIdentificationForm()">LINK SOURCE →</button>
        </div>
    </div>
</div>

<div id="pinSetupModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">CREATE TRANSACTION PIN</div>
        <div class="modal-body">
            <div id="pinSetupDots" class="pin-dots"></div>
            <div id="pinSetupNumpad" class="pin-numpad"></div>
            <div id="pinSetupError" class="error-text"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinSetupModal()">CANCEL</button>
        </div>
    </div>
</div>

<div id="pinModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">ENTER TRANSACTION PIN</div>
        <div class="modal-body">
            <div id="pinDots" class="pin-dots"></div>
            <div id="pinNumpad" class="pin-numpad"></div>
        </div>
        <div class="modal-footer">
            <button class="modal-btn" onclick="closePinModal()">CANCEL</button>
        </div>
    </div>
</div>

<div id="sharpConfirmModal" class="sharp-confirm">
    <div class="modal">
        <div class="modal-header" id="confirmTitle">CONFIRMATION</div>
        <div class="modal-body" id="confirmMessage">Are you sure?</div>
        <div class="modal-footer">
            <button class="modal-btn" id="confirmCancelBtn">CANCEL</button>
            <button class="modal-btn modal-btn-primary" id="confirmOkBtn">OK →</button>
        </div>
    </div>
</div>

<script>
const hasTransactionPin = <?php echo $hasTransactionPin ? 'true' : 'false'; ?>;
const userCurrency = <?php echo vm_json($userCurrency); ?>;
const userCountry = <?php echo vm_json($userCountry); ?>;
const dialCode = <?php echo vm_json($dialCode); ?>;
const allParticipants = <?php echo vm_json($participantsForJs); ?>;
const destinationCountries = <?php echo vm_json($destinationCountries); ?>;
const fundingSources = <?php echo vm_json($fundingSourcesForJs); ?>;

let selectedInstitution = null;
let selectedAssetType = null;
let selectedLinkedSourceId = null;
let selectedLinkedSourceName = null;
let pendingCallback = null;
let pinInput = '';
let pinSetupInput = '';

function sharpConfirm(message, title = 'CONFIRMATION') {
    return new Promise((resolve) => {
        document.getElementById('confirmTitle').innerHTML = title;
        document.getElementById('confirmMessage').innerHTML = message;
        document.getElementById('sharpConfirmModal').style.display = 'flex';
        const handleOk = () => { cleanup(); resolve(true); };
        const handleCancel = () => { cleanup(); resolve(false); };
        const cleanup = () => {
            document.getElementById('sharpConfirmModal').style.display = 'none';
            document.getElementById('confirmOkBtn').removeEventListener('click', handleOk);
            document.getElementById('confirmCancelBtn').removeEventListener('click', handleCancel);
        };
        document.getElementById('confirmOkBtn').addEventListener('click', handleOk);
        document.getElementById('confirmCancelBtn').addEventListener('click', handleCancel);
    });
}

function initDestCountries() {
    const select = document.getElementById('linkedDestCountry');
    select.innerHTML = '<option value="">Destination country</option>';
    destinationCountries.forEach(country => {
        select.innerHTML += `<option value="${country}">${country}</option>`;
    });
    const adhocSelect = document.getElementById('adhocDestCountry');
    adhocSelect.innerHTML = '<option value="">Destination country</option>';
    destinationCountries.forEach(country => {
        adhocSelect.innerHTML += `<option value="${country}">${country}</option>`;
    });
}
initDestCountries();

function loadAdhocSourceInstitutions() {
    const select = document.getElementById('adhocSourceInstitution');
    select.innerHTML = '<option value="">Select Source Institution</option>';
    const institutions = allParticipants.filter(p => p.country === userCountry && p.status === 'ACTIVE');
    institutions.forEach(inst => {
        select.innerHTML += `<option value="${inst.code}">${inst.name} (${inst.country})</option>`;
    });
}
loadAdhocSourceInstitutions();

document.getElementById('adhocSourceInstitution')?.addEventListener('change', () => {
    const institutionCode = document.getElementById('adhocSourceInstitution').value;
    const assetSelect = document.getElementById('adhocAssetType');
    if (!institutionCode) {
        assetSelect.innerHTML = '<option value="">Select Asset Type</option>';
        document.getElementById('adhocIdentifierFields').innerHTML = '';
        return;
    }
    const institution = allParticipants.find(p => p.code === institutionCode);
    if (!institution) return;
    assetSelect.innerHTML = '<option value="">Select Asset Type</option>';
    institution.asset_types.forEach(asset => {
        assetSelect.innerHTML += `<option value="${asset.type}">${asset.icon} ${asset.name}</option>`;
    });
    document.getElementById('adhocIdentifierFields').innerHTML = '';
});

document.getElementById('adhocAssetType')?.addEventListener('change', () => {
    const institutionCode = document.getElementById('adhocSourceInstitution').value;
    const assetType = document.getElementById('adhocAssetType').value;
    const container = document.getElementById('adhocIdentifierFields');
    if (!institutionCode || !assetType) {
        container.innerHTML = '';
        return;
    }
    const institution = allParticipants.find(p => p.code === institutionCode);
    if (!institution) return;
    const asset = institution.asset_types.find(a => a.type === assetType);
    if (!asset || !asset.identification_methods) {
        container.innerHTML = '<div class="error-text">No identification fields configured</div>';
        return;
    }
    container.innerHTML = '';
    asset.identification_methods.forEach(field => {
        const div = document.createElement('div');
        div.className = 'form-field';
        div.innerHTML = `
            <label class="form-label">${field.label} ${field.required ? '*' : ''}</label>
            <input type="${field.type}" id="adhoc_${field.field}" class="form-input" placeholder="${field.placeholder || ''}" ${field.required ? 'required' : ''}>
        `;
        container.appendChild(div);
    });
});

document.getElementById('linkedDestCountry')?.addEventListener('change', async () => {
    const country = document.getElementById('linkedDestCountry').value;
    const destSelect = document.getElementById('linkedDestInstitution');
    if (!country) {
        destSelect.innerHTML = '<option value="">Destination institution</option>';
        return;
    }
    const result = await executeOperation('get_destination_institutions', { country: country });
    if (result.success) {
        destSelect.innerHTML = '<option value="">Destination institution</option>';
        result.institutions.forEach(inst => {
            destSelect.innerHTML += `<option value="${inst.code}">${inst.name}</option>`;
        });
    }
});

document.getElementById('adhocDestCountry')?.addEventListener('change', async () => {
    const country = document.getElementById('adhocDestCountry').value;
    const destSelect = document.getElementById('adhocDestInstitution');
    if (!country) {
        destSelect.innerHTML = '<option value="">Destination institution</option>';
        return;
    }
    const result = await executeOperation('get_destination_institutions', { country: country });
    if (result.success) {
        destSelect.innerHTML = '<option value="">Destination institution</option>';
        result.institutions.forEach(inst => {
            destSelect.innerHTML += `<option value="${inst.code}">${inst.name}</option>`;
        });
    }
});

document.getElementById('swapTrigger')?.addEventListener('click', () => {
    document.getElementById('swapOptions').classList.toggle('active');
    document.getElementById('sourceOptions').classList.remove('active');
    document.getElementById('securityOptions').classList.remove('active');
});
document.getElementById('sourceTrigger')?.addEventListener('click', () => {
    document.getElementById('sourceOptions').classList.toggle('active');
    document.getElementById('swapOptions').classList.remove('active');
    document.getElementById('securityOptions').classList.remove('active');
});
document.getElementById('securityTrigger')?.addEventListener('click', () => {
    document.getElementById('securityOptions').classList.toggle('active');
    document.getElementById('swapOptions').classList.remove('active');
    document.getElementById('sourceOptions').classList.remove('active');
});
document.getElementById('userBadge')?.addEventListener('click', () => {
    document.getElementById('actionPanel').classList.toggle('open');
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('.trigger-btn') && !e.target.closest('.option-grid')) {
        document.getElementById('swapOptions').classList.remove('active');
        document.getElementById('sourceOptions').classList.remove('active');
        document.getElementById('securityOptions').classList.remove('active');
    }
});

function showLinkedSwap() {
    if (fundingSources.length === 0 && bankConnections.length === 0) {
        sharpConfirm('No linked sources found. Please link a source first.', 'INFO');
        showLinkInstitutions();
    } else {
        sharpConfirm('Click on a linked source in the right panel to start', 'INFO');
    }
}

function showAdhocSwap() {
    document.getElementById('actionPanel').classList.add('open');
    sharpConfirm('Enter source institution, your credentials, amount, and destination', 'AD-HOC SWAP');
}

function selectLinkedSource(id, name) {
    selectedLinkedSourceId = id;
    selectedLinkedSourceName = name;
    document.getElementById('selectedSourceInfo').innerHTML = `<span class="source-name">✓ Selected: ${name}</span>`;
    document.getElementById('linkedSwapForm').style.display = 'block';
    document.getElementById('actionPanel').classList.add('open');
}

async function executeOperation(operation, data) {
    const formData = new FormData();
    formData.append('action', operation);
    for (let key in data) formData.append(key, data[key]);
    const res = await fetch(window.location.href, { 
        method: 'POST', 
        body: formData, 
        headers: { 'X-Requested-With': 'XMLHttpRequest' } 
    });
    return await res.json();
}

function renderPinSetupDots() {
    const container = document.getElementById('pinSetupDots');
    let dots = '';
    for (let i = 0; i < 6; i++) dots += `<div class="pin-dot ${i < pinSetupInput.length ? 'filled' : ''}"></div>`;
    container.innerHTML = dots;
}

function renderPinSetupNumpad() {
    const container = document.getElementById('pinSetupNumpad');
    const nums = [1,2,3,4,5,6,7,8,9,0];
    let html = '';
    nums.forEach(n => { html += `<button class="numpad-btn" onclick="pinSetupAdd(${n})">${n}</button>`; });
    html += `<button class="numpad-btn" onclick="pinSetupDelete()">⌫</button><button class="numpad-btn" onclick="pinSetupClear()">CLR</button>`;
    container.innerHTML = html;
}

function pinSetupAdd(d) { 
    if (pinSetupInput.length < 6) { 
        pinSetupInput += d.toString(); 
        renderPinSetupDots(); 
        if (pinSetupInput.length === 6) submitPinSetup();
    } 
}
function pinSetupDelete() { pinSetupInput = pinSetupInput.slice(0, -1); renderPinSetupDots(); document.getElementById('pinSetupError').innerHTML = ''; }
function pinSetupClear() { pinSetupInput = ''; renderPinSetupDots(); document.getElementById('pinSetupError').innerHTML = ''; }

function showPinSetupModal() {
    pinSetupInput = '';
    renderPinSetupDots();
    renderPinSetupNumpad();
    document.getElementById('pinSetupModal').style.display = 'flex';
}

function closePinSetupModal() {
    document.getElementById('pinSetupModal').style.display = 'none';
    pinSetupInput = '';
}

async function submitPinSetup() {
    if (pinSetupInput.length !== 6) {
        document.getElementById('pinSetupError').innerHTML = 'PIN MUST BE 6 DIGITS';
        return;
    }
    const pin = pinSetupInput;
    const confirmed = await sharpConfirm('CONFIRM YOUR 6-DIGIT PIN', 'PIN CONFIRMATION');
    if (!confirmed) {
        document.getElementById('pinSetupError').innerHTML = 'CONFIRMATION CANCELLED';
        pinSetupInput = '';
        renderPinSetupDots();
        return;
    }
    let confirmPinInput = '';
    const tempModal = document.createElement('div');
    tempModal.className = 'sharp-confirm';
    tempModal.style.display = 'flex';
    tempModal.innerHTML = `
        <div class="modal">
            <div class="modal-header">CONFIRM PIN</div>
            <div class="modal-body">
                <div id="tempPinDots" class="pin-dots"></div>
                <div id="tempPinNumpad" class="pin-numpad"></div>
                <div id="tempPinError" class="error-text"></div>
            </div>
            <div class="modal-footer">
                <button id="tempPinCancel" class="modal-btn">CANCEL</button>
            </div>
        </div>
    `;
    document.body.appendChild(tempModal);
    const renderTempDots = () => {
        const container = document.getElementById('tempPinDots');
        let dots = '';
        for (let i = 0; i < 6; i++) dots += `<div class="pin-dot ${i < confirmPinInput.length ? 'filled' : ''}"></div>`;
        container.innerHTML = dots;
    };
    const renderTempNumpad = () => {
        const container = document.getElementById('tempPinNumpad');
        const nums = [1,2,3,4,5,6,7,8,9,0];
        let html = '';
        nums.forEach(n => { html += `<button class="numpad-btn" data-num="${n}">${n}</button>`; });
        html += `<button class="numpad-btn" data-action="delete">⌫</button><button class="numpad-btn" data-action="clear">CLR</button>`;
        container.innerHTML = html;
        container.querySelectorAll('.numpad-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const num = btn.dataset.num;
                const action = btn.dataset.action;
                if (num) {
                    if (confirmPinInput.length < 6) {
                        confirmPinInput += num;
                        renderTempDots();
                        if (confirmPinInput.length === 6) {
                            if (confirmPinInput === pin) {
                                tempModal.remove();
                                document.getElementById('pinSetupError').innerHTML = 'SETTING PIN...';
                                savePin(pin);
                            } else {
                                document.getElementById('tempPinError').innerHTML = 'PINS DO NOT MATCH';
                                confirmPinInput = '';
                                renderTempDots();
                            }
                        }
                    }
                } else if (action === 'delete') {
                    confirmPinInput = confirmPinInput.slice(0, -1);
                    renderTempDots();
                    document.getElementById('tempPinError').innerHTML = '';
                } else if (action === 'clear') {
                    confirmPinInput = '';
                    renderTempDots();
                    document.getElementById('tempPinError').innerHTML = '';
                }
            });
        });
    };
    document.getElementById('tempPinCancel').addEventListener('click', () => {
        tempModal.remove();
        document.getElementById('pinSetupError').innerHTML = 'CONFIRMATION CANCELLED';
        pinSetupInput = '';
        renderPinSetupDots();
    });
    renderTempDots();
    renderTempNumpad();
}

async function savePin(pin) {
    const result = await executeOperation('set_transaction_pin', { pin: pin, confirm_pin: pin });
    if (result.status === 'success') {
        await sharpConfirm('TRANSACTION PIN CREATED SUCCESSFULLY', 'SUCCESS');
        closePinSetupModal();
        location.reload();
    } else {
        document.getElementById('pinSetupError').innerHTML = result.message;
    }
}

function renderPinDots() {
    const container = document.getElementById('pinDots');
    let dots = '';
    for (let i = 0; i < 6; i++) dots += `<div class="pin-dot ${i < pinInput.length ? 'filled' : ''}"></div>`;
    container.innerHTML = dots;
}

function renderPinNumpad() {
    const container = document.getElementById('pinNumpad');
    const nums = [1,2,3,4,5,6,7,8,9,0];
    let html = '';
    nums.forEach(n => { html += `<button class="numpad-btn" onclick="pinAdd(${n})">${n}</button>`; });
    html += `<button class="numpad-btn" onclick="pinDelete()">⌫</button><button class="numpad-btn" onclick="pinClear()">CLR</button>`;
    container.innerHTML = html;
}

function pinAdd(d) { if (pinInput.length < 6) { pinInput += d.toString(); renderPinDots(); if (pinInput.length === 6) submitPin(); } }
function pinDelete() { pinInput = pinInput.slice(0, -1); renderPinDots(); }
function pinClear() { pinInput = ''; renderPinDots(); }

function showPinModal(callback) { 
    pinInput = ''; 
    pendingCallback = callback; 
    renderPinDots(); 
    renderPinNumpad(); 
    document.getElementById('pinModal').style.display = 'flex'; 
}

function closePinModal() { 
    document.getElementById('pinModal').style.display = 'none'; 
    pinInput = ''; 
    pendingCallback = null; 
}

async function submitPin() { 
    if (pinInput.length !== 6) return; 
    const pin = pinInput; 
    closePinModal(); 
    if (pendingCallback) await pendingCallback(pin); 
}

async function withPinVerification(operation, data, callback) {
    showPinModal(async (pin) => {
        const verify = await executeOperation('verify_transaction_pin', { pin: pin, operation: operation });
        if (verify.status === 'success') {
            data.consent_token = verify.consent_token;
            const result = await executeOperation(operation, data);
            if (callback) callback(result);
        } else {
            await sharpConfirm('INVALID TRANSACTION PIN', 'ERROR');
        }
    });
}

async function checkAndSetupPin() {
    if (!hasTransactionPin) {
        showPinSetupModal();
        return false;
    }
    return true;
}

async function executeLinkedSwap() {
    if (!await checkAndSetupPin()) return;
    if (!selectedLinkedSourceId) {
        sharpConfirm('Please select a linked source first', 'ERROR');
        return;
    }
    const amount = document.getElementById('linkedAmount').value;
    const destCountry = document.getElementById('linkedDestCountry').value;
    const destInstitution = document.getElementById('linkedDestInstitution').value;
    const destIdentifier = document.getElementById('linkedDestIdentifier').value;
    if (!amount || amount < 10) { sharpConfirm('Enter valid amount (min 10)', 'ERROR'); return; }
    if (!destCountry) { sharpConfirm('Select destination country', 'ERROR'); return; }
    if (!destInstitution) { sharpConfirm('Select destination institution', 'ERROR'); return; }
    if (!destIdentifier) { sharpConfirm('Enter destination identifier', 'ERROR'); return; }
    
    withPinVerification('swap_linked', {
        source_id: selectedLinkedSourceId,
        amount: parseFloat(amount),
        dest_country: destCountry,
        dest_institution: destInstitution,
        dest_identifier: destIdentifier
    }, async (result) => {
        if (result.status === 'success') {
            await sharpConfirm(`SWAP COMPLETED!\n\nREF: ${result.swap_reference}\nAMOUNT: ${amount} ${userCurrency}\nDESTINATION: ${destIdentifier}`, 'SUCCESS');
            document.getElementById('linkedAmount').value = '';
            document.getElementById('linkedDestIdentifier').value = '';
            document.getElementById('linkedSwapForm').style.display = 'none';
            selectedLinkedSourceId = null;
        } else {
            await sharpConfirm(`SWAP FAILED\n\n${result.message}`, 'ERROR');
        }
    });
}

async function executeAdhocSwap() {
    const sourceInstitution = document.getElementById('adhocSourceInstitution').value;
    const assetType = document.getElementById('adhocAssetType').value;
    const amount = document.getElementById('adhocAmount').value;
    const destCountry = document.getElementById('adhocDestCountry').value;
    const destInstitution = document.getElementById('adhocDestInstitution').value;
    const destIdentifier = document.getElementById('adhocDestIdentifier').value;
    
    if (!sourceInstitution) { sharpConfirm('Select source institution', 'ERROR'); return; }
    if (!assetType) { sharpConfirm('Select asset type', 'ERROR'); return; }
    if (!amount || amount < 10) { sharpConfirm('Enter valid amount (min 10)', 'ERROR'); return; }
    if (!destCountry) { sharpConfirm('Select destination country', 'ERROR'); return; }
    if (!destInstitution) { sharpConfirm('Select destination institution', 'ERROR'); return; }
    if (!destIdentifier) { sharpConfirm('Enter destination identifier', 'ERROR'); return; }
    
    const institution = allParticipants.find(p => p.code === sourceInstitution);
    const asset = institution?.asset_types.find(a => a.type === assetType);
    const credentials = {};
    if (asset && asset.identification_methods) {
        for (const field of asset.identification_methods) {
            const input = document.getElementById(`adhoc_${field.field}`);
            if (input && input.value) {
                credentials[field.field] = input.value;
            } else if (field.required) {
                sharpConfirm(`${field.label} is required`, 'ERROR');
                return;
            }
        }
    }
    
    const result = await executeOperation('swap_adhoc', {
        source_institution: sourceInstitution,
        asset_type: assetType,
        credentials: JSON.stringify(credentials),
        amount: parseFloat(amount),
        dest_country: destCountry,
        dest_institution: destInstitution,
        dest_identifier: destIdentifier
    });
    
    if (result.status === 'success') {
        await sharpConfirm(`SWAP COMPLETED!\n\nREF: ${result.swap_reference}\nAMOUNT: ${amount} ${userCurrency}\nDESTINATION: ${destIdentifier}`, 'SUCCESS');
        document.getElementById('adhocAmount').value = '';
        document.getElementById('adhocDestIdentifier').value = '';
        if (asset && asset.identification_methods) {
            asset.identification_methods.forEach(field => {
                const input = document.getElementById(`adhoc_${field.field}`);
                if (input) input.value = '';
            });
        }
    } else {
        await sharpConfirm(`SWAP FAILED\n\n${result.message}`, 'ERROR');
    }
}

async function showLinkInstitutions() {
    if (!await checkAndSetupPin()) return;
    const container = document.getElementById('institutionList');
    container.innerHTML = '<div style="text-align: center; padding: 40px;">LOADING...</div>';
    document.getElementById('institutionModal').style.display = 'flex';
    const institutions = allParticipants.filter(p => p.country === userCountry);
    if (institutions.length === 0) {
        container.innerHTML = '<div style="text-align: center; padding: 40px;">NO INSTITUTIONS AVAILABLE</div>';
        return;
    }
    container.innerHTML = '';
    institutions.forEach(inst => {
        const div = document.createElement('div');
        div.className = 'institution-item';
        div.innerHTML = `<div><div class="institution-name">${inst.name}</div><div class="institution-country">${inst.country} • ${inst.currency}</div></div><div class="oauth-badge">${inst.asset_types.length} ASSETS</div>`;
        div.onclick = () => selectInstitution(inst.code);
        container.appendChild(div);
    });
}

function selectInstitution(code) {
    selectedInstitution = allParticipants.find(p => p.code === code);
    closeInstitutionModal();
    showAssetTypes();
}

function showAssetTypes() {
    if (!selectedInstitution || !selectedInstitution.asset_types || selectedInstitution.asset_types.length === 0) {
        sharpConfirm('NO ASSET TYPES AVAILABLE', 'ERROR');
        return;
    }
    const container = document.getElementById('assetList');
    container.innerHTML = '';
    document.getElementById('assetModalHeader').innerHTML = `${selectedInstitution.name} • SELECT ASSET`;
    document.getElementById('assetModal').style.display = 'flex';
    selectedInstitution.asset_types.forEach(asset => {
        const div = document.createElement('div');
        div.className = 'asset-item';
        div.innerHTML = `<div><div class="asset-name">${asset.icon || '📄'} ${asset.name}</div><div class="asset-icon">${asset.type}</div></div><div class="oauth-badge">${asset.supports_oauth ? 'OAUTH' : 'MANUAL'}</div>`;
        div.onclick = () => selectAssetType(asset);
        container.appendChild(div);
    });
}

async function selectAssetType(asset) {
    selectedAssetType = asset;
    closeAssetModal();
    if (asset.supports_oauth) {
        const useOAuth = await sharpConfirm(`${selectedInstitution.name} - ${asset.name}\n\nThis institution supports OAuth for secure linking.\n\nOK = OAuth (bank login)\nCANCEL = Manual entry`, 'LINKING METHOD');
        if (useOAuth) {
            await sharpConfirm('OAuth coming soon. Using manual entry.', 'INFO');
        }
    }
    showIdentificationForm();
}

function showIdentificationForm() {
    const fields = selectedAssetType.identification_methods || [];
    if (fields.length === 0) {
        sharpConfirm('No identification methods configured', 'ERROR');
        return;
    }
    const container = document.getElementById('formFields');
    container.innerHTML = '';
    document.getElementById('formModalHeader').innerHTML = `${selectedInstitution.name} • ${selectedAssetType.name}`;
    document.getElementById('idFormModal').style.display = 'flex';
    fields.forEach(field => {
        const div = document.createElement('div');
        div.className = 'form-field';
        let inputHtml = '';
        if (field.type === 'select' && field.options) {
            inputHtml = `<select id="field_${field.field}" class="form-input">`;
            field.options.forEach(opt => { inputHtml += `<option value="${opt.value}">${opt.label}</option>`; });
            inputHtml += `</select>`;
        } else {
            inputHtml = `<input type="${field.type}" id="field_${field.field}" class="form-input" placeholder="${field.placeholder || ''}" ${field.required ? 'required' : ''}>`;
        }
        div.innerHTML = `<label class="form-label">${field.label} ${field.required ? '*' : ''}</label>${inputHtml}`;
        container.appendChild(div);
    });
}

async function submitIdentificationForm() {
    const fields = selectedAssetType.identification_methods || [];
    const identificationData = {};
    let isValid = true;
    fields.forEach(field => {
        const input = document.getElementById(`field_${field.field}`);
        if (input) {
            const value = input.value.trim();
            if (field.required && !value) {
                sharpConfirm(`${field.label} is required`, 'ERROR');
                isValid = false;
                return;
            }
            identificationData[field.field] = value;
        }
    });
    if (!isValid) return;
    closeIdFormModal();
    withPinVerification('save_manual_source', {
        institution_code: selectedInstitution.code,
        asset_type: selectedAssetType.type,
        identification_data: JSON.stringify(identificationData)
    }, async (result) => {
        if (result.status === 'success') {
            await sharpConfirm('Source linked successfully!', 'SUCCESS');
            location.reload();
        } else {
            await sharpConfirm('Failed: ' + result.message, 'ERROR');
        }
    });
}

function closeInstitutionModal() { document.getElementById('institutionModal').style.display = 'none'; }
function closeAssetModal() { document.getElementById('assetModal').style.display = 'none'; }
function closeIdFormModal() { document.getElementById('idFormModal').style.display = 'none'; }

function viewLinkedSources() {
    if (fundingSources.length === 0 && bankConnections.length === 0) {
        sharpConfirm('No sources linked', 'INFO');
    } else {
        let msg = 'LINKED SOURCES:\n\n';
        if (bankConnections.length) { msg += '🔐 BANK CONNECTIONS (OAuth):\n'; bankConnections.forEach(s => { msg += `  • ${s.name}\n`; }); }
        if (fundingSources.length) { msg += '\n📝 MANUAL SOURCES:\n'; fundingSources.forEach(s => { msg += `  • ${s.name} (${s.type})\n`; }); }
        sharpConfirm(msg, 'SOURCES');
    }
}

async function startMultiSource() { if (!await checkAndSetupPin()) return; sharpConfirm('Multi-source swap coming soon', 'INFO'); }
async function startCashout() { if (!await checkAndSetupPin()) return; sharpConfirm('Cashout feature - select withdrawal method', 'INFO'); }
async function startRecurring() { if (!await checkAndSetupPin()) return; sharpConfirm('Recurring swaps - schedule upcoming', 'INFO'); }
function manageTokens() { sharpConfirm('Active consent tokens: none', 'INFO'); }
async function changePin() { if (!await checkAndSetupPin()) return; sharpConfirm('Use Security → Change PIN', 'INFO'); }

async function changePassword() { 
    const current = prompt('Enter current password:'); 
    if (!current) return; 
    const newPwd = prompt('NEW PASSWORD (min 6 characters):'); 
    if (!newPwd || newPwd.length < 6) return; 
    const confirm = prompt('CONFIRM PASSWORD:'); 
    if (newPwd !== confirm) { sharpConfirm('PASSWORDS DO NOT MATCH', 'ERROR'); return; } 
    const result = await executeOperation('change_password', { current_password: current, new_password: newPwd }); 
    if (result.status === 'success') { await sharpConfirm('PASSWORD CHANGED. LOGIN AGAIN.', 'SUCCESS'); logout(); } 
    else { await sharpConfirm(result.message, 'ERROR'); }
}

function viewSession() { sharpConfirm(`SESSION ACTIVE\nDevice: ${navigator.userAgent.split(' ').slice(-2).join(' ')}\nTime: ${new Date().toLocaleString()}`, 'SESSION'); }
function logout() { window.location.href = 'logout.php'; }

if (!hasTransactionPin) {
    setTimeout(() => { showPinSetupModal(); }, 500);
}
</script>
</body>
</html>
