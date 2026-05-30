<?php
// public/user/user_dashboard.php
ob_start();

// Disable error reporting for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

if (!defined('DASHBOARD_LOADED')) {
    define('DASHBOARD_LOADED', true);
}

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$systemCountry = $user['country'] ?? 'BW';

$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

if (empty($dbConfig) || empty($dbConfig['host'])) {
    $dbConfig = null;
}

try {
    $db = DBConnection::getInstance($dbConfig);
    if (!$db) throw new \Exception("Failed to get database connection");
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_funding_sources (
            source_id BIGSERIAL PRIMARY KEY,
            user_id VARCHAR(100) NOT NULL,
            institution_code VARCHAR(100) NOT NULL,
            institution_name VARCHAR(150),
            source_type VARCHAR(30) NOT NULL,
            source_label VARCHAR(100),
            masked_identifier VARCHAR(100),
            encrypted_identifier TEXT,
            identifier_hash VARCHAR(255),
            linked_phone VARCHAR(30),
            verification_status VARCHAR(20) DEFAULT 'PENDING',
            is_default BOOLEAN DEFAULT FALSE,
            status VARCHAR(20) DEFAULT 'ACTIVE',
            last_used_at TIMESTAMP,
            metadata JSONB,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(user_id, institution_code, identifier_hash)
        )
    ");
    
} catch (\Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("System error: " . htmlspecialchars($e->getMessage()));
}

/* =========================
   HELPER FUNCTIONS
========================= */
function formatPhoneNumberForSwap($phoneNumber, $countryCode = 'BW') {
    $cleanNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $countryCodes = ['BW' => '267', 'KE' => '254', 'NG' => '234', 'ZA' => '27', 'GH' => '233'];
    $code = $countryCodes[$countryCode] ?? '267';
    if (empty($cleanNumber)) return '';
    if (substr($cleanNumber, 0, strlen($code)) === $code) return '+' . $cleanNumber;
    if (substr($cleanNumber, 0, 1) === '0') $cleanNumber = substr($cleanNumber, 1);
    return '+' . $code . $cleanNumber;
}

function safeJsonDecode($value): array {
    if (is_array($value)) return $value;
    if ($value === null || $value === '') return [];
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function participantIcon(array $participant): string {
    $type = strtoupper((string)($participant['type'] ?? ''));
    $category = strtoupper((string)($participant['category'] ?? ''));
    if ($type === 'MNO') return '📱';
    if ($category === 'CARD') return '💳';
    return '🏦';
}

function maskValue(string $value, int $visible = 4): string {
    $value = trim($value);
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= $visible) return str_repeat('*', $len);
    return str_repeat('*', $len - $visible) . substr($value, -$visible);
}

function encryptIdentifier(string $identifier, string $key): string {
    return base64_encode(openssl_encrypt($identifier, 'AES-256-CBC', $key, 0, substr($key, 0, 16)));
}

function decryptIdentifier(string $encrypted, string $key): string {
    return openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr($key, 0, 16));
}

