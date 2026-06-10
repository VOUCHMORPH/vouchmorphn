<?php
// --- SIMPLIFIED DASHBOARD - CORRECTED ---
// VouchMorph is an ORCHESTRATOR, not a wallet/bank
// We move money FROM source TO destination

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
use Application\Utils\SessionManager;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header("Location: login.php");
    exit;
}

$user = SessionManager::getUser();
$loggedPhone = htmlspecialchars($user['phone'] ?? '');
$userId = $user['user_id'] ?? null;

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;

$config = LoadCountry::getConfig();
$countryCode = $config['country_code'] ?? 'BW';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';

try {
    $swapDB = DBConnection::getConnection();
} catch (Exception $e) {
    die("Database error");
}

// Get user's saved phone numbers for SMS notifications (optional)
$savedPhones = [];
try {
    $stmt = $swapDB->prepare("SELECT phone_number, is_default FROM user_notification_phones WHERE user_id = ?");
    $stmt->execute([$userId]);
    $savedPhones = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Get recent swaps
$recentSwaps = [];
try {
    $stmt = $swapDB->prepare("
        SELECT swap_reference, amount, from_institution, to_institution, status, created_at 
        FROM swap_ledgers 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$userId]);
    $recentSwaps = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$apiUrl = getenv('API_URL') ?: 'https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | Swap Orchestrator</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }
        .logo h1 { font-size: 24px; background: linear-gradient(135deg, #fff, #00f0ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .logo p { font-size: 12px; color: #a0a0b0; margin-top: 4px; }
        .user-info { text-align: right; }
        .user-phone { color: #00f0ff; font-weight: bold; }
        
        /* Cards */
        .card {
            background: #12162e;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 8px; }
        
        /* Form */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #a0a0b0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        select, input {
            width: 100%;
            padding: 12px 16px;
            background: #1a1f3a;
            border: 1px solid #2a2f4a;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }
        select:focus, input:focus { outline: none; border-color: #00f0ff; }
        
        .source-detail, .dest-detail { margin-top: 16px; padding-top: 16px; border-top: 1px solid #2a2f4a; }
        
        .quick-amounts {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .quick-amount {
            padding: 6px 12px;
            background: #1a1f3a;
            border-radius: 20px;
            cursor: pointer;
            font-size: 12px;
            border: 1px solid #2a2f4a;
        }
        .quick-amount:hover { background: #00f0ff; color: #0a0e27; border-color: #00f0ff; }
        
        .info-note {
            background: rgba(0, 240, 255, 0.1);
            border-left: 3px solid #00f0ff;
            padding: 12px;
            border-radius: 8px;
            font-size: 12px;
            color: #a0a0b0;
            margin: 16px 0;
        }
        
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #00f0ff, #b000ff);
            color: #0a0e27;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }
        button:hover { transform: translateY(-2px); filter: brightness(1.05); }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        
        /* Summary */
        .summary {
            background: rgba(0, 240, 255, 0.05);
            padding: 16px;
            border-radius: 12px;
            margin: 20px 0;
            font-size: 14px;
            border: 1px solid rgba(0, 240, 255, 0.2);
        }
        
        /* Result */
        .result {
            padding: 16px;
            border-radius: 12px;
            margin-top: 16px;
            display: none;
            font-size: 13px;
        }
        .result.success { background: rgba(76, 175, 80, 0.2); border: 1px solid #4caf50; display: block; color: #4caf50; }
        .result.error { background: rgba(244, 67, 54, 0.2); border: 1px solid #f44336; display: block; color: #f44336; }
        .result.loading { background: rgba(255, 193, 7, 0.2); border: 1px solid #ffc107; display: block; color: #ffc107; }
        
        /* Recent swaps */
        .swap-item {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            border-bottom: 1px solid #2a2f4a;
            font-size: 13px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .swap-status.completed { color: #4caf50; }
        .swap-status.failed { color: #f44336; }
        .swap-status.processing { color: #ffc107; }
        
        .phone-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
        }
        .phone-option input { flex: 1; }
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: normal;
            text-transform: none;
        }
        .checkbox-label input { width: auto; margin: 0; }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; gap: 16px; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="logo">
            <h1>VouchMorph</h1>
            <p>Interoperability Orchestrator · Move money between institutions</p>
        </div>
        <div class="user-info">
            <div>👤 <?= htmlspecialchars($loggedPhone) ?></div>
            <div style="font-size: 11px; color: #00f0ff;">⚡ Connected</div>
        </div>
    </div>

    <!-- Info Note -->
    <div class="info-note">
        🔄 <strong>How it works:</strong> VouchMorph moves money FROM your source (voucher/account/wallet) TO your destination (ATM cashout / bank deposit). We don't hold your money — we just connect institutions.
    </div>

    <!-- Main Swap Card -->
    <div class="card">
        <h3>🔄 New Swap</h3>
        
        <div class="form-row">
            <div>
                <label>📤 FROM (Source)</label>
                <select id="sourceType">
                    <option value="voucher">🎫 Voucher / Prepaid Code</option>
                    <option value="account">🏦 Bank Account</option>
                    <option value="wallet">📱 Mobile Money Wallet</option>
                </select>
            </div>
            <div>
                <label>📥 TO (Destination)</label>
                <select id="destType">
                    <option value="atm">🏧 ATM Cashout (Get physical cash)</option>
                    <option value="deposit">💳 Bank Deposit (Send to account)</option>
                </select>
            </div>
        </div>

        <!-- Source Details - Voucher -->
        <div id="sourceVoucher" class="source-detail">
            <div class="form-row">
                <div class="form-group">
                    <label>🎫 Voucher Number</label>
                    <input type="text" id="voucherNumber" placeholder="e.g., 710083197">
                </div>
                <div class="form-group">
                    <label>🔐 Voucher PIN</label>
                    <input type="password" id="voucherPin" placeholder="e.g., 657250">
                </div>
            </div>
            <div class="info-note" style="margin-top: 0;">
                💡 Vouchers are prepaid codes from banks or mobile money providers.
            </div>
        </div>
        
        <!-- Source Details - Account -->
        <div id="sourceAccount" class="source-detail" style="display:none">
            <div class="form-row">
                <div class="form-group">
                    <label>🏦 Account Number</label>
                    <input type="text" id="accountNumber" placeholder="e.g., 10000001">
                </div>
                <div class="form-group">
                    <label>🔐 Account PIN / Password</label>
                    <input type="password" id="accountPin" placeholder="Enter your PIN">
                </div>
            </div>
            <div class="form-group">
                <label>🏛️ Institution</label>
                <select id="accountInstitution">
                    <option value="ZURUBANK">ZURUBANK</option>
                    <option value="SACCUSSALIS">SACCUSSALIS</option>
                </select>
            </div>
            <div class="info-note" style="margin-top: 0;">
                💡 We'll verify your account and place a temporary hold on the funds.
            </div>
        </div>
        
        <!-- Source Details - Wallet -->
        <div id="sourceWallet" class="source-detail" style="display:none">
            <div class="form-row">
                <div class="form-group">
                    <label>📱 Mobile Number</label>
                    <input type="tel" id="walletPhone" placeholder="e.g., 71234567">
                </div>
                <div class="form-group">
                    <label>🔐 Wallet PIN</label>
                    <input type="password" id="walletPin" placeholder="Enter your PIN">
                </div>
            </div>
            <div class="form-group">
                <label>📡 Mobile Network</label>
                <select id="walletNetwork">
                    <option value="CAZACOM">CAZACOM</option>
                    <option value="MASCOM">MASCOM</option>
                    <option value="ORANGE">ORANGE</option>
                </select>
            </div>
            <div class="info-note" style="margin-top: 0;">
                💡 We'll check your wallet balance and place a temporary hold.
            </div>
        </div>

        <!-- Destination Details - ATM Cashout -->
        <div id="destAtm" class="dest-detail">
            <div class="form-group">
                <label>📱 SMS Notification (Optional)</label>
                <input type="tel" id="smsPhone" placeholder="Phone number to receive ATM code" value="<?= $loggedPhone ?>">
                <small style="color:#a0a0b0; display:block; margin-top:5px;">
                    💡 We'll send an ATM cashout code via SMS. Leave blank if you don't need SMS.
                </small>
            </div>
            <div class="phone-option">
                <label class="checkbox-label">
                    <input type="checkbox" id="savePhone"> Save this number for future cashouts
                </label>
            </div>
            <div class="info-note" style="margin-top: 8px;">
                🏧 The ATM code will be generated by the destination bank. You can use it at any participating ATM.
            </div>
        </div>
        
        <!-- Destination Details - Deposit -->
        <div id="destDeposit" class="dest-detail" style="display:none">
            <div class="form-group">
                <label>🏦 Destination Account Number</label>
                <input type="text" id="destAccount" placeholder="e.g., 10000001">
            </div>
            <div class="form-group">
                <label>🏛️ Destination Institution</label>
                <select id="destInstitution">
                    <option value="SACCUSSALIS">SACCUSSALIS</option>
                    <option value="ZURUBANK">ZURUBANK</option>
                </select>
            </div>
            <div class="info-note" style="margin-top: 0;">
                💡 Funds will be deposited directly into this account.
            </div>
        </div>

        <!-- Amount -->
        <div class="form-group">
            <label>💰 Amount to Swap (<?= $currencySymbol ?>)</label>
            <input type="number" id="amount" placeholder="0.00" step="0.01">
            <div class="quick-amounts">
                <span class="quick-amount" data-amount="50">50</span>
                <span class="quick-amount" data-amount="100">100</span>
                <span class="quick-amount" data-amount="200">200</span>
                <span class="quick-amount" data-amount="500">500</span>
            </div>
        </div>

        <!-- Summary -->
        <div class="summary" id="summary">
            📋 Select your options above
        </div>

        <button id="executeBtn">🚀 Execute Swap →</button>
    </div>

    <!-- Result -->
    <div id="result" class="result"></div>

    <!-- Recent Swaps -->
    <div class="card">
        <h3>📋 Recent Swaps</h3>
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center; padding:30px; color:#a0a0b0;">
                No swaps yet. Create your first swap above.
            </div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <div class="swap-item">
                    <span style="font-family: monospace;"><?= htmlspecialchars(substr($swap['swap_reference'], 0, 16)) ?>...</span>
                    <span><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></span>
                    <span class="swap-amount"><?= number_format($swap['amount'], 2) ?> <?= $currencySymbol ?></span>
                    <span class="swap-status <?= strtolower($swap['status'] ?? 'processing') ?>"><?= $swap['status'] ?? 'Processing' ?></span>
                    <span style="font-size: 11px; color:#a0a0b0;"><?= date('M d, H:i', strtotime($swap['created_at'])) ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// DOM Elements
const sourceType = document.getElementById('sourceType');
const destType = document.getElementById('destType');
const sourceVoucher = document.getElementById('sourceVoucher');
const sourceAccount = document.getElementById('sourceAccount');
const sourceWallet = document.getElementById('sourceWallet');
const destAtm = document.getElementById('destAtm');
const destDeposit = document.getElementById('destDeposit');
const executeBtn = document.getElementById('executeBtn');
const resultDiv = document.getElementById('result');
const summaryDiv = document.getElementById('summary');

// Toggle source visibility
sourceType.addEventListener('change', () => {
    sourceVoucher.style.display = 'none';
    sourceAccount.style.display = 'none';
    sourceWallet.style.display = 'none';
    if (sourceType.value === 'voucher') sourceVoucher.style.display = 'block';
    if (sourceType.value === 'account') sourceAccount.style.display = 'block';
    if (sourceType.value === 'wallet') sourceWallet.style.display = 'block';
    updateSummary();
});

// Toggle destination visibility
destType.addEventListener('change', () => {
    destAtm.style.display = 'none';
    destDeposit.style.display = 'none';
    if (destType.value === 'atm') destAtm.style.display = 'block';
    if (destType.value === 'deposit') destDeposit.style.display = 'block';
    updateSummary();
});

// Quick amount buttons
document.querySelectorAll('.quick-amount').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('amount').value = btn.dataset.amount;
        updateSummary();
    });
});

// Update summary
function updateSummary() {
    const amount = document.getElementById('amount').value || '0';
    let sourceText = '';
    let destText = '';
    
    switch(sourceType.value) {
        case 'voucher': sourceText = 'Voucher'; break;
        case 'account': sourceText = 'Bank Account'; break;
        case 'wallet': sourceText = 'Mobile Wallet'; break;
    }
    
    switch(destType.value) {
        case 'atm': destText = 'ATM Cashout'; break;
        case 'deposit': destText = 'Bank Deposit'; break;
    }
    
    summaryDiv.innerHTML = `📋 Swap ${sourceText} → ${destText} | Amount: <?= $currencySymbol ?> ${parseFloat(amount).toFixed(2)}`;
}

// Input listeners
document.getElementById('amount').addEventListener('input', updateSummary);
document.getElementById('voucherNumber')?.addEventListener('input', updateSummary);
document.getElementById('accountNumber')?.addEventListener('input', updateSummary);
document.getElementById('walletPhone')?.addEventListener('input', updateSummary);

// Execute swap
executeBtn.addEventListener('click', async () => {
    const amount = parseFloat(document.getElementById('amount').value);
    if (!amount || amount <= 0) {
        alert('Please enter a valid amount');
        return;
    }
    
    // Build source details
    let sourceDetails = { amount: amount, currency: 'BWP' };
    let destDetails = {};
    
    // Source
    if (sourceType.value === 'voucher') {
        const voucherNumber = document.getElementById('voucherNumber').value;
        const voucherPin = document.getElementById('voucherPin').value;
        if (!voucherNumber || !voucherPin) { alert('Enter voucher number and PIN'); return; }
        sourceDetails = {
            institution: 'ZURUBANK',
            asset_type: 'VOUCHER',
            voucher_number: voucherNumber,
            voucher_pin: voucherPin,
            amount: amount,
            currency: 'BWP'
        };
    } else if (sourceType.value === 'account') {
        const accountNumber = document.getElementById('accountNumber').value;
        const accountPin = document.getElementById('accountPin').value;
        const institution = document.getElementById('accountInstitution').value;
        if (!accountNumber) { alert('Enter account number'); return; }
        sourceDetails = {
            institution: institution,
            asset_type: 'ACCOUNT',
            account_number: accountNumber,
            pin: accountPin,
            amount: amount,
            currency: 'BWP'
        };
    } else if (sourceType.value === 'wallet') {
        const walletPhone = document.getElementById('walletPhone').value;
        const walletPin = document.getElementById('walletPin').value;
        const network = document.getElementById('walletNetwork').value;
        if (!walletPhone) { alert('Enter mobile number'); return; }
        sourceDetails = {
            institution: network,
            asset_type: 'MNO-WALLET',
            wallet_phone: walletPhone,
            pin: walletPin,
            amount: amount,
            currency: 'BWP'
        };
    }
    
    // Destination
    if (destType.value === 'atm') {
        const smsPhone = document.getElementById('smsPhone').value;
        destDetails = {
            institution: 'SACCUSSALIS',
            type: 'ATM_CASHOUT',
            currency: 'BWP'
        };
        if (smsPhone) destDetails.beneficiary_phone = smsPhone;
        
        // Save phone if checked
        if (document.getElementById('savePhone')?.checked && smsPhone) {
            // Optional: API call to save phone
            console.log('Save phone for future:', smsPhone);
        }
    } else {
        const destAccount = document.getElementById('destAccount').value;
        const destInstitution = document.getElementById('destInstitution').value;
        if (!destAccount) { alert('Enter destination account number'); return; }
        destDetails = {
            institution: destInstitution,
            type: 'DEPOSIT',
            account_number: destAccount,
            currency: 'BWP'
        };
    }
    
    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMPOTENT_' + Date.now(),
        swap_type: destType.value === 'atm' ? 'CASHOUT' : 'DEPOSIT',
        source_details: sourceDetails,
        destination_details: destDetails
    };
    
    // Show loading
    executeBtn.disabled = true;
    executeBtn.textContent = '⏳ Processing...';
    resultDiv.className = 'result loading';
    resultDiv.innerHTML = '⏳ Processing your swap... This may take a few seconds.';
    
    try {
        const response = await fetch('<?= $apiUrl ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-API-Key': '<?= $apiKey ?>'
            },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        
        if (result.status === 'success' || result.atomic_commit?.status === 'committed') {
            resultDiv.className = 'result success';
            let resultHtml = `<strong>✅ Swap Successful!</strong><br><br>
                Reference: ${result.reference || result.swap_reference || 'N/A'}<br>
                Amount: <?= $currencySymbol ?> ${amount}<br>`;
            
            if (result.atm_code) {
                resultHtml += `<br><strong>🏧 ATM Cashout Code:</strong> ${result.atm_code}<br>
                <small>Use this code at any participating ATM. Keep it secure!</small>`;
            }
            
            resultHtml += `<br><br><details><summary>View Details</summary>
                <pre style="margin-top:10px; overflow-x:auto;">${JSON.stringify(result, null, 2)}</pre></details>`;
            resultDiv.innerHTML = resultHtml;
        } else {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = `<strong>❌ Swap Failed</strong><br><br>
                ${result.message || result.error || 'Unknown error'}<br><br>
                <details><summary>Error Details</summary>
                <pre style="margin-top:10px; overflow-x:auto;">${JSON.stringify(result, null, 2)}</pre></details>`;
        }
    } catch (error) {
        resultDiv.className = 'result error';
        resultDiv.innerHTML = `<strong>❌ Network Error</strong><br><br>${error.message}`;
    } finally {
        executeBtn.disabled = false;
        executeBtn.textContent = '🚀 Execute Swap →';
        resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});

// Initialize
updateSummary();
</script>
</body>
</html>
