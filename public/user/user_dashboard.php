<?php
// --- SESSION INIT ---
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

// --- DEPENDENCIES ---
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';
require_once __DIR__ . '/../../src/Domain/Services/SwapService.php';
require_once __DIR__ . '/../../src/Infrastructure/Banks/GenericBankClient.php';

use Core\Database\DBConnection;
use Core\Config\LoadCountry;
use Domain\Services\SwapService;

// --- Load Country & Config ---
$config = LoadCountry::getConfig();
$country = $config['country'] ?? 'Botswana';
$currencySymbol = $config['currency_symbol'] ?? 'BWP';

try {
    $swapDB = DBConnection::getConnection();
    if (!$swapDB) {
        throw new Exception("Database connection failed");
    }
    error_log("[DASHBOARD] Database connected successfully");
} catch (Exception $e) {
    error_log("[DASHBOARD] DB Error: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Load participants from YAML
$baseConfigPath = __DIR__ . '/../../src/Core/Config';
$countryConfigPath = $baseConfigPath . '/Countries/' . $country;
$participantsYamlPath = $countryConfigPath . '/participants.yaml';

function parseParticipantsYaml($content) {
    $participants = [];
    $lines = explode("\n", $content);
    $currentParticipant = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $participants[$currentParticipant] = ['code' => $currentParticipant];
            continue;
        }
        
        if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
            $participants[$currentParticipant][$key] = $value;
            continue;
        }
        
        if ($currentParticipant && preg_match('/^    asset_types:$/', $line)) {
            $participants[$currentParticipant]['asset_types'] = [];
            continue;
        }
        
        if ($currentParticipant && isset($participants[$currentParticipant]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['asset_types'][] = trim($matches[1]);
            continue;
        }
        
        if ($currentParticipant && preg_match('/^    limits:$/', $line)) {
            $participants[$currentParticipant]['limits'] = [];
            continue;
        }
        
        if ($currentParticipant && isset($participants[$currentParticipant]['limits']) && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['limits'][$matches[1]] = trim($matches[2]);
        }
    }
    
    return $participants;
}

$participants = [];
if (file_exists($participantsYamlPath)) {
    $content = file_get_contents($participantsYamlPath);
    $participants = parseParticipantsYaml($content);
}

// Get wallet balance
$walletBalance = 0;
try {
    $stmt = $swapDB->prepare("SELECT balance, held_balance FROM wallets WHERE user_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($wallet) {
        $walletBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
    }
} catch (Exception $e) {
    error_log("Wallet fetch error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VouchMorph | Swap Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --primary-navy: #0A2463;
    --primary-gold: #B8860B;
    --primary-slate: #2D3748;
    --secondary-steel: #4A5568;
    --light-gray: #F7FAFC;
    --border-gray: #E2E8F0;
    --success-green: #38A169;
    --error-red: #E53E3E;
    --warning-amber: #D69E2E;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%);
    color: var(--primary-slate);
    line-height: 1.6;
    min-height: 100vh;
    padding: 20px;
}

.dashboard-container {
    max-width: 1200px;
    margin: 0 auto;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    overflow: hidden;
}

.dashboard-header {
    background: var(--primary-navy);
    color: white;
    padding: 24px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.header-left h1 { font-size: 24px; font-weight: 600; margin-bottom: 4px; }
.header-left .subtitle { font-size: 13px; opacity: 0.8; }

.user-info span { color: var(--primary-gold); font-weight: 600; }
.logout-btn {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 8px 20px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.logout-btn:hover { background: rgba(255,255,255,0.1); border-color: var(--primary-gold); }

.balance-card {
    background: linear-gradient(135deg, #1a1a2e 0%, #0f0f23 100%);
    margin: 20px 32px;
    padding: 20px;
    border-radius: 12px;
    border: 1px solid var(--primary-gold);
    text-align: center;
}
.balance-amount { font-size: 32px; font-weight: 700; color: var(--primary-gold); }

.swap-tabs {
    display: flex;
    gap: 0;
    margin: 0 32px;
    border-bottom: 2px solid var(--border-gray);
}
.tab-btn {
    flex: 1;
    padding: 14px 24px;
    background: none;
    border: none;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    color: var(--secondary-steel);
    border-bottom: 3px solid transparent;
}
.tab-btn.active {
    color: var(--primary-navy);
    border-bottom-color: var(--primary-gold);
}

.form-container {
    padding: 32px;
    display: none;
}
.form-container.active { display: block; }

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 24px;
    margin-bottom: 24px;
}
.form-group.full-width { grid-column: span 2; }
.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--secondary-steel);
    margin-bottom: 8px;
    text-transform: uppercase;
}
.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-gray);
    border-radius: 8px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
}
.form-control:focus { outline: none; border-color: var(--primary-navy); }