/* =========================
   LOAD PARTICIPANTS
========================= */
$stmt = $db->prepare("
    SELECT participant_id, name, type, category, provider_code, auth_type, base_url,
           capabilities, resource_endpoints, phone_format, security_config,
           message_profile, routing_info, metadata, status
    FROM participants
    WHERE status = 'ACTIVE'
    ORDER BY name
");
$stmt->execute();
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

$participantConfig = [];
foreach ($participants as $p) {
    $participantConfig[$p['provider_code']] = [
        'participant_id' => $p['participant_id'],
        'name' => $p['name'],
        'provider_code' => $p['provider_code'],
        'type' => $p['type'],
        'category' => $p['category'],
        'auth_type' => $p['auth_type'],
        'base_url' => $p['base_url'],
        'capabilities' => safeJsonDecode($p['capabilities'] ?? '{}'),
        'resource_endpoints' => safeJsonDecode($p['resource_endpoints'] ?? '{}'),
        'phone_format' => safeJsonDecode($p['phone_format'] ?? '{}'),
        'security_config' => safeJsonDecode($p['security_config'] ?? '{}'),
        'message_profile' => safeJsonDecode($p['message_profile'] ?? '{}'),
        'routing_info' => safeJsonDecode($p['routing_info'] ?? '{}'),
        'metadata' => safeJsonDecode($p['metadata'] ?? '{}')
    ];
}

// Load user's funding sources
$stmt = $db->prepare("
    SELECT * FROM user_funding_sources 
    WHERE user_id = :user_id AND status = 'ACTIVE'
    ORDER BY is_default DESC, source_id ASC
");
$stmt->execute([':user_id' => $userId]);
$fundingSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$error = null;
$success = null;

// Handle AJAX for fetching transactions
if ($isAjax && isset($_GET['ajax']) && $_GET['ajax'] === 'transactions') {
    header('Content-Type: application/json');
    
    $stmt = $db->prepare("
        SELECT swap_id, swap_uuid, from_currency, to_currency, amount, 
               source_details, destination_details, status, created_at, metadata
        FROM swap_requests
        WHERE CAST(metadata AS TEXT) LIKE :phone_pattern 
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmt->execute([':phone_pattern' => '%' . $userPhone . '%']);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = [];
    foreach ($transactions as $tx) {
        $formatted[] = [
            'id' => $tx['swap_uuid'],
            'amount' => number_format((float)($tx['amount'] ?? 0), 2),
            'status' => $tx['status'] ?? 'PENDING',
            'date' => date('d M Y H:i', strtotime($tx['created_at'])),
            'type' => $tx['delivery_mode'] ?? 'swap'
        ];
    }
    
    echo json_encode(['success' => true, 'transactions' => $formatted]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $institutionName = trim($_POST['institution_name'] ?? '');
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            $sourceLabel = trim($_POST['source_label'] ?? '');
            $linkedPhone = trim($_POST['linked_phone'] ?? $userPhone);
            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            
            if (!isset($participantConfig[$institutionCode])) {
                throw new \Exception("Institution not found");
            }
            
            $participant = $participantConfig[$institutionCode];
            $tempRef = 'VERIFY_' . bin2hex(random_bytes(8));
            $bankClient = new \Infrastructure\Banks\GenericBankClient($participant);
            $verifyPayload = [
                'reference' => $tempRef,
                'institution' => $institutionCode,
                'asset_type' => $sourceType,
                'amount' => 0
            ];
            
            if ($sourceType === 'ACCOUNT') {
                $verifyPayload['account_number'] = $identifier;
            } elseif ($sourceType === 'WALLET' || $sourceType === 'E-WALLET') {
                $verifyPayload['phone'] = formatPhoneNumberForSwap($identifier, $systemCountry);
            } elseif ($sourceType === 'CARD') {
                $verifyPayload['card_number'] = $identifier;
            }
            
            $verifyResult = $bankClient->verifyAsset($verifyPayload);
            if (!($verifyResult['success'] ?? false)) {
                throw new \Exception("Cannot verify account. Please check your details.");
            }
            
            $identifierHash = hash('sha256', $identifier);
            $checkStmt = $db->prepare("
                SELECT source_id FROM user_funding_sources 
                WHERE user_id = :user_id AND institution_code = :inst AND identifier_hash = :hash
            ");
            $checkStmt->execute([
                ':user_id' => $userId,
                ':inst' => $institutionCode,
                ':hash' => $identifierHash
            ]);
            
            if ($checkStmt->fetch()) {
                throw new \Exception("Account already linked");
            }
            
            if ($isDefault) {
                $db->prepare("UPDATE user_funding_sources SET is_default = FALSE WHERE user_id = ?")->execute([$userId]);
            }
            
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encryptedIdentifier = encryptIdentifier($identifier, $encryptionKey);
            $maskedIdentifier = maskValue($identifier, 4);
            
            $stmt = $db->prepare("
                INSERT INTO user_funding_sources 
                (user_id, institution_code, institution_name, source_type, source_label,
                 masked_identifier, encrypted_identifier, identifier_hash, linked_phone,
                 is_default, verification_status, metadata)
                VALUES (:user_id, :inst, :inst_name, :type, :label,
                        :masked, :encrypted, :hash, :phone,
                        :default, 'VERIFIED', :metadata)
            ");
            
            $stmt->execute([
                ':user_id' => $userId,
                ':inst' => $institutionCode,
                ':inst_name' => $institutionName,
                ':type' => $sourceType,
                ':label' => $sourceLabel,
                ':masked' => $maskedIdentifier,
                ':encrypted' => $encryptedIdentifier,
                ':hash' => $identifierHash,
                ':phone' => $linkedPhone,
                ':default' => $isDefault ? 1 : 0,
                ':metadata' => json_encode(['verified_at' => date('c')])
            ]);
            
            $success = "✅ Source linked successfully!";
            
            if (!$isAjax) {
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if ($action === 'remove_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $stmt = $db->prepare("
            UPDATE user_funding_sources 
            SET status = 'REMOVED', updated_at = NOW()
            WHERE source_id = :id AND user_id = :user_id
        ");
        $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
        
        if (!$isAjax) {
            header("Location: " . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    if ($action === 'swap') {
        if ($isAjax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
        }
        
        try {
            $isMultiSource = isset($_POST['is_multi_source']) && $_POST['is_multi_source'] === '1';
            
            $countryConfigPath = __DIR__ . "/../../src/Core/Config/Countries/{$systemCountry}/config.php";
            $countryConfig = file_exists($countryConfigPath) ? require $countryConfigPath : [];
            $encryptionKey = $config['encryption']['key'] ?? getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            
            $swapService = new SwapService(
                $db,
                $countryConfig,
                $systemCountry,
                $encryptionKey,
                $participantConfig
            );
            
            if ($isMultiSource) {
                $sources = json_decode($_POST['sources'] ?? '[]', true);
                $destinationInstitution = trim($_POST['destination_institution'] ?? '');
                $destinationType = trim($_POST['destination_type'] ?? '');
                $destinationValue = trim($_POST['destination_value'] ?? '');
                $targetAmount = (float)($_POST['amount'] ?? 0);
                $distributionStrategy = $_POST['distribution_strategy'] ?? 'drain_smallest';
                
                $destinationDetails = [];
                switch ($destinationType) {
                    case 'cashout':
                        $destinationDetails = ['cashout' => ['beneficiary_phone' => $destinationValue]];
                        break;
                    case 'bank':
                        $destinationDetails = ['beneficiary_account' => $destinationValue];
                        break;
                    default:
                        $destinationDetails = ['beneficiary_wallet' => $destinationValue];
                }
                
                $multiSourcePayload = [
                    'master_reference' => 'MS-' . date('Ymd') . '-' . bin2hex(random_bytes(4)),
                    'distribution_strategy' => $distributionStrategy,
                    'destination' => array_merge([
                        'institution' => $destinationInstitution,
                        'delivery_mode' => $destinationType === 'cashout' ? 'cashout' : 'deposit',
                        'target_amount' => $targetAmount,
                        'currency' => 'BWP'
                    ], $destinationDetails),
                    'sources' => $sources
                ];
                
                $result = $swapService->executeMultiSourceSwap($multiSourcePayload);
            } else {
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $sourceType = trim($_POST['source_type'] ?? '');
                $sourceInstitution = trim($_POST['source_institution'] ?? '');
                $destinationInstitution = trim($_POST['destination_institution'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $destinationType = trim($_POST['destination_type'] ?? '');
                $destinationValue = trim($_POST['destination_value'] ?? '');
                
                $sourcePayload = [
                    'institution' => $sourceInstitution,
                    'asset_type' => $sourceType,
                    'amount' => $amount,
                    'currency' => 'BWP'
                ];
                
                if ($sourceId > 0) {
                    $stmt = $db->prepare("SELECT * FROM user_funding_sources WHERE source_id = :id AND user_id = :user_id");
                    $stmt->execute([':id' => $sourceId, ':user_id' => $userId]);
                    $savedSource = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($savedSource) {
                        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
                        $decryptedIdentifier = decryptIdentifier($savedSource['encrypted_identifier'], $encryptionKey);
                        
                        if ($sourceType === 'ACCOUNT') {
                            $sourcePayload['account_number'] = $decryptedIdentifier;
                        } elseif ($sourceType === 'CARD') {
                            $sourcePayload['card_number'] = $decryptedIdentifier;
                        } else {
                            $sourcePayload['phone'] = formatPhoneNumberForSwap($decryptedIdentifier, $systemCountry);
                        }
                        
                        $db->prepare("UPDATE user_funding_sources SET last_used_at = NOW() WHERE source_id = ?")->execute([$sourceId]);
                    }
                } else {
                    $identifier = trim($_POST['identifier'] ?? '');
                    if ($sourceType === 'ACCOUNT') {
                        $sourcePayload['account_number'] = $identifier;
                    } elseif ($sourceType === 'CARD') {
                        $sourcePayload['card_number'] = $identifier;
                    } else {
                        $sourcePayload['phone'] = formatPhoneNumberForSwap($identifier, $systemCountry);
                    }
                }
                
                $destinationDetails = [];
                switch ($destinationType) {
                    case 'cashout':
                        $destinationDetails = ['cashout' => ['beneficiary_phone' => $destinationValue]];
                        break;
                    case 'bank':
                        $destinationDetails = ['beneficiary_account' => $destinationValue];
                        break;
                    default:
                        $destinationDetails = ['beneficiary_wallet' => $destinationValue];
                }
                
                $swapPayload = [
                    'source' => $sourcePayload,
                    'destination' => array_merge([
                        'institution' => $destinationInstitution,
                        'delivery_mode' => $destinationType === 'cashout' ? 'cashout' : 'deposit',
                        'amount' => $amount,
                        'currency' => 'BWP'
                    ], $destinationDetails),
                    'metadata' => [
                        'user_id' => $userId,
                        'user_phone' => $userPhone,
                        'channel' => 'dashboard'
                    ]
                ];
                
                $result = $swapService->executeSwap($swapPayload);
            }
            
            if ($isAjax) {
                echo json_encode($result);
                exit;
            }
            
        } catch (\Exception $e) {
            error_log("SWAP ERROR: " . $e->getMessage());
            if ($isAjax) {
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
            $error = $e->getMessage();
        }
    }
    
    if ($isAjax) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
        exit;
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>VouchMorph – Command Center</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
        background: #0a0a0f;
        font-family: 'Inter', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
        padding: 2rem;
    }
    
    .container { max-width: 900px; margin: 0 auto; }
    
    /* Sharp edges - Porsche design language */
    .header, .card, .modal-content, button, input, select {
        border-radius: 0 !important;
    }
    
    /* Header - Clean and minimal */
    .header {
        background: #0a0a0f;
        border-bottom: 2px solid #00F0FF;
        padding: 1.5rem 0;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .logo-area {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .logo-icon {
        width: 48px;
        height: 48px;
        background: #00F0FF;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .logo-icon i { font-size: 1.5rem; color: #0a0a0f; }
    
    .logo-text h1 {
        font-family: 'Clash Display', sans-serif;
        font-size: 1.5rem;
        font-weight: 600;
        letter-spacing: -0.02em;
    }
    
    .logo-text p { font-size: 0.7rem; color: #666; margin-top: 0.1rem; }
    
    .user-badge {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .phone-badge {
        font-family: monospace;
        font-size: 0.875rem;
        color: #00F0FF;
        background: rgba(0, 240, 255, 0.1);
        padding: 0.5rem 1rem;
        border: 1px solid rgba(0, 240, 255, 0.3);
    }
    
    .logout-btn {
        padding: 0.5rem 1rem;
        background: transparent;
        border: 1px solid #333;
        color: #ccc;
        text-decoration: none;
        transition: all 0.2s;
    }
    
    .logout-btn:hover { border-color: #FF3030; color: #FF3030; }
    
    /* Card styles */
    .card {
        background: #0f0f15;
        border: 1px solid #222;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    
    .card-header {
        padding: 1.25rem 1.5rem;
        background: #0a0a0f;
        border-bottom: 1px solid #222;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
    }
    
    .card-header h3 {
        font-family: 'Clash Display', sans-serif;
        font-size: 1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .card-header i:first-child { color: #00F0FF; }
    .toggle-icon { transition: transform 0.2s; color: #666; }
    .card-header.collapsed .toggle-icon { transform: rotate(-90deg); }
    
    .card-body { padding: 1.5rem; }
    .card-body.collapsed { display: none; }
    
    /* Quick action buttons - Large, clear */
    .action-buttons {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .action-btn {
        background: #0f0f15;
        border: 1px solid #333;
        padding: 1.25rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .action-btn:hover {
        border-color: #00F0FF;
        transform: translateY(-2px);
        background: #12121a;
    }
    
    .action-btn i { font-size: 1.75rem; color: #00F0FF; margin-bottom: 0.5rem; display: block; }
    .action-btn .label { font-size: 0.8rem; font-weight: 600; }
    .action-btn .desc { font-size: 0.65rem; color: #666; margin-top: 0.25rem; display: block; }
    
    /* Form styles */
    .form-group { margin-bottom: 1rem; }
    .form-group label {
        display: block;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.5rem;
        color: #888;
    }
    
    .form-control, .form-select {
        width: 100%;
        padding: 0.875rem 1rem;
        background: #050505;
        border: 1px solid #222;
        color: #fff;
        font-size: 0.875rem;
        transition: all 0.2s;
    }
    
    .form-control:focus, .form-select:focus {
        outline: none;
        border-color: #00F0FF;
    }
    
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    
    /* Primary button - Porsche style */
    .btn-primary {
        width: 100%;
        padding: 1rem;
        background: #00F0FF;
        border: none;
        color: #0a0a0f;
        font-weight: 700;
        font-size: 0.875rem;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .btn-primary:hover {
        background: #B000FF;
        color: #fff;
    }
    
    .btn-secondary {
        padding: 0.5rem 1rem;
        background: transparent;
        border: 1px solid #333;
        color: #ccc;
        cursor: pointer;
        font-size: 0.75rem;
        transition: all 0.2s;
    }
    
    .btn-secondary:hover { border-color: #00F0FF; color: #00F0FF; }
    
    /* Source chips */
    .source-chip {
        padding: 0.5rem 1rem;
        background: #050505;
        border: 1px solid #333;
        font-size: 0.75rem;
        cursor: pointer;
    }
    
    .source-chip.selected {
        background: #00F0FF;
        color: #0a0a0f;
        border-color: #00F0FF;
    }
    
    /* Contribution list */
    .contribution-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: #050505;
        border: 1px solid #222;
        margin-bottom: 0.5rem;
    }
    
    .remove-contribution {
        color: #FF6060;
        background: none;
        border: none;
        cursor: pointer;
    }
    
    /* Info box */
    .info-box {
        background: rgba(0, 240, 255, 0.05);
        border-left: 3px solid #00F0FF;
        padding: 0.75rem 1rem;
        margin-bottom: 1rem;
        font-size: 0.75rem;
        color: #aaa;
    }
    
    /* Transaction list */
    .transaction-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        border-bottom: 1px solid #222;
    }
    
    .transaction-item:last-child { border-bottom: none; }
    
    .transaction-amount { font-weight: 700; color: #00F0FF; }
    .transaction-status {
        font-size: 0.65rem;
        padding: 0.2rem 0.5rem;
        border: 1px solid;
    }
    .status-pending { border-color: #FFC107; color: #FFC107; }
    .status-completed { border-color: #00F0FF; color: #00F0FF; }
    
    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #666;
    }
    
    /* Modal */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.95);
        z-index: 1000;
        align-items: center;
        justify-content: center;
    }
    
    .modal-content {
        background: #0f0f15;
        border: 2px solid #00F0FF;
        max-width: 500px;
        width: 90%;
        padding: 1.5rem;
    }
    
    .modal-content h3 { margin-bottom: 1rem; font-family: 'Clash Display'; }
    
    /* Hidden panels */
    .hidden { display: none; }
    
    /* Responsive */
    @media (max-width: 640px) {
        body { padding: 1rem; }
        .form-row { grid-template-columns: 1fr; }
        .action-buttons { grid-template-columns: 1fr; }
        .header { flex-direction: column; text-align: center; }
    }
</style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo-area">
            <div class="logo-icon"><i class="fas fa-bolt"></i></div>
            <div class="logo-text">
                <h1>VOUCHMORPH</h1>
                <p>command center</p>
            </div>
        </div>
        <div class="user-badge">
            <div class="phone-badge"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($userPhone) ?></div>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Exit</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="info-box" style="border-left-color: #FF3030; background: rgba(255,48,48,0.1);">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="info-box" style="border-left-color: #00F0FF;">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="action-buttons">
        <div class="action-btn" onclick="showPanel('singlePanel')">
            <i class="fas fa-arrow-right"></i>
            <span class="label">Single Source</span>
            <span class="desc">Use one account or wallet</span>
        </div>
        <div class="action-btn" onclick="showPanel('multiPanel')">
            <i class="fas fa-layer-group"></i>
            <span class="label">Multi-Source</span>
            <span class="desc">Combine multiple sources</span>
        </div>
        <div class="action-btn" onclick="showPanel('sourcesPanel')">
            <i class="fas fa-link"></i>
            <span class="label">My Sources</span>
            <span class="desc">Manage linked accounts</span>
        </div>
    </div>

    <!-- SINGLE SOURCE PANEL -->
    <div id="singlePanel" class="card hidden">
        <div class="card-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-arrow-right"></i> Send Money</h3>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="card-body">
            <form id="singleSwapForm">
                <input type="hidden" name="action" value="swap">
                <input type="hidden" name="is_multi_source" value="0">
                
                <div class="form-group">
                    <label>From (Source)</label>
                    <select id="savedSourceSelect" class="form-select" onchange="useSavedSource()">
                        <option value="">-- Choose saved source --</option>
                        <?php foreach ($fundingSources as $source): ?>
                            <option value="<?= $source['source_id'] ?>"><?= htmlspecialchars($source['institution_name']) ?> • <?= $source['masked_identifier'] ?></option>
                        <?php endforeach; ?>
                        <option value="manual">-- Enter manually --</option>
                    </select>
                </div>
                
                <div id="manualFieldsContainer"></div>
                
                <div class="form-group">
                    <label>To (Destination)</label>
                    <select id="destInstitution" class="form-select" required>
                        <option value="">Select institution</option>
                        <?php foreach ($participants as $p): ?>
                            <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>"><?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Send to</label>
                        <select id="destType" class="form-select">
                            <option value="cashout">Cashout (ATM)</option>
                            <option value="bank">Bank Account</option>
                            <option value="wallet">Mobile Wallet</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (BWP)</label>
                        <input type="number" id="swapAmount" class="form-control" step="0.01" placeholder="0.00" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Destination Details</label>
                    <input type="text" id="destValue" class="form-control" placeholder="Phone number or account number" required>
                </div>
                
                <button type="submit" class="btn-primary" id="singleSubmitBtn">
                    <i class="fas fa-bolt"></i> EXECUTE SWAP
                </button>
            </form>
        </div>
    </div>

    <!-- MULTI-SOURCE PANEL -->
    <div id="multiPanel" class="card hidden">
        <div class="card-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-layer-group"></i> Combine Sources</h3>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="card-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i> Combine multiple wallets/accounts to send one payment
            </div>
            
            <div class="form-group">
                <label>Distribution</label>
                <select id="distStrategy" class="form-select">
                    <option value="drain_smallest">Drain smallest first (recommended)</option>
                    <option value="ratio">Proportional to balance</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Sources</label>
                <div id="sourceSelector" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <?php foreach ($fundingSources as $source): ?>
                        <button type="button" class="source-chip" data-source='<?= json_encode($source) ?>' onclick="toggleSource(this)">
                            <?= $source['source_type'] === 'ACCOUNT' ? '🏦' : ($source['source_type'] === 'WALLET' ? '📱' : '💳') ?>
                            <?= htmlspecialchars($source['institution_name']) ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="source-chip" onclick="showManualSourceModal()">+ Manual</button>
                    <button type="button" class="source-chip" onclick="showVoucherModal()">+ Voucher</button>
                </div>
            </div>
            
            <div id="contributionList" class="contribution-list"></div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Total Amount (BWP)</label>
                    <input type="number" id="multiTargetAmount" class="form-control" step="0.01" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Send to</label>
                    <select id="multiDestType" class="form-select">
                        <option value="cashout">Cashout</option>
                        <option value="bank">Bank</option>
                        <option value="wallet">Wallet</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Destination Institution</label>
                <select id="multiDestInstitution" class="form-select">
                    <option value="">Select institution</option>
                    <?php foreach ($participants as $p): ?>
                        <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>"><?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Destination Details</label>
                <input type="text" id="multiDestValue" class="form-control" placeholder="Phone or account number">
            </div>
            
            <button class="btn-primary" onclick="executeMultiSourceSwap()">
                <i class="fas fa-bolt"></i> EXECUTE COMBINED SWAP
            </button>
        </div>
    </div>

    <!-- MANAGE SOURCES PANEL -->
    <div id="sourcesPanel" class="card hidden">
        <div class="card-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-link"></i> My Linked Sources</h3>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="card-body">
            <?php if (empty($fundingSources)): ?>
                <div class="empty-state">
                    <i class="fas fa-plug" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                    <p>No linked sources yet</p>
                    <p style="font-size: 0.7rem;">Link your bank accounts or wallets for quick access</p>
                </div>
            <?php else: ?>
                <?php foreach ($fundingSources as $source): ?>
                    <div class="contribution-item" style="<?= $source['is_default'] ? 'border-left: 3px solid #00F0FF;' : '' ?>">
                        <div>
                            <strong><?= htmlspecialchars($source['institution_name']) ?></strong>
                            <?php if ($source['is_default']): ?> <span style="color:#00F0FF; font-size:0.6rem;">DEFAULT</span><?php endif; ?>
                            <div style="font-size: 0.7rem; color: #666;"><?= $source['source_type'] ?> • <?= $source['masked_identifier'] ?></div>
                        </div>
                        <div>
                            <button class="btn-secondary" style="margin-right: 0.5rem;" onclick="setDefaultSource(<?= $source['source_id'] ?>)">★</button>
                            <button class="btn-secondary" style="border-color:#FF3030;" onclick="removeSource(<?= $source['source_id'] ?>)">🗑</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <button class="btn-secondary" style="width: 100%; margin-top: 1rem;" onclick="showAddSourceModal()">
                <i class="fas fa-plus"></i> Link New Source
            </button>
        </div>
    </div>

    <!-- TRANSACTIONS BUTTON & CONTAINER -->
    <div class="card">
        <div class="card-header" onclick="toggleCard(this)">
            <h3><i class="fas fa-history"></i> Recent Transactions</h3>
            <i class="fas fa-chevron-down toggle-icon"></i>
        </div>
        <div class="card-body">
            <button class="btn-secondary" id="loadTransactionsBtn" style="width: 100%; margin-bottom: 1rem;">
                <i class="fas fa-refresh"></i> Load Transactions
            </button>
            <div id="transactionsContainer">
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <p>Click "Load Transactions" to see your history</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modals -->
<div id="addSourceModal" class="modal">
    <div class="modal-content">
        <h3>Link New Source</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_source">
            <div class="form-group">
                <label>Institution</label>
                <select name="institution_code" class="form-select" required>
                    <?php foreach ($participants as $p): ?>
                        <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>"><?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select name="source_type" class="form-select" required>
                    <option value="ACCOUNT">Bank Account</option>
                    <option value="WALLET">Mobile Wallet</option>
                    <option value="CARD">Card</option>
                </select>
            </div>
            <div class="form-group">
                <label>Identifier</label>
                <input type="text" name="identifier" class="form-control" placeholder="Account/Phone/Card number" required>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="is_default" value="1"> Set as default</label>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-primary" style="flex: 1;">Link</button>
                <button type="button" class="btn-secondary" onclick="closeModal('addSourceModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="manualSourceModal" class="modal">
    <div class="modal-content">
        <h3>Add Manual Source</h3>
        <div class="form-group">
            <label>Type</label>
            <select id="manualType" class="form-select">
                <option value="ACCOUNT">Bank Account</option>
                <option value="WALLET">Mobile Wallet</option>
                <option value="CARD">Card</option>
            </select>
        </div>
        <div class="form-group">
            <label>Institution</label>
            <select id="manualInst" class="form-select">
                <?php foreach ($participants as $p): ?>
                    <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>"><?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Identifier</label>
            <input type="text" id="manualIdentifier" class="form-control" placeholder="Account/Phone/Card number">
        </div>
        <div style="display: flex; gap: 1rem;">
            <button class="btn-primary" style="flex: 1;" onclick="addManualSource()">Add</button>
            <button class="btn-secondary" onclick="closeModal('manualSourceModal')">Cancel</button>
        </div>
    </div>
</div>

<div id="voucherModal" class="modal">
    <div class="modal-content">
        <h3>Add Voucher</h3>
        <div class="info-box" style="margin-bottom: 1rem;">Vouchers are temporary - not saved</div>
        <div class="form-group">
            <label>Voucher Number</label>
            <input type="text" id="voucherNumber" class="form-control">
        </div>
        <div class="form-group">
            <label>Claimant Phone</label>
            <input type="text" id="voucherPhone" class="form-control" value="<?= $userPhone ?>">
        </div>
        <div style="display: flex; gap: 1rem;">
            <button class="btn-primary" style="flex: 1;" onclick="addVoucher()">Add</button>
            <button class="btn-secondary" onclick="closeModal('voucherModal')">Cancel</button>
        </div>
    </div>
</div>

<script>
let selectedSources = [];

function showPanel(panelId) {
    document.querySelectorAll('.card').forEach(panel => {
        panel.classList.add('hidden');
    });
    document.getElementById(panelId).classList.remove('hidden');
    document.getElementById(panelId).scrollIntoView({ behavior: 'smooth' });
}

function toggleCard(header) {
    header.classList.toggle('collapsed');
    const body = header.nextElementSibling;
    body.classList.toggle('collapsed');
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function showAddSourceModal() {
    document.getElementById('addSourceModal').style.display = 'flex';
}

function showManualSourceModal() {
    document.getElementById('manualSourceModal').style.display = 'flex';
}

function showVoucherModal() {
    document.getElementById('voucherModal').style.display = 'flex';
}

function updateManualFields() {
    const select = document.getElementById('savedSourceSelect');
    const container = document.getElementById('manualFieldsContainer');
    
    if (select.value === 'manual') {
        container.innerHTML = `
            <div class="form-group">
                <label>Source Type</label>
                <select id="manualSourceType" class="form-select">
                    <option value="ACCOUNT">Bank Account</option>
                    <option value="WALLET">Mobile Wallet</option>
                    <option value="CARD">Card</option>
                    <option value="VOUCHER">Voucher</option>
                </select>
            </div>
            <div class="form-group">
                <label>Identifier</label>
                <input type="text" name="identifier" class="form-control" placeholder="Account/Phone/Card number">
            </div>
        `;
    } else {
        container.innerHTML = '';
    }
}

function useSavedSource() {
    updateManualFields();
}

function toggleSource(btn) {
    btn.classList.toggle('selected');
    const sourceData = JSON.parse(btn.dataset.source);
    
    if (btn.classList.contains('selected')) {
        selectedSources.push({
            institution: sourceData.institution_code,
            asset_type: sourceData.source_type,
            identifier: sourceData.masked_identifier,
            source_id: sourceData.source_id
        });
    } else {
        selectedSources = selectedSources.filter(s => s.source_id !== sourceData.source_id);
    }
    updateContributionList();
}

function updateContributionList() {
    const container = document.getElementById('contributionList');
    if (selectedSources.length === 0) {
        container.innerHTML = '<div class="empty-state">No sources selected</div>';
        return;
    }
    
    let html = '';
    selectedSources.forEach((source, idx) => {
        html += `<div class="contribution-item">
            <div>${source.asset_type} • ${source.institution}<br><span style="font-size:0.7rem;">${source.identifier}</span></div>
            <button class="remove-contribution" onclick="removeContribution(${idx})"><i class="fas fa-trash"></i></button>
        </div>`;
    });
    container.innerHTML = html;
}

function removeContribution(index) {
    selectedSources.splice(index, 1);
    updateContributionList();
    document.querySelectorAll('.source-chip.selected').forEach(btn => btn.classList.remove('selected'));
}

function addManualSource() {
    const type = document.getElementById('manualType').value;
    const institution = document.getElementById('manualInst').value;
    const identifier = document.getElementById('manualIdentifier').value;
    
    if (!identifier) { alert('Enter identifier'); return; }
    
    selectedSources.push({
        institution: institution,
        asset_type: type,
        identifier: identifier,
        is_manual: true
    });
    
    updateContributionList();
    closeModal('manualSourceModal');
    document.getElementById('manualIdentifier').value = '';
}

function addVoucher() {
    const voucherNumber = document.getElementById('voucherNumber').value;
    const voucherPhone = document.getElementById('voucherPhone').value;
    
    if (!voucherNumber) { alert('Enter voucher number'); return; }
    
    selectedSources.push({
        asset_type: 'VOUCHER',
        institution: 'VOUCHER',
        identifier: voucherNumber,
        claimant_phone: voucherPhone,
        is_voucher: true
    });
    
    updateContributionList();
    closeModal('voucherModal');
    document.getElementById('voucherNumber').value = '';
}

async function executeMultiSourceSwap() {
    if (selectedSources.length === 0) { alert('Select sources'); return; }
    
    const targetAmount = parseFloat(document.getElementById('multiTargetAmount').value);
    const destType = document.getElementById('multiDestType').value;
    const destInstitution = document.getElementById('multiDestInstitution').value;
    const destValue = document.getElementById('multiDestValue').value;
    
    if (!targetAmount || targetAmount <= 0) { alert('Enter amount'); return; }
    if (!destInstitution || !destValue) { alert('Enter destination'); return; }
    
    const formData = new FormData();
    formData.append('action', 'swap');
    formData.append('is_multi_source', '1');
    formData.append('amount', targetAmount);
    formData.append('destination_type', destType);
    formData.append('destination_institution', destInstitution);
    formData.append('destination_value', destValue);
    formData.append('sources', JSON.stringify(selectedSources));
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSING...';
    btn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            alert('✅ Swap successful!');
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}

function setDefaultSource(sourceId) {
    fetch(window.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=set_default&source_id=${sourceId}`
    }).then(() => location.reload());
}

function removeSource(sourceId) {
    if (confirm('Remove this source?')) {
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=remove_source&source_id=${sourceId}`
        }).then(() => location.reload());
    }
}

// Load transactions on button click
document.getElementById('loadTransactionsBtn')?.addEventListener('click', async function() {
    const container = document.getElementById('transactionsContainer');
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    
    try {
        const response = await fetch(window.location.href + '?ajax=transactions', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.success && data.transactions.length > 0) {
            let html = '';
            for (const tx of data.transactions) {
                html += `
                    <div class="transaction-item">
                        <div>
                            <div style="font-size:0.7rem; color:#666;">${tx.date}</div>
                            <div>${tx.type}</div>
                        </div>
                        <div class="transaction-amount">${tx.amount} BWP</div>
                        <div><span class="transaction-status status-${tx.status.toLowerCase()}">${tx.status}</span></div>
                    </div>
                `;
            }
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="empty-state"><i class="fas fa-history"></i><p>No transactions found</p></div>';
        }
    } catch (error) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Failed to load</p></div>';
    }
});

// Single source form submission
document.getElementById('singleSwapForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'swap');
    formData.append('is_multi_source', '0');
    
    const sourceId = document.querySelector('input[name="source_id"]')?.value;
    if (sourceId) {
        formData.append('source_id', sourceId);
    } else {
        const sourceType = document.getElementById('manualSourceType')?.value || 'WALLET';
        formData.append('source_type', sourceType);
        formData.append('source_institution', document.getElementById('destInstitution')?.value || '');
        const identifier = document.querySelector('input[name="identifier"]')?.value;
        if (identifier) formData.append('identifier', identifier);
    }
    
    formData.append('destination_institution', document.getElementById('destInstitution').value);
    formData.append('destination_type', document.getElementById('destType').value);
    formData.append('destination_value', document.getElementById('destValue').value);
    formData.append('amount', document.getElementById('swapAmount').value);
    
    const btn = document.getElementById('singleSubmitBtn');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> PROCESSING...';
    btn.disabled = true;
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        
        if (data.status === 'success') {
            alert('✅ Swap successful!\nReference: ' + (data.swap_reference || 'OK'));
            location.reload();
        } else {
            alert('❌ Error: ' + (data.message || 'Unknown error'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
});

updateManualFields();
</script>
</body>
</html>
