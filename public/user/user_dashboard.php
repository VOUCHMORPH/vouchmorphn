<?php
// public/user/dashboard.php - REDESIGNED SHARP DASHBOARD
// Clean, sharp, voucher-stub aesthetic with one action per screen

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
$userId = $user['user_id'] ?? null;

// Get ALL identifiers from session
$userIdentifiers = [];
$userIdentifiers['phone'] = $user['phone'] ?? null;
$userIdentifiers['phone2'] = $user['phone2'] ?? null;
$userIdentifiers['phone3'] = $user['phone3'] ?? null;
$userIdentifiers['email'] = $user['email'] ?? null;
$userIdentifiers['national_id'] = $user['national_id'] ?? null;
$userIdentifiers['drivers_license'] = $user['drivers_license'] ?? null;
$userIdentifiers['passport'] = $user['passport'] ?? null;

// Get primary identifier
$primaryIdentifier = '';
$displayIdentifier = '';
foreach (['phone', 'email', 'national_id', 'drivers_license', 'passport'] as $type) {
    if (!empty($user[$type])) {
        $primaryIdentifier = $user[$type];
        $displayIdentifier = $user[$type];
        break;
    }
}
if (empty($primaryIdentifier)) {
    foreach (['phone2', 'phone3'] as $type) {
        if (!empty($user[$type])) {
            $primaryIdentifier = $user[$type];
            $displayIdentifier = $user[$type];
            break;
        }
    }
}

// Build valid identifiers list
$validIdentifiers = [];
$typeIcons = [
    'phone' => '📱',
    'phone2' => '📱',
    'phone3' => '📱',
    'email' => '✉️',
    'national_id' => '🆔',
    'drivers_license' => '🚗',
    'passport' => '📖'
];

foreach ($userIdentifiers as $type => $value) {
    if (!empty($value)) {
        $validIdentifiers[] = [
            'type' => $type,
            'value' => $value,
            'display' => $type . ': ' . $value,
            'icon' => $typeIcons[$type] ?? '🔑'
        ];
    }
}

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

// Load participants
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
// LOAD ASSET TYPES FROM AssetTypeRegistry
// ============================================================
AssetTypeRegistry::initialize();
$allAssetTypes = AssetTypeRegistry::all();

$assetFieldsMap = [];
$assetUIMap = [];
$assetRulesMap = [];
$assetTypeNames = [];

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
    $assetTypeNames[] = $code;
}

// Get cloud balances
$cloudBalances = [];
$cloudTotal = 0;
try {
    $stmt = $swapDB->prepare("
        SELECT 
            identity_type,
            identity_value,
            SUM(amount) as total_amount,
            COUNT(*) as count,
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        FROM identity_swap_holds 
        WHERE user_id = ? 
        AND status = 'pending'
        GROUP BY identity_type, identity_value
        ORDER BY created_at DESC
    ");
    $stmt->execute([$userId]);
    $cloudBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cloudBalances as &$cb) {
        $cloudTotal += (float)$cb['total_amount'];
        $cb['expires_at'] = date('Y-m-d H:i:s', strtotime($cb['newest']) + 86400);
    }
} catch (Exception $e) {
    error_log("Error fetching cloud balances: " . $e->getMessage());
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
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching recent swaps: " . $e->getMessage());
}

$apiUrl = '/api/v1/swap/execute.php';
$previewUrl = '/api/v1/swap/preview.php';
$cloudBalanceUrl = '/api/v1/swap/cloud_balance.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';

$denominationsList = implode(', ', $atmDenominations);

// ============================================================
// Participant options with asset type details for JS
// ============================================================
$participantOptions = [];
foreach ($participants as $code => $p) {
    $participantOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT'],
        'type' => $p['type'] ?? 'BANK',
        'delivery_modes' => $p['delivery_modes'] ?? ['CASHOUT', 'DEPOSIT'],
    ];
}

// Build destination options with asset types
$destinationOptions = [];
foreach ($participants as $code => $p) {
    $destinationOptions[$code] = [
        'name' => $p['name'] ?? $code,
        'type' => $p['type'] ?? 'BANK',
        'asset_types' => $p['asset_types'] ?? ['ACCOUNT'],
        'delivery_modes' => $p['delivery_modes'] ?? ['CASHOUT', 'DEPOSIT'],
    ];
}

