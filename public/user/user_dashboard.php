<?php
// public/user/user_dashboard.php - DEBUG VERSION
// This will show us what data is actually loading

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
// DEBUG: LOAD AND DISPLAY RAW YAML DATA
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
            $participants[$currentParticipant] = [];
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
        }
        
        // Match asset_types list
        if ($currentParticipant && preg_match('/^    asset_types:$/', $line)) {
            $participants[$currentParticipant]['asset_types'] = [];
            continue;
        }
        
        // Match items in asset_types list
        if ($currentParticipant && isset($participants[$currentParticipant]['asset_types']) && preg_match('/^      - (.+)$/', $line, $matches)) {
            $participants[$currentParticipant]['asset_types'][] = trim($matches[1]);
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH | DEBUG</title>
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
    </style>
</head>
<body>
    <h1>🔧 VOUCHMORPH DEBUG DASHBOARD</h1>
    <p>User: <?= htmlspecialchars($userFullName) ?> | Phone: <?= htmlspecialchars($userPhone) ?> | Country: <?= htmlspecialchars($userCountry) ?></p>
    
    <!-- FILE PATHS -->
    <div class="section">
        <h2>📁 FILE PATHS</h2>
        <pre><?php 
        echo "Base Config Path: " . $baseConfigPath . "\n";
        echo "Country Config Path: " . $countryConfigPath . "\n";
        echo "Participants YAML: " . $participantsYamlPath . "\n";
        echo "Assets YAML: " . $assetsYamlPath . "\n";
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
        <pre><?= htmlspecialchars(substr($participantsRawContent, 0, 2000)) ?></pre>
        <?php if (strlen($participantsRawContent) > 2000): ?>
            <p><em>... truncated (full file is <?= strlen($participantsRawContent) ?> bytes)</em></p>
        <?php endif; ?>
    </div>
    
    <!-- PARSED PARTICIPANTS -->
    <div class="section">
        <h2>🔍 PARSED PARTICIPANTS (from YAML)</h2>
        <table>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Type</th>
                <th>Asset Types</th>
                <th>Min Amount</th>
                <th>Max Amount</th>
                <th>Currency</th>
            </tr>
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
        </table>
        <?php if (empty($parsedParticipants)): ?>
            <p class="error">⚠ No participants parsed! Check YAML format.</p>
        <?php endif; ?>
    </div>
    
    <!-- SIMPLE TEST FORM -->
    <div class="section">
        <h2>🧪 SIMPLE TEST FORM</h2>
        <p>If participants load correctly above, this form should show them:</p>
        
        <div style="margin: 20px 0;">
            <label style="display:block; margin-bottom:5px;">Source Institution:</label>
            <select id="testSource" style="width:100%; padding:10px; background:#1a1a1a; color:#fff; border:1px solid #333;">
                <option value="">-- Select --</option>
            </select>
        </div>
        
        <div style="margin: 20px 0;">
            <label style="display:block; margin-bottom:5px;">Asset Type:</label>
            <select id="testAsset" style="width:100%; padding:10px; background:#1a1a1a; color:#fff; border:1px solid #333;">
                <option value="">-- Select --</option>
            </select>
        </div>
        
        <div id="testResult" style="margin-top: 20px; padding: 15px; background: #1a1a1a; border-left: 3px solid #FF9800;">
            <strong>Debug Info:</strong>
            <div id="debugOutput">Waiting for selection...</div>
        </div>
    </div>
    
    <script>
        // Parse participants from PHP to JavaScript
        const participants = <?php echo json_encode(array_values($parsedParticipants)); ?>;
        const assets = <?php echo json_encode([]); ?>; // We'll add assets later
        
        console.log('Participants loaded:', participants);
        
        // Populate source dropdown
        const sourceSelect = document.getElementById('testSource');
        if (sourceSelect && participants.length > 0) {
            participants.forEach(p => {
                const option = document.createElement('option');
                option.value = p.code;
                option.textContent = `${p.name} (${p.type || 'BANK'})`;
                option.dataset.assetTypes = JSON.stringify(p.asset_types || []);
                option.dataset.minAmount = p.limits?.min_amount || 10;
                option.dataset.maxAmount = p.limits?.max_amount || 500000;
                sourceSelect.appendChild(option);
            });
            
            sourceSelect.onchange = function() {
                const selected = sourceSelect.options[sourceSelect.selectedIndex];
                const assetTypes = JSON.parse(selected.dataset.assetTypes || '[]');
                const minAmount = selected.dataset.minAmount;
                const maxAmount = selected.dataset.maxAmount;
                
                const assetSelect = document.getElementById('testAsset');
                assetSelect.innerHTML = '<option value="">-- Select --</option>';
                
                assetTypes.forEach(asset => {
                    const opt = document.createElement('option');
                    opt.value = asset;
                    opt.textContent = asset;
                    assetSelect.appendChild(opt);
                });
                
                document.getElementById('debugOutput').innerHTML = `
                    <strong>Selected:</strong> ${selected.textContent}<br>
                    <strong>Asset Types:</strong> ${assetTypes.join(', ') || 'None'}<br>
                    <strong>Amount Range:</strong> ${minAmount} - ${maxAmount} BWP
                `;
            };
        } else {
            document.getElementById('debugOutput').innerHTML = '<span class="error">No participants found! Check YAML file and parser.</span>';
        }
    </script>
</body>
</html>
