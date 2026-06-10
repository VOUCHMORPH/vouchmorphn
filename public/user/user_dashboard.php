<?php
// public/user/user_dashboard.php - SAME STYLE AS DEBUG VERSION
// Fully functional with wallet balance, transactions, and participants

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/Core/Config/LoadCountry.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;
use Core\Config\LoadCountry;

SessionManager::start();

if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$userPhone = $user['phone'] ?? '';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$userCountry = $user['country'] ?? 'Botswana';
$userFullName = $user['full_name'] ?? $user['username'] ?? 'User';

// ============================================================
// LOAD CONFIGURATION
// ============================================================

try {
    $config = LoadCountry::getConfig();
    $countryCode = $config['country'] ?? 'BW';
    $countryName = $config['country_settings'][$userCountry]['name'] ?? $userCountry;
    $currencySymbol = $config['currency_symbol'] ?? 'BWP';
} catch (Throwable $e) {
    error_log("Dashboard config error: " . $e->getMessage());
    $countryCode = 'BW';
    $countryName = $userCountry;
    $currencySymbol = 'BWP';
}

// ============================================================
// DATABASE CONNECTION
// ============================================================

try {
    $db = DBConnection::getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("Database connection failed.");
}

// ============================================================
// LOAD YAML DATA
// ============================================================

$baseConfigPath = __DIR__ . '/../../src/Core/Config';
$countryConfigPath = $baseConfigPath . '/Countries/' . $userCountry;
$participantsYamlPath = $countryConfigPath . '/participants.yaml';
$assetsYamlPath = $baseConfigPath . '/assets.yaml';

$filesExist = [
    'participants.yaml' => file_exists($participantsYamlPath),
    'assets.yaml' => file_exists($assetsYamlPath),
    'country_config' => is_dir($countryConfigPath)
];