$identifiersJson = json_encode($validIdentifiers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | <?= htmlspecialchars($countryName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /* [All existing styles remain the same] */
        /* ============================================================
           TOKENS
           ink        #121212  primary text / borders / stamps
           paper      #F7F5F0  page background
           panel      #FFFFFF  card surface
           cobalt     #2440FF  primary action accent
           amber      #FFB400  pending / cloud-balance accent
           forest     #14804A  success accent
           ============================================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F7F5F0;
            color: #121212;
            min-height: 100vh;
        }
        .font-display { font-family: 'Space Grotesk', sans-serif; }
        
        /* ============================================================
           CLIPPED CORNER UTILITY
           ============================================================ */
        .clip-corner { clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%); }
        .clip-corner-sm { clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%); }
        .clip-corner-lg { clip-path: polygon(0 0, calc(100% - 18px) 0, 100% 18px, 100% 100%, 0 100%); }
        
        /* ============================================================
           LAYOUT
           ============================================================ */
        .container { max-width: 640px; margin: 0 auto; padding: 0; }
        
        /* ============================================================
           TOP BAR
           ============================================================ */
        .topbar {
            background: #121212;
            color: #F7F5F0;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .topbar .logo-mark {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #2440FF;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Space Grotesk', sans-serif;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
        }
        .topbar .logo-text {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 600;
            font-size: 15px;
            letter-spacing: -0.3px;
        }
        .topbar .user-area {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
        }
        .topbar .user-area .phone { opacity: 0.7; }
        .topbar .user-area .logout-btn {
            background: none;
            border: none;
            color: #F7F5F0;
            opacity: 0.5;
            cursor: pointer;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            transition: opacity 0.2s;
        }
        .topbar .user-area .logout-btn:hover { opacity: 1; }
        
        /* ============================================================
           HOME
           ============================================================ */
        .home { padding: 20px; }
        
        /* Cloud Strip */
        .cloud-strip {
            display: none;
            width: 100%;
            padding: 16px 20px;
            margin-bottom: 20px;
            background: #FFB400;
            border: 2px solid #121212;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
            cursor: pointer;
            transition: transform 0.15s;
            text-align: left;
        }
        .cloud-strip.visible { display: flex; align-items: center; justify-content: space-between; }
        .cloud-strip:hover { transform: translateY(-2px); }
        .cloud-strip .label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.6; }
        .cloud-strip .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 26px; }
        
        /* Product Grid */
        .product-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 24px;
        }
        .product-tile {
            background: #FFFFFF;
            border: 2px solid #121212;
            padding: 20px 18px;
            text-align: left;
            cursor: pointer;
            transition: transform 0.15s;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
        }
        .product-tile:hover { transform: translateY(-3px); }
        .product-tile .icon { margin-bottom: 10px; display: block; }
        .product-tile .label { font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: 16px; }
        .product-tile .desc { font-size: 12px; opacity: 0.5; margin-top: 4px; line-height: 1.4; }
        
        /* Activity Link */
        .activity-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-top: 2px solid #D8D4CB;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: opacity 0.2s;
        }
        .activity-link:hover { opacity: 0.6; }
        .activity-link .arrow { font-size: 18px; opacity: 0.4; }
        
        /* ============================================================
           PANELS - Full screen overlay
           ============================================================ */
        .panel-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #F7F5F0;
            z-index: 1000;
            overflow-y: auto;
            padding: 0;
        }
        .panel-overlay.active { display: block; }
        
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 2px solid #121212;
            background: #F7F5F0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .panel-header .back-btn {
            background: none;
            border: none;
            font-size: 22px;
            cursor: pointer;
            padding: 4px;
            color: #121212;
        }
        .panel-header .title {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 600;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .panel-header .close-btn {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            padding: 4px;
            color: #121212;
        }
        
        .panel-body { padding: 20px; }
        
        /* ============================================================
           FORM ELEMENTS
           ============================================================ */
        .field { margin-bottom: 18px; }
        .field-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.6;
            margin-bottom: 6px;
        }
        
        .pill-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            background: #FFFFFF;
            border: 2px solid #D8D4CB;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.15s;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
        }
        .pill:hover { border-color: #121212; }
        .pill.active {
            background: #2440FF;
            border-color: #2440FF;
            color: #FFFFFF;
        }
        .pill .badge {
            font-size: 11px;
            opacity: 0.6;
            margin-right: 6px;
        }
        .pill .asset-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-left: 6px;
        }
        
        .text-input {
            width: 100%;
            padding: 12px 14px;
            background: #FFFFFF;
            border: 2px solid #D8D4CB;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            outline: none;
            clip-path: polygon(0 0, calc(100% - 8px) 0, 100% 8px, 100% 100%, 0 100%);
            transition: border-color 0.2s;
        }
        .text-input:focus { border-color: #2440FF; }
        .text-input::placeholder { opacity: 0.4; }
        
        .quick-amounts {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }
        .quick-amount {
            padding: 4px 14px;
            background: #FFFFFF;
            border: 1px solid #D8D4CB;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.15s;
            clip-path: polygon(0 0, calc(100% - 6px) 0, 100% 6px, 100% 100%, 0 100%);
        }
        .quick-amount:hover { background: #121212; color: #FFFFFF; border-color: #121212; }
        
        .info-note {
            padding: 12px 16px;
            background: rgba(36, 64, 255, 0.06);
            border-left: 3px solid #2440FF;
            font-size: 13px;
            line-height: 1.5;
            margin: 8px 0 16px 0;
        }
        .info-note strong { color: #121212; }
        
        /* ============================================================
           CONFIRM STEP
           ============================================================ */
        .confirm-box {
            padding: 20px;
            background: #FFFFFF;
            border: 2px solid #121212;
            margin-bottom: 20px;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
        }
        .confirm-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        .confirm-row .label { opacity: 0.5; }
        .confirm-row .value { font-weight: 600; }
        .confirm-row .value.highlight { color: #2440FF; font-family: 'Space Grotesk', sans-serif; font-size: 18px; }
        .confirm-row .value.negative { color: #121212; opacity: 0.6; }
        .confirm-divider { border-top: 2px solid #D8D4CB; margin: 8px 0; }
        
        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn-primary {
            width: 100%;
            padding: 16px;
            background: #121212;
            color: #FFFFFF;
            border: none;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: opacity 0.2s;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary:hover { opacity: 0.8; }
        .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
        .btn-primary .arrow { font-size: 18px; }
        
        .btn-primary.cobalt {
            background: #2440FF;
        }
        
        /* ============================================================
           SUCCESS STATE
           ============================================================ */
        .success-box {
            text-align: center;
            padding: 40px 20px;
        }
        .success-box .check {
            width: 64px;
            height: 64px;
            background: #14804A;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #FFFFFF;
            margin: 0 auto 16px;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
        }
        .success-box .title {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 22px;
            margin-bottom: 6px;
        }
        .success-box .ref {
            font-size: 13px;
            opacity: 0.5;
            margin-bottom: 16px;
        }
        .success-box .code-box {
            padding: 16px 24px;
            background: #FFFFFF;
            border: 2px solid #121212;
            display: inline-block;
            margin: 12px auto;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .success-box .code-box .code-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.5;
        }
        .success-box .code-box .code {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 32px;
            letter-spacing: 4px;
        }
        
        /* ============================================================
           MULTI-SOURCE - FIXED with destination institution/asset
           ============================================================ */
        .source-entry {
            background: #FFFFFF;
            border: 2px solid #D8D4CB;
            padding: 16px;
            margin-bottom: 12px;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .source-entry .source-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .source-entry .source-header .num { font-size: 12px; font-weight: 600; opacity: 0.5; }
        .source-entry .source-header .remove-btn {
            background: none;
            border: none;
            font-size: 16px;
            cursor: pointer;
            opacity: 0.3;
            transition: opacity 0.2s;
        }
        .source-entry .source-header .remove-btn:hover { opacity: 1; }
        .source-entry .source-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .source-entry .source-fields .field { margin-bottom: 0; }
        .source-entry .source-fields select, .source-entry .source-fields input {
            width: 100%;
            padding: 8px 10px;
            background: #F7F5F0;
            border: 1px solid #D8D4CB;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            outline: none;
        }
        .source-entry .source-fields select:focus, .source-entry .source-fields input:focus { border-color: #2440FF; }
        .source-entry .asset-fields {
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .source-entry .asset-fields input {
            width: 100%;
            padding: 8px 10px;
            background: #F7F5F0;
            border: 1px solid #D8D4CB;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            outline: none;
        }
        .source-entry .asset-fields input:focus { border-color: #2440FF; }
        
        .add-source-btn {
            width: 100%;
            padding: 12px;
            background: transparent;
            border: 2px dashed #D8D4CB;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .add-source-btn:hover { border-color: #121212; background: rgba(18, 18, 18, 0.03); }
        
        .source-summary {
            padding: 12px 16px;
            background: rgba(36, 64, 255, 0.05);
            border: 1px solid rgba(36, 64, 255, 0.2);
            margin-top: 12px;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .source-summary .total {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 20px;
            color: #2440FF;
        }
        .source-summary .list { font-size: 12px; opacity: 0.6; margin-top: 4px; }
        
        /* ============================================================
           DESTINATION ASSET SELECTION - NEW
           ============================================================ */
        .dest-asset-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .dest-asset-card {
            background: #FFFFFF;
            border: 2px solid #D8D4CB;
            padding: 14px;
            text-align: center;
            cursor: pointer;
            transition: all 0.15s;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .dest-asset-card:hover { border-color: #121212; }
        .dest-asset-card.active {
            border-color: #2440FF;
            background: rgba(36, 64, 255, 0.05);
        }
        .dest-asset-card .icon { font-size: 24px; display: block; margin-bottom: 4px; }
        .dest-asset-card .name { font-weight: 600; font-size: 14px; }
        .dest-asset-card .desc { font-size: 11px; opacity: 0.4; margin-top: 2px; }
        
        /* ============================================================
           CLOUD PANEL
           ============================================================ */
        .cloud-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #D8D4CB;
            font-size: 14px;
        }
        .cloud-item:last-child { border-bottom: none; }
        .cloud-item .ident { font-weight: 500; }
        .cloud-item .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 600; color: #FFB400; }
        .cloud-item .expires { font-size: 12px; opacity: 0.4; }
        
        .cloud-total {
            padding: 16px 20px;
            background: #FFB400;
            border: 2px solid #121212;
            margin-bottom: 16px;
            clip-path: polygon(0 0, calc(100% - 14px) 0, 100% 14px, 100% 100%, 0 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cloud-total .label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; opacity: 0.6; }
        .cloud-total .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 28px; }
        
        /* ============================================================
           HISTORY
           ============================================================ */
        .history-item {
            display: flex;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid #D8D4CB;
            font-size: 14px;
        }
        .history-item:last-child { border-bottom: none; }
        .history-item .route { font-weight: 500; }
        .history-item .route .arrow { opacity: 0.3; margin: 0 6px; }
        .history-item .amount { font-family: 'Space Grotesk', sans-serif; font-weight: 600; }
        .history-item .status { font-size: 12px; font-weight: 500; }
        .history-item .status.completed { color: #14804A; }
        .history-item .status.pending { color: #FFB400; }
        .history-item .status.failed { color: #121212; opacity: 0.4; }
        .history-item .when { font-size: 12px; opacity: 0.4; margin-top: 2px; }
        
        /* ============================================================
           IDENTIFIERS
           ============================================================ */
        .identifier-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .identifier-card {
            background: #FFFFFF;
            border: 2px solid #D8D4CB;
            padding: 16px;
            text-align: center;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
        }
        .identifier-card .icon { font-size: 28px; display: block; margin-bottom: 6px; }
        .identifier-card .value { font-weight: 600; font-size: 14px; }
        .identifier-card .type { font-size: 11px; text-transform: uppercase; opacity: 0.4; margin-top: 4px; }
        
        /* ============================================================
           MODAL
           ============================================================ */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(18, 18, 18, 0.85);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.show { display: flex; }
        .modal-box {
            background: #F7F5F0;
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            clip-path: polygon(0 0, calc(100% - 18px) 0, 100% 18px, 100% 100%, 0 100%);
        }
        .modal-box h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px;
            margin-bottom: 16px;
        }
        .modal-error {
            display: none;
            padding: 12px 16px;
            background: rgba(18, 18, 18, 0.06);
            border-left: 3px solid #121212;
            margin-bottom: 12px;
            font-size: 14px;
            color: #121212;
        }
        .modal-error.show { display: block; }
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .modal-actions .btn-cancel {
            flex: 1;
            padding: 14px;
            background: transparent;
            border: 2px solid #121212;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
            transition: background 0.2s;
        }
        .modal-actions .btn-cancel:hover { background: rgba(18, 18, 18, 0.05); }
        .modal-actions .btn-confirm {
            flex: 2;
            padding: 14px;
            background: #121212;
            color: #FFFFFF;
            border: none;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 10px, 100% 100%, 0 100%);
            transition: opacity 0.2s;
        }
        .modal-actions .btn-confirm:hover { opacity: 0.8; }
        .modal-actions .btn-confirm:disabled { opacity: 0.4; cursor: not-allowed; }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 480px) {
            .product-grid { gap: 10px; }
            .product-tile { padding: 16px 14px; }
            .product-tile .label { font-size: 14px; }
            .source-entry .source-fields { grid-template-columns: 1fr; }
            .source-entry .asset-fields { grid-template-columns: 1fr; }
            .identifier-grid { grid-template-columns: 1fr; }
            .modal-actions { flex-direction: column; }
            .topbar .logo-text { font-size: 13px; }
            .topbar .user-area .phone { font-size: 12px; }
            .confirm-row { font-size: 13px; }
            .cloud-total .amount { font-size: 22px; }
            .dest-asset-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ============================================================
     TOP BAR
     ============================================================ -->
<div class="topbar">
    <div class="logo">
        <div class="logo-mark">V</div>
        <span class="logo-text">VOUCHMORPH</span>
    </div>
    <div class="user-area">
        <span class="phone"><?= htmlspecialchars($primaryIdentifier) ?></span>
        <a href="logout.php" class="logout-btn">Log out</a>
    </div>
</div>

<!-- ============================================================
     HOME
     ============================================================ -->
<div id="homeView" class="home">
    <!-- Cloud Balance Strip -->
    <div id="cloudStrip" class="cloud-strip <?= $cloudTotal > 0 ? 'visible' : '' ?>" onclick="openPanel('cloud')">
        <div>
            <div class="label">Waiting for you</div>
            <div class="amount"><?= $currencySymbol ?> <?= number_format($cloudTotal, 2) ?></div>
        </div>
        <span style="font-size:22px;">›</span>
    </div>

    <!-- Product Grid -->
    <div class="product-grid">
        <div class="product-tile" onclick="openFlow('send')">
            <span class="icon" style="font-size:28px;">↗</span>
            <div class="label">Send</div>
            <div class="desc">Account or wallet, direct</div>
        </div>
        <div class="product-tile" onclick="openFlow('cashout')">
            <span class="icon" style="font-size:28px;">💵</span>
            <div class="label">Cashout</div>
            <div class="desc">Get cash, no deposit needed</div>
        </div>
        <div class="product-tile" onclick="openFlow('identity')">
            <span class="icon" style="font-size:28px;">🔐</span>
            <div class="label">Send to identity</div>
            <div class="desc">Phone, ID or email — they choose</div>
        </div>
        <div class="product-tile" onclick="openFlow('pool')">
            <span class="icon" style="font-size:28px;">📦</span>
            <div class="label">Combine sources</div>
            <div class="desc">Use several accounts at once</div>
        </div>
    </div>

    <!-- Activity -->
    <div class="activity-link" onclick="openPanel('history')">
        <span>Recent activity</span>
        <span class="arrow">›</span>
    </div>
    <div class="activity-link" onclick="openPanel('identifiers')" style="border-top: none; padding-top: 8px;">
        <span>Your identifiers</span>
        <span class="arrow">›</span>
    </div>
</div>

<!-- ============================================================
     FLOW PANEL (Shared for send/cashout/identity/pool)
     ============================================================ -->
<div id="flowPanel" class="panel-overlay">
    <div class="panel-header">
        <button class="back-btn" onclick="closeFlow()">‹</button>
        <span class="title" id="flowTitle">Send</span>
        <button class="close-btn" onclick="closeFlow()">✕</button>
    </div>
    <div class="panel-body" id="flowBody">
        <!-- Dynamic content rendered by JS -->
    </div>
</div>

<!-- ============================================================
     CLOUD PANEL
     ============================================================ -->
<div id="cloudPanel" class="panel-overlay">
    <div class="panel-header">
        <button class="back-btn" onclick="closePanel('cloud')">‹</button>
        <span class="title">Waiting for you</span>
        <button class="close-btn" onclick="closePanel('cloud')">✕</button>
    </div>
    <div class="panel-body" id="cloudBody">
        <?php if (empty($cloudBalances)): ?>
            <div style="text-align:center;padding:60px 20px;">
                <div style="font-size:48px;margin-bottom:16px;">☁️</div>
                <div style="font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:18px;">Nothing waiting</div>
                <div style="opacity:0.4;font-size:14px;margin-top:8px;">When someone sends to your identity, it appears here.</div>
            </div>
        <?php else: ?>
            <div class="cloud-total">
                <span class="label">Total</span>
                <span class="amount"><?= $currencySymbol ?> <?= number_format($cloudTotal, 2) ?></span>
            </div>
            <?php foreach ($cloudBalances as $cb): ?>
                <div class="cloud-item">
                    <div>
                        <span class="ident"><?= htmlspecialchars($cb['identity_type']) ?>: <?= htmlspecialchars($cb['identity_value']) ?></span>
                        <div class="expires">Expires <?= date('M d, H:i', strtotime($cb['expires_at'])) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="amount"><?= $currencySymbol ?> <?= number_format($cb['total_amount'], 2) ?></div>
                        <div style="font-size:11px;opacity:0.4;"><?= $cb['count'] ?> item(s)</div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     HISTORY PANEL
     ============================================================ -->
<div id="historyPanel" class="panel-overlay">
    <div class="panel-header">
        <button class="back-btn" onclick="closePanel('history')">‹</button>
        <span class="title">Recent activity</span>
        <button class="close-btn" onclick="closePanel('history')">✕</button>
    </div>
    <div class="panel-body">
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center;padding:60px 20px;">
                <div style="font-size:48px;margin-bottom:16px;">📭</div>
                <div style="font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:18px;">No activity</div>
                <div style="opacity:0.4;font-size:14px;margin-top:8px;">Your swaps will appear here.</div>
            </div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <?php $ref = $swap['swap_reference'] ?? null; if (!$ref) continue; ?>
                <div class="history-item" onclick="window.location.href='history.php?id=<?= urlencode($ref) ?>'">
                    <div>
                        <div class="route">
                            <?= htmlspecialchars($swap['from_institution'] ?? '?') ?>
                            <span class="arrow">→</span>
                            <?= htmlspecialchars($swap['to_institution'] ?? '?') ?>
                        </div>
                        <div class="when"><?= date('M d, H:i', strtotime($swap['created_at'] ?? 'now')) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="amount"><?= $currencySymbol ?> <?= number_format($swap['amount'] ?? 0, 2) ?></div>
                        <div class="status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     IDENTIFIERS PANEL
     ============================================================ -->
<div id="identifiersPanel" class="panel-overlay">
    <div class="panel-header">
        <button class="back-btn" onclick="closePanel('identifiers')">‹</button>
        <span class="title">Your identifiers</span>
        <button class="close-btn" onclick="closePanel('identifiers')">✕</button>
    </div>
    <div class="panel-body">
        <?php if (empty($validIdentifiers)): ?>
            <div style="text-align:center;padding:60px 20px;">
                <div style="font-size:48px;margin-bottom:16px;">🔑</div>
                <div style="font-family:'Space Grotesk',sans-serif;font-weight:600;font-size:18px;">No identifiers</div>
                <div style="opacity:0.4;font-size:14px;margin-top:8px;">Add identifiers to receive money.</div>
            </div>
        <?php else: ?>
            <div class="identifier-grid">
                <?php foreach ($validIdentifiers as $id): ?>
                    <div class="identifier-card">
                        <span class="icon"><?= $id['icon'] ?></span>
                        <div class="value"><?= htmlspecialchars($id['value']) ?></div>
                        <div class="type"><?= htmlspecialchars($id['type']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ============================================================
     CONFIRMATION MODAL
     ============================================================ -->
<div id="confirmModal" class="modal-overlay">
    <div class="modal-box">
        <h2>Confirm swap</h2>
        <div id="modalDetails">
            <div style="text-align:center;padding:20px;">
                <div style="display:inline-block;width:24px;height:24px;border:3px solid #D8D4CB;border-top-color:#121212;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
                <br><br>Calculating fees...
            </div>
        </div>
        <div id="modalError" class="modal-error"></div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeConfirm()">Cancel</button>
            <button id="confirmBtn" class="btn-confirm">Confirm</button>
        </div>
    </div>
</div>

<style>
    @keyframes spin { to { transform: rotate(360deg); } }
</style>

<script>
// ============================================================
// CONFIG
// ============================================================
const participants = <?= json_encode($participantOptions) ?>;
const destinationOptions = <?= json_encode($destinationOptions) ?>;
const assetFields = <?= json_encode($assetFieldsMap) ?>;
const assetUI = <?= json_encode($assetUIMap) ?>;
const currencySymbol = '<?= $currencySymbol ?>';
const userIdentifiers = <?= $identifiersJson ?>;
const apiUrl = '<?= $apiUrl ?>';
const previewUrl = '<?= $previewUrl ?>';
const apiKey = '<?= $apiKey ?>';
const userId = '<?= $userId ?>';
const loggedPhone = '<?= htmlspecialchars($primaryIdentifier) ?>';

// ============================================================
// Institution type badge mapping
// ============================================================
const participantTypeBadge = {
    'BANK':         { label: 'Bank',    icon: '🏦' },
    'MNO':          { label: 'Mobile',  icon: '📱' },
    'ORCHESTRATOR': { label: 'Network', icon: '⚙️' },
};

const assetDisplay = {
    'ACCOUNT': { icon: '💰', name: 'Account' },
    'WALLET': { icon: '📱', name: 'Wallet' },
    'VOUCHER': { icon: '🎫', name: 'Voucher' },
    'MNO-WALLET': { icon: '📱', name: 'Mobile Wallet' },
};

// ============================================================
// STATE
// ============================================================
let currentFlow = null; // 'send' | 'cashout' | 'identity' | 'pool'
let currentStep = 0; // 0 = details, 1 = confirm, 2 = done
let pendingPayload = null;
let previewData = null;
let sources = [];
let sourceCounter = 0;
let stdDestType = 'ACCOUNT';
let msDestType = 'ACCOUNT';
let selectedDestAsset = null;
let sourceSelections = {}; // { [sourceId]: { inst: code, asset: type } }

// ============================================================
// PANEL NAVIGATION
// ============================================================
function openPanel(type) {
    const map = {
        'cloud': 'cloudPanel',
        'history': 'historyPanel',
        'identifiers': 'identifiersPanel'
    };
    const id = map[type];
    if (id) document.getElementById(id).classList.add('active');
}

function closePanel(type) {
    const map = {
        'cloud': 'cloudPanel',
        'history': 'historyPanel',
        'identifiers': 'identifiersPanel'
    };
    const id = map[type];
    if (id) document.getElementById(id).classList.remove('active');
}

function openFlow(type) {
    currentFlow = type;
    currentStep = 0;
    selectedDestAsset = null;
    document.getElementById('flowPanel').classList.add('active');
    renderFlow();
}

function closeFlow() {
    document.getElementById('flowPanel').classList.remove('active');
    currentFlow = null;
    currentStep = 0;
    sources = [];
    sourceSelections = {};
    sourceCounter = 0;
}

// ============================================================
// FLOW RENDERER
// ============================================================
function renderFlow() {
    const titleMap = {
        'send': 'Send',
        'cashout': 'Cashout',
        'identity': 'Send to identity',
        'pool': 'Combine sources'
    };
    document.getElementById('flowTitle').textContent = titleMap[currentFlow] || 'Swap';
    const body = document.getElementById('flowBody');
    
    if (currentStep === 0) body.innerHTML = renderDetails();
    else if (currentStep === 1) body.innerHTML = renderConfirm();
    else if (currentStep === 2) body.innerHTML = renderDone();
}

function renderDetails() {
    const flow = currentFlow;
    let html = '';
    
    // Common: Source selection for all except pool
    if (flow !== 'pool') {
        html += `
            <div class="field">
                <div class="field-label">Send from</div>
                <div class="pill-group" id="fromPills">
                    ${Object.entries(participants).map(([code, p]) => {
                        const badge = participantTypeBadge[p.type] || participantTypeBadge['BANK'];
                        return `
                            <button class="pill" data-value="${code}" onclick="selectFrom('${code}')">
                                <span style="opacity:0.6;font-size:11px;margin-right:4px;">${badge.icon}</span>${p.name}
                            </button>
                        `;
                    }).join('')}
                </div>
            </div>
            <div class="field" id="assetField">
                <div class="field-label">Asset type</div>
                <div class="pill-group" id="assetPills"></div>
            </div>
            <div class="field" id="sourceIdField">
                <div class="field-label">Your identifier</div>
                <select class="text-input" id="sourceIdentifier">
                    <option value="">Select an identifier</option>
                    ${userIdentifiers.map(id => `<option value="${id.value}">${id.icon} ${id.value}</option>`).join('')}
                </select>
            </div>
            <div class="field">
                <div class="field-label">Amount (${currencySymbol})</div>
                <input type="number" class="text-input" id="amountInput" placeholder="0.00" step="0.01">
                <div class="quick-amounts">
                    ${[200, 100, 50, 20, 10, 500, 1000].map(a => `<span class="quick-amount" onclick="document.getElementById('amountInput').value=${a};updateSummary()">${a}</span>`).join('')}
                </div>
            </div>
            <div id="assetFieldsContainer" class="field"></div>
        `;
    }
    
    // Flow-specific fields
    if (flow === 'send') {
        html += `
            <div class="field">
                <div class="field-label">Send to</div>
                <div class="pill-group" id="toPills">
                    ${Object.entries(participants).map(([code, p]) => {
                        const badge = participantTypeBadge[p.type] || participantTypeBadge['BANK'];
                        return `
                            <button class="pill" data-value="${code}" onclick="selectTo('${code}')">
                                <span style="opacity:0.6;font-size:11px;margin-right:4px;">${badge.icon}</span>${p.name}
                            </button>
                        `;
                    }).join('')}
                </div>
            </div>
            <div class="field">
                <div class="field-label">Destination type</div>
                <div class="pill-group">
                    <button class="pill active" data-value="ACCOUNT" onclick="selectDestType('ACCOUNT')">Account</button>
                    <button class="pill" data-value="WALLET" onclick="selectDestType('WALLET')">Wallet</button>
                </div>
            </div>
            <div class="field" id="destField">
                <div class="field-label">Destination identifier</div>
                <input class="text-input" id="destInput" placeholder="Account number or phone">
            </div>
        `;
    }
    
    if (flow === 'cashout') {
        html += `
            <div class="info-note">💳 They receive an ATM code via SMS. No destination account needed.</div>
            <div class="field">
                <div class="field-label">Beneficiary phone</div>
                <input class="text-input" id="beneficiaryPhone" placeholder="+267 7X XXX XXX" value="${loggedPhone}">
            </div>
        `;
    }
    
    if (flow === 'identity') {
        html += `
            <div class="info-note">🔐 Funds held against this identity. Recipient chooses cashout or deposit later — fees set at that point.</div>
            <div class="field">
                <div class="field-label">Identity type</div>
                <div class="pill-group" id="identityTypePills">
                    <button class="pill active" data-value="phone" onclick="selectIdentityType('phone')">Phone</button>
                    <button class="pill" data-value="national_id" onclick="selectIdentityType('national_id')">National ID</button>
                    <button class="pill" data-value="email" onclick="selectIdentityType('email')">Email</button>
                </div>
            </div>
            <div class="field">
                <div class="field-label">Identity value</div>
                <input class="text-input" id="identityValue" placeholder="Enter phone, ID or email">
            </div>
        `;
    }
    
    if (flow === 'pool') {
        html += `
            <div class="field">
                <div class="field-label">Pay to</div>
                <div class="pill-group" id="toPills">
                    ${Object.entries(participants).map(([code, p]) => {
                        const badge = participantTypeBadge[p.type] || participantTypeBadge['BANK'];
                        return `
                            <button class="pill" data-value="${code}" onclick="selectTo('${code}')">
                                <span style="opacity:0.6;font-size:11px;margin-right:4px;">${badge.icon}</span>${p.name}
                            </button>
                        `;
                    }).join('')}
                </div>
            </div>
            <div class="field">
                <div class="field-label">Destination asset type</div>
                <div class="dest-asset-grid" id="destAssetGrid">
                    ${Object.entries(assetDisplay).map(([code, info]) => `
                        <div class="dest-asset-card" data-value="${code}" onclick="selectDestAsset('${code}')">
                            <span class="icon">${info.icon}</span>
                            <div class="name">${info.name}</div>
                            <div class="desc">${code}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
            <div class="field" id="destField">
                <div class="field-label">Destination identifier</div>
                <input class="text-input" id="destInput" placeholder="Account number or phone">
            </div>
            <div class="field">
                <div class="field-label">Sources (2+ required)</div>
                <div id="sourceEntries"></div>
                <button class="add-source-btn" onclick="addSource()">+ Add source</button>
                <div id="sourceSummary" class="source-summary" style="display:none;">
                    <div>Total: <span class="total" id="totalSourceAmount">${currencySymbol} 0.00</span></div>
                    <div class="list" id="sourceList">No sources configured</div>
                </div>
            </div>
        `;
    }
    
    // PIN field (always at bottom)
    html += `
        <div class="field">
            <div class="field-label">Your PIN</div>
            <input type="password" class="text-input" id="pinInput" placeholder="••••" autocomplete="new-password">
        </div>
        <div id="summaryBox" class="info-note" style="margin-top:0;">Fill in the fields above</div>
        <button class="btn-primary" id="reviewBtn" onclick="goToConfirm()">
            Review <span class="arrow">›</span>
        </button>
    `;
    
    // Initialize dynamic fields
    setTimeout(() => {
        if (flow !== 'pool') {
            const fromPills = document.querySelectorAll('#fromPills .pill');
            if (fromPills.length) fromPills[0].click();
        }
        if (flow === 'pool') {
            // Select first destination asset by default
            const firstAsset = document.querySelector('.dest-asset-card');
            if (firstAsset) firstAsset.click();
            if (sources.length === 0) addSource();
        }
        updateSummary();
    }, 50);
    
    return html;
}

// ============================================================
// FLOW HELPERS
// ============================================================
let selectedFrom = null;
let selectedTo = null;
let selectedAsset = null;
let selectedIdentType = 'phone';

function selectFrom(code) {
    selectedFrom = code;
    document.querySelectorAll('#fromPills .pill').forEach(el => {
        el.classList.toggle('active', el.dataset.value === code);
    });
    updateAssetTypes();
    updateSummary();
}

function selectTo(code) {
    selectedTo = code;
    const pills = document.querySelectorAll('#toPills .pill');
    if (pills.length) {
        pills.forEach(el => el.classList.toggle('active', el.dataset.value === code));
    }
    updateSummary();
}

function selectDestType(type) {
    stdDestType = type;
    document.querySelectorAll('#destField .pill-group .pill').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    const input = document.getElementById('destInput');
    if (input) input.placeholder = type === 'ACCOUNT' ? 'Account number' : 'Phone number';
    updateSummary();
}

function selectDestAsset(type) {
    selectedDestAsset = type;
    document.querySelectorAll('.dest-asset-card').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    msDestType = type;
    updateSummary();
}

function selectIdentityType(type) {
    selectedIdentType = type;
    document.querySelectorAll('#identityTypePills .pill').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    updateSummary();
}

function updateAssetTypes() {
    const container = document.getElementById('assetPills');
    if (!container) return;
    container.innerHTML = '';
    const assets = participants[selectedFrom]?.asset_types || ['ACCOUNT'];
    assets.forEach(type => {
        const ui = assetUI[type] || {};
        const pill = document.createElement('button');
        pill.className = 'pill';
        pill.dataset.value = type;
        pill.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
        pill.onclick = () => selectAsset(type);
        container.appendChild(pill);
    });
    if (container.children.length) container.children[0].click();
}

function selectAsset(type) {
    selectedAsset = type;
    document.querySelectorAll('#assetPills .pill').forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });
    renderAssetFields();
    updateSummary();
}

function renderAssetFields() {
    const container = document.getElementById('assetFieldsContainer');
    if (!container) return;
    container.innerHTML = '';
    if (!selectedAsset) return;
    const fields = assetFields[selectedAsset] || [];
    if (fields.length === 0) {
        container.innerHTML = '<div class="info-note" style="margin:0;">✅ No additional fields required</div>';
        return;
    }
    let html = '<div style="margin-top:8px;">';
    fields.forEach(f => {
        const isPin = f.type === 'password' || f.name.includes('pin') || f.vault_field === 'pin';
        html += `
            <div class="field" style="margin-bottom:10px;">
                <div class="field-label">${f.label || f.name}</div>
                <input type="${isPin ? 'password' : (f.type || 'text')}" 
                       class="text-input" 
                       id="asset_${f.name}" 
                       placeholder="${f.placeholder || ''}"
                       autocomplete="${isPin ? 'new-password' : 'on'}">
                ${isPin ? '<div style="font-size:10px;opacity:0.4;margin-top:4px;">🔑 PIN field</div>' : ''}
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

// ============================================================
// POOL SOURCES
// ============================================================
function addSource() {
    sourceCounter++;
    const id = 'src_' + sourceCounter;
    const entry = document.createElement('div');
    entry.className = 'source-entry';
    entry.id = id;
    
    entry.innerHTML = `
        <div class="source-header">
            <span class="num">Source ${sourceCounter}</span>
            <button class="remove-btn" onclick="removeSource('${id}')">✕</button>
        </div>
        <div class="field">
            <div class="field-label">Institution</div>
            <div class="pill-group" id="${id}_instPills">
                ${Object.entries(participants).map(([code, p]) => {
                    const badge = participantTypeBadge[p.type] || participantTypeBadge['BANK'];
                    return `
                        <button type="button" class="pill" data-value="${code}" onclick="selectSourceInst('${id}','${code}')">
                            <span style="opacity:0.6;font-size:11px;margin-right:4px;">${badge.icon}</span>${p.name}
                        </button>
                    `;
                }).join('')}
            </div>
        </div>
        <div class="field" style="margin-top:10px;">
            <div class="field-label">Asset type</div>
            <div class="pill-group" id="${id}_assetPills"></div>
        </div>
        <div class="field" style="margin-top:10px;">
            <div class="field-label">Amount (${currencySymbol})</div>
            <input type="number" class="text-input" id="${id}_amount" placeholder="0.00" step="0.01" oninput="updateSummary()">
        </div>
        <div class="field" style="margin-top:10px;">
            <div class="field-label">Identifier</div>
            <select class="text-input" id="${id}_ident">
                <option value="">Select</option>
                ${userIdentifiers.map(id => `<option value="${id.value}">${id.icon} ${id.value}</option>`).join('')}
            </select>
        </div>
        <div id="${id}_fields" class="asset-fields"></div>
    `;
    document.getElementById('sourceEntries').appendChild(entry);
    sources.push({ id, counter: sourceCounter });
    updateSourceCount();
    updateSummary();
}

function removeSource(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
    sources = sources.filter(s => s.id !== id);
    delete sourceSelections[id];
    updateSourceCount();
    updateSummary();
    if (sources.length === 0) addSource();
}

function updateSourceCount() {
    // Count is tracked via sources array length
}

function selectSourceInst(sourceId, code) {
    sourceSelections[sourceId] = sourceSelections[sourceId] || {};
    sourceSelections[sourceId].inst = code;
    sourceSelections[sourceId].asset = null;

    document.querySelectorAll(`#${sourceId}_instPills .pill`).forEach(el => {
        el.classList.toggle('active', el.dataset.value === code);
    });

    renderSourceAssetPills(sourceId, code);
    updateSummary();
}

function renderSourceAssetPills(sourceId, instCode) {
    const container = document.getElementById(sourceId + '_assetPills');
    if (!container) return;
    container.innerHTML = '';
    const assets = participants[instCode]?.asset_types || ['ACCOUNT'];
    assets.forEach(type => {
        const ui = assetUI[type] || {};
        const pill = document.createElement('button');
        pill.type = 'button';
        pill.className = 'pill';
        pill.dataset.value = type;
        pill.textContent = (ui.icon || '') + ' ' + (ui.display_name || type);
        pill.onclick = () => selectSourceAsset(sourceId, type);
        container.appendChild(pill);
    });
    if (container.children.length) container.children[0].click();
}

function selectSourceAsset(sourceId, type) {
    sourceSelections[sourceId] = sourceSelections[sourceId] || {};
    sourceSelections[sourceId].asset = type;

    document.querySelectorAll(`#${sourceId}_assetPills .pill`).forEach(el => {
        el.classList.toggle('active', el.dataset.value === type);
    });

    updateSourceFields(sourceId);
    updateSummary();
}

function updateSourceFields(id) {
    const asset = sourceSelections[id]?.asset || '';
    const container = document.getElementById(id + '_fields');
    container.innerHTML = '';
    if (!asset) return;
    const fields = assetFields[asset] || [];
    if (fields.length === 0) return;
    fields.forEach(f => {
        const isPin = f.type === 'password' || f.name.includes('pin') || f.vault_field === 'pin';
        const div = document.createElement('div');
        div.innerHTML = `
            <div class="field" style="margin:0;">
                <div class="field-label">${f.label || f.name}</div>
                <input type="${isPin ? 'password' : (f.type || 'text')}" 
                       class="text-input" 
                       id="${id}_${f.name}" 
                       placeholder="${f.placeholder || ''}"
                       autocomplete="${isPin ? 'new-password' : 'on'}">
            </div>
        `;
        container.appendChild(div.firstElementChild);
    });
}

// ============================================================
// SUMMARY
// ============================================================
function updateSummary() {
    const box = document.getElementById('summaryBox');
    if (!box) return;
    const flow = currentFlow;
    let text = 'Fill in the fields above';
    
    if (flow === 'send') {
        const from = selectedFrom || '?';
        const to = selectedTo || '?';
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        text = `${from} → ${to} · ${currencySymbol} ${amt.toFixed(2)}`;
    } else if (flow === 'cashout') {
        const from = selectedFrom || '?';
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        text = `${from} → Cashout · ${currencySymbol} ${amt.toFixed(2)}`;
    } else if (flow === 'identity') {
        const from = selectedFrom || '?';
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        const idVal = document.getElementById('identityValue')?.value || '?';
        text = `${from} → ${selectedIdentType}: ${idVal} · ${currencySymbol} ${amt.toFixed(2)}`;
    } else if (flow === 'pool') {
        let total = 0;
        const sourceDetails = [];
        sources.forEach(s => {
            const amt = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            total += amt;
            const inst = sourceSelections[s.id]?.inst || '?';
            const asset = sourceSelections[s.id]?.asset || '?';
            if (amt > 0) sourceDetails.push(`${inst}(${asset}):${amt.toFixed(2)}`);
        });
        document.getElementById('totalSourceAmount').textContent = currencySymbol + ' ' + total.toFixed(2);
        document.getElementById('sourceList').textContent = sourceDetails.join(' | ') || 'No sources configured';
        const to = selectedTo || '?';
        const destAsset = selectedDestAsset || '?';
        text = `${to} → ${destAsset} · ${currencySymbol} ${total.toFixed(2)} · ${sources.length} source(s)`;
    }
    box.textContent = '📋 ' + text;
}

// ============================================================
// GO TO CONFIRM
// ============================================================
function goToConfirm() {
    const pin = document.getElementById('pinInput')?.value;
    if (!pin || pin.length < 4) {
        alert('Enter your PIN');
        return;
    }
    
    // Validate based on flow
    const flow = currentFlow;
    let valid = true;
    
    if (flow === 'send') {
        if (!selectedFrom || !selectedTo) { alert('Select source and destination'); return; }
        if (selectedFrom === selectedTo) { alert('Source and destination must be different'); return; }
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        if (amt <= 0) { alert('Enter a valid amount'); return; }
        const dest = document.getElementById('destInput')?.value?.trim();
        if (!dest) { alert('Enter destination identifier'); return; }
        const srcId = document.getElementById('sourceIdentifier')?.value;
        if (!srcId) { alert('Select your source identifier'); return; }
    } else if (flow === 'cashout') {
        if (!selectedFrom) { alert('Select source'); return; }
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        if (amt <= 0) { alert('Enter a valid amount'); return; }
        const phone = document.getElementById('beneficiaryPhone')?.value?.trim();
        if (!phone) { alert('Enter beneficiary phone'); return; }
        const srcId = document.getElementById('sourceIdentifier')?.value;
        if (!srcId) { alert('Select your source identifier'); return; }
    } else if (flow === 'identity') {
        if (!selectedFrom) { alert('Select source'); return; }
        const amt = parseFloat(document.getElementById('amountInput')?.value) || 0;
        if (amt <= 0) { alert('Enter a valid amount'); return; }
        const idVal = document.getElementById('identityValue')?.value?.trim();
        if (!idVal) { alert('Enter the identity value'); return; }
        const srcId = document.getElementById('sourceIdentifier')?.value;
        if (!srcId) { alert('Select your source identifier'); return; }
    } else if (flow === 'pool') {
        let hasError = false;
        sources.forEach(s => {
            const amt = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            const inst = sourceSelections[s.id]?.inst;
            const asset = sourceSelections[s.id]?.asset;
            const ident = document.getElementById(s.id + '_ident')?.value;
            if (!inst) { hasError = true; alert('Select institution for source ' + s.counter); return; }
            if (!asset) { hasError = true; alert('Select asset type for source ' + s.counter); return; }
            if (!ident) { hasError = true; alert('Select identifier for source ' + s.counter); return; }
            if (amt <= 0) { hasError = true; alert('Enter amount for source ' + s.counter); return; }
        });
        if (hasError) return;
        if (sources.length < 2) { alert('Add at least 2 sources'); return; }
        if (!selectedTo) { alert('Select destination'); return; }
        if (!selectedDestAsset) { alert('Select destination asset type'); return; }
        const dest = document.getElementById('destInput')?.value?.trim();
        if (!dest) { alert('Enter destination identifier'); return; }
    }
    
    const payload = buildPayload();
    if (payload) {
        pendingPayload = payload;
        showConfirm(payload);
    }
}

// ============================================================
// BUILD PAYLOAD
// ============================================================
function buildPayload() {
    const flow = currentFlow;
    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMP_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8),
        currency: '<?= $currency ?>'
    };
    
    if (flow === 'send' || flow === 'cashout') {
        payload.swap_type = flow === 'cashout' ? 'CASHOUT' : 'DEPOSIT';
        payload.from_institution = selectedFrom;
        payload.to_institution = selectedTo || 'ATM';
        payload.asset_type = selectedAsset || 'ACCOUNT';
        payload.source_identifier = document.getElementById('sourceIdentifier')?.value || '';
        payload.amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
        payload.destination_asset_type = stdDestType || 'ACCOUNT';
        
        const fields = assetFields[selectedAsset] || [];
        fields.forEach(f => {
            const el = document.getElementById('asset_' + f.name);
            if (el && el.value.trim()) payload[f.name] = el.value.trim();
        });
        
        if (flow === 'cashout') {
            payload.beneficiary_phone = document.getElementById('beneficiaryPhone')?.value || '';
            payload.destination_identifier = payload.beneficiary_phone;
            payload.destination_identifier_type = 'phone';
        } else {
            const dest = document.getElementById('destInput')?.value?.trim() || '';
            if (stdDestType === 'ACCOUNT') {
                payload.destination_account = dest;
                payload.destination_identifier = dest;
                payload.destination_identifier_type = 'account';
            } else {
                payload.destination_phone = dest;
                payload.destination_identifier = dest;
                payload.destination_identifier_type = 'phone';
            }
        }
    } else if (flow === 'identity') {
        payload.swap_type = 'IDENTITY';
        payload.from_institution = selectedFrom;
        payload.asset_type = selectedAsset || 'ACCOUNT';
        payload.source_identifier = document.getElementById('sourceIdentifier')?.value || '';
        payload.amount = parseFloat(document.getElementById('amountInput')?.value) || 0;
        payload.identity_type = selectedIdentType || 'phone';
        payload.identity_value = document.getElementById('identityValue')?.value?.trim() || '';
        const fields = assetFields[selectedAsset] || [];
        fields.forEach(f => {
            const el = document.getElementById('asset_' + f.name);
            if (el && el.value.trim()) payload[f.name] = el.value.trim();
        });
    } else if (flow === 'pool') {
        payload.swap_type = 'MULTI_SOURCE';
        payload.sources = [];
        let total = 0;
        sources.forEach(s => {
            const inst = sourceSelections[s.id]?.inst || '';
            const asset = sourceSelections[s.id]?.asset || 'ACCOUNT';
            const amount = parseFloat(document.getElementById(s.id + '_amount')?.value) || 0;
            const ident = document.getElementById(s.id + '_ident')?.value || '';
            if (inst && amount > 0) {
                const source = { 
                    institution: inst, 
                    asset_type: asset, 
                    amount: amount, 
                    identifier: ident 
                };
                const fields = assetFields[asset] || [];
                fields.forEach(f => {
                    const el = document.getElementById(s.id + '_' + f.name);
                    if (el && el.value.trim()) source[f.name] = el.value.trim();
                });
                payload.sources.push(source);
                total += amount;
            }
        });
        payload.amount = total;
        payload.to_institution = selectedTo;
        payload.delivery_method = 'DEPOSIT';
        payload.destination_asset_type = selectedDestAsset || 'ACCOUNT';
        payload.contribution_strategy = 'SMART';
        const dest = document.getElementById('destInput')?.value?.trim() || '';
        if (selectedDestAsset === 'ACCOUNT') {
            payload.destination_account = dest;
            payload.destination_identifier = dest;
            payload.destination_identifier_type = 'account';
        } else {
            payload.destination_phone = dest;
            payload.destination_identifier = dest;
            payload.destination_identifier_type = 'phone';
        }
    }
    
    const pin = document.getElementById('pinInput')?.value || '';
    if (pin) { payload.pin = pin; payload.wallet_pin = pin; }
    return payload;
}

// ============================================================
// CONFIRM MODAL
// ============================================================
async function showConfirm(payload) {
    const modal = document.getElementById('confirmModal');
    const details = document.getElementById('modalDetails');
    const error = document.getElementById('modalError');
    const btn = document.getElementById('confirmBtn');
    
    error.classList.remove('show');
    btn.disabled = true;
    btn.textContent = 'Loading...';
    details.innerHTML = '<div style="text-align:center;padding:20px;"><div style="display:inline-block;width:24px;height:24px;border:3px solid #D8D4CB;border-top-color:#121212;border-radius:50%;animation:spin 0.8s linear infinite;"></div><br><br>Calculating fees...</div>';
    modal.classList.add('show');
    
    try {
        const resp = await fetch(previewUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
            body: JSON.stringify(payload)
        });
        const result = await resp.json();
        if (!result.success) throw new Error(result.error || 'Fee calculation failed');
        
        previewData = result.preview;
        const p = previewData;
        const totalFee = p.total_fee || 0;
        const netAmount = p.net_amount_destination_currency || p.amount || 0;
        
        let feeHTML = '';
        if (p.fee_breakdown && p.fee_breakdown.length > 0) {
            feeHTML = '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #D8D4CB;">';
            p.fee_breakdown.forEach(item => {
                if ((item.amount || 0) > 0) {
                    feeHTML += `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;opacity:0.6;">
                        <span>${item.name || item.slot || 'Fee'}</span>
                        <span>${(item.amount || 0).toFixed(2)} ${p.source_currency || 'BWP'}</span>
                    </div>`;
                }
            });
            feeHTML += '</div>';
        }
        
        details.innerHTML = `
            <div class="confirm-box">
                <div class="confirm-row">
                    <span class="label">Swap</span>
                    <span>${p.source_institution || '?'} → ${p.destination_institution || '?'}</span>
                </div>
                <div class="confirm-row">
                    <span class="label">Amount</span>
                    <span>${(p.amount_requested || p.amount || 0).toFixed(2)} ${p.source_currency || 'BWP'}</span>
                </div>
                <div class="confirm-row">
                    <span class="label">Fee</span>
                    <span class="value negative">${totalFee.toFixed(2)} ${p.source_currency || 'BWP'}</span>
                </div>
                ${feeHTML}
                <div class="confirm-divider"></div>
                <div class="confirm-row">
                    <span class="label">You receive</span>
                    <span class="value highlight">${netAmount.toFixed(2)} ${p.destination_currency || p.source_currency || 'BWP'}</span>
                </div>
                ${p.is_multi_source ? `<div style="margin-top:8px;font-size:13px;opacity:0.5;">📦 ${p.source_count || 0} source(s) → ${p.destination_asset_type || '?'}</div>` : ''}
                ${p.identity_type ? `<div style="margin-top:8px;font-size:13px;opacity:0.5;">🔐 ${p.identity_type}: ${p.identity_value}</div>` : ''}
            </div>
        `;
        btn.disabled = false;
        btn.textContent = 'Confirm';
        btn.onclick = () => executeSwap();
    } catch (err) {
        error.textContent = '❌ ' + err.message;
        error.classList.add('show');
        details.innerHTML = '<div style="text-align:center;padding:20px;opacity:0.5;">Failed to calculate fees</div>';
        btn.disabled = true;
        btn.textContent = 'Error';
    }
}

function closeConfirm() {
    document.getElementById('confirmModal').classList.remove('show');
}

async function executeSwap() {
    const btn = document.getElementById('confirmBtn');
    const error = document.getElementById('modalError');
    btn.disabled = true;
    btn.textContent = 'Executing...';
    error.classList.remove('show');
    
    try {
        const resp = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-API-Key': apiKey },
            body: JSON.stringify(pendingPayload)
        });
        const result = await resp.json();
        const isSuccess = result.success === true || result.status === 'pending_cashout' || result.atomic_commit?.status === 'committed';
        
        if (isSuccess) {
            closeConfirm();
            const ref = result.reference || result.swap_reference || 'N/A';
            const atmCode = result.atm_code || result.atm_pin || result.data?.atm_code || null;
            pendingResult = { ref, atmCode, amount: pendingPayload.amount || 0, fee: result.fee || 0, identity: pendingPayload.identity_type };
            currentStep = 2;
            renderFlow();
        } else {
            const err = result.message || result.error || 'Unknown error';
            error.textContent = '❌ ' + err;
            error.classList.add('show');
            btn.disabled = false;
            btn.textContent = 'Try again';
        }
    } catch (err) {
        error.textContent = '❌ Network error: ' + err.message;
        error.classList.add('show');
        btn.disabled = false;
        btn.textContent = 'Try again';
    }
}

let pendingResult = null;

function renderDone() {
    const r = pendingResult || {};
    const isIdentity = currentFlow === 'identity';
    return `
        <div class="success-box">
            <div class="check">✓</div>
            <div class="title">${isIdentity ? 'Held for recipient' : 'Done'}</div>
            <div class="ref">Ref ${r.ref || 'N/A'}</div>
            ${r.atmCode ? `
                <div class="code-box">
                    <div class="code-label">Withdrawal code</div>
                    <div class="code">${r.atmCode}</div>
                </div>
            ` : ''}
            ${r.identity ? `<div style="font-size:14px;opacity:0.5;margin-top:12px;">🔐 ${r.identity}</div>` : ''}
            <button class="btn-primary" style="max-width:200px;margin:20px auto 0;" onclick="closeFlow()">
                Back to home
            </button>
        </div>
    `;
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('✅ VouchMorph Sharp Dashboard loaded');
    
    // Close modal on overlay click
    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) closeConfirm();
    });
});
</script>
</body>
</html>
