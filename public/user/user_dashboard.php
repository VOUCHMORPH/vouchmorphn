<?php
// public/user/user_dashboard.php
// MUST be the very first thing - no whitespace before this!
ob_start();

// Disable error reporting for AJAX requests
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Include files AFTER output buffering starts
require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Domain\Services\SwapService;
use Core\Config\LoadCountry;

// Start session
SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$systemCountry = $user['country'] ?? 'BW';

// Load country configuration
$config = LoadCountry::getConfig();
$dbConfig = $config['db']['swap'] ?? null;

// Debug: log what we got
error_log("=== DB CONFIG FROM LOADCOUNTRY ===");
error_log(json_encode($dbConfig));

// If dbConfig is empty or missing required fields, use fallback
if (empty($dbConfig) || empty($dbConfig['host'])) {
    error_log("DB Config missing, using environment fallback");
    $dbConfig = null; // Let DBConnection use environment
}

try {
    // Pass config to getInstance - NOW IT WILL BE RESPECTED!
    $db = DBConnection::getInstance($dbConfig);
    
    if (!$db) {
        throw new \Exception("Failed to get database connection");
    }
    
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    error_log("Database connection successful");
    
    // Ensure tables exist
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
    // Simple encryption - in production use proper encryption
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

// Build participant config
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

// Load user's recent transactions
$stmt = $db->prepare("
    SELECT swap_id, swap_uuid, from_currency, to_currency, amount, 
           source_details, destination_details, status, created_at, metadata
    FROM swap_requests
    WHERE CAST(metadata AS TEXT) LIKE :phone_pattern 
    ORDER BY created_at DESC
    LIMIT 30
");
$stmt->execute([':phone_pattern' => '%' . $userPhone . '%']);
$userTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   HANDLE REQUESTS
========================= */
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Handle adding funding source
    if ($action === 'add_source') {
        try {
            $institutionCode = trim($_POST['institution_code'] ?? '');
            $institutionName = trim($_POST['institution_name'] ?? '');
            $sourceType = strtoupper(trim($_POST['source_type'] ?? ''));
            $identifier = trim($_POST['identifier'] ?? '');
            $sourceLabel = trim($_POST['source_label'] ?? '');
            $linkedPhone = trim($_POST['linked_phone'] ?? $userPhone);
            $isDefault = isset($_POST['is_default']) ? 1 : 0;
            
            // Validate institution exists
            if (!isset($participantConfig[$institutionCode])) {
                throw new \Exception("Institution not found");
            }
            
            // Verify the account exists before saving
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
            
            // Check if already exists
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
            
            // If default, remove other defaults
            if ($isDefault) {
                $db->prepare("UPDATE user_funding_sources SET is_default = FALSE WHERE user_id = ?")->execute([$userId]);
            }
            
            // Encrypt identifier
            $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!';
            $encryptedIdentifier = encryptIdentifier($identifier, $encryptionKey);
            $maskedIdentifier = maskValue($identifier, 4);
            
            // Insert
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
    
    // Handle removing source
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
    
    // Handle swap execution
    if ($action === 'swap') {
        if ($isAjax) {
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/json');
        }
        
        try {
            $isMultiSource = isset($_POST['is_multi_source']) && $_POST['is_multi_source'] === '1';
            $result = null;
            
            // Initialize SwapService
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
                // Multi-source swap
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
                // Single source swap
                $sourceId = (int)($_POST['source_id'] ?? 0);
                $sourceType = trim($_POST['source_type'] ?? '');
                $sourceInstitution = trim($_POST['source_institution'] ?? '');
                $destinationInstitution = trim($_POST['destination_institution'] ?? '');
                $amount = (float)($_POST['amount'] ?? 0);
                $destinationType = trim($_POST['destination_type'] ?? '');
                $destinationValue = trim($_POST['destination_value'] ?? '');
                
                // Build source payload
                $sourcePayload = [
                    'institution' => $sourceInstitution,
                    'asset_type' => $sourceType,
                    'amount' => $amount,
                    'currency' => 'BWP'
                ];
                
                // If using saved source, decrypt identifier
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
                        
                        // Update last used
                        $db->prepare("UPDATE user_funding_sources SET last_used_at = NOW() WHERE source_id = ?")->execute([$sourceId]);
                    }
                } else {
                    // Manual entry
                    $identifier = trim($_POST['identifier'] ?? '');
                    if ($sourceType === 'ACCOUNT') {
                        $sourcePayload['account_number'] = $identifier;
                    } elseif ($sourceType === 'CARD') {
                        $sourcePayload['card_number'] = $identifier;
                    } else {
                        $sourcePayload['phone'] = formatPhoneNumberForSwap($identifier, $systemCountry);
                    }
                }
                
                // Destination details
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
<title>VouchMorph™ – Command Center</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        background: #050505;
        font-family: 'Inter', sans-serif;
        color: #FFFFFF;
        min-height: 100vh;
        padding: 1.5rem;
    }
    .container { max-width: 1200px; margin: 0 auto; }
    
    /* Header */
    .header {
        background: rgba(10, 10, 15, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .user-info { display: flex; align-items: center; gap: 1rem; }
    .avatar {
        width: 56px; height: 56px;
        background: linear-gradient(135deg, #00F0FF, #B000FF);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .avatar i { font-size: 1.5rem; color: #050505; }
    .user-name h2 { font-family: 'Clash Display', sans-serif; font-size: 1.25rem; margin-bottom: 0.25rem; }
    .user-phone { color: #00F0FF; font-family: monospace; font-size: 0.875rem; }
    .logout-btn {
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        color: #E0E0E0;
        text-decoration: none;
        transition: all 0.2s;
    }
    .logout-btn:hover { border-color: #00F0FF; color: #00F0FF; }
    
    /* Action Grid */
    .action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    .action-card {
        background: rgba(0, 240, 255, 0.04);
        border: 1px solid rgba(0, 240, 255, 0.15);
        border-radius: 16px;
        padding: 1.25rem;
        text-align: left;
        cursor: pointer;
        transition: all 0.2s;
    }
    .action-card:hover {
        background: rgba(0, 240, 255, 0.08);
        border-color: #00F0FF;
        transform: translateY(-2px);
    }
    .action-card i { font-size: 1.5rem; color: #00F0FF; margin-bottom: 0.75rem; display: block; }
    .action-card strong { display: block; font-family: 'Clash Display', sans-serif; font-size: 1rem; margin-bottom: 0.25rem; }
    .action-card span { color: #A0A0B0; font-size: 0.75rem; }
    
    /* Flow Panels */
    .flow-panel {
        background: rgba(10, 10, 15, 0.95);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 2rem;
    }
    .flow-panel.hidden { display: none; }
    .panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid rgba(255,255,255,0.08);
    }
    .panel-header h3 { font-family: 'Clash Display', sans-serif; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; }
    .close-panel {
        background: none; border: none; color: #606070; cursor: pointer; font-size: 1.25rem;
    }
    .close-panel:hover { color: #00F0FF; }
    
    /* Form Styles */
    .form-group { margin-bottom: 1rem; }
    .form-group label { display: block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.5rem; color: #C0C0D0; }
    .form-control, .form-select {
        width: 100%;
        padding: 0.75rem 1rem;
        background: rgba(0,0,0,0.5);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 10px;
        color: #FFFFFF;
        font-size: 0.875rem;
    }
    .form-control:focus, .form-select:focus { outline: none; border-color: #00F0FF; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    
    /* Buttons */
    .btn-primary {
        width: 100%;
        padding: 0.875rem;
        background: linear-gradient(135deg, #00F0FF, #B000FF);
        border: none;
        border-radius: 10px;
        color: #050505;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(0,240,255,0.4); }
    .btn-secondary {
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        color: #E0E0E0;
        cursor: pointer;
        font-size: 0.75rem;
    }
    .btn-secondary:hover { border-color: #00F0FF; color: #00F0FF; }
    .btn-danger { background: rgba(255,48,48,0.1); border-color: rgba(255,48,48,0.3); color: #FF6060; }
    .btn-danger:hover { background: rgba(255,48,48,0.2); }
    
    /* Source Cards */
    .sources-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-top: 1rem;
    }
    .source-card {
        background: rgba(0, 240, 255, 0.03);
        border: 1px solid rgba(0, 240, 255, 0.1);
        border-radius: 12px;
        padding: 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .source-card.default { border-color: #00F0FF; background: rgba(0, 240, 255, 0.08); }
    .source-info .source-name { font-weight: 600; margin-bottom: 0.25rem; }
    .source-info .source-detail { font-size: 0.7rem; color: #A0A0B0; font-family: monospace; }
    .source-badge { font-size: 0.6rem; padding: 0.15rem 0.5rem; background: rgba(0,240,255,0.15); border-radius: 4px; }
    
    /* Contribution List */
    .contribution-list { margin: 1rem 0; }
    .contribution-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: rgba(0,0,0,0.3);
        border-radius: 10px;
        margin-bottom: 0.5rem;
    }
    .remove-contribution { color: #FF6060; background: none; border: none; cursor: pointer; }
    
    /* Source Chips */
    .source-chip {
        padding: 0.5rem 1rem;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 20px;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .source-chip.selected { background: #00F0FF; color: #050505; border-color: #00F0FF; }
    
    /* Alerts */
    .alert { padding: 1rem; border-radius: 10px; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem; }
    .alert-error { background: rgba(255,48,48,0.1); border-left: 3px solid #FF3030; }
    .alert-success { background: rgba(0,240,255,0.1); border-left: 3px solid #00F0FF; }
    
    /* Status */
    .status-badge {
        font-size: 0.6rem;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }
    .status-pending { background: rgba(255,193,7,0.15); color: #FFC107; }
    .status-completed { background: rgba(0,240,255,0.15); color: #00F0FF; }
    
    /* Responsive */
    @media (max-width: 640px) {
        body { padding: 1rem; }
        .form-row { grid-template-columns: 1fr; }
        .action-grid { grid-template-columns: 1fr; }
    }
</style>
</head>
<body>

<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="user-info">
            <div class="avatar"><i class="fas fa-user"></i></div>
            <div class="user-name">
                <h2>Command Center</h2>
                <div class="user-phone"><i class="fas fa-mobile-alt"></i> <?= htmlspecialchars($userPhone) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Exit</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Action Grid -->
    <div class="action-grid">
        <button class="action-card" onclick="showPanel('singleSwapPanel')">
            <i class="fas fa-arrow-right"></i>
            <strong>Single Source Swap</strong>
            <span>Use one account, wallet, or voucher</span>
        </button>
        <button class="action-card" onclick="showPanel('multiSourcePanel')">
            <i class="fas fa-layer-group"></i>
            <strong>Multi-Source Swap</strong>
            <span>Combine multiple accounts & wallets</span>
        </button>
        <button class="action-card" onclick="showPanel('sourcesPanel')">
            <i class="fas fa-link"></i>
            <strong>Manage Sources</strong>
            <span>Tie accounts & wallets to dashboard</span>
        </button>
    </div>

    <!-- SINGLE SOURCE SWAP PANEL -->
    <div id="singleSwapPanel" class="flow-panel hidden">
        <div class="panel-header">
            <h3><i class="fas fa-arrow-right"></i> Single Source Swap</h3>
            <button class="close-panel" onclick="hideAllPanels()"><i class="fas fa-times"></i></button>
        </div>
        
        <form id="singleSwapForm">
            <input type="hidden" name="action" value="swap">
            <input type="hidden" name="is_multi_source" value="0">
            
            <div class="form-row">
                <div class="form-group">
                    <label>Use Saved Source</label>
                    <select id="savedSourceSelect" class="form-select" onchange="useSavedSource()">
                        <option value="">-- Manual Entry --</option>
                        <?php foreach ($fundingSources as $source): ?>
                            <option value="<?= $source['source_id'] ?>" 
                                    data-type="<?= $source['source_type'] ?>"
                                    data-institution="<?= htmlspecialchars($source['institution_code']) ?>"
                                    data-masked="<?= htmlspecialchars($source['masked_identifier']) ?>">
                                <?= htmlspecialchars($source['institution_name']) ?> • <?= $source['source_type'] ?> • <?= $source['masked_identifier'] ?>
                                <?= $source['is_default'] ? ' ★' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Or Source Type</label>
                    <select id="manualSourceType" class="form-select" onchange="updateManualFields()">
                        <option value="ACCOUNT">🏦 Bank Account</option>
                        <option value="WALLET">📱 Mobile Wallet</option>
                        <option value="CARD">💳 Card</option>
                        <option value="VOUCHER">🎫 Voucher (Temporary)</option>
                    </select>
                </div>
            </div>
            
            <div id="manualFieldsContainer"></div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Destination Institution</label>
                    <select id="destInstitution" class="form-select" required>
                        <option value="">Select institution</option>
                        <?php foreach ($participants as $p): ?>
                            <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                                <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destination Type</label>
                    <select id="destType" class="form-select" onchange="updateDestPlaceholder()">
                        <option value="cashout">💰 Cashout (ATM/Agent)</option>
                        <option value="bank">🏦 Bank Account Deposit</option>
                        <option value="wallet">📱 Mobile Wallet Transfer</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label>Destination Value</label>
                <input type="text" id="destValue" class="form-control" placeholder="Phone number or account number" required>
            </div>
            
            <div class="form-group">
                <label>Amount (BWP)</label>
                <input type="number" id="swapAmount" class="form-control" step="0.01" placeholder="0.00" required>
            </div>
            
            <button type="submit" class="btn-primary" id="singleSubmitBtn">
                <i class="fas fa-bolt"></i> EXECUTE SWAP
            </button>
        </form>
    </div>

    <!-- MULTI-SOURCE SWAP PANEL -->
    <div id="multiSourcePanel" class="flow-panel hidden">
        <div class="panel-header">
            <h3><i class="fas fa-layer-group"></i> Multi-Source Swap</h3>
            <button class="close-panel" onclick="hideAllPanels()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="info-box" style="background: rgba(0,240,255,0.05); padding: 1rem; border-radius: 10px; margin-bottom: 1rem;">
            <i class="fas fa-info-circle"></i> Combine multiple sources to fund one destination
        </div>
        
        <div class="form-group">
            <label>Distribution Strategy</label>
            <select id="distStrategy" class="form-select">
                <option value="drain_smallest">🥤 Drain Smallest First (Recommended)</option>
                <option value="ratio">📊 Proportional to Balance</option>
                <option value="user_specified">✏️ User Specified</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Select Sources</label>
            <div id="sourceSelector" class="source-selector" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php foreach ($fundingSources as $source): ?>
                    <button type="button" class="source-chip" data-source='<?= json_encode($source) ?>' onclick="toggleSource(this)">
                        <?= $source['source_type'] === 'ACCOUNT' ? '🏦' : ($source['source_type'] === 'WALLET' ? '📱' : '💳') ?>
                        <?= htmlspecialchars($source['institution_name']) ?> • <?= $source['masked_identifier'] ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="source-chip" onclick="showManualSourceModal()">
                    <i class="fas fa-plus"></i> Add Manual
                </button>
                <button type="button" class="source-chip" onclick="showVoucherModal()">
                    <i class="fas fa-ticket-alt"></i> Add Voucher
                </button>
            </div>
        </div>
        
        <div id="contributionList" class="contribution-list"></div>
        
        <div class="form-row">
            <div class="form-group">
                <label>Target Amount (BWP)</label>
                <input type="number" id="multiTargetAmount" class="form-control" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Destination Type</label>
                <select id="multiDestType" class="form-select">
                    <option value="cashout">💰 Cashout</option>
                    <option value="bank">🏦 Bank Account</option>
                    <option value="wallet">📱 Mobile Wallet</option>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label>Destination Institution</label>
            <select id="multiDestInstitution" class="form-select">
                <option value="">Select institution</option>
                <?php foreach ($participants as $p): ?>
                    <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                        <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group">
            <label>Destination Details</label>
            <input type="text" id="multiDestValue" class="form-control" placeholder="Phone number or account number">
        </div>
        
        <button class="btn-primary" onclick="executeMultiSourceSwap()">
            <i class="fas fa-bolt"></i> EXECUTE MULTI-SOURCE SWAP
        </button>
    </div>

    <!-- MANAGE SOURCES PANEL -->
    <div id="sourcesPanel" class="flow-panel hidden">
        <div class="panel-header">
            <h3><i class="fas fa-link"></i> Manage Funding Sources</h3>
            <button class="close-panel" onclick="hideAllPanels()"><i class="fas fa-times"></i></button>
        </div>
        
        <div class="sources-grid">
            <?php foreach ($fundingSources as $source): ?>
                <div class="source-card <?= $source['is_default'] ? 'default' : '' ?>">
                    <div class="source-info">
                        <div class="source-name">
                            <?= htmlspecialchars($source['institution_name']) ?>
                            <?php if ($source['is_default']): ?><span class="source-badge">DEFAULT</span><?php endif; ?>
                        </div>
                        <div class="source-detail">
                            <?= $source['source_type'] ?> • <?= $source['masked_identifier'] ?>
                            <?php if ($source['source_label']): ?> • <?= htmlspecialchars($source['source_label']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <button class="btn-secondary" style="margin-right: 0.5rem;" onclick="setDefaultSource(<?= $source['source_id'] ?>)">
                            <i class="fas fa-star"></i>
                        </button>
                        <button class="btn-secondary btn-danger" onclick="removeSource(<?= $source['source_id'] ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="source-card" style="border-style: dashed; justify-content: center;">
                <button class="btn-secondary" onclick="showAddSourceModal()" style="width: 100%;">
                    <i class="fas fa-plus"></i> Link New Source
                </button>
            </div>
        </div>
    </div>

    <!-- RECENT TRANSACTIONS -->
    <div class="flow-panel" style="margin-top: 1rem;">
        <div class="panel-header">
            <h3><i class="fas fa-history"></i> Recent Transactions</h3>
        </div>
        <?php if (empty($userTransactions)): ?>
            <div style="text-align: center; padding: 2rem; color: #606070;">
                <i class="fas fa-history" style="font-size: 2rem; margin-bottom: 0.5rem; display: block;"></i>
                <p>No transactions yet</p>
            </div>
        <?php else: ?>
            <?php foreach (array_slice($userTransactions, 0, 10) as $tx): ?>
                <div style="padding: 0.75rem 0; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between;">
                    <div>
                        <div style="font-size: 0.7rem; color: #606070;"><?= date('d M Y H:i', strtotime($tx['created_at'])) ?></div>
                        <div style="font-size: 0.8rem;"><?= number_format((float)($tx['amount'] ?? 0), 2) ?> BWP</div>
                    </div>
                    <div><span class="status-badge status-<?= strtolower($tx['status'] ?? 'pending') ?>"><?= $tx['status'] ?? 'PENDING' ?></span></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Source Modal -->
<div id="addSourceModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.95); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #0A0A0F; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; max-width: 500px; width: 90%; padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;">Link New Source</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_source">
            <div class="form-group">
                <label>Institution</label>
                <select name="institution_code" class="form-select" required>
                    <?php foreach ($participants as $p): ?>
                        <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                            <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="institution_name" id="instName">
            </div>
            <div class="form-group">
                <label>Source Type</label>
                <select name="source_type" class="form-select" required>
                    <option value="ACCOUNT">🏦 Bank Account</option>
                    <option value="WALLET">📱 Mobile Wallet</option>
                    <option value="CARD">💳 Card</option>
                </select>
            </div>
            <div class="form-group">
                <label>Identifier (Account number / Phone / Card number)</label>
                <input type="text" name="identifier" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Label (Optional)</label>
                <input type="text" name="source_label" class="form-control" placeholder="e.g., My Salary Account">
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="is_default" value="1"> Set as default source</label>
            </div>
            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn-primary" style="flex: 1;">Link Source</button>
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Manual Source Modal for Multi-Source -->
<div id="manualSourceModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.95); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #0A0A0F; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; max-width: 500px; width: 90%; padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;">Add Manual Source</h3>
        <div class="form-group">
            <label>Source Type</label>
            <select id="manualType" class="form-select">
                <option value="ACCOUNT">🏦 Bank Account</option>
                <option value="WALLET">📱 Mobile Wallet</option>
                <option value="CARD">💳 Card</option>
            </select>
        </div>
        <div class="form-group">
            <label>Institution</label>
            <select id="manualInst" class="form-select">
                <?php foreach ($participants as $p): ?>
                    <option value="<?= htmlspecialchars($p['provider_code'] ?: $p['name']) ?>">
                        <?= participantIcon($p) ?> <?= htmlspecialchars($p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Identifier</label>
            <input type="text" id="manualIdentifier" class="form-control" placeholder="Account number / Phone / Card number">
        </div>
        <div class="form-group">
            <label>Amount (Optional)</label>
            <input type="number" id="manualAmount" class="form-control" step="0.01" placeholder="Auto-distribute">
        </div>
        <div style="display: flex; gap: 1rem;">
            <button class="btn-primary" style="flex: 1;" onclick="addManualSource()">Add Source</button>
            <button class="btn-secondary" onclick="closeManualModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Voucher Modal -->
<div id="voucherModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.95); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: #0A0A0F; border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; max-width: 500px; width: 90%; padding: 1.5rem;">
        <h3 style="margin-bottom: 1rem;">Add Voucher (Temporary)</h3>
        <div class="info-box" style="margin-bottom: 1rem; padding: 0.75rem; background: rgba(255,193,7,0.1); border-radius: 8px;">
            <i class="fas fa-info-circle"></i> Vouchers are temporary and will not be saved to your dashboard
        </div>
        <div class="form-group">
            <label>Voucher Number</label>
            <input type="text" id="voucherNumber" class="form-control" placeholder="Enter voucher number">
        </div>
        <div class="form-group">
            <label>Voucher PIN (if required)</label>
            <input type="password" id="voucherPin" class="form-control" placeholder="Enter PIN">
        </div>
        <div class="form-group">
            <label>Claimant Phone</label>
            <input type="text" id="voucherPhone" class="form-control" value="<?= $userPhone ?>">
        </div>
        <div class="form-group">
            <label>Amount (Optional)</label>
            <input type="number" id="voucherAmount" class="form-control" step="0.01" placeholder="Full voucher amount">
        </div>
        <div style="display: flex; gap: 1rem;">
            <button class="btn-primary" style="flex: 1;" onclick="addVoucher()">Add Voucher</button>
            <button class="btn-secondary" onclick="closeVoucherModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
let selectedSources = [];

function showPanel(panelId) {
    document.querySelectorAll('.flow-panel').forEach(panel => {
        panel.classList.add('hidden');
    });
    document.getElementById(panelId).classList.remove('hidden');
    document.getElementById(panelId).scrollIntoView({ behavior: 'smooth' });
}

function hideAllPanels() {
    document.querySelectorAll('.flow-panel').forEach(panel => {
        panel.classList.add('hidden');
    });
}

function updateManualFields() {
    const type = document.getElementById('manualSourceType').value;
    const container = document.getElementById('manualFieldsContainer');
    
    if (type === 'ACCOUNT') {
        container.innerHTML = `<div class="form-group"><label>Account Number</label><input type="text" name="identifier" class="form-control" placeholder="Enter account number"></div>`;
    } else if (type === 'CARD') {
        container.innerHTML = `<div class="form-group"><label>Card Number</label><input type="text" name="identifier" class="form-control" placeholder="Enter card number"></div>`;
    } else if (type === 'VOUCHER') {
        container.innerHTML = `
            <div class="form-group"><label>Voucher Number</label><input type="text" name="identifier" class="form-control" placeholder="Enter voucher number"></div>
            <div class="form-group"><label>Claimant Phone</label><input type="text" name="voucher_phone" class="form-control" value="<?= $userPhone ?>"></div>
        `;
    } else {
        container.innerHTML = `<div class="form-group"><label>Phone Number</label><input type="text" name="identifier" class="form-control" placeholder="Enter phone number"></div>`;
    }
}

function useSavedSource() {
    const select = document.getElementById('savedSourceSelect');
    const option = select.options[select.selectedIndex];
    const sourceId = select.value;
    
    if (sourceId) {
        document.getElementById('manualSourceType').disabled = true;
        document.getElementById('manualFieldsContainer').innerHTML = `
            <div class="form-group">
                <label>Using Saved Source</label>
                <input type="hidden" name="source_id" value="${sourceId}">
                <input type="text" class="form-control" value="${option.dataset.masked}" readonly disabled>
            </div>
        `;
    } else {
        document.getElementById('manualSourceType').disabled = false;
        updateManualFields();
    }
}

function updateDestPlaceholder() {
    const type = document.getElementById('destType').value;
    const input = document.getElementById('destValue');
    if (type === 'cashout') input.placeholder = 'Beneficiary phone number';
    else if (type === 'bank') input.placeholder = 'Beneficiary account number';
    else input.placeholder = 'Wallet phone number';
}

// Multi-source functions
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
        container.innerHTML = '<div style="text-align: center; padding: 1rem; color: #606070;">No sources selected</div>';
        return;
    }
    
    let html = '<div style="margin-bottom: 0.5rem; font-size: 0.75rem;">Selected Sources:</div>';
    selectedSources.forEach((source, idx) => {
        html += `<div class="contribution-item">
            <div><strong>${source.asset_type}</strong> • ${source.institution}<br><span style="font-size: 0.7rem;">${source.identifier}</span></div>
            <button class="remove-contribution" onclick="removeContribution(${idx})"><i class="fas fa-trash"></i></button>
        </div>`;
    });
    container.innerHTML = html;
}

function removeContribution(index) {
    selectedSources.splice(index, 1);
    updateContributionList();
    // Also update the source chips
    document.querySelectorAll('.source-chip.selected').forEach(btn => {
        btn.classList.remove('selected');
    });
    selectedSources.forEach(s => {
        document.querySelectorAll('.source-chip').forEach(btn => {
            if (btn.dataset.source && JSON.parse(btn.dataset.source).source_id === s.source_id) {
                btn.classList.add('selected');
            }
        });
    });
}

function showManualSourceModal() {
    document.getElementById('manualSourceModal').style.display = 'flex';
}

function closeManualModal() {
    document.getElementById('manualSourceModal').style.display = 'none';
}

function addManualSource() {
    const type = document.getElementById('manualType').value;
    const institution = document.getElementById('manualInst').value;
    const identifier = document.getElementById('manualIdentifier').value;
    const amount = document.getElementById('manualAmount').value;
    
    if (!identifier) {
        alert('Please enter identifier');
        return;
    }
    
    selectedSources.push({
        institution: institution,
        asset_type: type,
        identifier: identifier,
        amount: amount ? parseFloat(amount) : null,
        is_manual: true
    });
    
    updateContributionList();
    closeManualModal();
    document.getElementById('manualIdentifier').value = '';
    document.getElementById('manualAmount').value = '';
}

function showVoucherModal() {
    document.getElementById('voucherModal').style.display = 'flex';
}

function closeVoucherModal() {
    document.getElementById('voucherModal').style.display = 'none';
}

function addVoucher() {
    const voucherNumber = document.getElementById('voucherNumber').value;
    const voucherPin = document.getElementById('voucherPin').value;
    const voucherPhone = document.getElementById('voucherPhone').value;
    const amount = document.getElementById('voucherAmount').value;
    
    if (!voucherNumber) {
        alert('Please enter voucher number');
        return;
    }
    
    selectedSources.push({
        asset_type: 'VOUCHER',
        institution: 'VOUCHER_ISSUER',
        identifier: voucherNumber,
        voucher_pin: voucherPin,
        claimant_phone: voucherPhone,
        amount: amount ? parseFloat(amount) : null,
        is_voucher: true
    });
    
    updateContributionList();
    closeVoucherModal();
    document.getElementById('voucherNumber').value = '';
    document.getElementById('voucherPin').value = '';
    document.getElementById('voucherAmount').value = '';
}

async function executeMultiSourceSwap() {
    if (selectedSources.length === 0) {
        alert('Please select at least one source');
        return;
    }
    
    const targetAmount = parseFloat(document.getElementById('multiTargetAmount').value);
    const destType = document.getElementById('multiDestType').value;
    const destInstitution = document.getElementById('multiDestInstitution').value;
    const destValue = document.getElementById('multiDestValue').value;
    const strategy = document.getElementById('distStrategy').value;
    
    if (!targetAmount || targetAmount <= 0) {
        alert('Please enter target amount');
        return;
    }
    if (!destInstitution || !destValue) {
        alert('Please enter destination details');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'swap');
    formData.append('is_multi_source', '1');
    formData.append('amount', targetAmount);
    formData.append('destination_type', destType);
    formData.append('destination_institution', destInstitution);
    formData.append('destination_value', destValue);
    formData.append('distribution_strategy', strategy);
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
            alert('✅ Multi-source swap executed!\nReference: ' + (data.master_reference || data.swap_reference));
            if (data.withdrawal_code) {
                alert('💰 Withdrawal Code: ' + data.withdrawal_code);
            }
            selectedSources = [];
            updateContributionList();
            document.getElementById('multiTargetAmount').value = '';
            document.getElementById('multiDestValue').value = '';
            document.querySelectorAll('.source-chip.selected').forEach(btn => btn.classList.remove('selected'));
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

// Manage sources functions
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

function showAddSourceModal() {
    document.getElementById('addSourceModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('addSourceModal').style.display = 'none';
}

// Single source form submission
document.getElementById('singleSwapForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'swap');
    formData.append('is_multi_source', '0');
    
    // Check if using saved source
    const sourceId = document.querySelector('input[name="source_id"]')?.value;
    if (sourceId) {
        formData.append('source_id', sourceId);
    } else {
        const sourceType = document.getElementById('manualSourceType').value;
        formData.append('source_type', sourceType);
        formData.append('source_institution', document.querySelector('select[name="source_institution"]')?.value || '');
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
            alert('✅ Swap successful!\nReference: ' + data.swap_reference);
            if (data.withdrawal_code) alert('💰 Withdrawal Code: ' + data.withdrawal_code);
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
updateDestPlaceholder();
</script>
</body>
</html>