.dynamic-fields { margin-top: 16px; }

.source-group, .destination-group {
    background: var(--light-gray);
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 24px;
}
.source-group h4, .destination-group h4 {
    font-size: 14px;
    margin-bottom: 16px;
    color: var(--primary-navy);
}

.execute-btn {
    background: var(--primary-navy);
    color: white;
    border: none;
    padding: 14px 32px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    width: 100%;
    transition: all 0.2s;
}
.execute-btn:hover { background: #0A1E4D; transform: translateY(-1px); }

.result-panel {
    margin: 0 32px 32px;
    border: 1px solid var(--border-gray);
    border-radius: 8px;
    overflow: hidden;
    display: none;
}
.result-panel.active { display: block; }
.result-header {
    background: var(--light-gray);
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-gray);
}
.result-content { padding: 20px; max-height: 400px; overflow-y: auto; }
.result-content pre { background: var(--light-gray); padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; }

.dashboard-footer {
    padding: 16px 32px;
    background: var(--light-gray);
    border-top: 1px solid var(--border-gray);
    font-size: 12px;
    text-align: center;
}

@media (max-width: 768px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full-width { grid-column: span 1; }
    .swap-tabs { margin: 0 16px; }
    .form-container, .balance-card { margin: 16px; }
}
</style>
</head>
<body>

