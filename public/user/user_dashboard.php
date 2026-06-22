<?php
// public/user/dashboard.php - FULLY DYNAMIC WITH MULTI-SOURCE SUPPORT

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Config/AssetTypeRegistry.php';

use Application\Utils\SessionManager;
use Core\Config\AssetTypeRegistry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$loggedPhone = htmlspecialchars($user['phone'] ?? '');
$userId = $user['user_id'] ?? null;
$userPhone = htmlspecialchars($user['phone'] ?? '');
$userNationalId = htmlspecialchars($user['national_id'] ?? '');
$userEmail = htmlspecialchars($user['email'] ?? '');

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;

$config = LoadCountry::getConfig();
$countryCode = $config['country_code'] ?? 'BW';
$countryName = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';
$currency = $config['currency'] ?? 'BWP';

// Load ATM notes
$atmNotesPath = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName . '/atm_notes.json';
$atmDenominations = [200, 100, 50, 20, 10];
if (file_exists($atmNotesPath)) {
    $atmData = json_decode(file_get_contents($atmNotesPath), true);
    $atmDenominations = $atmData[$currency] ?? $atmDenominations;
}

try {
    $swapDB = DBConnection::getConnection();
} catch (Exception $e) {
    die("Database error");
}

// ============================================================
// LOAD PARTICIPANTS FROM COUNTRY FOLDER
// ============================================================
$countryFolder = __DIR__ . '/../../src/Core/Config/Countries/' . $countryName;
$participantsYamlPath = $countryFolder . '/participants.yaml';

function parseParticipantsYaml($path) {
    $participants = [];
    if (!file_exists($path)) return $participants;
    
    $content = file_get_contents($path);
    $lines = explode("\n", $content);
    $current = null;
    $inParticipants = false;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^participants:$/', $line)) {
            $inParticipants = true;
            continue;
        }
        
        if ($inParticipants && preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $current = $matches[1];
            $participants[$current] = [
                'code' => $current,
                'name' => $current,
                'asset_types' => [],
                'delivery_modes' => ['CASHOUT', 'DEPOSIT'],
                'type' => 'BANK'
            ];
            continue;
        }
        
        if ($current && preg_match('/^    name: (.+)$/', $line, $matches)) {
            $participants[$current]['name'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    type: (.+)$/', $line, $matches)) {
            $participants[$current]['type'] = trim($matches[1], '"\'');
            continue;
        }
        
        if ($current && preg_match('/^    asset_types:$/', $line)) {
            $participants[$current]['asset_types'] = [];
            continue;
        }
        
        if ($current && isset($participants[$current]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$current]['asset_types'][] = trim($matches[1]);
            continue;
        }
        
        if ($current && preg_match('/^    delivery_modes:$/', $line)) {
            $participants[$current]['delivery_modes'] = [];
            continue;
        }
        
        if ($current && isset($participants[$current]['delivery_modes']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$current]['delivery_modes'][] = trim($matches[1]);
        }
    }
    
    // If no asset_types defined, set default based on institution type
    foreach ($participants as $code => &$p) {
        if (empty($p['asset_types'])) {
            if ($code === 'ZURUBANK') {
                $p['asset_types'] = ['VOUCHER', 'ACCOUNT'];
            } elseif ($code === 'VOUCHMORPH') {
                $p['asset_types'] = ['VOUCHER', 'ACCOUNT'];
            } elseif ($code === 'SACCUSSALIS') {
                $p['asset_types'] = ['ACCOUNT'];
            } elseif ($code === 'CAZACOM') {
                $p['asset_types'] = ['MNO-WALLET'];
            } else {
                $p['asset_types'] = ['ACCOUNT'];
            }
        }
    }
    
    return $participants;
}

$participants = parseParticipantsYaml($participantsYamlPath);

// ============================================================
// LOAD ASSET TYPES FROM GLOBAL assets.yaml
// ============================================================
AssetTypeRegistry::initialize();
$allAssetTypes = AssetTypeRegistry::all();

// Build asset fields mapping for JavaScript
$assetFieldsMap = [];
$assetUIMap = [];
$assetRulesMap = [];
foreach ($allAssetTypes as $code => $config) {
    $assetFieldsMap[$code] = $config['fields'] ?? [];
    $assetUIMap[$code] = $config['ui'] ?? [];
    $assetRulesMap[$code] = [
        'hold_required' => $config['hold_required'] ?? false,
        'hold_expiry_seconds' => $config['hold_expiry_seconds'] ?? 300,
        'reversal_window_seconds' => $config['reversal_window_seconds'] ?? 86400,
        'partial_debit_allowed' => $config['partial_debit_allowed'] ?? false,
        'delivery_modes' => $config['delivery_modes'] ?? ['deposit', 'cashout']
    ];
}

// ============================================================
// GET SELECTED SWAP DETAILS
// ============================================================
$swapId = $_GET['id'] ?? null;
$selectedSwap = null;
$fullResponse = null;
$feeCalcDetails = null;
$generatedCodes = null;
$signatureChain = null;
$deliveryDetails = null;
$requestPayload = null;

