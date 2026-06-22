<?php
/**
 * VouchMorph - GenericBankClient Test Tool
 * This file tests how the dashboard payload is processed by GenericBankClient
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use Core\Database\DBConnection;
use Infrastructure\Banks\GenericBankClient;

header("Content-Type: text/html; charset=UTF-8");

// Get database connection
$db = DBConnection::getConnection();
if (!$db) {
    die("Database connection failed");
}

// Load country config
$countryConfig = \Core\Config\LoadCountry::getConfig();
$participants = $countryConfig['participants'] ?? [];

// Get available participants
$participantList = [];
foreach ($participants as $code => $participant) {
    if (isset($participant['type']) && $participant['type'] === 'BANK') {
        $participantList[] = $code;
    }
}

// Handle test request
$testResult = null;
$testPayload = null;
$testResponse = null;
$testError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $institution = $_POST['institution'] ?? '';
    $amount = floatval($_POST['amount'] ?? 100);
    $assetType = $_POST['asset_type'] ?? 'BANK-WALLET';
    $phone = $_POST['phone'] ?? '+26770000000';
    $pin = $_POST['wallet_pin'] ?? $_POST['pin'] ?? null;
    $reference = 'TEST_' . time();
    
    // Build test payload similar to dashboard
    $testPayload = [
        'action' => $action,
        'reference' => $reference,
        'amount' => $amount,
        'asset_type' => $assetType,
        'currency' => 'BWP',
        'phone' => $phone,
        'wallet_phone' => $phone,
        'source_identifier' => $phone,
        'source_identifier_type' => 'phone',
        'institution' => $institution,
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ];
    
    // Add PIN if provided
    if ($pin) {
        $testPayload['pin'] = $pin;
        $testPayload['wallet_pin'] = $pin;
        $testPayload['asset_fields'] = ['wallet_pin' => $pin];
    }
    
    try {
        // Get participant config
        $participantConfig = $participants[$institution] ?? null;
        if (!$participantConfig) {
            throw new Exception("Participant not found: $institution");
        }
        
        // Add base_url to config if not present
        if (!isset($participantConfig['base_url'])) {
            $participantConfig['base_url'] = "https://{$institution}-production.up.railway.app/backend";
        }
        
        // Create GenericBankClient
        $bankClient = new GenericBankClient($participantConfig);
        
        // Execute the requested action
        if ($action === 'verify_asset') {
            $testResponse = $bankClient->verifyAssetSigned($testPayload);
        } elseif ($action === 'place_hold') {
            $testResponse = $bankClient->placeHoldSigned($testPayload);
        } elseif ($action === 'generate_token') {
            $testResponse = $bankClient->generateTokenWithProof($testPayload);
        } else {
            throw new Exception("Unknown action: $action");
        }
        
        $testResult = 'success';
    } catch (Exception $e) {
        $testError = $e->getMessage();
        $testResult = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GenericBankClient Test Tool</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 30px;
            color: #fff;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        h1 { 
            font-size: 28px; 
            background: linear-gradient(135deg, #fff, #00f0ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        .subtitle { color: #888; margin-bottom: 30px; font-size: 14px; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { 
            font-size: 16px; 
            margin-bottom: 16px;
            color: #00f0ff;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group { margin-bottom: 14px; }
        label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #a0a0b0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        select, input {
            width: 100%;
            padding: 10px 12px;
            background: #1a1f3a;
            border: 1px solid #2a2f4a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }
        select:focus, input:focus { outline: none; border-color: #00f0ff; }
        select option { background: #1a1f3a; }
        
        button {
            padding: 12px 24px;
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
        
        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-secondary {
            background: #1a1f3a;
            color: #fff;
            border: 1px solid #2a2f4a;
        }
        .btn-secondary:hover { background: #2a2f4a; }
        
        .payload-box, .response-box {
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
            margin-top: 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-success { background: rgba(76,175,80,0.2); color: #4caf50; border: 1px solid #4caf50; }
        .status-error { background: rgba(244,67,54,0.2); color: #f44336; border: 1px solid #f44336; }
        .status-pending { background: rgba(255,193,7,0.2); color: #ffc107; border: 1px solid #ffc107; }
        
        .info-note {
            background: rgba(0,240,255,0.05);
            border-left: 3px solid #00f0ff;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #a0a0b0;
            margin: 8px 0;
        }
        
        .pin-field {
            border-color: #b000ff !important;
        }
        .pin-field:focus {
            border-color: #00f0ff !important;
        }
        
        @media (max-width: 768px) {
            .two-columns { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 GenericBankClient Test Tool</h1>
    <div class="subtitle">Test how dashboard payload is processed by GenericBankClient</div>
    
    <!-- Test Form -->
    <div class="card">
        <h3>📤 Test Request</h3>
        <form method="POST" action="">
            <div class="two-columns">
                <div>
                    <div class="form-group">
                        <label>🏦 Institution</label>
                        <select name="institution" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($participantList as $code): ?>
                                <option value="<?= htmlspecialchars($code) ?>" <?= isset($_POST['institution']) && $_POST['institution'] === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($code) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>📦 Asset Type</label>
                        <select name="asset_type">
                            <option value="BANK-WALLET" <?= isset($_POST['asset_type']) && $_POST['asset_type'] === 'BANK-WALLET' ? 'selected' : '' ?>>BANK-WALLET</option>
                            <option value="ACCOUNT" <?= isset($_POST['asset_type']) && $_POST['asset_type'] === 'ACCOUNT' ? 'selected' : '' ?>>ACCOUNT</option>
                            <option value="MNO-WALLET" <?= isset($_POST['asset_type']) && $_POST['asset_type'] === 'MNO-WALLET' ? 'selected' : '' ?>>MNO-WALLET</option>
                            <option value="CASHOUT-VOUCHER" <?= isset($_POST['asset_type']) && $_POST['asset_type'] === 'CASHOUT-VOUCHER' ? 'selected' : '' ?>>CASHOUT-VOUCHER</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>📱 Phone Number</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '+26770000000') ?>">
                    </div>
                </div>
                
                <div>
                    <div class="form-group">
                        <label>⚡ Action</label>
                        <select name="action" required>
                            <option value="verify_asset" <?= isset($_POST['action']) && $_POST['action'] === 'verify_asset' ? 'selected' : '' ?>>verify_asset</option>
                            <option value="place_hold" <?= isset($_POST['action']) && $_POST['action'] === 'place_hold' ? 'selected' : '' ?>>place_hold</option>
                            <option value="generate_token" <?= isset($_POST['action']) && $_POST['action'] === 'generate_token' ? 'selected' : '' ?>>generate_token</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>💰 Amount</label>
                        <input type="number" name="amount" step="0.01" value="<?= htmlspecialchars($_POST['amount'] ?? '100') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>🔑 Wallet PIN (wallet_pin)</label>
                        <input type="password" name="wallet_pin" class="pin-field" 
                               placeholder="Enter PIN (e.g., 77777)" 
                               value="<?= htmlspecialchars($_POST['wallet_pin'] ?? '') ?>">
                        <div class="info-note">💡 This simulates the wallet_pin from the dashboard</div>
                    </div>
                </div>
            </div>
            
            <div class="btn-group">
                <button type="submit">🚀 Send Request</button>
                <button type="reset" class="btn-secondary">🔄 Reset</button>
            </div>
        </form>
    </div>
    
    <!-- Results -->
    <?php if ($testResult !== null): ?>
        <div class="card">
            <h3>
                📬 Test Result
                <span class="status-badge <?= $testResult === 'success' ? 'status-success' : 'status-error' ?>">
                    <?= $testResult === 'success' ? '✅ SUCCESS' : '❌ ERROR' ?>
                </span>
            </h3>
            
            <?php if ($testError): ?>
                <div style="background: rgba(244,67,54,0.1); border-left: 3px solid #f44336; padding: 12px; border-radius: 6px; margin-bottom: 16px;">
                    <strong style="color: #f44336;">Error:</strong> <?= htmlspecialchars($testError) ?>
                </div>
            <?php endif; ?>
            
            <div class="two-columns">
                <div>
                    <h4 style="font-size: 13px; color: #888; margin-bottom: 6px;">📨 Request Payload</h4>
                    <div class="payload-box">
                        <?php 
                        $displayPayload = $testPayload ?? [];
                        // Mask PIN in display
                        if (isset($displayPayload['pin']) && $displayPayload['pin']) {
                            $displayPayload['pin'] = substr($displayPayload['pin'], 0, 2) . '****';
                        }
                        if (isset($displayPayload['wallet_pin']) && $displayPayload['wallet_pin']) {
                            $displayPayload['wallet_pin'] = substr($displayPayload['wallet_pin'], 0, 2) . '****';
                        }
                        if (isset($displayPayload['asset_fields']) && isset($displayPayload['asset_fields']['wallet_pin'])) {
                            $displayPayload['asset_fields']['wallet_pin'] = substr($displayPayload['asset_fields']['wallet_pin'], 0, 2) . '****';
                        }
                        echo htmlspecialchars(json_encode($displayPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        ?>
                    </div>
                </div>
                <div>
                    <h4 style="font-size: 13px; color: #888; margin-bottom: 6px;">📬 Response</h4>
                    <div class="response-box">
                        <?php 
                        $displayResponse = $testResponse ?? ['error' => 'No response'];
                        echo htmlspecialchars(json_encode($displayResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- PIN Detection Info -->
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #2a2f4a;">
                <h4 style="font-size: 13px; color: #888; margin-bottom: 8px;">🔍 PIN Detection</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 13px;">
                    <div>
                        <strong>wallet_pin in payload:</strong>
                        <span style="color: <?= isset($testPayload['wallet_pin']) ? '#4caf50' : '#f44336' ?>">
                            <?= isset($testPayload['wallet_pin']) ? '✅ YES' : '❌ NO' ?>
                        </span>
                    </div>
                    <div>
                        <strong>pin in payload:</strong>
                        <span style="color: <?= isset($testPayload['pin']) ? '#4caf50' : '#f44336' ?>">
                            <?= isset($testPayload['pin']) ? '✅ YES' : '❌ NO' ?>
                        </span>
                    </div>
                    <div>
                        <strong>asset_fields.wallet_pin:</strong>
                        <span style="color: <?= isset($testPayload['asset_fields']['wallet_pin']) ? '#4caf50' : '#f44336' ?>">
                            <?= isset($testPayload['asset_fields']['wallet_pin']) ? '✅ YES' : '❌ NO' ?>
                        </span>
                    </div>
                    <div>
                        <strong>PIN sent to bank:</strong>
                        <span style="color: <?= ($testPayload['wallet_pin'] ?? $testPayload['pin'] ?? null) ? '#4caf50' : '#f44336' ?>">
                            <?= ($testPayload['wallet_pin'] ?? $testPayload['pin'] ?? null) ? '✅ YES' : '❌ NO' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Instructions -->
    <div class="card">
        <h3>📖 How to Use</h3>
        <div style="font-size: 13px; color: #a0a0b0; line-height: 1.8;">
            <ol style="padding-left: 20px;">
                <li>Select an <strong>Institution</strong> (e.g., SACCUSSALIS)</li>
                <li>Select the <strong>Asset Type</strong> (BANK-WALLET for PIN testing)</li>
                <li>Enter a <strong>Phone Number</strong></li>
                <li>Select the <strong>Action</strong> (verify_asset, place_hold, generate_token)</li>
                <li>Enter a <strong>Wallet PIN</strong> (e.g., 77777) - this simulates the dashboard input</li>
                <li>Click <strong>Send Request</strong></li>
            </ol>
            <div class="info-note" style="margin-top: 12px;">
                💡 <strong>Test PIN 77777:</strong> Make sure this PIN exists in the <code>ewallet_pins</code> table.
            </div>
        </div>
    </div>
</div>
</body>
</html>