<div class="dashboard-container">
    <div class="dashboard-header">
        <div class="header-left">
            <h1>VouchMorph</h1>
            <div class="subtitle">Swap Orchestration Platform</div>
        </div>
        <div class="user-info">
            User: <span><?= $loggedPhone ?></span>
            <form action="logout.php" method="post" style="display: inline; margin-left: 15px;">
                <button type="submit" class="logout-btn">Logout</button>
            </form>
        </div>
    </div>

    <div class="balance-card">
        <div style="font-size: 14px; color: #A0A0B0;">Available Balance</div>
        <div class="balance-amount"><?= number_format($walletBalance, 2) ?> <?= htmlspecialchars($currencySymbol) ?></div>
    </div>

    <div class="swap-tabs">
        <button class="tab-btn active" data-tab="single">Single Source Swap</button>
        <button class="tab-btn" data-tab="multi">Multi-Source Swap</button>
    </div>

    <!-- SINGLE SOURCE SWAP -->
    <div id="singleSwap" class="form-container active">
        <form id="singleSwapForm">
            <div class="source-group">
                <h4>📤 SOURCE</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Institution</label>
                        <select name="source_institution" class="form-control" required>
                            <option value="">Select Institution</option>
                            <?php foreach ($participants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Asset Type</label>
                        <select name="asset_type" class="form-control" required>
                            <option value="ACCOUNT">Account</option>
                            <option value="VOUCHER">Voucher</option>
                            <option value="E-WALLET">E-Wallet</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Identifier (Account/Voucher/Wallet)</label>
                        <input type="text" name="identifier" class="form-control" placeholder="Account number or Voucher number or Phone" required>
                    </div>
                    <div class="form-group">
                        <label>PIN (if applicable)</label>
                        <input type="password" name="pin" class="form-control" placeholder="PIN code">
                    </div>
                </div>
            </div>

            <div class="destination-group">
                <h4>📥 DESTINATION</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Institution</label>
                        <select name="dest_institution" class="form-control" required>
                            <option value="">Select Institution</option>
                            <?php foreach ($participants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Destination Type</label>
                        <select name="dest_type" class="form-control" required>
                            <option value="ATM_CASHOUT">ATM Cashout</option>
                            <option value="DEPOSIT">Bank Deposit</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Beneficiary / Account Number</label>
                        <input type="text" name="beneficiary" class="form-control" placeholder="Phone for cashout or Account number for deposit" required>
                    </div>
                </div>
            </div>

            <div class="form-group full-width">
                <label>Amount (<?= htmlspecialchars($currencySymbol) ?>)</label>
                <input type="number" name="amount" step="0.01" class="form-control" placeholder="0.00" required>
            </div>

            <button type="submit" class="execute-btn">Execute Single Swap →</button>
        </form>
    </div>

    <!-- MULTI-SOURCE SWAP -->
    <div id="multiSwap" class="form-container">
        <form id="multiSwapForm">
            <div class="source-group">
                <h4>📤 SOURCES (Multiple)</h4>
                <div id="multiSources">
                    <div class="source-row" style="display: grid; grid-template-columns: 2fr 2fr 1.5fr 1fr; gap: 10px; margin-bottom: 10px;">
                        <select name="source_institution[]" class="form-control" placeholder="Institution">
                            <option value="">Select</option>
                            <?php foreach ($participants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="asset_type[]" class="form-control">
                            <option value="ACCOUNT">Account</option>
                            <option value="VOUCHER">Voucher</option>
                            <option value="E-WALLET">E-Wallet</option>
                        </select>
                        <input type="text" name="identifier[]" class="form-control" placeholder="Identifier">
                        <input type="number" name="amount[]" step="0.01" class="form-control" placeholder="Amount">
                    </div>
                </div>
                <button type="button" id="addSourceBtn" style="margin-top: 10px; padding: 8px 16px; background: var(--light-gray); border: 1px solid var(--border-gray); border-radius: 6px; cursor: pointer;">+ Add Another Source</button>
            </div>

            <div class="destination-group">
                <h4>📥 DESTINATION</h4>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Institution</label>
                        <select name="dest_institution" class="form-control" required>
                            <option value="">Select Institution</option>
                            <?php foreach ($participants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Destination Type</label>
                        <select name="dest_type" class="form-control" required>
                            <option value="ATM_CASHOUT">ATM Cashout</option>
                            <option value="DEPOSIT">Bank Deposit</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Beneficiary / Account Number</label>
                        <input type="text" name="beneficiary" class="form-control" placeholder="Phone for cashout or Account number for deposit" required>
                    </div>
                </div>
            </div>

            <div class="form-group full-width">
                <label>Distribution Strategy</label>
                <select name="strategy" class="form-control">
                    <option value="drain_smallest">Drain Smallest First</option>
                    <option value="drain_largest">Drain Largest First</option>
                    <option value="proportional">Proportional</option>
                </select>
            </div>

            <button type="submit" class="execute-btn">Execute Multi-Source Swap →</button>
        </form>
    </div>

    <div id="resultPanel" class="result-panel">
        <div class="result-header">
            <strong>Transaction Result</strong>
            <span id="resultStatus"></span>
        </div>
        <div class="result-content" id="resultContent">
            <pre id="resultJson">Waiting for transaction...</pre>
        </div>
    </div>

    <div class="dashboard-footer">
        <div>VouchMorph™ | <?= htmlspecialchars(strtoupper($country)) ?> | Interoperability Platform</div>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('singleSwap').classList.remove('active');
        document.getElementById('multiSwap').classList.remove('active');
        document.getElementById(btn.dataset.tab + 'Swap').classList.add('active');
    });
});

// Add source row for multi-source
document.getElementById('addSourceBtn')?.addEventListener('click', () => {
    const container = document.getElementById('multiSources');
    const newRow = document.createElement('div');
    newRow.className = 'source-row';
    newRow.style.display = 'grid';
    newRow.style.gridTemplateColumns = '2fr 2fr 1.5fr 1fr';
    newRow.style.gap = '10px';
    newRow.style.marginBottom = '10px';
    newRow.innerHTML = `
        <select name="source_institution[]" class="form-control">
            <option value="">Select</option>
            <?php foreach ($participants as $code => $p): ?>
                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="asset_type[]" class="form-control">
            <option value="ACCOUNT">Account</option>
            <option value="VOUCHER">Voucher</option>
            <option value="E-WALLET">E-Wallet</option>
        </select>
        <input type="text" name="identifier[]" class="form-control" placeholder="Identifier">
        <input type="number" name="amount[]" step="0.01" class="form-control" placeholder="Amount">
        <button type="button" class="removeSourceBtn" style="width: auto; background: #f44336; color: white; border: none; border-radius: 6px; cursor: pointer;">✗</button>
    `;
    newRow.querySelector('.removeSourceBtn')?.addEventListener('click', () => newRow.remove());
    container.appendChild(newRow);
});

