<?php
// public/user/user_dashboard.php - FIXED VERSION
// Using DBConnection and proper config loading

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
// DATABASE CONNECTION - Using DBConnection
// ============================================================

try {
    $db = DBConnection::getConnection();
    
    if (!$db) {
        throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
    }
    
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("[USER DASHBOARD] Database connected successfully via DBConnection");
    
} catch (Throwable $e) {
    error_log("USER DASHBOARD DB ERROR: " . $e->getMessage());
    die("System initialisation failed. Please check database configuration.");
}

// ============================================================
// LOAD AND DISPLAY RAW YAML DATA (for debugging)
// ============================================================

$baseConfigPath = __DIR__ . '/../../src/Core/Config';
$countryConfigPath = $baseConfigPath . '/Countries/' . $userCountry;

// Check if files exist
$participantsYamlPath = $countryConfigPath . '/participants.yaml';
$assetsYamlPath = $baseConfigPath . '/assets.yaml';

$filesExist = [
    'participants.yaml' => file_exists($participantsYamlPath),
    'assets.yaml' => file_exists($assetsYamlPath),
    'country_config' => is_dir($countryConfigPath)
];

// Read raw YAML content
$participantsRawContent = $filesExist['participants.yaml'] ? file_get_contents($participantsYamlPath) : 'FILE NOT FOUND';
$assetsRawContent = $filesExist['assets.yaml'] ? file_get_contents($assetsYamlPath) : 'FILE NOT FOUND';

