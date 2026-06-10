<?php
// public/user/dashboard.php - WORKS WITH YOUR ACTUAL SWAPSERVICE

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
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';

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

$apiUrl = '/api/v1/swap/execute.php';
$apiKey = getenv('VOUCHMORPH_API_KEY') ?: 'vouchmorph_live_1aB2cD3eF4gH5iJ6';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VouchMorph | Swap</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0a0e27;
            padding: 20px;
            color: #fff;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        
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
        .user-info { text-align: right; }
        .user-phone { color: #00f0ff; font-weight: bold; }
        
        .card {
            background: #12162e;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.08);
        }
        .card h3 { margin-bottom: 20px; font-size: 18px; }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #a0a0b0;
            text-transform: uppercase;
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
        
        .source-detail, .dest-detail { 
            margin-top: 16px; 
            padding-top: 16px; 
            border-top: 1px solid #2a2f4a;
            display: none;
        }
        .source-detail.active, .dest-detail.active { display: block; }
        
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
        .quick-amount:hover { background: #00f0ff; color: #0a0e27; }
        
        .info-note {
            background: rgba(0, 240, 255, 0.08);
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
        }
        button:hover { transform: translateY(-2px); filter: brightness(1.05); }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        
        .summary {
            background: rgba(0, 240, 255, 0.05);
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
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
            font-size: 13px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .swap-status.completed { color: #4caf50; }
        .swap-status.failed { color: #f44336; }
        
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; gap: 16px; }
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
        <div class="user-info">
            <div>👤 <span class="user-phone"><?= $loggedPhone ?></span></div>
            <div style="font-size: 11px; margin-top: 4px;"><a href="logout.php" style="color: #f44336;">Logout</a></div>
        </div>
    </div>

    <div class="card">
        <h3>🔄 New Swap</h3>
        
        <div class="form-row">
            <div>
                <label>📤 SOURCE INSTITUTION</label>
                <select id="sourceInstitution">
                    <option value="ZURUBANK">ZURUBANK</option>
                    <option value="SACCUSSALIS">SACCUSSALIS</option>
                </select>
            </div>
            <div>
                <label>📥 DESTINATION INSTITUTION</label>
                <select id="destInstitution">
                    <option value="SACCUSSALIS">SACCUSSALIS</option>
                    <option value="ZURUBANK">ZURUBANK</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div>
                <label>ASSET TYPE</label>
                <select id="assetType">
                    <option value="VOUCHER">🎫 Voucher</option>
                    <option value="ACCOUNT">🏦 Bank Account</option>
                    <option value="MNO-WALLET">📱 Mobile Wallet</option>
                </select>
            </div>
            <div>
                <label>DELIVERY MODE</label>
                <select id="deliveryMode">
                    <option value="CASHOUT">🏧 Cashout (ATM)</option>
                    <option value="DEPOSIT">💳 Deposit (Account)</option>
                </select>
            </div>
        </div>

        <!-- Source Details - Voucher -->
        <div id="sourceVoucher" class="source-detail active">
            <div class="form-row">
                <div><label>VOUCHER NUMBER</label><input type="text" id="voucherNumber" placeholder="e.g., 710083197"></div>
                <div><label>VOUCHER PIN</label><input type="password" id="voucherPin" placeholder="PIN"></div>
            </div>
        </div>

        <!-- Source Details - Account -->
        <div id="sourceAccount" class="source-detail">
            <div><label>ACCOUNT NUMBER</label><input type="text" id="accountNumber" placeholder="e.g., 10000001"></div>
        </div>

        <!-- Source Details - Wallet -->
        <div id="sourceWallet" class="source-detail">
            <div><label>WALLET PHONE</label><input type="tel" id="walletPhone" placeholder="e.g., 71234567"></div>
        </div>

        <!-- Destination Details -->
        <div id="destCashout" class="dest-detail active">
            <div><label>SMS PHONE (Optional)</label><input type="tel" id="smsPhone" placeholder="Where to send ATM code" value="<?= $loggedPhone ?>"></div>
            <div class="info-note">💡 We'll send an ATM cashout code via SMS if provided.</div>
        </div>

        <div id="destDeposit" class="dest-detail">
            <div><label>DESTINATION ACCOUNT</label><input type="text" id="destAccount" placeholder="Account number"></div>
        </div>

        <div class="form-group">
            <label>AMOUNT (<?= $currencySymbol ?>)</label>
            <input type="number" id="amount" step="0.01" placeholder="0.00">
            <div class="quick-amounts">
                <span class="quick-amount" data-amount="50">50</span>
                <span class="quick-amount" data-amount="100">100</span>
                <span class="quick-amount" data-amount="200">200</span>
                <span class="quick-amount" data-amount="500">500</span>
            </div>
        </div>

        <div class="summary" id="summary">📋 Select options above</div>

        <button id="executeBtn">🚀 Execute Swap</button>
    </div>

    <div id="result" class="result"></div>

    <div class="card">
        <h3>📋 Recent Swaps</h3>
        <?php if (empty($recentSwaps)): ?>
            <div style="text-align:center; padding:20px; color:#a0a0b0;">No swaps yet</div>
        <?php else: ?>
            <?php foreach ($recentSwaps as $swap): ?>
                <div class="swap-item">
                    <span><?= htmlspecialchars($swap['from_institution'] ?? '?') ?> → <?= htmlspecialchars($swap['to_institution'] ?? '?') ?></span>
                    <span><?= number_format($swap['amount'], 2) ?> <?= $currencySymbol ?></span>
                    <span class="swap-status <?= strtolower($swap['status'] ?? 'completed') ?>"><?= $swap['status'] ?? 'Completed' ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// DOM Elements
const sourceInstitution = document.getElementById('sourceInstitution');
const destInstitution = document.getElementById('destInstitution');
const assetType = document.getElementById('assetType');
const deliveryMode = document.getElementById('deliveryMode');
const sourceVoucher = document.getElementById('sourceVoucher');
const sourceAccount = document.getElementById('sourceAccount');
const sourceWallet = document.getElementById('sourceWallet');
const destCashout = document.getElementById('destCashout');
const destDeposit = document.getElementById('destDeposit');
const executeBtn = document.getElementById('executeBtn');
const resultDiv = document.getElementById('result');
const summaryDiv = document.getElementById('summary');

// Toggle source fields based on asset type
assetType.addEventListener('change', () => {
    sourceVoucher.classList.remove('active');
    sourceAccount.classList.remove('active');
    sourceWallet.classList.remove('active');
    if (assetType.value === 'VOUCHER') sourceVoucher.classList.add('active');
    if (assetType.value === 'ACCOUNT') sourceAccount.classList.add('active');
    if (assetType.value === 'MNO-WALLET') sourceWallet.classList.add('active');
    updateSummary();
});

// Toggle destination fields based on delivery mode
deliveryMode.addEventListener('change', () => {
    destCashout.classList.remove('active');
    destDeposit.classList.remove('active');
    if (deliveryMode.value === 'CASHOUT') destCashout.classList.add('active');
    if (deliveryMode.value === 'DEPOSIT') destDeposit.classList.add('active');
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
    const source = sourceInstitution.value;
    const dest = destInstitution.value;
    const asset = assetType.options[assetType.selectedIndex]?.text || 'Asset';
    const mode = deliveryMode.options[deliveryMode.selectedIndex]?.text || 'Mode';
    summaryDiv.innerHTML = `📋 ${source} (${asset}) → ${dest} (${mode}) | Amount: <?= $currencySymbol ?> ${parseFloat(amount).toFixed(2)}`;
}

// Input listeners
document.getElementById('amount').addEventListener('input', updateSummary);
sourceInstitution.addEventListener('change', updateSummary);
destInstitution.addEventListener('change', updateSummary);

// Execute swap
executeBtn.addEventListener('click', async () => {
    const amount = parseFloat(document.getElementById('amount').value);
    if (!amount || amount <= 0) {
        alert('Enter a valid amount');
        return;
    }

    // Build source_details based on asset type
    let sourceDetails = {
        institution: sourceInstitution.value,
        asset_type: assetType.value,
        amount: amount,
        currency: 'BWP'
    };

    if (assetType.value === 'VOUCHER') {
        const voucherNumber = document.getElementById('voucherNumber').value;
        const voucherPin = document.getElementById('voucherPin').value;
        if (!voucherNumber || !voucherPin) { alert('Enter voucher number and PIN'); return; }
        sourceDetails.voucher_number = voucherNumber;
        sourceDetails.voucher_pin = voucherPin;
    } else if (assetType.value === 'ACCOUNT') {
        const accountNumber = document.getElementById('accountNumber').value;
        if (!accountNumber) { alert('Enter account number'); return; }
        sourceDetails.account_number = accountNumber;
    } else if (assetType.value === 'MNO-WALLET') {
        const walletPhone = document.getElementById('walletPhone').value;
        if (!walletPhone) { alert('Enter wallet phone'); return; }
        sourceDetails.wallet_phone = walletPhone;
    }

    // Build destination_details based on delivery mode
    let destDetails = {
        institution: destInstitution.value,
        type: deliveryMode.value,
        currency: 'BWP'
    };

    if (deliveryMode.value === 'CASHOUT') {
        const smsPhone = document.getElementById('smsPhone').value;
        if (smsPhone) destDetails.beneficiary_phone = smsPhone;
    } else {
        const destAccount = document.getElementById('destAccount').value;
        if (!destAccount) { alert('Enter destination account number'); return; }
        destDetails.account_number = destAccount;
    }

    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMPOTENT_' + Date.now(),
        swap_type: deliveryMode.value,
        source_details: sourceDetails,
        destination_details: destDetails
    };

    executeBtn.disabled = true;
    executeBtn.textContent = '⏳ Processing...';
    resultDiv.className = 'result loading';
    resultDiv.innerHTML = 'Processing swap...';

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
            let html = `<strong>✅ Swap Successful!</strong><br><br>
                Reference: ${result.reference || 'N/A'}<br>
                Amount: <?= $currencySymbol ?> ${amount}<br>`;
            if (result.atm_code) html += `<br><strong>🏧 ATM Code:</strong> ${result.atm_code}`;
            html += `<br><br><details><summary>Details</summary><pre style="margin-top:8px;">${JSON.stringify(result, null, 2)}</pre></details>`;
            resultDiv.innerHTML = html;
            setTimeout(() => location.reload(), 3000);
        } else {
            resultDiv.className = 'result error';
            resultDiv.innerHTML = `<strong>❌ Swap Failed</strong><br><br>${result.message || result.error || 'Unknown error'}<br><br><details><summary>Details</summary><pre>${JSON.stringify(result, null, 2)}</pre></details>`;
        }
    } catch (error) {
        resultDiv.className = 'result error';
        resultDiv.innerHTML = `<strong>❌ Error</strong><br><br>${error.message}`;
    } finally {
        executeBtn.disabled = false;
        executeBtn.textContent = '🚀 Execute Swap';
        resultDiv.scrollIntoView({ behavior: 'smooth' });
    }
});

updateSummary();
</script>
</body>
</html>