// Single Swap Submission
document.getElementById('singleSwapForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const resultPanel = document.getElementById('resultPanel');
    const resultContent = document.getElementById('resultContent');
    const resultStatus = document.getElementById('resultStatus');
    
    resultPanel.classList.add('active');
    resultStatus.innerHTML = 'Processing...';
    resultContent.innerHTML = '<pre>Processing single swap...</pre>';
    
    const payload = {
        reference: 'SWAP_' + Date.now(),
        idempotency_key: 'IDEMPOTENT_' + Date.now(),
        swap_type: 'CASHOUT',
        source_details: {
            institution: form.source_institution.value,
            asset_type: form.asset_type.value,
            identifier: form.identifier.value,
            pin: form.pin.value,
            amount: parseFloat(form.amount.value),
            currency: 'BWP'
        },
        destination_details: {
            institution: form.dest_institution.value,
            type: form.dest_type.value,
            beneficiary: form.beneficiary.value,
            currency: 'BWP'
        }
    };
    
    try {
        const response = await fetch('/api/v1/swap/execute.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        resultStatus.innerHTML = result.status === 'success' ? '✅ Success' : '❌ Failed';
        resultContent.innerHTML = `<pre>${JSON.stringify(result, null, 2)}</pre>`;
    } catch (error) {
        resultStatus.innerHTML = '❌ Error';
        resultContent.innerHTML = `<pre>Error: ${error.message}</pre>`;
    }
});

// Multi-Swap Submission
document.getElementById('multiSwapForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const resultPanel = document.getElementById('resultPanel');
    const resultContent = document.getElementById('resultContent');
    const resultStatus = document.getElementById('resultStatus');
    
    resultPanel.classList.add('active');
    resultStatus.innerHTML = 'Processing...';
    resultContent.innerHTML = '<pre>Processing multi-source swap...</pre>';
    
    const sources = [];
    const institutions = form.querySelectorAll('select[name="source_institution[]"]');
    const assetTypes = form.querySelectorAll('select[name="asset_type[]"]');
    const identifiers = form.querySelectorAll('input[name="identifier[]"]');
    const amounts = form.querySelectorAll('input[name="amount[]"]');
    
    for (let i = 0; i < institutions.length; i++) {
        if (institutions[i].value && identifiers[i].value && amounts[i].value) {
            sources.push({
                institution: institutions[i].value,
                asset_type: assetTypes[i].value,
                identifier: identifiers[i].value,
                requested_amount: parseFloat(amounts[i].value),
                currency: 'BWP'
            });
        }
    }
    
    const payload = {
        reference: 'MULTI_SWAP_' + Date.now(),
        idempotency_key: 'IDEMPOTENT_MULTI_' + Date.now(),
        swap_type: 'MULTI_SOURCE',
        is_multi_source: true,
        distribution_strategy: form.strategy.value,
        sources: sources,
        destination: {
            institution: form.dest_institution.value,
            type: form.dest_type.value,
            target_amount: sources.reduce((sum, s) => sum + s.requested_amount, 0),
            currency: 'BWP',
            beneficiary: form.beneficiary.value
        }
    };
    
    try {
        const response = await fetch('/api/v1/swap/execute.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const result = await response.json();
        resultStatus.innerHTML = result.status === 'success' ? '✅ Success' : '❌ Failed';
        resultContent.innerHTML = `<pre>${JSON.stringify(result, null, 2)}</pre>`;
    } catch (error) {
        resultStatus.innerHTML = '❌ Error';
        resultContent.innerHTML = `<pre>Error: ${error.message}</pre>`;
    }
});
</script>
</body>
</html>
