<?php
// public/user/user_dashboard.php - ENHANCED DEBUG VERSION
// Tests both YAML config AND database connection

ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../../src/Application/Utils/SessionManager.php';
require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../src/bootstrap.php';

use Application\Utils\SessionManager;
use Core\Database\DBConnection;

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
// TEST DATABASE CONNECTION
// ============================================================
$dbStatus = [
    'connected' => false,
    'error' => null,
    'tables' => []
];

try {
    $db = DBConnection::getConnection();
    
    if ($db) {
        $dbStatus['connected'] = true;
        
        // Check which tables exist
        $tables = ['users', 'transactions', 'wallets', 'swap_requests', 'hold_transactions'];
        foreach ($tables as $table) {
            try {
                $stmt = $db->query("SELECT 1 FROM {$table} LIMIT 1");
                $dbStatus['tables'][$table] = true;
            } catch (Exception $e) {
                $dbStatus['tables'][$table] = false;
            }
        }
        
        // Get user count
        $stmt = $db->query("SELECT COUNT(*) FROM users");
        $dbStatus['user_count'] = $stmt->fetchColumn();
        
    } else {
        $dbStatus['error'] = "DBConnection::getConnection() returned null";
    }
} catch (Exception $e) {
    $dbStatus['error'] = $e->getMessage();
}

// ============================================================
// LOAD AND DISPLAY RAW YAML DATA
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

$parsedParticipants = $filesExist['participants.yaml'] ? parseParticipantsYaml($participantsRawContent) : [];