function parseParticipantsYaml($content) {
    $participants = [];
    $lines = explode("\n", $content);
    $currentParticipant = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $participants[$currentParticipant] = [];
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

$parsedParticipants = [];
$participantsRawContent = '';
if ($filesExist['participants.yaml']) {
    $participantsRawContent = file_get_contents($participantsYamlPath);
    $parsedParticipants = parseParticipantsYaml($participantsRawContent);
}

// Get user's recent transactions
$recentTransactions = [];
try {
    $stmt = $db->prepare("
        SELECT transaction_id, type, amount, status, created_at, reference
        FROM transactions 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([':user_id' => $userId]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Transaction fetch error: " . $e->getMessage());
}

// Get wallet balance
$walletBalance = 0;
try {
    $stmt = $db->prepare("
        SELECT balance, held_balance 
        FROM wallets 
        WHERE user_id = :user_id AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $userId]);
    $wallet = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($wallet) {
        $walletBalance = $wallet['balance'] - ($wallet['held_balance'] ?? 0);
    }
} catch (Throwable $e) {
    error_log("Wallet fetch error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH | DASHBOARD</title>
    <style>
        body { background: #0a0a0a; color: #fff; font-family: monospace; padding: 20px; }
        pre { background: #1a1a1a; padding: 15px; overflow-x: auto; border-left: 3px solid #4CAF50; margin: 10px 0; }
        .error { color: #f44336; }
        .success { color: #4CAF50; }
        .section { margin-bottom: 30px; border-bottom: 1px solid #333; padding-bottom: 20px; }
        h2 { color: #FF9800; font-size: 18px; }
        h3 { color: #2196F3; font-size: 14px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #1a1a1a; }
        .balance-card {
            background: #1a1a1a;
            border-left: 3px solid #4CAF50;
            padding: 15px;
            margin-bottom: 20px;
        }
        .balance-amount {
            font-size: 2rem;
            color: #4CAF50;
        }
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .btn {
            background: #1a1a1a;
            border: 1px solid #333;
            padding: 8px 16px;
            color: #fff;
            text-decoration: none;
            font-family: monospace;
            cursor: pointer;
        }
        .btn:hover {
            border-color: #4CAF50;
            color: #4CAF50;
        }
        .status-completed { color: #4CAF50; }
        .status-pending { color: #FF9800; }
        .status-failed { color: #f44336; }
    </style>
</head>
<body>
    <h1>🔧 VOUCHMORPH DASHBOARD</h1>
    <p>User: <?= htmlspecialchars($userFullName) ?> | Phone: <?= htmlspecialchars($userPhone) ?> | Country: <?= htmlspecialchars($countryName) ?></p>
    
    <!-- Balance Card -->
    <div class="balance-card">
        <div>💰 AVAILABLE BALANCE</div>
        <div class="balance-amount"><?= number_format($walletBalance, 2) ?> <?= htmlspecialchars($currencySymbol) ?></div>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="swap.php" class="btn">🔄 NEW SWAP</a>
        <a href="cashout.php" class="btn">🏧 CASH OUT</a>
        <a href="deposit.php" class="btn">📥 DEPOSIT</a>
        <a href="history.php" class="btn">📜 HISTORY</a>
        <a href="logout.php" class="btn">🚪 LOGOUT</a>
    </div>
    
    <!-- FILE EXISTENCE (Debug info) -->
    <div class="section">
        <h2>📁 CONFIGURATION STATUS</h2>
        <table>
            <tr><th>File</th><th>Exists?</th></tr>
            <tr><td>participants.yaml</td><td class="<?= $filesExist['participants.yaml'] ? 'success' : 'error' ?>"><?= $filesExist['participants.yaml'] ? '✓ YES' : '✗ NO' ?></td></tr>
            <tr><td>assets.yaml</td><td class="<?= $filesExist['assets.yaml'] ? 'success' : 'error' ?>"><?= $filesExist['assets.yaml'] ? '✓ YES' : '✗ NO' ?></td></tr>
            <tr><td>Country folder</td><td class="<?= $filesExist['country_config'] ? 'success' : 'error' ?>"><?= $filesExist['country_config'] ? '✓ YES' : '✗ NO' ?></td></tr>
        </table>
    </div>
    
    <!-- RECENT TRANSACTIONS -->
    <div class="section">
        <h2>📋 RECENT TRANSACTIONS</h2>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTransactions)): ?>
                    <tr><td colspan="5" style="text-align: center;">No transactions yet</td></tr>
                <?php else: ?>
                    <?php foreach ($recentTransactions as $tx): ?>
                        <tr>
                            <td><?= htmlspecialchars($tx['reference'] ?? $tx['transaction_id']) ?></td>
                            <td><?= htmlspecialchars($tx['type'] ?? 'Swap') ?></td>
                            <td><?= number_format($tx['amount'], 2) ?> <?= htmlspecialchars($currencySymbol) ?></td>
                            <td class="status-<?= strtolower($tx['status'] ?? 'pending') ?>"><?= htmlspecialchars($tx['status'] ?? 'Pending') ?></td>
                            <td><?= date('Y-m-d H:i', strtotime($tx['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- PARSED PARTICIPANTS -->
    <div class="section">
        <h2>🏦 AVAILABLE INSTITUTIONS</h2>
        <?php if (empty($parsedParticipants)): ?>
            <p class="error">⚠ No participants parsed! Check YAML file.</p>
            <pre><?= htmlspecialchars(substr($participantsRawContent, 0, 500)) ?></pre>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Asset Types</th>
                        <th>Min Amount</th>
                        <th>Max Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsedParticipants as $code => $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($code) ?></td>
                            <td><?= htmlspecialchars($p['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['type'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(implode(', ', $p['asset_types'] ?? ['ACCOUNT'])) ?></td>
                            <td><?= htmlspecialchars($p['limits']['min_amount'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['limits']['max_amount'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- QUICK SWAP FORM -->
    <div class="section">
        <h2>🔄 QUICK SWAP</h2>
        <form action="swap.php" method="GET">
            <table>
                <tr>
                    <td>FROM:</td>
                    <td>
                        <select name="source" style="background:#1a1a1a; border:1px solid #333; color:#fff; padding:8px;">
                            <option value="">-- Select --</option>
                            <?php foreach ($parsedParticipants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>TO:</td>
                    <td>
                        <select name="destination" style="background:#1a1a1a; border:1px solid #333; color:#fff; padding:8px;">
                            <option value="">-- Select --</option>
                            <?php foreach ($parsedParticipants as $code => $p): ?>
                                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td>AMOUNT (<?= htmlspecialchars($currencySymbol) ?>):</td>
                    <td><input type="number" name="amount" step="0.01" style="background:#1a1a1a; border:1px solid #333; color:#fff; padding:8px;"></td>
                </tr>
                <tr>
                    <td colspan="2"><button type="submit" class="btn">GO →</button></td>
                </tr>
            </table>
        </form>
    </div>
</body>
</html>