// Simple YAML parser for participants
function parseParticipantsYaml($content) {
    $participants = [];
    $lines = explode("\n", $content);
    $currentParticipant = null;
    
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (empty($line) || $line[0] === '#') continue;
        
        // Match participant name (indented with 2 spaces)
        if (preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
            $currentParticipant = $matches[1];
            $participants[$currentParticipant] = ['code' => $currentParticipant];
            continue;
        }
        
        // Match properties (indented with 4 spaces)
        if ($currentParticipant && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
            $key = $matches[1];
            $value = trim($matches[2]);
            // Remove quotes
            if (preg_match('/^"(.+)"$/', $value, $q)) $value = $q[1];
            if (preg_match("/^'(.+)'$/", $value, $q)) $value = $q[1];
            $participants[$currentParticipant][$key] = $value;
            continue;
        }
        
        // Match asset_types list
        if ($currentParticipant && preg_match('/^    asset_types:$/', $line)) {
            $participants[$currentParticipant]['asset_types'] = [];
            continue;
        }
        
        // Match items in asset_types list
        if ($currentParticipant && isset($participants[$currentParticipant]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['asset_types'][] = trim($matches[1]);
            continue;
        }
        
        // Match limits
        if ($currentParticipant && preg_match('/^    limits:$/', $line)) {
            $participants[$currentParticipant]['limits'] = [];
            continue;
        }
        
        // Match limits properties
        if ($currentParticipant && isset($participants[$currentParticipant]['limits']) && preg_match('/^      ([a-z_]+): (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['limits'][$matches[1]] = trim($matches[2]);
        }
    }
    
    return $participants;
}

$parsedParticipants = $filesExist['participants.yaml'] ? parseParticipantsYaml($participantsRawContent) : [];

// Get user's recent transactions from database
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
    <title>VOUCHMORPH | Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #0a0a0a;
            color: #fff;
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 100%);
            border-radius: 12px;
            margin-bottom: 30px;
            border: 1px solid rgba(0, 240, 255, 0.2);
        }
        
        .logo h1 {
            font-size: 1.5rem;
            background: linear-gradient(135deg, #FFFFFF 0%, #00F0FF 40%, #B000FF 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .user-info {
            text-align: right;
        }
        
        .user-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #00F0FF;
        }
        
        .user-phone {
            font-size: 0.8rem;
            color: #A0A0B0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #1a1a2e;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            transition: transform 0.2s, border-color 0.2s;
        }
        
        .stat-card:hover {
            border-color: #00F0FF;
            transform: translateY(-2px);
        }
        
        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #A0A0B0;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #00F0FF;
        }
        
        .stat-currency {
            font-size: 0.8rem;
            color: #A0A0B0;
        }
        
        .section {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #00F0FF;
            display: inline-block;
        }
        
        .participants-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .participant-card {
            background: #0f0f23;
            padding: 15px;
            border-radius: 8px;
            border-left: 3px solid #00F0FF;
        }
        
        .participant-name {
            font-weight: 600;
            color: #00F0FF;
            margin-bottom: 5px;
        }
        
        .participant-type {
            font-size: 0.7rem;
            color: #A0A0B0;
            text-transform: uppercase;
        }
        
        .participant-assets {
            font-size: 0.75rem;
            color: #fff;
            margin-top: 8px;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transaction-table th,
        .transaction-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .transaction-table th {
            color: #00F0FF;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .status-completed {
            color: #4CAF50;
        }
        
        .status-pending {
            color: #FF9800;
        }
        
        .status-failed {
            color: #f44336;
        }
        
        .btn {
            background: linear-gradient(135deg, #00F0FF 0%, #B000FF 100%);
            color: #050505;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: transform 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
        }
        
        .logout-btn {
            background: transparent;
            border: 1px solid #f44336;
            color: #f44336;
        }
        
        .logout-btn:hover {
            background: #f44336;
            color: #fff;
        }
        
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }
        
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 15px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="logo">
                <h1>VOUCHMORPH™</h1>
                <div style="font-size: 0.7rem; color: #A0A0B0;">Interoperability Platform</div>
            </div>
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($userFullName) ?></div>
                <div class="user-phone"><?= htmlspecialchars($userPhone) ?></div>
                <div class="user-phone" style="font-size: 0.7rem;"><?= htmlspecialchars($countryName) ?></div>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Available Balance</div>
                <div class="stat-value"><?= number_format($walletBalance, 2) ?> <span class="stat-currency"><?= htmlspecialchars($currencySymbol) ?></span></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Recent Transactions</div>
                <div class="stat-value"><?= count($recentTransactions) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Available Institutions</div>
                <div class="stat-value"><?= count($parsedParticipants) ?></div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="swap.php" class="btn">💰 New Swap</a>
            <a href="cashout.php" class="btn">🏧 Cash Out</a>
            <a href="deposit.php" class="btn">📥 Deposit</a>
            <a href="history.php" class="btn">📜 Transaction History</a>
            <a href="logout.php" class="btn logout-btn">🚪 Logout</a>
        </div>
        
        <!-- Recent Transactions -->
        <div class="section">
            <div class="section-title">RECENT TRANSACTIONS</div>
            <table class="transaction-table">
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
                        <tr>
                            <td colspan="5" style="text-align: center; color: #A0A0B0;">No transactions yet</td>
                        </tr>
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
        
        <!-- Available Institutions -->
        <div class="section">
            <div class="section-title">AVAILABLE INSTITUTIONS</div>
            <?php if (empty($parsedParticipants)): ?>
                <p style="color: #A0A0B0;">No institutions configured. Please check configuration files.</p>
                <details style="margin-top: 15px;">
                    <summary style="color: #FF9800; cursor: pointer;">Debug Info (click to expand)</summary>
                    <pre style="margin-top: 10px; padding: 10px; background: #0f0f23; overflow-x: auto; font-size: 0.7rem;">
<?php 
echo "Config Path: " . $countryConfigPath . "\n";
echo "Participants YAML exists: " . ($filesExist['participants.yaml'] ? 'Yes' : 'No') . "\n";
if (!$filesExist['participants.yaml']) {
    echo "\nLooking for file at: " . $participantsYamlPath . "\n";
}
?>
                    </pre>
                </details>
            <?php else: ?>
                <div class="participants-grid">
                    <?php foreach ($parsedParticipants as $code => $p): ?>
                        <div class="participant-card">
                            <div class="participant-name"><?= htmlspecialchars($p['name'] ?? $code) ?></div>
                            <div class="participant-type"><?= htmlspecialchars($p['type'] ?? 'FINANCIAL_INSTITUTION') ?></div>
                            <div class="participant-assets">
                                <strong>Assets:</strong> <?= htmlspecialchars(implode(', ', $p['asset_types'] ?? ['ACCOUNT'])) ?>
                            </div>
                            <?php if (isset($p['limits'])): ?>
                                <div class="participant-assets" style="font-size: 0.7rem;">
                                    Limits: <?= htmlspecialchars($p['limits']['min_amount'] ?? '0') ?> - <?= htmlspecialchars($p['limits']['max_amount'] ?? '∞') ?> <?= htmlspecialchars($p['limits']['currency'] ?? 'BWP') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Swap Form -->
        <div class="section">
            <div class="section-title">QUICK SWAP</div>
            <form action="swap.php" method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.7rem; margin-bottom: 5px;">From</label>
                    <select name="source" style="width: 100%; padding: 10px; background: #0f0f23; border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 6px;">
                        <option value="">Select Institution</option>
                        <?php foreach ($parsedParticipants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.7rem; margin-bottom: 5px;">To</label>
                    <select name="destination" style="width: 100%; padding: 10px; background: #0f0f23; border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 6px;">
                        <option value="">Select Institution</option>
                        <?php foreach ($parsedParticipants as $code => $p): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($p['name'] ?? $code) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="width: 150px;">
                    <label style="display: block; font-size: 0.7rem; margin-bottom: 5px;">Amount (<?= htmlspecialchars($currencySymbol) ?>)</label>
                    <input type="number" name="amount" step="0.01" style="width: 100%; padding: 10px; background: #0f0f23; border: 1px solid rgba(255,255,255,0.1); color: #fff; border-radius: 6px;">
                </div>
                <div>
                    <button type="submit" class="btn" style="padding: 10px 20px;">GO →</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
