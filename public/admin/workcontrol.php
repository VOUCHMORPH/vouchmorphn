<?php
/**
 * VouchMorph System Introspection & Diagnostics Center
 * Complete working version with API Key Testing & Environment Viewer
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));

// ============================================================
// COMPOSER AUTOLOADER
// ============================================================
require_once PROJECT_ROOT . '/vendor/autoload.php';

// ============================================================
// USE STATEMENTS
// ============================================================
use Core\Database\DBConnection;

// ============================================================
// WHITELISTED TABLES FOR SECURITY
// ============================================================
$allowedTables = [
    'users', 'user_funding_sources', 'swap_transactions', 'settlement_obligations',
    'sessions', 'audit_logs', 'wallets', 'accounts', 'transactions'
];

// ============================================================
// LOAD .env FILE FROM COUNTRY FOLDER
// ============================================================
function loadEnvFile($filePath) {
    if (!file_exists($filePath)) return false;
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, '"\'');
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
    return true;
}

// Load .env from Botswana folder
$botswanaEnv = PROJECT_ROOT . '/src/Core/Config/Countries/Botswana/.env';
loadEnvFile($botswanaEnv);

// ============================================================
// AJAX HANDLER
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $result = ['status' => 'error', 'message' => 'Unknown action'];
    
    // Get file contents
    if ($action === 'get_file') {
        $file = $_POST['file'] ?? '';
        $fullPath = PROJECT_ROOT . '/' . ltrim($file, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            $content = file_get_contents($fullPath);
            if (in_array($ext, ['json', 'php', 'sql', 'txt', 'md', 'html', 'css', 'js'])) {
                $result = ['status' => 'success', 'content' => $content, 'path' => $fullPath];
            } else {
                $result = ['status' => 'success', 'content' => '[Binary file]', 'path' => $fullPath];
            }
        } else {
            $result = ['status' => 'error', 'message' => 'File not found: ' . $fullPath];
        }
        echo json_encode($result);
        exit;
    }
    
    // Get folder contents
    if ($action === 'get_folder') {
        $folder = $_POST['folder'] ?? '';
        $fullPath = PROJECT_ROOT . '/' . ltrim($folder, '/');
        if (is_dir($fullPath)) {
            $items = scandir($fullPath);
            $files = [];
            foreach ($items as $item) {
                if ($item !== '.' && $item !== '..') {
                    $itemPath = $fullPath . '/' . $item;
                    $files[] = [
                        'name' => $item,
                        'type' => is_dir($itemPath) ? 'dir' : 'file',
                        'path' => str_replace(PROJECT_ROOT, '', $itemPath)
                    ];
                }
            }
            $result = ['status' => 'success', 'files' => $files, 'path' => $fullPath];
        } else {
            $result = ['status' => 'error', 'message' => 'Folder not found: ' . $fullPath];
        }
        echo json_encode($result);
        exit;
    }
    
    // Test endpoint with API key support
    if ($action === 'test_endpoint') {
        $url = $_POST['url'] ?? '';
        $method = $_POST['method'] ?? 'GET';
        $payload = json_decode($_POST['payload'] ?? '{}', true);
        $apiKey = $_POST['api_key'] ?? null;
        $apiKeyHeader = $_POST['api_key_header'] ?? 'X-API-Key';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        $headers = ['Content-Type: application/json'];
        if ($apiKey) {
            $headers[] = "$apiKeyHeader: $apiKey";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($payload)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        }
        
        $start = microtime(true);
        $response = curl_exec($ch);
        $time = round((microtime(true) - $start) * 1000, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        curl_close($ch);
        
        $result = [
            'status' => 'success',
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'response_time' => $time,
            'redirect_url' => $redirectUrl,
            'response' => $response ? json_decode($response, true) : null,
            'error' => $error
        ];
        echo json_encode($result);
        exit;
    }
    
    // Get environment variables (API keys)
    if ($action === 'get_env_vars') {
        $envVars = [];
        $keyNames = [
            'API_KEY_SYSTEM', 'API_KEY_VOUCHMORPH', 'API_KEY_CAZACOM',
            'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS', 'API_KEY_PARTNER_1',
            'API_KEY_PARTNER_2', 'API_KEY_PARTNER_3', 'API_KEY_PARTNER_4',
            'APP_ENV', 'APP_DEBUG', 'APP_URL', 'PG_HOST', 'PG_NAME'
        ];
        
        foreach ($keyNames as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $envVars[$key] = $value;
            }
        }
        
        // Also check $_ENV and $_SERVER
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'API_KEY') === 0 && !isset($envVars[$key])) {
                $envVars[$key] = $value;
            }
        }
        
        $result = ['status' => 'success', 'env_vars' => $envVars];
        echo json_encode($result);
        exit;
    }
    
    // Test API connection with key
    if ($action === 'test_api_with_key') {
        $url = $_POST['url'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $payload = json_decode($_POST['payload'] ?? '{}', true);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Country-Code: BW'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $start = microtime(true);
        $response = curl_exec($ch);
        $time = round((microtime(true) - $start) * 1000, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $result = [
            'status' => 'success',
            'http_code' => $httpCode,
            'response_time' => $time,
            'response' => $response ? json_decode($response, true) : null,
            'error' => $error
        ];
        echo json_encode($result);
        exit;
    }
    
    // Get participants live from config
    if ($action === 'get_participants_live') {
        $participants = [];
        $configBasePath = PROJECT_ROOT . '/src/Core/Config/Countries/';
        if (is_dir($configBasePath)) {
            foreach (scandir($configBasePath) as $country) {
                if ($country !== '.' && $country !== '..' && is_dir($configBasePath . $country)) {
                    $participantsFile = $configBasePath . $country . '/participants.json';
                    if (file_exists($participantsFile)) {
                        $data = json_decode(file_get_contents($participantsFile), true);
                        $parts = $data['participants'] ?? $data ?? [];
                        foreach ($parts as $code => $p) {
                            $participants[$code] = array_merge($p, ['country' => $country]);
                        }
                    }
                }
            }
        }
        $result = ['status' => 'success', 'participants' => $participants];
        echo json_encode($result);
        exit;
    }
    
    // Get table data
    if ($action === 'get_table_data') {
        global $allowedTables;
        $table = $_POST['table'] ?? '';
        
        if (!in_array($table, $allowedTables)) {
            $result = ['status' => 'error', 'message' => 'Table not allowed: ' . $table];
            echo json_encode($result);
            exit;
        }
        
        try {
            $db = DBConnection::getInstance();
            $stmt = $db->query("SELECT * FROM $table LIMIT 50");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = ['status' => 'success', 'data' => $data, 'count' => count($data)];
        } catch (Exception $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
        echo json_encode($result);
        exit;
    }
    
    // Trace swap
    if ($action === 'trace_swap') {
        $swapRef = $_POST['swap_ref'] ?? '';
        try {
            $db = DBConnection::getInstance();
            $stmt = $db->prepare("SELECT * FROM swap_transactions WHERE swap_reference = :ref");
            $stmt->execute(['ref' => $swapRef]);
            $swap = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("SELECT * FROM settlement_obligations WHERE swap_reference = :ref");
            $stmt->execute(['ref' => $swapRef]);
            $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $result = ['status' => 'success', 'swap' => $swap, 'settlement' => $settlement];
        } catch (Exception $e) {
            $result = ['status' => 'error', 'message' => $e->getMessage()];
        }
        echo json_encode($result);
        exit;
    }
    
    // Fix dashboard
    if ($action === 'fix_dashboard') {
        $dashboardPath = PROJECT_ROOT . '/public/user/user_dashboard.php';
        $backupPath = $dashboardPath . '.backup_' . date('Ymd_His');
        
        if (file_exists($dashboardPath)) {
            copy($dashboardPath, $backupPath);
            $result = ['status' => 'backup_created', 'backup_path' => $backupPath, 'message' => 'Backup created'];
        } else {
            $result = ['status' => 'error', 'message' => 'Dashboard file not found'];
        }
        echo json_encode($result);
        exit;
    }
    
    // Test swap linked
    if ($action === 'swap_linked') {
        $result = [
            'status' => 'success',
            'swap_reference' => 'VM-TEST-' . date('YmdHis'),
            'message' => 'Test swap completed (mock)'
        ];
        echo json_encode($result);
        exit;
    }
    
    echo json_encode($result);
    exit;
}

// ============================================================
// LOAD ALL CONFIGURATIONS
// ============================================================
$configBasePath = PROJECT_ROOT . '/src/Core/Config/Countries/';
$availableCountries = [];
$countryFiles = [];
$allParticipants = [];

if (is_dir($configBasePath)) {
    foreach (scandir($configBasePath) as $country) {
        if ($country !== '.' && $country !== '..' && is_dir($configBasePath . $country)) {
            $availableCountries[] = $country;
            $countryPath = $configBasePath . $country;
            $countryFiles[$country] = [
                'participants.json' => file_exists($countryPath . '/participants.json'),
                'fees.json' => file_exists($countryPath . '/fees.json'),
                'config.php' => file_exists($countryPath . '/config.php'),
                'database.php' => file_exists($countryPath . '/database.php'),
                '.env' => file_exists($countryPath . '/.env')
            ];
            
            $participantsFile = $countryPath . '/participants.json';
            if (file_exists($participantsFile)) {
                $data = json_decode(file_get_contents($participantsFile), true);
                $parts = $data['participants'] ?? $data ?? [];
                foreach ($parts as $code => $p) {
                    $allParticipants[$code] = array_merge($p, ['country' => $country, 'code' => $code]);
                }
            }
        }
    }
}

// Database connection status
$dbConnected = false;
try {
    $db = DBConnection::getInstance();
    $dbConnected = true;
} catch (Exception $e) {}

// Get table list
$tables = [];
if ($dbConnected) {
    try {
        $stmt = $db->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        $allDbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_intersect($allDbTables, $allowedTables);
    } catch (Exception $e) {}
}

// Discover routes
$apiRoutes = [];
function scanForRoutes($dir, $basePath, $baseUrl = '') {
    $routes = [];
    if (!is_dir($dir)) return $routes;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        $urlPath = $baseUrl . '/' . $item;
        if (is_dir($path)) {
            $routes = array_merge($routes, scanForRoutes($path, $basePath, $urlPath));
        } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
            $content = file_get_contents($path);
            $method = 'GET';
            if (strpos($content, '$_POST') !== false || strpos($content, 'POST') !== false) $method = 'POST';
            if (strpos($content, '$_GET') !== false && $method === 'GET') $method = 'BOTH';
            $routes[] = ['url' => $urlPath, 'method' => $method, 'file' => str_replace($basePath, '', $path)];
        }
    }
    return $routes;
}

$apiRoutes = scanForRoutes(PROJECT_ROOT . '/public/api', PROJECT_ROOT, '/api');
$apiRoutes2 = scanForRoutes(PROJECT_ROOT . '/api', PROJECT_ROOT, '/api');
$apiRoutes = array_merge($apiRoutes, $apiRoutes2);
$srcRoutes = scanForRoutes(PROJECT_ROOT . '/src/Application/Controllers', PROJECT_ROOT, '/src');

// Get API keys from environment
$apiKeys = [];
$keyNames = ['API_KEY_SYSTEM', 'API_KEY_VOUCHMORPH', 'API_KEY_CAZACOM', 'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS'];
foreach ($keyNames as $key) {
    $value = getenv($key);
    if ($value) {
        $apiKeys[$key] = substr($value, 0, 20) . (strlen($value) > 20 ? '...' : '');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · DIAGNOSTICS CENTER</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #0a0a0a;
            color: #e0e0e0;
            padding: 20px;
        }
        .container { max-width: 1600px; margin: 0 auto; }
        
        .header {
            background: #001B44;
            border-bottom: 3px solid #FFDA63;
            padding: 20px 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        .logo { font-size: 1.2rem; font-weight: 700; color: #fff; }
        .logo span { color: #FFDA63; }
        
        .health-score {
            display: flex;
            align-items: center;
            gap: 15px;
            background: #1a1a1a;
            padding: 10px 20px;
        }
        .score-value { font-size: 28px; font-weight: 700; }
        .score-label { font-size: 10px; color: #888; }
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #1a1a1a;
            padding: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 12px 24px;
            background: #0a0a0a;
            border: none;
            color: #888;
            cursor: pointer;
            font-family: monospace;
            font-size: 13px;
            transition: all 0.2s;
        }
        .tab.active { background: #001B44; color: #FFDA63; border-bottom: 2px solid #FFDA63; }
        .tab:hover { background: #1a1a1a; color: #fff; }
        
        .panel { display: none; }
        .panel.active { display: block; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        
        .card {
            background: #111;
            border: 1px solid #333;
            overflow: hidden;
        }
        .card-header {
            background: #1a1a1a;
            padding: 14px 18px;
            border-bottom: 1px solid #333;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .card-header:hover { background: #222; }
        .card-body { padding: 18px; display: none; }
        .card-body.expanded { display: block; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            font-size: 10px;
            font-weight: 600;
        }
        .success { background: #10b981; color: #000; }
        .error { background: #ef4444; color: #fff; }
        .warning { background: #f59e0b; color: #000; }
        .info { background: #3b82f6; color: #fff; }
        
        .file-tree {
            font-family: monospace;
            font-size: 12px;
            line-height: 1.8;
            max-height: 500px;
            overflow-y: auto;
        }
        .folder { color: #FFDA63; cursor: pointer; }
        .file { color: #888; cursor: pointer; margin-left: 20px; }
        .file:hover { color: #fff; }
        
        .json-viewer {
            background: #0a0a0a;
            padding: 12px;
            font-family: monospace;
            font-size: 11px;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .participant-card {
            background: #0a0a0a;
            padding: 12px;
            margin-bottom: 12px;
            border-left: 3px solid;
        }
        .participant-card.online { border-left-color: #10b981; }
        .participant-card.offline { border-left-color: #ef4444; }
        
        .ajax-monitor {
            background: #001B44;
            padding: 16px;
            font-family: monospace;
            font-size: 11px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .metric { font-size: 28px; font-weight: 700; }
        .metric-label { font-size: 10px; color: #888; margin-top: 4px; }
        
        .btn {
            padding: 8px 16px;
            background: transparent;
            border: 1px solid #FFDA63;
            color: #FFDA63;
            cursor: pointer;
            font-family: monospace;
            font-size: 11px;
            transition: all 0.2s;
        }
        .btn:hover { background: #FFDA63; color: #000; }
        .btn-primary { background: #001B44; border-color: #001B44; color: #fff; }
        
        input, select, textarea {
            background: #1a1a1a;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 8px 12px;
            font-family: monospace;
            width: 100%;
            margin-bottom: 12px;
        }
        
        .trace-step {
            padding: 12px;
            margin: 8px 0;
            border-left: 3px solid;
            background: #1a1a1a;
        }
        .trace-step.success { border-left-color: #10b981; }
        .trace-step.error { border-left-color: #ef4444; }
        .trace-step.info { border-left-color: #3b82f6; }
        
        .log-entry { padding: 6px 0; border-bottom: 1px solid #1a1a1a; font-size: 11px; }
        .log-success { color: #10b981; }
        .log-error { color: #ef4444; }
        .log-warning { color: #f59e0b; }
        .log-info { color: #3b82f6; }
        
        .api-key-card {
            background: #0a2a2a;
            padding: 12px;
            margin-bottom: 8px;
            font-family: monospace;
            font-size: 11px;
        }
        
        @media (max-width: 1024px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">VOUCHMORPH <span>DIAGNOSTICS CENTER</span></div>
        <div class="health-score" id="healthScore">
            <div class="score-value" id="scoreValue">0%</div>
            <div class="score-label">HEALTH SCORE</div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab active" onclick="showPanel('explorer')">📁 SYSTEM EXPLORER</button>
        <button class="tab" onclick="showPanel('participants')">🏦 PARTICIPANT INSPECTOR</button>
        <button class="tab" onclick="showPanel('keys')">🔑 API KEYS & ENV</button>
        <button class="tab" onclick="showPanel('routes')">🔄 SYSTEM ROUTES</button>
        <button class="tab" onclick="showPanel('database')">🗄️ DATABASE EXPLORER</button>
        <button class="tab" onclick="showPanel('dashboard')">📊 DASHBOARD DEBUG</button>
        <button class="tab" onclick="showPanel('ajax')">📡 AJAX MONITOR</button>
        <button class="tab" onclick="showPanel('trace')">🔍 SWAP TRACE</button>
    </div>
    
    <!-- PANEL 1: SYSTEM EXPLORER -->
    <div id="panel-explorer" class="panel active">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📁 VOUCHMORPH STRUCTURE</div>
                <div class="card-body">
                    <div class="file-tree" id="vouchmorphTree"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">⚙️ CONFIGURATION FILES</div>
                <div class="card-body" id="configFiles"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📄 FILE VIEWER</div>
            <div class="card-body">
                <input type="text" id="filePath" placeholder="Enter file path to view..." value="src/Core/Config/Countries/Botswana/participants.json">
                <button class="btn" onclick="viewFile()">VIEW FILE</button>
                <div id="fileContent" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 2: PARTICIPANT INSPECTOR -->
    <div id="panel-participants" class="panel">
        <div id="participantsGrid" class="grid-2"></div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔌 ENDPOINT TESTER</div>
            <div class="card-body">
                <select id="testParticipantSelect"></select>
                <select id="testEndpointSelect"></select>
                <input type="text" id="testApiKey" placeholder="API Key (optional)">
                <textarea id="testPayload" rows="4" placeholder='{"test": true}'></textarea>
                <button class="btn" onclick="testEndpointLive()">TEST ENDPOINT →</button>
                <div id="testResult" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 3: API KEYS & ENVIRONMENT -->
    <div id="panel-keys" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🔑 API KEYS LOADED</div>
                <div class="card-body">
                    <div id="apiKeysList"></div>
                    <button class="btn" onclick="refreshEnvVars()" style="margin-top: 12px;">⟳ REFRESH</button>
                </div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🧪 TEST API CONNECTION</div>
                <div class="card-body">
                    <input type="text" id="testApiUrl" placeholder="API URL" value="https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php">
                    <input type="text" id="testApiKeyField" placeholder="API Key">
                    <textarea id="testApiPayload" rows="4" placeholder='{"source":{"institution":"CAZACOM","asset_type":"MNO-WALLET","amount":100},"destination":{"institution":"ZURUBANK","delivery_mode":"deposit","identifier":"10000001"}}'></textarea>
                    <button class="btn" onclick="testApiConnection()">TEST API →</button>
                    <div id="testApiResult" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📋 ENVIRONMENT VARIABLES</div>
            <div class="card-body">
                <div id="envVarsList"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 4: SYSTEM ROUTES -->
    <div id="panel-routes" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔗 API ROUTES</div>
            <div class="card-body">
                <div id="routesList"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 5: DATABASE EXPLORER -->
    <div id="panel-database" class="panel">
        <div class="grid-3" id="dbStats"></div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📊 TABLE BROWSER</div>
            <div class="card-body">
                <select id="tableSelect">
                    <option value="">Select a table...</option>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>"><?= htmlspecialchars($table) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" onclick="loadTableData()">LOAD DATA</button>
                <div id="tableData" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 6: DASHBOARD DEBUG -->
    <div id="panel-dashboard" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📋 DASHBOARD STATE</div>
                <div class="card-body" id="dashboardState"></div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🛠️ REPAIR TOOLS</div>
                <div class="card-body">
                    <button class="btn" onclick="repairDashboard()">🔧 BACKUP DASHBOARD</button>
                    <button class="btn" onclick="checkParticipantsPath()">📁 CHECK PARTICIPANTS PATH</button>
                    <button class="btn" onclick="testDashboardApi()">🔌 TEST DASHBOARD API</button>
                    <div id="repairResult" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PANEL 7: AJAX MONITOR -->
    <div id="panel-ajax" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📡 LIVE AJAX MONITOR</div>
            <div class="card-body">
                <div class="ajax-monitor" id="ajaxMonitor">
                    <div class="log-entry log-info">✨ AJAX Monitor ready</div>
                    <div class="log-entry log-info">📡 Intercepting dashboard requests...</div>
                </div>
                <button class="btn" onclick="testDashboardGetParticipants()" style="margin-top: 12px;">TEST get_participants</button>
                <button class="btn" onclick="testDashboardSwap()">TEST swap_linked</button>
            </div>
        </div>
    </div>
    
    <!-- PANEL 8: SWAP TRACE -->
    <div id="panel-trace" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔍 SWAP EXECUTION TRACE</div>
            <div class="card-body">
                <input type="text" id="traceSwapRef" placeholder="Enter swap reference (e.g., VM-ABCD-123456)">
                <button class="btn" onclick="traceSwap()">TRACE →</button>
                <div id="traceResult"></div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// UTILITY FUNCTIONS
// ============================================================
function showPanel(panelId) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`panel-${panelId}`).classList.add('active');
    if (event && event.target) event.target.classList.add('active');
}

function toggleCard(header) {
    const body = header.nextElementSibling;
    body.classList.toggle('expanded');
}

function addLog(level, message, containerId = 'ajaxMonitor') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const timestamp = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `log-entry log-${level}`;
    div.innerHTML = `[${timestamp}] ${message}`;
    container.appendChild(div);
    container.scrollTop = container.scrollHeight;
    while (container.children.length > 100) container.removeChild(container.firstChild);
}

async function apiCall(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    for (let key in data) {
        formData.append(key, data[key]);
    }
    
    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    });
    return await response.json();
}

// ============================================================
// SYSTEM EXPLORER
// ============================================================
async function loadFolder(path, containerId) {
    const result = await apiCall('get_folder', { folder: path });
    if (result.status === 'success') {
        const container = document.getElementById(containerId);
        container.innerHTML = renderFileTree(result.files, path);
    }
}

function renderFileTree(files, basePath) {
    let html = '';
    const folders = files.filter(f => f.type === 'dir').sort((a,b) => a.name.localeCompare(b.name));
    const fileItems = files.filter(f => f.type === 'file').sort((a,b) => a.name.localeCompare(b.name));
    
    for (const folder of folders) {
        html += `<div class="folder" onclick="loadFolder('${folder.path}', '${folder.name}Tree')">📁 ${folder.name}</div>`;
        html += `<div id="${folder.name}Tree" style="margin-left: 20px;"></div>`;
    }
    for (const file of fileItems) {
        html += `<div class="file" onclick="viewFilePath('${file.path}')">📄 ${file.name}</div>`;
    }
    return html;
}

async function viewFilePath(filePath) {
    document.getElementById('filePath').value = filePath;
    await viewFile();
}

async function viewFile() {
    const filePath = document.getElementById('filePath').value;
    const result = await apiCall('get_file', { file: filePath });
    const container = document.getElementById('fileContent');
    
    if (result.status === 'success') {
        const ext = filePath.split('.').pop();
        if (ext === 'json') {
            try {
                const parsed = JSON.parse(result.content);
                container.innerHTML = `<pre style="white-space: pre-wrap;">${JSON.stringify(parsed, null, 2)}</pre>`;
            } catch(e) {
                container.innerHTML = `<pre style="white-space: pre-wrap;">${result.content}</pre>`;
            }
        } else {
            container.innerHTML = `<pre style="white-space: pre-wrap;">${escapeHtml(result.content)}</pre>`;
        }
        addLog('info', `Viewed: ${filePath}`, 'ajaxMonitor');
    } else {
        container.innerHTML = `<div class="error">${result.message}</div>`;
        addLog('error', `Failed to view: ${result.message}`, 'ajaxMonitor');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================================
// PARTICIPANT INSPECTOR
// ============================================================
async function loadParticipants() {
    const result = await apiCall('get_participants_live');
    if (result.status === 'success') {
        const participants = result.participants;
        const grid = document.getElementById('participantsGrid');
        const select = document.getElementById('testParticipantSelect');
        
        let html = '';
        select.innerHTML = '<option value="">Select participant...</option>';
        
        for (const [code, p] of Object.entries(participants)) {
            select.innerHTML += `<option value="${code}" data-url="${p.base_url || ''}">${code} (${p.country})</option>`;
            html += `
                <div class="participant-card online">
                    <div><strong>${code}</strong> <span class="status-badge success">${p.country}</span></div>
                    <div style="font-size: 11px; margin-top: 8px;">Base URL: ${p.base_url || 'Not configured'}</div>
                    <div style="font-size: 11px;">Asset Types: ${(p.capabilities?.asset_types || []).join(', ')}</div>
                    <div style="font-size: 11px;">Endpoints: ${Object.keys(p.resource_endpoints || {}).join(', ')}</div>
                </div>
            `;
        }
        grid.innerHTML = html;
        addLog('info', `Loaded ${Object.keys(participants).length} participants`, 'ajaxMonitor');
    }
}

document.getElementById('testParticipantSelect')?.addEventListener('change', async function() {
    const code = this.value;
    const endpointSelect = document.getElementById('testEndpointSelect');
    
    if (code) {
        const result = await apiCall('get_participants_live');
        const participant = result.participants[code];
        endpointSelect.innerHTML = '<option value="health">Health Check</option>';
        
        if (participant && participant.resource_endpoints) {
            for (const [name, url] of Object.entries(participant.resource_endpoints)) {
                endpointSelect.innerHTML += `<option value="${name}">${name}</option>`;
            }
        }
    }
});

async function testEndpointLive() {
    const participant = document.getElementById('testParticipantSelect').value;
    const endpoint = document.getElementById('testEndpointSelect').value;
    const apiKey = document.getElementById('testApiKey').value;
    const payload = document.getElementById('testPayload').value;
    const resultDiv = document.getElementById('testResult');
    
    if (!participant) {
        resultDiv.innerHTML = '<div class="error">Select a participant first</div>';
        return;
    }
    
    const partsResult = await apiCall('get_participants_live');
    const participantData = partsResult.participants[participant];
    
    if (!participantData || !participantData.base_url) {
        resultDiv.innerHTML = '<div class="error">Participant has no base URL configured</div>';
        return;
    }
    
    let url = participantData.base_url.replace(/\/$/, '');
    if (endpoint === 'health') {
        url += '/health';
    } else if (participantData.resource_endpoints && participantData.resource_endpoints[endpoint]) {
        url += participantData.resource_endpoints[endpoint];
    } else {
        resultDiv.innerHTML = '<div class="error">Unknown endpoint</div>';
        return;
    }
    
    resultDiv.innerHTML = '<div class="info">Testing...</div>';
    addLog('info', `Testing endpoint: ${url} with API key: ${apiKey ? 'Yes' : 'No'}`, 'ajaxMonitor');
    
    const testResult = await apiCall('test_endpoint', {
        url: url,
        method: 'POST',
        payload: payload || '{}',
        api_key: apiKey,
        api_key_header: 'X-API-Key'
    });
    
    if (testResult.http_code === 200) {
        resultDiv.innerHTML = `
            <div class="success">✅ SUCCESS</div>
            <div>HTTP ${testResult.http_code} (${testResult.response_time}ms)</div>
            <pre style="margin-top: 8px;">${JSON.stringify(testResult.response, null, 2)}</pre>
        `;
        addLog('success', `Endpoint test passed: ${url}`, 'ajaxMonitor');
    } else if (testResult.http_code === 401) {
        resultDiv.innerHTML = `
            <div class="error">❌ UNAUTHORIZED (401)</div>
            <div>Invalid or missing API key</div>
            <div>Try using API key: ${apiKey ? 'Current key rejected' : 'No key provided'}</div>
        `;
        addLog('error', `Endpoint unauthorized: ${url} - Check API key`, 'ajaxMonitor');
    } else {
        resultDiv.innerHTML = `
            <div class="error">❌ FAILED</div>
            <div>HTTP ${testResult.http_code || 'N/A'}</div>
            <div>Error: ${testResult.error || 'Unknown'}</div>
            ${testResult.redirect_url ? `<div>Redirected to: ${testResult.redirect_url}</div>` : ''}
        `;
        addLog('error', `Endpoint test failed: ${url} - ${testResult.error}`, 'ajaxMonitor');
    }
}

// ============================================================
// API KEYS & ENVIRONMENT
// ============================================================
async function refreshEnvVars() {
    const result = await apiCall('get_env_vars');
    if (result.status === 'success') {
        const container = document.getElementById('envVarsList');
        const keysContainer = document.getElementById('apiKeysList');
        
        let envHtml = '<div style="font-family: monospace; font-size: 11px;">';
        for (const [key, value] of Object.entries(result.env_vars)) {
            envHtml += `<div><strong>${key}</strong>: ${value}</div>`;
        }
        envHtml += '</div>';
        container.innerHTML = envHtml;
        
        let keysHtml = '';
        const apiKeys = ['API_KEY_SYSTEM', 'API_KEY_VOUCHMORPH', 'API_KEY_CAZACOM', 'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS'];
        for (const key of apiKeys) {
            const value = result.env_vars[key];
            keysHtml += `<div class="api-key-card"><strong>${key}</strong><br><span style="color: #FFDA63;">${value || 'NOT SET'}</span></div>`;
        }
        keysContainer.innerHTML = keysHtml;
        
        addLog('info', 'Environment variables refreshed', 'ajaxMonitor');
    }
}

async function testApiConnection() {
    const url = document.getElementById('testApiUrl').value;
    const apiKey = document.getElementById('testApiKeyField').value;
    const payload = document.getElementById('testApiPayload').value;
    const resultDiv = document.getElementById('testApiResult');
    
    if (!url) {
        resultDiv.innerHTML = '<div class="error">Enter API URL</div>';
        return;
    }
    
    resultDiv.innerHTML = '<div class="info">Testing...</div>';
    addLog('info', `Testing API: ${url}`, 'ajaxMonitor');
    
    let payloadObj = {};
    try {
        payloadObj = JSON.parse(payload || '{}');
    } catch(e) {}
    
    const testResult = await apiCall('test_api_with_key', {
        url: url,
        api_key: apiKey,
        payload: JSON.stringify(payloadObj)
    });
    
    if (testResult.http_code === 200) {
        resultDiv.innerHTML = `
            <div class="success">✅ API RESPONSE (${testResult.response_time}ms)</div>
            <pre style="margin-top: 8px;">${JSON.stringify(testResult.response, null, 2)}</pre>
        `;
        addLog('success', `API test passed: ${url}`, 'ajaxMonitor');
    } else {
        resultDiv.innerHTML = `
            <div class="error">❌ FAILED (HTTP ${testResult.http_code})</div>
            <div>Error: ${testResult.error || 'Unknown'}</div>
        `;
        addLog('error', `API test failed: ${url} - ${testResult.error}`, 'ajaxMonitor');
    }
}

// ============================================================
// SYSTEM ROUTES
// ============================================================
function displayRoutes() {
    const routes = <?php echo json_encode(array_merge($apiRoutes, $srcRoutes)); ?>;
    const container = document.getElementById('routesList');
    
    if (!routes.length) {
        container.innerHTML = '<div class="warning">No routes discovered</div>';
        return;
    }
    
    let html = '<table style="width: 100%; border-collapse: collapse;">';
    html += '<tr style="background: #1a1a1a;"><th style="padding: 8px; text-align: left;">Method</th><th style="padding: 8px; text-align: left;">URL</th><th style="padding: 8px; text-align: left;">File</th></table>';
    
    for (const route of routes) {
        const methodClass = route.method === 'POST' ? 'success' : (route.method === 'GET' ? 'info' : 'warning');
        html += `<tr style="border-bottom: 1px solid #333;">
            <td style="padding: 8px;"><span class="status-badge ${methodClass}">${route.method}</span></td>
            <td style="padding: 8px; font-family: monospace;">${route.url}</td>
            <td style="padding: 8px; font-size: 11px; color: #888;">${route.file}</td>
        </tr>`;
    }
    html += '</table>';
    container.innerHTML = html;
}

// ============================================================
// DATABASE EXPLORER
// ============================================================
async function loadTableData() {
    const table = document.getElementById('tableSelect').value;
    if (!table) return;
    
    const result = await apiCall('get_table_data', { table: table });
    const container = document.getElementById('tableData');
    
    if (result.status === 'success') {
        container.innerHTML = `
            <div class="success">✅ Loaded ${result.count} records</div>
            <pre style="margin-top: 8px; white-space: pre-wrap;">${JSON.stringify(result.data, null, 2)}</pre>
        `;
        addLog('success', `Loaded ${result.count} records from ${table}`, 'ajaxMonitor');
    } else {
        container.innerHTML = `<div class="error">${result.message}</div>`;
        addLog('error', `Failed to load ${table}: ${result.message}`, 'ajaxMonitor');
    }
}

// ============================================================
// DASHBOARD DEBUG
// ============================================================
async function loadDashboardState() {
    const container = document.getElementById('dashboardState');
    
    const participantsResult = await apiCall('get_participants_live');
    const participantsLoaded = participantsResult.status === 'success';
    const participantsCount = participantsLoaded ? Object.keys(participantsResult.participants).length : 0;
    
    const sourcesResult = await apiCall('get_table_data', { table: 'user_funding_sources' });
    const sourcesCount = sourcesResult.status === 'success' ? sourcesResult.count : 0;
    
    const swapsResult = await apiCall('get_table_data', { table: 'swap_transactions' });
    const swapsCount = swapsResult.status === 'success' ? swapsResult.count : 0;
    
    container.innerHTML = `
        <div style="margin-bottom: 16px;">
            <div class="metric">${participantsLoaded ? '✓' : '✗'}</div>
            <div class="metric-label">Participants.json Loaded</div>
            <div style="font-size: 11px; margin-top: 4px;">Found ${participantsCount} participants</div>
        </div>
        <div style="margin-bottom: 16px;">
            <div class="metric"><?php echo $dbConnected ? '✓' : '✗'; ?></div>
            <div class="metric-label">Database Connected</div>
        </div>
        <div style="margin-bottom: 16px;">
            <div class="metric">${sourcesCount}</div>
            <div class="metric-label">Linked Sources</div>
        </div>
        <div style="margin-bottom: 16px;">
            <div class="metric">${swapsCount}</div>
            <div class="metric-label">Swap Transactions</div>
        </div>
        <div>
            <div class="metric"><?php echo session_status() === PHP_SESSION_ACTIVE ? '✓' : '✗'; ?></div>
            <div class="metric-label">Session Active</div>
        </div>
    `;
}

async function repairDashboard() {
    const result = await apiCall('fix_dashboard');
    const container = document.getElementById('repairResult');
    if (result.status === 'backup_created') {
        container.innerHTML = `<div class="success">✅ Backup created at ${result.backup_path}</div>`;
        addLog('success', `Dashboard backup created`, 'ajaxMonitor');
    } else {
        container.innerHTML = `<div class="error">${result.message}</div>`;
        addLog('error', `Dashboard backup failed: ${result.message}`, 'ajaxMonitor');
    }
}

async function checkParticipantsPath() {
    const paths = [
        'src/Core/Config/Countries/Botswana/participants.json',
        'src/Core/Config/countries/Botswana/participants.json'
    ];
    const container = document.getElementById('repairResult');
    let html = '<div><strong>Checking participants.json paths:</strong></div>';
    
    for (const path of paths) {
        const result = await apiCall('get_file', { file: path });
        html += `<div style="margin-top: 8px;">${result.status === 'success' ? '✅' : '❌'} ${path}</div>`;
        if (result.status === 'success') {
            try {
                const data = JSON.parse(result.content);
                const participantCount = Object.keys(data.participants || data || {}).length;
                html += `<div style="margin-left: 20px; font-size: 11px;">→ ${participantCount} participants found</div>`;
            } catch(e) {}
        }
    }
    container.innerHTML = html;
}

async function testDashboardApi() {
    const container = document.getElementById('repairResult');
    container.innerHTML = '<div class="info">Testing dashboard API endpoints...</div>';
    
    const participantsResult = await apiCall('get_participants_live');
    if (participantsResult.status === 'success') {
        addLog('success', `get_participants: ${Object.keys(participantsResult.participants).length} participants found`, 'ajaxMonitor');
        container.innerHTML = `<div class="success">✅ API test successful</div>`;
    } else {
        addLog('error', `get_participants failed`, 'ajaxMonitor');
        container.innerHTML = `<div class="error">❌ API test failed</div>`;
    }
}

// ============================================================
// AJAX MONITOR
// ============================================================
async function testDashboardGetParticipants() {
    addLog('info', 'Testing get_participants...', 'ajaxMonitor');
    addLog('info', '📤 AJAX REQUEST: action=get_participants_live', 'ajaxMonitor');
    
    const result = await apiCall('get_participants_live');
    
    if (result.status === 'success') {
        const count = Object.keys(result.participants).length;
        addLog('success', `📥 AJAX RESPONSE: ${count} participants loaded`, 'ajaxMonitor');
        addLog('info', `Participants: ${Object.keys(result.participants).join(', ')}`, 'ajaxMonitor');
    } else {
        addLog('error', `📥 AJAX ERROR: ${result.message}`, 'ajaxMonitor');
    }
}

async function testDashboardSwap() {
    addLog('info', 'Testing swap execution...', 'ajaxMonitor');
    addLog('info', '📤 AJAX REQUEST: action=swap_linked', 'ajaxMonitor');
    
    const result = await apiCall('swap_linked', {
        source_id: 1,
        amount: 100,
        dest_institution: 'ZURUBANK',
        dest_identifier: '10000001',
        dest_action: 'deposit'
    });
    
    if (result.status === 'success') {
        addLog('success', `📥 AJAX RESPONSE: ${result.message}`, 'ajaxMonitor');
        addLog('info', `Swap Reference: ${result.swap_reference}`, 'ajaxMonitor');
    } else {
        addLog('error', `📥 AJAX ERROR: ${result.message}`, 'ajaxMonitor');
    }
}

// ============================================================
// SWAP TRACE
// ============================================================
async function traceSwap() {
    const swapRef = document.getElementById('traceSwapRef').value;
    const container = document.getElementById('traceResult');
    
    if (!swapRef) {
        container.innerHTML = '<div class="error">Enter a swap reference</div>';
        return;
    }
    
    container.innerHTML = '<div class="info">Tracing...</div>';
    addLog('info', `Tracing swap: ${swapRef}`, 'ajaxMonitor');
    
    const result = await apiCall('trace_swap', { swap_ref: swapRef });
    
    if (result.status === 'success' && result.swap) {
        container.innerHTML = `
            <div class="trace-step success">✅ SWAP FOUND: ${result.swap.swap_reference}</div>
            <div class="trace-step info">📤 Source: ${result.swap.source_institution} (${result.swap.source_asset_type || 'N/A'})</div>
            <div class="trace-step info">📥 Destination: ${result.swap.destination_institution} → ${result.swap.destination_identifier}</div>
            <div class="trace-step info">💰 Amount: ${result.swap.amount} ${result.swap.currency || 'BWP'}</div>
            <div class="trace-step info">📅 Created: ${result.swap.created_at}</div>
            <div class="trace-step ${result.swap.status === 'completed' ? 'success' : 'warning'}">📊 Status: ${result.swap.status}</div>
            ${result.settlement ? `<div class="trace-step success">🏦 Settlement: ${result.settlement.from_participant} → ${result.settlement.to_participant} (${result.settlement.amount} BWP)</div>` : ''}
        `;
        addLog('success', `Traced swap: ${swapRef}`, 'ajaxMonitor');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ Swap not found: ${result.message || 'No such reference'}</div>`;
        addLog('error', `Swap not found: ${swapRef}`, 'ajaxMonitor');
    }
}

// ============================================================
// HEALTH SCORE CALCULATION
// ============================================================
async function calculateHealthScore() {
    let score = 0;
    let total = 7;
    
    // Database connected
    <?php if ($dbConnected): ?>score++;<?php endif; ?>
    
    // Participants loaded
    const participants = await apiCall('get_participants_live');
    if (participants.status === 'success' && Object.keys(participants.participants).length > 0) score++;
    
    // Database has tables
    <?php if (!empty($tables)): ?>score++;<?php endif; ?>
    
    // Session active
    <?php if (session_status() === PHP_SESSION_ACTIVE): ?>score++;<?php endif; ?>
    
    // Config files exist
    const configCheck = await apiCall('get_file', { file: 'src/Core/Config/Countries/Botswana/participants.json' });
    if (configCheck.status === 'success') score++;
    
    // Routes discovered
    const routesCount = <?php echo count($apiRoutes); ?>;
    if (routesCount > 0) score++;
    
    // API Keys configured
    const envResult = await apiCall('get_env_vars');
    if (envResult.status === 'success') {
        const hasApiKeys = envResult.env_vars['API_KEY_SYSTEM'] || envResult.env_vars['API_KEY_CAZACOM'];
        if (hasApiKeys) score++;
    }
    
    const percentage = Math.round((score / total) * 100);
    document.getElementById('scoreValue').textContent = `${percentage}%`;
    document.getElementById('scoreValue').style.color = percentage >= 70 ? '#10b981' : (percentage >= 40 ? '#f59e0b' : '#ef4444');
}

// ============================================================
// INITIALIZATION
// ============================================================
async function init() {
    await loadFolder('', 'vouchmorphTree');
    
    const configHtml = `<?php 
        foreach ($availableCountries as $country) {
            echo "<div style='margin-bottom: 16px;'><strong>{$country}</strong><div style='margin-left: 16px;'>";
            foreach ($countryFiles[$country] as $file => $exists) {
                $icon = $exists ? '✅' : '❌';
                echo "<div>{$icon} {$file}</div>";
            }
            echo "</div></div>";
        }
    ?>`;
    document.getElementById('configFiles').innerHTML = configHtml;
    
    await loadParticipants();
    displayRoutes();
    await loadDashboardState();
    await refreshEnvVars();
    await calculateHealthScore();
    
    addLog('info', '✨ Diagnostics Center ready', 'ajaxMonitor');
    addLog('info', `📊 Found <?php echo count($allParticipants); ?> participants`, 'ajaxMonitor');
    addLog('info', `🔗 Discovered <?php echo count($apiRoutes); ?> API routes`, 'ajaxMonitor');
    addLog('info', `🗄️ Database: <?php echo $dbConnected ? 'Connected' : 'Disconnected'; ?>`, 'ajaxMonitor');
}

init();
</script>
</body>
</html>