// Get recent transactions from database (if connected)
$recentTransactions = [];
if ($dbStatus['connected'] && isset($db)) {
    try {
        $stmt = $db->prepare("
            SELECT transaction_id, type, amount, status, created_at, reference
            FROM transactions 
            WHERE user_id = :user_id 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([':user_id' => $userId]);
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Ignore - just won't show transactions
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH | DEBUG DASHBOARD</title>
    <style>
        body { background: #0a0a0a; color: #fff; font-family: monospace; padding: 20px; }
        pre { background: #1a1a1a; padding: 15px; overflow-x: auto; border-left: 3px solid #4CAF50; margin: 10px 0; font-size: 11px; }
        .error { color: #f44336; }
        .success { color: #4CAF50; }
        .warning { color: #FF9800; }
        .section { margin-bottom: 30px; border-bottom: 1px solid #333; padding-bottom: 20px; }
        h2 { color: #FF9800; font-size: 18px; }
        h3 { color: #2196F3; font-size: 14px; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #333; padding: 8px; text-align: left; }
        th { background: #1a1a1a; }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        .status-online { background: #4CAF50; color: #fff; }
        .status-offline { background: #f44336; color: #fff; }
    </style>
</head>
<body>
    <h1>🔧 VOUCHMORPH DEBUG DASHBOARD</h1>
    <p>User: <?= htmlspecialchars($userFullName) ?> | Phone: <?= htmlspecialchars($userPhone) ?> | Country: <?= htmlspecialchars($userCountry) ?> | User ID: <?= htmlspecialchars($userId) ?></p>
    
    <!-- DATABASE STATUS -->
    <div class="section">
        <h2>🗄️ DATABASE CONNECTION STATUS</h2>
        <div>
            <strong>Status:</strong> 
            <?php if ($dbStatus['connected']): ?>
                <span class="status-badge status-online">✓ CONNECTED</span>
            <?php else: ?>
                <span class="status-badge status-offline">✗ DISCONNECTED</span>
            <?php endif; ?>
        </div>
        
        <?php if ($dbStatus['error']): ?>
            <div class="error">Error: <?= htmlspecialchars($dbStatus['error']) ?></div>
        <?php endif; ?>
        
        <?php if ($dbStatus['connected']): ?>
            <div class="success">User count: <?= $dbStatus['user_count'] ?? 'N/A' ?></div>
            
            <h3>Table Existence:</h3>
            <table>
                <thead><tr><th>Table</th><th>Exists?</th></tr></thead>
                <tbody>
                    <?php foreach ($dbStatus['tables'] as $table => $exists): ?>
                        <tr>
                            <td><?= htmlspecialchars($table) ?></td>
                            <td class="<?= $exists ? 'success' : 'error' ?>"><?= $exists ? '✓ YES' : '✗ NO' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- RECENT TRANSACTIONS -->
    <?php if (!empty($recentTransactions)): ?>
    <div class="section">
        <h2>📋 RECENT TRANSACTIONS</h2>
        <table>
            <thead><tr><th>ID</th><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
                <?php foreach ($recentTransactions as $tx): ?>
                    <tr>
                        <td><?= htmlspecialchars($tx['transaction_id'] ?? $tx['reference']) ?></td>
                        <td><?= htmlspecialchars($tx['type'] ?? 'N/A') ?></td>
                        <td><?= number_format($tx['amount'] ?? 0, 2) ?> BWP</td>
                        <td class="<?= ($tx['status'] ?? '') === 'completed' ? 'success' : 'warning' ?>"><?= htmlspecialchars($tx['status'] ?? 'N/A') ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($tx['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- FILE PATHS -->
    <div class="section">
        <h2>📁 FILE PATHS</h2>
        <pre><?php 
        echo "Base Config Path: " . $baseConfigPath . "\n";
        echo "Country Config Path: " . $countryConfigPath . "\n";
        echo "Participants YAML: " . $participantsYamlPath . "\n";
        echo "Assets YAML: " . $assetsYamlPath . "\n";
        echo "\nDOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
        echo "SCRIPT_FILENAME: " . $_SERVER['SCRIPT_FILENAME'] . "\n";
        ?></pre>
    </div>
    
    <!-- FILE EXISTENCE -->
    <div class="section">
        <h2>📁 FILE EXISTENCE</h2>
        <table>
            <tr><th>File</th><th>Exists?</th></tr>
            <tr><td>participants.yaml</td><td class="<?= $filesExist['participants.yaml'] ? 'success' : 'error' ?>"><?= $filesExist['participants.yaml'] ? '✓ YES' : '✗ NO' ?></td></tr>
            <tr><td>assets.yaml</td><td class="<?= $filesExist['assets.yaml'] ? 'success' : 'error' ?>"><?= $filesExist['assets.yaml'] ? '✓ YES' : '✗ NO' ?></td></tr>
            <tr><td>Country folder</td><td class="<?= $filesExist['country_config'] ? 'success' : 'error' ?>"><?= $filesExist['country_config'] ? '✓ YES' : '✗ NO' ?></td></tr>
        </table>
    </div>
    
    <!-- RAW PARTICIPANTS YAML -->
    <div class="section">
        <h2>📄 RAW participants.yaml CONTENT</h2>
        <pre><?= htmlspecialchars(substr($participantsRawContent, 0, 3000)) ?></pre>
        <?php if (strlen($participantsRawContent) > 3000): ?>
            <p><em>... truncated (full file is <?= strlen($participantsRawContent) ?> bytes)</em></p>
        <?php endif; ?>
    </div>
    
    <!-- PARSED PARTICIPANTS -->
    <div class="section">
        <h2>🔍 PARSED PARTICIPANTS (from YAML)</h2>
        <?php if (empty($parsedParticipants)): ?>
            <p class="error">⚠ No participants parsed! Check YAML format.</p>
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
                        <th>Currency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsedParticipants as $code => $p): ?>
                        <tr>
                            <td><?= htmlspecialchars($code) ?></td>
                            <td><?= htmlspecialchars($p['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['type'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars(implode(', ', $p['asset_types'] ?? [])) ?></td>
                            <td><?= htmlspecialchars($p['limits']['min_amount'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['limits']['max_amount'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($p['limits']['currency'] ?? 'N/A') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- ENVIRONMENT VARIABLES (safe ones only) -->
    <div class="section">
        <h2>🌐 ENVIRONMENT (Safe Values)</h2>
        <table>
            <tr><th>Variable</th><th>Value</th></tr>
            <tr><td>APP_ENV</td><td><?= htmlspecialchars(getenv('APP_ENV') ?: 'not set') ?></td></tr>
            <tr><td>VM_COUNTRY</td><td><?= htmlspecialchars(getenv('VM_COUNTRY') ?: 'not set') ?></td></tr>
            <tr><td>DATABASE_URL</td><td><?= getenv('DATABASE_URL') ? '***SET***' : 'NOT SET' ?></td></tr>
        </table>
    </div>
</body>
</html>