if ($swapId) {
    try {
        $stmt = $swapDB->prepare("
            SELECT sl.*, 
                   sl.request_payload, 
                   sl.response_payload,
                   sl.fee_calculation_details,
                   sl.generated_codes,
                   sl.signature_chain,
                   sl.delivery_details
            FROM swap_ledgers sl
            WHERE (sl.swap_reference = ? OR sl.reference = ?) AND sl.user_id = ?
        ");
        $stmt->execute([$swapId, $swapId, $userId]);
        $selectedSwap = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$selectedSwap) {
            $stmt = $swapDB->prepare("
                SELECT sl.*, 
                       sl.request_payload, 
                       sl.response_payload,
                       sl.fee_calculation_details,
                       sl.generated_codes,
                       sl.signature_chain,
                       sl.delivery_details
                FROM swap_ledgers sl
                WHERE sl.swap_reference = ? OR sl.reference = ?
            ");
            $stmt->execute([$swapId, $swapId]);
            $selectedSwap = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($selectedSwap) {
            $fullResponse = json_decode($selectedSwap['response_payload'] ?? '{}', true);
            $feeCalcDetails = json_decode($selectedSwap['fee_calculation_details'] ?? '{}', true);
            $generatedCodes = json_decode($selectedSwap['generated_codes'] ?? '{}', true);
            $signatureChain = json_decode($selectedSwap['signature_chain'] ?? '{}', true);
            $deliveryDetails = json_decode($selectedSwap['delivery_details'] ?? '{}', true);
            $requestPayload = json_decode($selectedSwap['request_payload'] ?? '{}', true);
        }
    } catch (Exception $e) {
        error_log("Error fetching swap details: " . $e->getMessage());
    }
}

// Get recent swaps
$recentSwaps = [];
try {
    $stmt = $swapDB->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, 
               status, created_at, fee_amount, swap_type
        FROM swap_ledgers 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching recent swaps: " . $e->getMessage());
}

$apiUrl = '/api/v1/swap/execute.php';
$previewUrl = '/api/v1/swap/preview.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$denominationsList = implode(', ', $atmDenominations);

// Generate participant options for JavaScript
$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT']
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | Swap | <?= htmlspecialchars($countryName) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .logo h1 { font-size: 24px; background: linear-gradient(135deg, #fff, #00f0ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .logo p { font-size: 12px; color: #a0a0b0; margin-top: 4px; }
        .country-badge { 
            padding: 4px 12px; 
            background: rgba(0,240,255,0.1); 
            border: 1px solid #00f0ff; 
            border-radius: 20px;
            font-size: 12px;
        }
        .user-info { text-align: right; }
        .user-phone { color: #00f0ff; font-weight: bold; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        .card h4 { margin: 16px 0 12px 0; font-size: 14px; color: #00f0ff; }
        
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #a0a0b0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        select, input {
            width: 100%;
            padding: 12px;
            background: #1a1f3a;
            border: 1px solid #2a2f4a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }
        select:focus, input:focus { outline: none; border-color: #00f0ff; }
        select option { background: #1a1f3a; }
        
        .dynamic-fields {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid #2a2f4a;
            display: none;
        }
        .dynamic-fields.active { display: block; }
        
        .corridor-warning {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 16px;
            display: none;
        }
        .corridor-warning.show { display: block; }
        
        .denomination-info {
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00f0ff;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            margin-bottom: 16px;
            display: none;
        }
        .denomination-info.show { display: block; }
        
        .quick-amounts {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .quick-amount {
            padding: 5px 12px;
            background: #1a1f3a;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #2a2f4a;
        }
        .quick-amount:hover { background: #00f0ff; color: #0a0e27; }
        
        .info-note {
            background: rgba(0, 240, 255, 0.05);
            border-left: 3px solid #00f0ff;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            color: #a0a0b0;
            margin: 12px 0;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            color: #0a0e27;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
        }
        button:hover { transform: translateY(-1px); filter: brightness(1.05); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        
        .btn-secondary {
            background: #1a1f3a;
            color: #fff;
            border: 1px solid #2a2f4a;
            width: auto;
            padding: 8px 16px;
            margin-top: 0;
        }
        .btn-secondary:hover { background: #2a2f4a; transform: none; filter: none; }
        
        .btn-danger {
            background: #ff6b6b;
            color: #fff;
            width: auto;
            padding: 4px 12px;
            margin-top: 0;
            font-size: 12px;
        }
        .btn-danger:hover { background: #ff4444; transform: none; filter: none; }
        
        .btn-success {
            background: #4caf50;
            color: #fff;
            width: auto;
            padding: 8px 16px;
            margin-top: 0;
        }
        .btn-success:hover { background: #388e3c; transform: none; filter: none; }
        
        .summary {
            background: rgba(0, 240, 255, 0.05);
            padding: 14px;
            border-radius: 8px;
            margin: 16px 0;
            font-size: 13px;
            border: 1px solid rgba(0, 240, 255, 0.2);
        }
        
        .result {
            padding: 16px;
            border-radius: 8px;
            margin-top: 16px;
            display: none;
        }
        .result.success { background: rgba(76, 175, 80, 0.2); border: 1px solid #4caf50; display: block; color: #4caf50; }
        .result.error { background: rgba(244, 67, 54, 0.2); border: 1px solid #f44336; display: block; color: #f44336; }
        .result.loading { background: rgba(255, 193, 7, 0.2); border: 1px solid #ffc107; display: block; color: #ffc107; }
        
        .swap-item {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #2a2f4a;
            font-size: 12px;
            flex-wrap: wrap;
            gap: 8px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .swap-item:hover { background: #1a1f3a; }
        .swap-status.completed { color: #4caf50; }
        .swap-status.failed { color: #f44336; }
        .swap-status.pending { color: #ffc107; }
        
        .fee-display { color: #ffc107; font-size: 10px; margin-top: 4px; }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #00f0ff;
            text-decoration: none;
        }
        
        .code-block {
            background: #0a0e27;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-break: break-all;
            border: 1px solid #2a2f4a;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .fee-breakdown {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
            padding: 12px;
            border-radius: 6px;
            margin: 10px 0;
        }
        
        .delivery-info {
            background: rgba(76, 175, 80, 0.1);
            border-left: 3px solid #4caf50;
            padding: 12px;
            border-radius: 6px;
            margin: 10px 0;
        }
        
        .generated-code {
            background: linear-gradient(135deg, #1a1f3a, #0a0e27);
            border: 2px solid #00f0ff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin: 20px 0;
        }
        .generated-code .code {
            font-size: 32px;
            font-family: monospace;
            letter-spacing: 4px;
            color: #00f0ff;
            font-weight: bold;
        }
        
        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid #2a2f4a;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 10px 20px;
            background: #1a1f3a;
            border: none;
            color: #fff;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
        }
        .tab.active {
            background: #00f0ff;
            color: #0a0e27;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .signature-chain {
            background: #0a0e27;
            padding: 12px;
            border-radius: 8px;
            margin: 8px 0;
            font-size: 11px;
        }
        
        .info-box {
            background: #1a1f3a;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #2a2f4a;
        }
        .info-label { font-size: 10px; text-transform: uppercase; color: #888; margin-bottom: 5px; }
        
        .three-columns {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }

        .modal {
            background: #12162e;
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid rgba(255,255,255,0.1);
            position: relative;
        }

        .modal h2 {
            font-size: 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .confirmation-details {
            background: #0a0e27;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .confirmation-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #1a1f3a;
        }

        .confirmation-row:last-child { border-bottom: none; }
        .confirmation-row .label { color: #888; font-size: 13px; }
        .confirmation-row .value { font-weight: bold; font-size: 14px; }
        .confirmation-row .value.positive { color: #4caf50; }
        .confirmation-row .value.negative { color: #ff6b6b; }
        .confirmation-row .value.highlight { color: #00f0ff; }

        .fee-breakdown-item {
            padding: 6px 0;
            font-size: 13px;
            color: #ccc;
            border-bottom: 1px solid #1a1f3a;
        }
        .fee-breakdown-item:last-child { border-bottom: none; }
        .fee-breakdown-item .fee-name { color: #888; }
        .fee-breakdown-item .fee-amount { float: right; font-weight: bold; }

        .total-row {
            font-size: 18px;
            padding-top: 12px;
            margin-top: 8px;
            border-top: 2px solid #00f0ff;
            color: #00f0ff;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .modal-actions button {
            flex: 1;
            padding: 14px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            font-size: 15px;
            border: none;
        }

        .btn-cancel {
            background: transparent;
            border: 1px solid #ff6b6b !important;
            color: #ff6b6b;
        }
        .btn-cancel:hover { background: rgba(255,107,107,0.1); }

        .btn-confirm {
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            border: none;
            color: #0a0e27;
        }
        .btn-confirm:hover { transform: translateY(-1px); filter: brightness(1.05); }
        .btn-confirm:disabled { opacity: 0.5; cursor: not-allowed; }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #00f0ff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .forex-info {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            margin: 8px 0;
        }

        .modal-error {
            color: #ff6b6b;
            font-size: 13px;
            display: none;
            margin-bottom: 12px;
            padding: 10px;
            background: rgba(255,107,107,0.1);
            border-radius: 6px;
        }

        /* ============================================================
           MULTI-SOURCE STYLES
           ============================================================ */
        .source-entry {
            background: #0a0e27;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 12px;
            border: 1px solid #2a2f4a;
            position: relative;
        }
        .source-entry .source-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .source-entry .source-header .source-number {
            color: #888;
            font-size: 12px;
        }
        .source-entry .source-fields {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        .source-entry .source-fields .form-group { margin-bottom: 0; }
        .source-entry .remove-source {
            background: #ff6b6b;
            color: #fff;
            border: none;
            padding: 2px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        .source-entry .remove-source:hover { background: #ff4444; }
        
        .multi-source-toggle {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-bottom: 16px;
            padding: 12px;
            background: #0a0e27;
            border-radius: 8px;
            border: 1px solid #2a2f4a;
        }
        .multi-source-toggle label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: #fff;
            font-size: 14px;
            margin: 0;
        }
        .multi-source-toggle input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #00f0ff;
            cursor: pointer;
        }
        .multi-source-toggle .source-count {
            color: #00f0ff;
            font-weight: bold;
            font-size: 14px;
        }
        
        .source-summary {
            background: rgba(0, 240, 255, 0.05);
            border-radius: 8px;
            padding: 12px;
            margin: 12px 0;
            border: 1px solid rgba(0, 240, 255, 0.2);
            font-size: 13px;
        }
        .source-summary .total-label { color: #888; }
        .source-summary .total-amount { color: #00f0ff; font-weight: bold; font-size: 16px; }
        .source-summary .source-list { color: #a0a0b0; font-size: 12px; margin-top: 4px; }
        
        .btn-add-source {
            background: transparent;
            border: 2px dashed #2a2f4a;
            color: #888;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            width: 100%;
            font-size: 14px;
            margin-top: 8px;
        }
        .btn-add-source:hover {
            border-color: #00f0ff;
            color: #00f0ff;
            background: rgba(0,240,255,0.05);
        }
        
        @media (max-width: 768px) {
            .two-columns, .three-columns { grid-template-columns: 1fr; gap: 16px; }
            .source-entry .source-fields { grid-template-columns: 1fr; }
            .modal { padding: 20px; }
            .modal-actions { flex-direction: column; }
            .multi-source-toggle { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">
            <h1>VouchMorph</h1>
            <p>Financial Institution Interoperability</p>
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <div class="country-badge"><?= htmlspecialchars($countryCode) ?></div>
            <div class="user-info">
                <div>👤 <span class="user-phone"><?= $loggedPhone ?></span></div>
                <div style="font-size: 10px; margin-top: 4px;"><a href="logout.php" style="color: #888;">Logout</a></div>
            </div>
        </div>
    </div>

    <?php if ($selectedSwap && $swapId): ?>
        <!-- DETAILED SWAP VIEW -->
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <div class="card">
            <h3>🔍 Swap Details: <?= htmlspecialchars($selectedSwap['swap_reference'] ?? $selectedSwap['reference'] ?? 'N/A') ?></h3>
            
            <?php if (!empty($generatedCodes) && (!empty($generatedCodes['atm_pin']) || !empty($generatedCodes['voucher_number']))): ?>
                <div class="generated-code">
                    <div style="font-size: 12px; margin-bottom: 10px;">🎫 Generated Codes</div>
                    <?php if (!empty($generatedCodes['atm_pin'])): ?>
                        <div class="code">🏧 <?= htmlspecialchars($generatedCodes['atm_pin']) ?></div>
                        <div style="font-size: 11px; margin-top: 8px;">ATM Cashout Code</div>
                    <?php endif; ?>
                    <?php if (!empty($generatedCodes['voucher_number'])): ?>
                        <div class="code" style="font-size: 20px;">🎟️ <?= htmlspecialchars($generatedCodes['voucher_number']) ?></div>
                        <div style="font-size: 11px; margin-top: 8px;">Voucher Number</div>
                    <?php endif; ?>
                    <?php if (!empty($generatedCodes['expires_at'])): ?>
                        <div style="font-size: 10px; margin-top: 8px;">Expires: <?= htmlspecialchars($generatedCodes['expires_at']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="three-columns">
                <div class="info-box">
                    <div class="info-label">Status</div>
                    <div><?= ucfirst($selectedSwap['status'] ?? 'Pending') ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Swap Type</div>
                    <div><?= htmlspecialchars($selectedSwap['swap_type'] ?? 'STANDARD') ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Created At</div>
                    <div><?= htmlspecialchars($selectedSwap['created_at'] ?? 'N/A') ?></div>
                </div>
            </div>
            
            <div class="two-columns">
                <div class="info-box">
                    <div class="info-label">Source</div>
                    <strong><?= htmlspecialchars($selectedSwap['from_institution'] ?? '?') ?></strong>
                </div>
                <div class="info-box">
                    <div class="info-label">Destination</div>
                    <strong><?= htmlspecialchars($selectedSwap['to_institution'] ?? '?') ?></strong>
                </div>
                <div class="info-box">
                    <div class="info-label">Amount</div>
                    <strong><?= number_format($selectedSwap['amount'] ?? 0, 2) ?> <?= $currencySymbol ?></strong>
                </div>
                <div class="info-box">
                    <div class="info-label">Fee</div>
                    <strong><?= number_format($selectedSwap['fee_amount'] ?? 0, 2) ?> <?= $currencySymbol ?></strong>
                </div>
                <div class="info-box">
                    <div class="info-label">Net Amount</div>
                    <strong><?= number_format(($selectedSwap['amount'] ?? 0) - ($selectedSwap['fee_amount'] ?? 0), 2) ?> <?= $currencySymbol ?></strong>
                </div>
                <div class="info-box">
                    <div class="info-label">Asset Type</div>
                    <strong><?= htmlspecialchars($selectedSwap['asset_type'] ?? 'ACCOUNT') ?></strong>
                </div>
            </div>
            
            <?php if (!empty($deliveryDetails)): ?>
                <h4>📦 Delivery Details</h4>
                <div class="delivery-info">
                    <strong>Method:</strong> <?= htmlspecialchars($deliveryDetails['delivery_method'] ?? 'N/A') ?><br>
                    <strong>Delivered Amount:</strong> <?= number_format($deliveryDetails['amount_delivered'] ?? 0, 2) ?> <?= $currencySymbol ?><br>
                    <?php if (!empty($deliveryDetails['remainder_at_source'])): ?>
                        <strong>Remainder at Source:</strong> <?= number_format($deliveryDetails['remainder_at_source'], 2) ?> <?= $currencySymbol ?><br>
                    <?php endif; ?>
                    <?php if (!empty($deliveryDetails['message'])): ?>
                        <strong>Message:</strong> <?= htmlspecialchars($deliveryDetails['message']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($deliveryDetails['note_breakdown'])): ?>
                        <div class="note-breakdown">
                            <strong>🏧 Note Breakdown:</strong><br>
                            <?php foreach ($deliveryDetails['note_breakdown'] as $note => $count): ?>
                                <?= $count ?> x <?= $note ?> <?= $currencySymbol ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($feeCalcDetails)): ?>
                <h4>💰 Fee Calculation</h4>
                <div class="fee-breakdown">
                    <strong>Fee Type:</strong> <?= htmlspecialchars($feeCalcDetails['fee_type'] ?? 'N/A') ?><br>
                    <strong>Original Amount:</strong> <?= number_format($feeCalcDetails['original_amount'] ?? 0, 2) ?> <?= $currencySymbol ?><br>
                    <strong>Total Fee:</strong> <?= number_format($feeCalcDetails['total_fee'] ?? 0, 2) ?> <?= $currencySymbol ?><br>
                    <strong>Net Amount:</strong> <?= number_format($feeCalcDetails['net_amount'] ?? 0, 2) ?> <?= $currencySymbol ?><br>
                    <?php if (!empty($feeCalcDetails['breakdown'])): ?>
                        <div style="margin-top: 10px;"><strong>Breakdown:</strong></div>
                        <?php foreach ($feeCalcDetails['breakdown'] as $feeName => $feeValue): ?>
                            <?php if (is_numeric($feeValue)): ?>
                                <div style="margin-left: 15px;">• <?= ucfirst(str_replace('_', ' ', $feeName)) ?>: <?= number_format($feeValue, 2) ?> <?= $currencySymbol ?></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($feeCalcDetails['revenue_split'])): ?>
                        <div style="margin-top: 10px;"><strong>Revenue Split:</strong></div>
                        <?php foreach ($feeCalcDetails['revenue_split'] as $party => $split): ?>
                            <div style="margin-left: 15px;">• <?= htmlspecialchars($party) ?>: <?= number_format($split, 2) ?> <?= $currencySymbol ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <h4>📄 Request & Response Data</h4>
            <div class="tabs">
                <button class="tab active" onclick="showTab('request')">📨 Request Payload</button>
                <button class="tab" onclick="showTab('response')">📬 Response Payload</button>
                <?php if (!empty($signatureChain)): ?>
                    <button class="tab" onclick="showTab('signatures')">🔐 Signature Chain</button>
                <?php endif; ?>
            </div>
            
            <div id="tab-request" class="tab-content active">
                <div class="code-block">
                    <?php 
                    $displayPayload = $requestPayload ?? json_decode($selectedSwap['request_payload'] ?? '{}', true);
                    echo htmlspecialchars(json_encode($displayPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    ?>
                </div>
            </div>
            
            <div id="tab-response" class="tab-content">
                <div class="code-block">
                    <?php 
                    $displayResponse = $fullResponse ?? json_decode($selectedSwap['response_payload'] ?? '{}', true);
                    echo htmlspecialchars(json_encode($displayResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    ?>
                </div>
            </div>
            
            <?php if (!empty($signatureChain)): ?>
                <div id="tab-signatures" class="tab-content">
                    <?php foreach ($signatureChain as $type => $sig): ?>
                        <div class="signature-chain">
                            <strong><?= ucfirst($type) ?>:</strong><br>
                            <div style="font-size: 10px; word-break: break-all;">
                                Signature: <?= htmlspecialchars(substr($sig['signature'] ?? '', 0, 100)) ?>...<br>
                                Timestamp: <?= htmlspecialchars($sig['timestamp'] ?? 'N/A') ?><br>
                                Source: <?= htmlspecialchars($sig['source'] ?? 'N/A') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
        <!-- NEW SWAP FORM -->
        <div class="card">
            <h3>🔄 New Swap</h3>
            
            <div id="corridorWarning" class="corridor-warning">
                ⚠️ Source and destination institutions must be different.
            </div>
            
            <div id="denominationInfo" class="denomination-info">
                💡 Cashout amounts are dispensed using available notes: <strong><?= $denominationsList ?> <?= $currencySymbol ?></strong>
            </div>
            
            <!-- DESTINATION SECTION (Always visible) -->
            <div class="two-columns">
                <div>
                    <div class="form-group">
                        <label>📥 DESTINATION INSTITUTION</label>
                        <select id="toInstitution">
                            <option value="">-- Select --</option>
                            <?php foreach ($participants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>">
                                    <?= htmlspecialchars($p['name'] ?? $code) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>📦 SWAP TYPE</label>
                        <select id="swapType">
                            <option value="CASHOUT">🏧 Cashout (ATM Code)</option>
                            <option value="DEPOSIT">💳 Deposit (Wallet/Bank)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>🔑 IDENTIFIER TYPE</label>
                        <select id="identifierType">
                            <option value="auto" selected>🔍 Auto-detect</option>
                            <option value="phone">📱 Phone Number</option>
                            <option value="national_id">🆔 National ID</option>
                            <option value="email">📧 Email</option>
                        </select>
                    </div>
                </div>
                
                <div>
                    <div class="form-group">
                        <label>🏷️ ASSET TYPE</label>
                        <select id="assetType">
                            <option value="">-- Select Asset Type --</option>
                        </select>
                        <div id="assetTypeHint" style="font-size: 10px; color: #888; margin-top: 5px;"></div>
                    </div>
                    
                    <!-- DYNAMIC FIELDS - RENDERED FROM assets.yaml -->
                    <div id="assetFieldsContainer" class="dynamic-fields"></div>
                    
                    <!-- DESTINATION FIELDS (CASHOUT/DEPOSIT specific) -->
                    <div id="destFieldsContainer" class="dynamic-fields"></div>
                </div>
            </div>

            <!-- ============================================================
                 MULTI-SOURCE TOGGLE AND SOURCES
                 ============================================================ -->
            <div class="multi-source-toggle">
                <label>
                    <input type="checkbox" id="multiSourceToggle" onchange="toggleMultiSource()">
                    🔗 Multi-Source Swap
                </label>
                <span style="color:#888; font-size:12px;">
                    Combine funds from multiple sources (different institutions or accounts)
                </span>
                <span class="source-count" id="sourceCount">0 sources</span>
            </div>
            
            <!-- SOURCES CONTAINER -->
            <div id="sourcesContainer" style="display:none;">
                <div id="sourceEntries"></div>
                <button class="btn-add-source" onclick="addSource()">➕ Add Source</button>
                
                <!-- Source Summary -->
                <div id="sourceSummary" class="source-summary" style="display:none;">
                    <div>
                        <span class="total-label">💰 Total from all sources:</span>
                        <span class="total-amount" id="totalSourceAmount"><?= $currencySymbol ?> 0.00</span>
                    </div>
                    <div class="source-list" id="sourceList"></div>
                </div>
            </div>

            <!-- SINGLE SOURCE AMOUNT (hidden when multi-source is enabled) -->
            <div id="singleAmountContainer" class="form-group">
                <label>💰 AMOUNT (<?= $currencySymbol ?>)</label>
                <input type="number" id="amount" step="0.01" placeholder="0.00">
                <div class="quick-amounts">
                    <?php foreach ($atmDenominations as $denom): ?>
                        <span class="quick-amount" data-amount="<?= $denom ?>"><?= $denom ?></span>
                    <?php endforeach; ?>
                    <span class="quick-amount" data-amount="500">500</span>
                    <span class="quick-amount" data-amount="1000">1000</span>
                </div>
            </div>

            <div class="summary" id="summary">📋 Fill in the fields above</div>

            <button id="executeBtn">🚀 Execute Swap</button>
        </div>

        <div id="result" class="result"></div>
    <?php endif; ?>

    <!-- CONFIRMATION MODAL -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <h2>🔄 Confirm Swap</h2>
            
            <div id="confirmationDetails" class="confirmation-details">
                <div style="text-align:center; padding:20px;">
                    <div class="loading-spinner"></div>
                    <br>Calculating fees...
                </div>
            </div>
            
            <div id="modalError" class="modal-error"></div>
            
            <div class="modal-actions">
                <button class="btn-cancel" onclick="closeConfirmation()">Cancel</button>
                <button id="confirmBtn" class="btn-confirm">✅ Confirm & Execute</button>
            </div>
        </div>
    </div>

    <!-- RECENT SWAPS -->
    <div class="card">
        <h3>📋 Recent Swaps</h3>
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center; padding:20px; color:#a0a0b0;">No swaps yet</div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <?php 
                $ref = $swap['swap_reference'] ?? null;
                if (!$ref) continue;
                ?>
                <div class="swap-item" onclick="window.location.href='?id=<?= urlencode($ref) ?>'">
                    <div>
                        <strong><?= htmlspecialchars($swap['from_institution'] ?? '?') ?></strong> → <strong><?= htmlspecialchars($swap['to_institution'] ?? '?') ?></strong>
                        <?php if (!empty($swap['swap_type'])): ?>
                            <div style="font-size: 10px; color: #888;"><?= htmlspecialchars($swap['swap_type']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($swap['fee_amount']) && $swap['fee_amount'] > 0): ?>
                            <div class="fee-display">Fee: <?= number_format($swap['fee_amount'], 2) ?> <?= $currencySymbol ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align: right;">
                        <div><?= number_format($swap['amount'] ?? 0, 2) ?> <?= $currencySymbol ?></div>
                        <div class="swap-status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></div>
                        <div style="font-size: 10px; color: #888;"><?= date('Y-m-d H:i', strtotime($swap['created_at'] ?? 'now')) ?></div>
                        <div style="font-size: 10px; color: #00f0ff; margin-top: 4px;">Click to view details →</div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// ============================================================
// CONFIGURATION
// ============================================================
const assetFields = <?= json_encode($assetFieldsMap) ?>;
const assetUI = <?= json_encode($assetUIMap) ?>;
const assetRules = <?= json_encode($assetRulesMap) ?>;
const participants = <?= json_encode($participantOptions) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const loggedPhone = '<?= $loggedPhone ?>';

// Build institution asset mapping
const institutionAssets = {};
for (const [code, p] of Object.entries(participants)) {
    institutionAssets[code] = p.asset_types || ['ACCOUNT'];
}

// DOM Elements
const fromInstSelect = document.getElementById('fromInstitution');
const toInstSelect = document.getElementById('toInstitution');
const assetTypeSelect = document.getElementById('assetType');
const assetTypeHint = document.getElementById('assetTypeHint');
const swapTypeSelect = document.getElementById('swapType');
const assetFieldsContainer = document.getElementById('assetFieldsContainer');
const destFieldsContainer = document.getElementById('destFieldsContainer');
const corridorWarning = document.getElementById('corridorWarning');
const denominationInfo = document.getElementById('denominationInfo');
const amountInput = document.getElementById('amount');
const summaryDiv = document.getElementById('summary');
const sourceIdentifierInput = document.getElementById('sourceIdentifier');
const identifierTypeSelect = document.getElementById('identifierType');

// Multi-source elements
const multiSourceToggle = document.getElementById('multiSourceToggle');
const sourcesContainer = document.getElementById('sourcesContainer');
const sourceEntries = document.getElementById('sourceEntries');
const sourceSummary = document.getElementById('sourceSummary');
const totalSourceAmount = document.getElementById('totalSourceAmount');
const sourceList = document.getElementById('sourceList');
const sourceCount = document.getElementById('sourceCount');
const singleAmountContainer = document.getElementById('singleAmountContainer');

// Modal elements
const confirmModal = document.getElementById('confirmModal');
const confirmationDetails = document.getElementById('confirmationDetails');
const modalError = document.getElementById('modalError');
const confirmBtn = document.getElementById('confirmBtn');

let pendingPayload = null;
let previewData = null;
let sourceCounter = 0;
let sources = [];

// ============================================================
// MULTI-SOURCE FUNCTIONS
// ============================================================

function toggleMultiSource() {
    const enabled = multiSourceToggle.checked;
    sourcesContainer.style.display = enabled ? 'block' : 'none';
    singleAmountContainer.style.display = enabled ? 'none' : 'block';
    
    if (enabled && sources.length === 0) {
        addSource();
    }
    updateSummary();
}

function addSource() {
    sourceCounter++;
    const sourceId = 'source_' + sourceCounter;
    
    const entry = document.createElement('div');
    entry.className = 'source-entry';
    entry.id = sourceId;
    entry.innerHTML = `
        <div class="source-header">
            <span class="source-number">📤 Source ${sourceCounter}</span>
            <button class="remove-source" onclick="removeSource('${sourceId}')">✕ Remove</button>
        </div>
        <div class="source-fields">
            <div class="form-group">
                <label>Institution</label>
                <select id="${sourceId}_institution" onchange="updateSourceAssetTypes('${sourceId}')">
                    <option value="">-- Select --</option>
                    ${Object.entries(participants).map(([code, p]) => 
                        `<option value="${code}">${p.name}</option>`
                    ).join('')}
                </select>
            </div>
            <div class="form-group">
                <label>Asset Type</label>
                <select id="${sourceId}_assetType" onchange="updateSourceFields('${sourceId}')">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div class="form-group">
                <label>Amount (${currencySymbol})</label>
                <input type="number" id="${sourceId}_amount" step="0.01" placeholder="0.00" oninput="updateSummary()">
            </div>
        </div>
        <div id="${sourceId}_fields" class="dynamic-fields" style="margin-top:12px; padding-top:12px; border-top:1px solid #2a2f4a;"></div>
        <div class="form-group" style="margin-top:8px;">
            <label>Identifier (Phone/National ID/Email)</label>
            <input type="text" id="${sourceId}_identifier" placeholder="Enter identifier" value="${loggedPhone}" oninput="updateSummary()">
        </div>
    `;
    
    sourceEntries.appendChild(entry);
    
    // Store source reference
    sources.push({
        id: sourceId,
        counter: sourceCounter
    });
    
    updateSourceCount();
    updateSummary();
}

function removeSource(sourceId) {
    const entry = document.getElementById(sourceId);
    if (entry) {
        entry.remove();
        sources = sources.filter(s => s.id !== sourceId);
        updateSourceCount();
        updateSummary();
    }
    
    if (sources.length === 0) {
        // Auto-add a source if none left
        addSource();
    }
}

function updateSourceCount() {
    sourceCount.textContent = sources.length + ' source' + (sources.length > 1 ? 's' : '');
}

function updateSourceAssetTypes(sourceId) {
    const instSelect = document.getElementById(sourceId + '_institution');
    const assetSelect = document.getElementById(sourceId + '_assetType');
    const inst = instSelect.value;
    
    assetSelect.innerHTML = '<option value="">-- Select --</option>';
    
    if (inst && institutionAssets[inst]) {
        const types = institutionAssets[inst];
        types.forEach(type => {
            const ui = assetUI[type] || {};
            const option = document.createElement('option');
            option.value = type;
            option.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
            assetSelect.appendChild(option);
        });
    }
    
    updateSourceFields(sourceId);
    updateSummary();
}

function updateSourceFields(sourceId) {
    const assetSelect = document.getElementById(sourceId + '_assetType');
    const fieldsContainer = document.getElementById(sourceId + '_fields');
    const assetType = assetSelect.value;
    
    fieldsContainer.innerHTML = '';
    fieldsContainer.classList.remove('active');
    
    if (!assetType) return;
    
    const fields = assetFields[assetType] || [];
    if (fields.length === 0) {
        fieldsContainer.innerHTML = `<div class="info-note">✅ No additional fields required</div>`;
        fieldsContainer.classList.add('active');
        return;
    }
    
    let html = '<div class="two-columns" style="margin-bottom:0;">';
    fields.forEach(field => {
        const fieldName = field.name;
        const label = field.label || fieldName.replace(/_/g, ' ').toUpperCase();
        const placeholder = field.placeholder || `Enter ${fieldName.replace(/_/g, ' ')}`;
        const isPin = fieldName.includes('pin') || field.type === 'password';
        const inputType = isPin ? 'password' : (field.type || 'text');
        const requiredAttr = field.required ? ' required' : '';
        
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input type="${inputType}" id="${sourceId}_${fieldName}" class="source-asset-field" 
                       placeholder="${placeholder}" autocomplete="${isPin ? 'new-password' : 'on'}"
                       ${requiredAttr}>
                ${isPin ? `<div style="font-size:10px; color:#b000ff; margin-top:4px;">🔑 Enter PIN</div>` : ''}
            </div>
        `;
    });
    html += '</div>';
    
    fieldsContainer.innerHTML = html;
    fieldsContainer.classList.add('active');
    updateSummary();
}

function updateSummary() {
    const isMulti = multiSourceToggle.checked;
    
    if (isMulti) {
        // Calculate total from all sources
        let total = 0;
        let sourceDetails = [];
        
        sources.forEach(s => {
            const amountEl = document.getElementById(s.id + '_amount');
            const instEl = document.getElementById(s.id + '_institution');
            const assetEl = document.getElementById(s.id + '_assetType');
            const identEl = document.getElementById(s.id + '_identifier');
            
            const amount = parseFloat(amountEl?.value) || 0;
            total += amount;
            
            const instName = instEl?.options[instEl.selectedIndex]?.text || '?';
            const assetName = assetEl?.options[assetEl.selectedIndex]?.text || '?';
            const ident = identEl?.value || '?';
            
            if (amount > 0) {
                sourceDetails.push(`${currencySymbol}${amount.toFixed(2)} from ${instName} (${assetName})`);
            }
        });
        
        totalSourceAmount.textContent = currencySymbol + ' ' + total.toFixed(2);
        sourceList.textContent = sourceDetails.length > 0 ? sourceDetails.join('; ') : 'No sources configured';
        sourceSummary.style.display = total > 0 ? 'block' : 'none';
        
        summaryDiv.innerHTML = `📋 Multi-Source Swap | Total: ${currencySymbol} ${total.toFixed(2)} | ${sources.length} source(s)`;
    } else {
        // Single source
        const amount = parseFloat(amountInput.value) || 0;
        const fromInst = fromInstSelect.options[fromInstSelect.selectedIndex]?.text || '?';
        const toInst = toInstSelect.options[toInstSelect.selectedIndex]?.text || '?';
        const asset = assetTypeSelect.options[assetTypeSelect.selectedIndex]?.text || '?';
        const swapType = swapTypeSelect.options[swapTypeSelect.selectedIndex]?.text || '?';
        
        summaryDiv.innerHTML = `📋 ${fromInst} (${asset}) → ${toInst} (${swapType}) | Amount: ${currencySymbol} ${amount.toFixed(2)}`;
    }
}

// ============================================================
// BUILD PAYLOAD (with Multi-Source support)
// ============================================================

function buildPayload() {
    const fromInst = fromInstSelect.value;
    const toInst = toInstSelect.value;
    const assetType = assetTypeSelect.value;
    const swapType = swapTypeSelect.value;
    const sourceIdentifier = sourceIdentifierInput?.value.trim() || '';
    
    const isMulti = multiSourceToggle.checked;
    const reference = 'SWAP_' + Date.now();
    const idempotencyKey = 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
    
    // Identifier type
    let identifierType = identifierTypeSelect?.value || 'auto';
    if (identifierType === 'auto' && sourceIdentifier) {
        if (sourceIdentifier.match(/^[\+]?[0-9]{10,15}$/)) identifierType = 'phone';
        else if (sourceIdentifier.match(/^[A-Z0-9]{6,20}$/i)) identifierType = 'national_id';
        else if (sourceIdentifier.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) identifierType = 'email';
    }
    
    // Base payload
    const payload = {
        reference: reference,
        idempotency_key: idempotencyKey,
        swap_type: swapType,
        to_institution: toInst,
        asset_type: assetType,
        currency: '<?= $currency ?>',
        source_identifier: sourceIdentifier,
        source_identifier_type: identifierType,
        phone: sourceIdentifier,
        wallet_phone: sourceIdentifier
    };
    
    if (identifierType === 'phone') payload.source_phone = sourceIdentifier;
    else if (identifierType === 'national_id') payload.source_national_id = sourceIdentifier;
    else if (identifierType === 'email') payload.source_email = sourceIdentifier;
    
    if (isMulti) {
        // Multi-Source: Build sources array
        const sourcesList = [];
        let totalAmount = 0;
        
        sources.forEach(s => {
            const instEl = document.getElementById(s.id + '_institution');
            const assetEl = document.getElementById(s.id + '_assetType');
            const amountEl = document.getElementById(s.id + '_amount');
            const identEl = document.getElementById(s.id + '_identifier');
            
            const inst = instEl?.value || '';
            const asset = assetEl?.value || 'ACCOUNT';
            const amount = parseFloat(amountEl?.value) || 0;
            const ident = identEl?.value.trim() || '';
            
            if (inst && amount > 0) {
                // Collect asset fields for this source
                const fields = {};
                const assetFieldsList = assetFields[asset] || [];
                assetFieldsList.forEach(field => {
                    const fieldEl = document.getElementById(s.id + '_' + field.name);
                    if (fieldEl && fieldEl.value.trim()) {
                        fields[field.name] = fieldEl.value.trim();
                    }
                });
                
                sourcesList.push({
                    institution: inst,
                    asset_type: asset,
                    amount: amount,
                    identifier: ident,
                    identifier_type: 'auto',
                    asset_fields: fields
                });
                
                totalAmount += amount;
            }
        });
        
        payload.sources = sourcesList;
        payload.amount = totalAmount;
        payload.from_institution = sourcesList.length > 0 ? sourcesList[0].institution : '';
        
        // Also add as multi-source flag
        payload.swap_type = 'MULTI_SOURCE';
        
        console.log('[buildPayload] Multi-source payload with', sourcesList.length, 'sources:', sourcesList);
    } else {
        // Single Source
        const amount = parseFloat(amountInput.value) || 0;
        payload.from_institution = fromInst;
        payload.amount = amount;
        
        // Collect asset fields
        const assetFieldsData = {};
        document.querySelectorAll('.asset-field').forEach(field => {
            const value = field.value.trim();
            if (value) {
                assetFieldsData[field.id] = value;
                payload[field.id] = value;
            }
        });
        
        // Get PIN
        const pin = getPinFromForm();
        if (pin) {
            payload.wallet_pin = pin;
            payload.pin = pin;
            if (!payload.asset_fields) payload.asset_fields = {};
            payload.asset_fields.wallet_pin = pin;
            payload.asset_fields.pin = pin;
        }
        
        if (Object.keys(assetFieldsData).length > 0) {
            payload.asset_fields = { ...payload.asset_fields, ...assetFieldsData };
        }
    }
    
    // Destination fields
    if (swapType === 'CASHOUT') {
        const beneficiaryPhone = document.getElementById('beneficiaryPhone')?.value.trim();
        if (beneficiaryPhone) {
            payload.beneficiary_phone = beneficiaryPhone;
            payload.destination_identifier = beneficiaryPhone;
            payload.destination_identifier_type = 'phone';
        }
    } else if (swapType === 'DEPOSIT') {
        const destinationIdentifier = document.getElementById('destinationIdentifier')?.value.trim();
        if (destinationIdentifier) {
            payload.destination_identifier = destinationIdentifier;
            payload.destination_identifier_type = 'auto';
            if (destinationIdentifier.match(/^[\+]?[0-9]{10,15}$/)) {
                payload.destination_identifier_type = 'phone';
                payload.destination_phone = destinationIdentifier;
            } else if (destinationIdentifier.match(/^[A-Z0-9]{6,20}$/i)) {
                payload.destination_identifier_type = 'national_id';
                payload.destination_national_id = destinationIdentifier;
            }
            payload.destination_account = destinationIdentifier;
        }
    }
    
    // Add rules from assets.yaml
    const rules = assetRules[assetType] || {};
    if (rules.hold_required) {
        payload.hold_required = true;
        payload.hold_expiry_seconds = rules.hold_expiry_seconds || 300;
    }
    
    console.log('[buildPayload] Final payload:', payload);
    return payload;
}

// ============================================================
// ORIGINAL DASHBOARD FUNCTIONS (kept for compatibility)
// ============================================================

function updateAssetTypes() {
    const fromInst = fromInstSelect.value;
    assetTypeSelect.innerHTML = '<option value="">-- Select Asset Type --</option>';
    
    if (!fromInst) {
        assetTypeHint.innerHTML = '';
        return;
    }
    
    const assetsList = institutionAssets[fromInst] || ['ACCOUNT'];
    
    assetsList.forEach(assetCode => {
        const ui = assetUI[assetCode] || {};
        const displayName = ui.display_name || assetCode;
        const icon = ui.icon || '';
        const option = document.createElement('option');
        option.value = assetCode;
        option.textContent = icon ? `${icon} ${displayName}` : displayName;
        assetTypeSelect.appendChild(option);
    });
    
    const typeNames = assetsList.map(code => {
        const ui = assetUI[code] || {};
        return ui.display_name || code;
    });
    assetTypeHint.innerHTML = `Supported: ${typeNames.join(', ')}`;
    
    if (assetsList.length === 1) {
        assetTypeSelect.value = assetsList[0];
        updateAssetFields();
        updateSummary();
    }
}

function updateAssetFields() {
    const assetType = assetTypeSelect.value;
    let fields = assetFields[assetType] || [];
    const ui = assetUI[assetType] || {};
    
    assetFieldsContainer.innerHTML = '';
    assetFieldsContainer.classList.remove('active');
    
    if (!assetType) return;
    
    if (fields.length === 0) {
        assetFieldsContainer.innerHTML = `
            <div class="info-note">✅ No additional fields required for ${ui.display_name || assetType}</div>
        `;
        assetFieldsContainer.classList.add('active');
        return;
    }
    
    let html = '<div class="two-columns">';
    fields.forEach(field => {
        const fieldName = field.name;
        const label = field.label || fieldName.replace(/_/g, ' ').toUpperCase();
        const placeholder = field.placeholder || `Enter ${fieldName.replace(/_/g, ' ')}`;
        const isPin = fieldName.includes('pin') || field.type === 'password';
        const inputType = isPin ? 'password' : (field.type || 'text');
        const requiredAttr = field.required ? ' required' : '';
        const patternAttr = field.pattern ? ` pattern="${field.pattern}"` : '';
        const minAttr = field.min !== undefined ? ` min="${field.min}"` : '';
        const maxAttr = field.max !== undefined ? ` max="${field.max}"` : '';
        const readonlyAttr = field.readonly ? ' readonly' : '';
        const pinNote = isPin ? `<div style="font-size:10px; color:#b000ff; margin-top:4px;">🔑 Enter your PIN</div>` : '';
        
        html += `
            <div class="form-group">
                <label>${label}</label>
                <input type="${inputType}" id="${fieldName}" class="asset-field" 
                       placeholder="${placeholder}" autocomplete="${isPin ? 'new-password' : 'on'}"
                       ${requiredAttr}${patternAttr}${minAttr}${maxAttr}${readonlyAttr}>
                ${pinNote}
            </div>
        `;
    });
    html += '</div>';
    
    assetFieldsContainer.innerHTML = html;
    assetFieldsContainer.classList.add('active');
}

function updateDestinationFields() {
    const swapType = swapTypeSelect.value;
    destFieldsContainer.innerHTML = '';
    destFieldsContainer.classList.remove('active');
    
    if (swapType === 'CASHOUT') {
        denominationInfo.classList.add('show');
        destFieldsContainer.innerHTML = `
            <div class="form-group">
                <label>📱 BENEFICIARY PHONE</label>
                <input type="tel" id="beneficiaryPhone" placeholder="+267XXXXXXXX" value="<?= $loggedPhone ?>">
            </div>
            <div class="info-note">💡 ATM cashout code will be sent via SMS to this number.</div>
        `;
        destFieldsContainer.classList.add('active');
    } else {
        denominationInfo.classList.remove('show');
        destFieldsContainer.innerHTML = `
            <div class="form-group">
                <label>🏦 DESTINATION IDENTIFIER</label>
                <input type="text" id="destinationIdentifier" placeholder="Phone number or National ID or Account number">
            </div>
            <div class="info-note">💡 The person receiving the money.</div>
        `;
        destFieldsContainer.classList.add('active');
    }
}

function validateCorridor() {
    const fromInst = fromInstSelect.value;
    const toInst = toInstSelect.value;
    
    if (!fromInst || !toInst) {
        corridorWarning.classList.remove('show');
        return true;
    }
    
    if (fromInst === toInst) {
        corridorWarning.classList.add('show');
        return false;
    }
    
    corridorWarning.classList.remove('show');
    return true;
}

// ============================================================
// GET PIN FROM FORM
// ============================================================

function getPinFromForm() {
    let pin = null;
    
    // Check all password fields and fields with 'pin' in the name
    document.querySelectorAll('.asset-field').forEach(el => {
        const value = el.value.trim();
        if (value && (el.type === 'password' || el.id.includes('pin'))) {
            pin = value;
        }
    });
    
    // Also check source fields
    document.querySelectorAll('.source-asset-field').forEach(el => {
        const value = el.value.trim();
        if (value && (el.type === 'password' || el.id.includes('pin'))) {
            pin = value;
        }
    });
    
    return pin;
}

// ============================================================
// CONFIRMATION MODAL
// ============================================================

async function showConfirmation(payload) {
    modalError.style.display = 'none';
    confirmBtn.disabled = true;
    confirmBtn.textContent = '⏳ Loading...';
    confirmationDetails.innerHTML = `
        <div style="text-align:center; padding:20px;">
            <div class="loading-spinner"></div>
            <br>Calculating fees...
        </div>
    `;
    confirmModal.classList.add('show');
    
    try {
        const response = await fetch('<?= $previewUrl ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': '<?= $apiKey ?>'
            },
            body: JSON.stringify(payload)
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Failed to calculate fees');
        }
        
        previewData = result.preview;
        pendingPayload = payload;
        
        buildConfirmationUI(previewData);
        confirmBtn.disabled = false;
        confirmBtn.textContent = '✅ Confirm & Execute';
        
    } catch (error) {
        modalError.textContent = '❌ ' + error.message;
        modalError.style.display = 'block';
        confirmationDetails.innerHTML = `
            <div style="text-align:center; padding:20px; color:#ff6b6b;">
                ❌ Failed to calculate fees
            </div>
        `;
        confirmBtn.disabled = true;
        confirmBtn.textContent = '❌ Error';
    }
}

function buildConfirmationUI(preview) {
    const sourceCurrency = preview.source_currency || '<?= $currency ?>';
    const destCurrency = preview.destination_currency || sourceCurrency;
    const amount = preview.amount_requested || 0;
    const totalFee = preview.total_fee || 0;
    const netAmount = preview.net_amount_destination_currency || amount;
    const exchangeRate = preview.exchange_rate || 1.0;
    const forexApplied = preview.forex_applied || false;
    const breakdown = preview.fee_breakdown || [];
    
    let breakdownHTML = '';
    if (breakdown.length > 0) {
        breakdownHTML = '<div style="margin-top:12px; padding-top:12px; border-top:1px solid #1a1f3a;">';
        breakdownHTML += '<div style="font-size:12px; color:#888; margin-bottom:8px;">Fee Breakdown:</div>';
        breakdown.forEach(item => {
            const amountVal = item.amount || 0;
            const name = item.name || item.slot || 'Fee';
            const owner = item.owner || '';
            if (amountVal > 0) {
                breakdownHTML += `
                    <div class="fee-breakdown-item">
                        <span class="fee-name">${name} ${owner ? '(' + owner + ')' : ''}</span>
                        <span class="fee-amount">${amountVal.toFixed(2)} ${sourceCurrency}</span>
                    </div>
                `;
            }
        });
        breakdownHTML += '</div>';
    }
    
    let forexHTML = '';
    if (forexApplied) {
        forexHTML = `
            <div class="forex-info">
                🌍 Exchange Rate: 1 ${sourceCurrency} = ${exchangeRate.toFixed(6)} ${destCurrency}
                ${preview.forex_profit > 0 ? `<br>💹 FX Profit: ${preview.forex_profit.toFixed(2)} ${destCurrency}` : ''}
            </div>
        `;
    }
    
    confirmationDetails.innerHTML = `
        <div style="margin-bottom:12px;">
            <div style="font-size:12px; color:#888;">${preview.swap_type || 'Swap'}</div>
            <div style="font-size:14px; color:#00f0ff;">${preview.source_institution} → ${preview.destination_institution}</div>
        </div>
        
        <div class="confirmation-row">
            <span class="label">💰 Amount Requested</span>
            <span class="value">${amount.toFixed(2)} ${sourceCurrency}</span>
        </div>
        
        <div class="confirmation-row">
            <span class="label">📊 Total Fee</span>
            <span class="value negative">${totalFee.toFixed(2)} ${sourceCurrency}</span>
        </div>
        
        ${forexHTML}
        ${breakdownHTML}
        
        <div class="confirmation-row total-row">
            <span class="label">📥 You Will Receive</span>
            <span class="value highlight">${netAmount.toFixed(2)} ${destCurrency}</span>
        </div>
    `;
}

function closeConfirmation() {
    confirmModal.classList.remove('show');
    pendingPayload = null;
    previewData = null;
    confirmBtn.disabled = false;
    confirmBtn.textContent = '✅ Confirm & Execute';
    modalError.style.display = 'none';
}

function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(btn => btn.classList.remove('active'));
    document.getElementById(`tab-${tabName}`).classList.add('active');
    if (window.event && window.event.target) window.event.target.classList.add('active');
}

// ============================================================
// EXECUTE BUTTON
// ============================================================

const executeBtn = document.getElementById('executeBtn');
if (executeBtn) {
    executeBtn.addEventListener('click', async function(e) {
        const toInst = toInstSelect.value;
        const assetType = assetTypeSelect.value;
        const swapType = swapTypeSelect.value;
        const isMulti = multiSourceToggle.checked;
        
        if (!toInst) { alert('Select DESTINATION institution'); return; }
        if (!assetType) { alert('Select asset type'); return; }
        
        if (isMulti) {
            // Validate multi-source
            let hasError = false;
            let totalAmount = 0;
            
            sources.forEach(s => {
                const instEl = document.getElementById(s.id + '_institution');
                const amountEl = document.getElementById(s.id + '_amount');
                const identEl = document.getElementById(s.id + '_identifier');
                const assetEl = document.getElementById(s.id + '_assetType');
                
                if (!instEl?.value) { hasError = true; alert('Select institution for source ' + s.counter); return; }
                if (!assetEl?.value) { hasError = true; alert('Select asset type for source ' + s.counter); return; }
                if (!amountEl?.value || parseFloat(amountEl.value) <= 0) { 
                    hasError = true; 
                    alert('Enter valid amount for source ' + s.counter); 
                    return; 
                }
                if (!identEl?.value) { hasError = true; alert('Enter identifier for source ' + s.counter); return; }
                
                totalAmount += parseFloat(amountEl.value) || 0;
            });
            
            if (hasError) return;
            if (totalAmount <= 0) { alert('Total amount must be greater than 0'); return; }
            if (sources.length < 2) { alert('Add at least 2 sources for multi-source swap'); return; }
        } else {
            // Single source validation
            const fromInst = fromInstSelect.value;
            const amount = parseFloat(amountInput.value);
            const sourceIdentifier = sourceIdentifierInput?.value.trim();
            
            if (!fromInst) { alert('Select SOURCE institution'); return; }
            if (!sourceIdentifier) { alert('Enter source identifier'); return; }
            if (!amount || amount <= 0) { alert('Enter valid amount'); return; }
            if (fromInst === toInst) { alert('Source and destination must be different'); return; }
        }
        
        // Validate required fields from assets.yaml
        const fields = assetFields[assetType] || [];
        let missingFields = [];
        fields.forEach(field => {
            if (field.required) {
                const el = document.getElementById(field.name);
                if (!el || !el.value.trim()) {
                    missingFields.push(field.label || field.name);
                }
            }
        });
        
        if (missingFields.length > 0) {
            alert('Please fill in: ' + missingFields.join(', '));
            return;
        }
        
        // Check for PIN
        const pin = getPinFromForm();
        if (!pin && !isMulti) {
            alert('🔑 Please enter your PIN');
            return;
        }
        
        if (swapType === 'CASHOUT') {
            const beneficiaryPhone = document.getElementById('beneficiaryPhone')?.value.trim();
            if (!beneficiaryPhone) { alert('Enter beneficiary phone number'); return; }
        } else if (swapType === 'DEPOSIT') {
            const destinationIdentifier = document.getElementById('destinationIdentifier')?.value.trim();
            if (!destinationIdentifier) { alert('Enter destination identifier'); return; }
        }
        
        const payload = buildPayload();
        await showConfirmation(payload);
    });
}

// ============================================================
// CONFIRM BUTTON
// ============================================================

confirmBtn.addEventListener('click', async function() {
    if (!pendingPayload) return;
    
    const btn = this;
    const resultDiv = document.getElementById('result');
    
    btn.disabled = true;
    btn.innerHTML = '<div class="loading-spinner"></div> Executing...';
    modalError.style.display = 'none';
    
    try {
        const response = await fetch('<?= $apiUrl ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': '<?= $apiKey ?>'
            },
            body: JSON.stringify(pendingPayload)
        });
        
        const result = await response.json();
        
        const isSuccess = result.success === true || 
                          result.status === 'success' || 
                          result.status === 'pending_cashout' ||
                          result.atomic_commit?.status === 'committed';
        
        if (isSuccess) {
            closeConfirmation();
            
            resultDiv.className = 'result success';
            const ref = result.reference || result.swap_reference || result.data?.reference || 'N/A';
            
            let html = `<strong>✅ Swap Successful!</strong><br><br>
                Reference: ${ref}<br>
                Amount: <?= $currencySymbol ?> ${pendingPayload.amount.toFixed(2)}<br>`;
            
            const fee = result.fee || result.data?.fee || result.fee_amount || 0;
            if (parseFloat(fee) > 0) {
                html += `<strong>Fee: <?= $currencySymbol ?> ${parseFloat(fee).toFixed(2)}</strong><br>`;
            }
            
            const atmCode = result.atm_code || result.atm_pin || 
                           result.data?.atm_code || result.data?.atm_pin || 
                           result.data?.generated_codes?.atm_pin || 
                           result.data?.generated_codes?.atm_code || null;
            if (atmCode) {
                html += `<br><strong>🏧 ATM Code:</strong> <span style="font-size:24px; color:#00f0ff;">${atmCode}</span><br>`;
            }
            
            const voucher = result.voucher_number || result.data?.voucher_number || 
                           result.data?.generated_codes?.voucher_number || null;
            if (voucher) {
                html += `<br><strong>🎫 Voucher:</strong> ${voucher}<br>`;
            }
            
            // Show multi-source info if applicable
            if (pendingPayload.sources && pendingPayload.sources.length > 0) {
                html += `<br><strong>📤 Sources:</strong> ${pendingPayload.sources.length} source(s)`;
            }
            
            html += `<br><details><summary><strong>📋 Full Response</strong></summary><pre style="margin-top:8px; font-size:11px; overflow-x:auto;">${JSON.stringify(result, null, 2)}</pre></details>`;
            html += `<br><a href="?id=${ref}" style="color:#00f0ff;">View Full Details →</a>`;
            resultDiv.innerHTML = html;
            resultDiv.scrollIntoView({ behavior: 'smooth' });
            
            setTimeout(() => location.reload(), 3000);
        } else {
            let errorMsg = result.message || result.error || 'Unknown error';
            modalError.textContent = '❌ ' + errorMsg;
            modalError.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '🔄 Try Again';
        }
        
    } catch (error) {
        modalError.textContent = '❌ Network error: ' + error.message;
        modalError.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '🔄 Try Again';
    }
});

// ============================================================
// EVENT LISTENERS
// ============================================================

fromInstSelect.addEventListener('change', () => {
    updateAssetTypes();
    validateCorridor();
    updateSummary();
});

toInstSelect.addEventListener('change', () => {
    validateCorridor();
    updateSummary();
});

assetTypeSelect.addEventListener('change', () => {
    updateAssetFields();
    updateSummary();
});

swapTypeSelect.addEventListener('change', () => {
    updateDestinationFields();
    updateSummary();
});

amountInput.addEventListener('input', updateSummary);

document.querySelectorAll('.quick-amount').forEach(btn => {
    btn.addEventListener('click', () => {
        if (amountInput) amountInput.value = btn.dataset.amount;
        updateSummary();
    });
});

// ============================================================
// INITIALIZATION
// ============================================================

updateAssetTypes();
updateDestinationFields();

// Add initial source if multi-source is enabled by default
// (disabled by default)

console.log('[Dashboard] ✅ Initialized with Multi-Source support');
console.log('[Dashboard] Asset types:', Object.keys(assetFields));
console.log('[Dashboard] Participants:', Object.keys(participants));

// Update summary periodically
setInterval(updateSummary, 500);
</script>
</body>
</html>
