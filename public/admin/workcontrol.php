<?php
/**
 * VouchMorph Complete Diagnostic Center
 * Full System Introspection, Network Testing, API Validation, and Repair Tools
 * FIXED: Event handlers, JSON parsing, Railway sleep mode, CURL options
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));

// ============================================================
// LOAD DEPENDENCIES
// ============================================================
require_once PROJECT_ROOT . '/vendor/autoload.php';
use Core\Database\DBConnection;

// ============================================================
// WHITELISTED TABLES FOR SECURITY
// ============================================================
$allowedTables = [
    'users', 'user_funding_sources', 'swap_transactions', 'settlement_obligations',
    'sessions', 'audit_logs', 'wallets', 'accounts', 'transactions'
];

// ============================================================
// LOAD .env FILE
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
            $content = file_get_contents($fullPath);
            $result = ['status' => 'success', 'content' => $content, 'path' => $fullPath];
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
    
    // Network diagnostic - FIXED: use GET instead of NOBODY
    if ($action === 'network_diagnostic') {
        $targets = [
            ['name' => 'VouchMorph API', 'url' => 'https://vouchmorphn-production.up.railway.app/health'],
            ['name' => 'Cazacom API', 'url' => 'https://cazacom-production.up.railway.app/health'],
            ['name' => 'Zurubank API', 'url' => 'https://zurubank-production.up.railway.app/Backend/health'],
            ['name' => 'Saccussalis API', 'url' => 'https://saccussalis-production.up.railway.app/backend/health']
        ];
        
        $results = [];
        foreach ($targets as $target) {
            $ch = curl_init($target['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            // FIXED: Use GET request instead of NOBODY
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_NOBODY, false);
            
            $start = microtime(true);
            $response = curl_exec($ch);
            $time = round((microtime(true) - $start) * 1000, 2);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            $results[] = [
                'name' => $target['name'],
                'url' => $target['url'],
                'reachable' => $httpCode > 0 && $httpCode < 500,
                'http_code' => $httpCode,
                'response_time' => $time,
                'error' => $error
            ];
        }
        
        $result = ['status' => 'success', 'results' => $results];
        echo json_encode($result);
        exit;
    }
    
    // DNS Lookup
    if ($action === 'dns_lookup') {
        $hostnames = [
            'vouchmorphn-production.up.railway.app',
            'cazacom-production.up.railway.app',
            'zurubank-production.up.railway.app',
            'saccussalis-production.up.railway.app'
        ];
        
        $results = [];
        foreach ($hostnames as $hostname) {
            $ips = gethostbynamel($hostname);
            $results[] = [
                'hostname' => $hostname,
                'resolves' => $ips !== false,
                'ips' => $ips ?: [],
                'error' => $ips === false ? 'DNS resolution failed' : null
            ];
        }
        
        $result = ['status' => 'success', 'results' => $results];
        echo json_encode($result);
        exit;
    }
    
    // Port scan
    if ($action === 'port_scan') {
        $host = $_POST['host'] ?? 'vouchmorphn-production.up.railway.app';
        $ports = [80, 443, 3306, 5432, 8080, 8443];
        
        $results = [];
        foreach ($ports as $port) {
            $connection = @fsockopen($host, $port, $errno, $errstr, 3);
            $isOpen = $connection !== false;
            if ($isOpen) fclose($connection);
            
            $results[] = [
                'port' => $port,
                'open' => $isOpen,
                'service' => $port == 80 ? 'HTTP' : ($port == 443 ? 'HTTPS' : ($port == 5432 ? 'PostgreSQL' : ($port == 3306 ? 'MySQL' : 'Unknown'))),
                'error' => $isOpen ? null : ($errstr ?: 'Connection refused')
            ];
        }
        
        $result = ['status' => 'success', 'host' => $host, 'results' => $results];
        echo json_encode($result);
        exit;
    }
    
    // Test API endpoint with full details - FIXED: better timeout handling
    if ($action === 'test_api_detailed') {
        $url = $_POST['url'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $payload = json_decode($_POST['payload'] ?? '{}', true);
        $method = $_POST['method'] ?? 'POST';
        
        // Handle Railway sleep mode - first try a wake-up request
        $wakeUrl = str_replace('/api/v1/swap/execute.php', '/health', $url);
        $wakeCh = curl_init($wakeUrl);
        curl_setopt($wakeCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($wakeCh, CURLOPT_TIMEOUT, 5);
        curl_setopt($wakeCh, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($wakeCh, CURLOPT_NOBODY, true);
        curl_exec($wakeCh);
        curl_close($wakeCh);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Increased timeout for Railway wake-up
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $headers = ['Content-Type: application/json'];
        if ($apiKey) {
            $headers[] = 'X-API-Key: ' . $apiKey;
            $headers[] = 'X-Country-Code: BW';
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
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $namelookupTime = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $pretransferTime = curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME);
        $starttransferTime = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $error = curl_error($ch);
        
        // Parse headers
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers_raw = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        curl_close($ch);
        
        $result = [
            'status' => 'success',
            'url' => $url,
            'method' => $method,
            'http_code' => $httpCode,
            'total_time_ms' => $time,
            'timing' => [
                'dns' => round($namelookupTime * 1000, 2),
                'connect' => round($connectTime * 1000, 2),
                'pretransfer' => round($pretransferTime * 1000, 2),
                'starttransfer' => round($starttransferTime * 1000, 2)
            ],
            'redirect_url' => $redirectUrl,
            'headers' => $headers_raw,
            'response' => $body ? json_decode($body, true) : null,
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
    
    // Get environment variables
    if ($action === 'get_env_vars') {
        $envVars = [];
        $keyNames = [
            'API_KEY_SYSTEM', 'API_KEY_VOUCHMORPH', 'API_KEY_CAZACOM',
            'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS', 'API_KEY_PARTNER_1',
            'API_KEY_PARTNER_2', 'API_KEY_PARTNER_3', 'API_KEY_PARTNER_4',
            'APP_ENV', 'APP_DEBUG', 'APP_URL', 'PG_HOST', 'PG_NAME',
            'CAZACOM_BASE_URL', 'ZURUBANK_BASE_URL', 'SACCUSSALIS_BASE_URL'
        ];
        
        foreach ($keyNames as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $envVars[$key] = $value;
            }
        }
        
        $result = ['status' => 'success', 'env_vars' => $envVars];
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
    
    // Fix dashboard backup
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
    
    echo json_encode($result);
    exit;
}

// ============================================================
// LOAD CONFIGURATIONS
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

// Database connection
$dbConnected = false;
try {
    $db = DBConnection::getInstance();
    $dbConnected = true;
} catch (Exception $e) {}

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
            $routes[] = ['url' => $urlPath, 'method' => $method, 'file' => str_replace($basePath, '', $path)];
        }
    }
    return $routes;
}

$apiRoutes = scanForRoutes(PROJECT_ROOT . '/public/api', PROJECT_ROOT, '/api');
$apiRoutes2 = scanForRoutes(PROJECT_ROOT . '/api', PROJECT_ROOT, '/api');
$apiRoutes = array_merge($apiRoutes, $apiRoutes2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · COMPLETE DIAGNOSTIC CENTER</title>
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
            padding: 12px 20px;
            background: #0a0a0a;
            border: none;
            color: #888;
            cursor: pointer;
            font-family: monospace;
            font-size: 12px;
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
            padding: 12px 16px;
            border-bottom: 1px solid #333;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        .card-header:hover { background: #222; }
        .card-body { padding: 16px; display: none; max-height: 500px; overflow-y: auto; }
        .card-body.expanded { display: block; }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 600;
        }
        .success { background: #10b981; color: #000; }
        .error { background: #ef4444; color: #fff; }
        .warning { background: #f59e0b; color: #000; }
        .info { background: #3b82f6; color: #fff; }
        
        .file-tree {
            font-family: monospace;
            font-size: 11px;
            line-height: 1.8;
            max-height: 400px;
            overflow-y: auto;
        }
        .folder { color: #FFDA63; cursor: pointer; }
        .file { color: #888; cursor: pointer; margin-left: 20px; }
        .file:hover { color: #fff; }
        
        .json-viewer {
            background: #0a0a0a;
            padding: 12px;
            font-family: monospace;
            font-size: 10px;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .participant-card {
            background: #0a0a0a;
            padding: 10px;
            margin-bottom: 10px;
            border-left: 3px solid;
            font-size: 11px;
        }
        .participant-card.online { border-left-color: #10b981; }
        .participant-card.offline { border-left-color: #ef4444; }
        
        .metric { font-size: 24px; font-weight: 700; }
        .metric-label { font-size: 9px; color: #888; margin-top: 4px; }
        
        .btn {
            padding: 6px 12px;
            background: transparent;
            border: 1px solid #FFDA63;
            color: #FFDA63;
            cursor: pointer;
            font-family: monospace;
            font-size: 10px;
            transition: all 0.2s;
            margin: 2px;
        }
        .btn:hover { background: #FFDA63; color: #000; }
        
        input, select, textarea {
            background: #1a1a1a;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 6px 10px;
            font-family: monospace;
            font-size: 11px;
            width: 100%;
            margin-bottom: 8px;
        }
        
        .trace-step {
            padding: 8px;
            margin: 6px 0;
            border-left: 3px solid;
            background: #1a1a1a;
            font-size: 11px;
        }
        .trace-step.success { border-left-color: #10b981; }
        .trace-step.error { border-left-color: #ef4444; }
        .trace-step.info { border-left-color: #3b82f6; }
        .trace-step.warning { border-left-color: #f59e0b; }
        
        .log-entry { padding: 4px 0; border-bottom: 1px solid #1a1a1a; font-size: 10px; }
        .log-success { color: #10b981; }
        .log-error { color: #ef4444; }
        .log-warning { color: #f59e0b; }
        .log-info { color: #3b82f6; }
        
        .api-key-card {
            background: #0a2a2a;
            padding: 8px;
            margin-bottom: 6px;
            font-family: monospace;
            font-size: 10px;
        }
        
        @media (max-width: 1024px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">VOUCHMORPH <span>COMPLETE DIAGNOSTIC CENTER</span></div>
        <div class="health-score" id="healthScore">
            <div class="score-value" id="scoreValue">0%</div>
            <div class="score-label">HEALTH SCORE</div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab" data-panel="dashboard">📊 DASHBOARD</button>
        <button class="tab" data-panel="network">🌐 NETWORK</button>
        <button class="tab" data-panel="participants">🏦 PARTICIPANTS</button>
        <button class="tab" data-panel="keys">🔑 API KEYS</button>
        <button class="tab" data-panel="database">🗄️ DATABASE</button>
        <button class="tab" data-panel="files">📁 FILES</button>
        <button class="tab" data-panel="trace">🔍 SWAP TRACE</button>
        <button class="tab" data-panel="repair">🛠️ REPAIR</button>
    </div>
    
    <!-- PANEL: DASHBOARD STATUS -->
    <div id="panel-dashboard" class="panel active">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📊 SYSTEM STATUS</div>
                <div class="card-body expanded" id="systemStatus"></div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📋 QUICK ACTIONS</div>
                <div class="card-body expanded">
                    <button class="btn" onclick="runFullDiagnostic()">🔍 RUN FULL DIAGNOSTIC</button>
                    <button class="btn" onclick="testAllEndpoints()">🌐 TEST ALL ENDPOINTS</button>
                    <button class="btn" onclick="refreshEnvVars()">🔄 REFRESH ENV VARS</button>
                    <button class="btn" onclick="checkDatabaseConnection()">🗄️ CHECK DATABASE</button>
                    <div id="quickResult" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📡 LIVE LOG</div>
            <div class="card-body expanded">
                <div id="liveLog" style="height: 200px; overflow-y: auto; background: #0a0a0a; padding: 8px; font-family: monospace; font-size: 10px;"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL: NETWORK DIAGNOSTIC -->
    <div id="panel-network" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🌐 DNS LOOKUP</div>
                <div class="card-body">
                    <button class="btn" onclick="runDnsLookup()">RUN DNS LOOKUP</button>
                    <div id="dnsResults" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🔌 PORT SCAN</div>
                <div class="card-body">
                    <input type="text" id="scanHost" placeholder="Hostname" value="vouchmorphn-production.up.railway.app">
                    <button class="btn" onclick="runPortScan()">SCAN PORTS</button>
                    <div id="portResults" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📡 API ENDPOINT TESTER</div>
            <div class="card-body">
                <input type="text" id="apiTestUrl" placeholder="API URL" value="https://vouchmorphn-production.up.railway.app/api/v1/swap/execute.php">
                <input type="text" id="apiTestKey" placeholder="API Key (optional)">
                <textarea id="apiTestPayload" rows="3" placeholder='{"source":{"institution":"CAZACOM","asset_type":"MNO-WALLET","amount":100},"destination":{"institution":"ZURUBANK","delivery_mode":"deposit","identifier":"10000001"}}'></textarea>
                <button class="btn" onclick="testApiDetailed()">TEST API →</button>
                <div id="apiTestResult" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🏥 HEALTH CHECKS</div>
            <div class="card-body">
                <button class="btn" onclick="runHealthChecks()">RUN HEALTH CHECKS</button>
                <div id="healthResults" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL: PARTICIPANTS -->
    <div id="panel-participants" class="panel">
        <div id="participantsGrid" class="grid-2"></div>
    </div>
    
    <!-- PANEL: API KEYS -->
    <div id="panel-keys" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🔑 LOADED API KEYS</div>
                <div class="card-body" id="apiKeysList"></div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📋 ENVIRONMENT VARIABLES</div>
                <div class="card-body" id="envVarsList"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">⚙️ CONFIGURATION FILES</div>
            <div class="card-body" id="configFilesList"></div>
        </div>
    </div>
    
    <!-- PANEL: DATABASE -->
    <div id="panel-database" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🗄️ DATABASE STATUS</div>
                <div class="card-body" id="dbStatus"></div>
            </div>
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
    </div>
    
    <!-- PANEL: FILES -->
    <div id="panel-files" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📁 VOUCHMORPH STRUCTURE</div>
                <div class="card-body">
                    <div class="file-tree" id="vouchmorphTree"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📄 FILE VIEWER</div>
                <div class="card-body">
                    <input type="text" id="filePath" placeholder="File path" value="src/Core/Config/Countries/Botswana/participants.json">
                    <button class="btn" onclick="viewFile()">VIEW FILE</button>
                    <div id="fileContent" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PANEL: SWAP TRACE -->
    <div id="panel-trace" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔍 SWAP EXECUTION TRACE</div>
            <div class="card-body">
                <input type="text" id="traceSwapRef" placeholder="Enter swap reference">
                <button class="btn" onclick="traceSwap()">TRACE →</button>
                <div id="traceResult"></div>
            </div>
        </div>
    </div>
    
    <!-- PANEL: REPAIR -->
    <div id="panel-repair" class="panel">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🛠️ REPAIR TOOLS</div>
                <div class="card-body">
                    <button class="btn" onclick="backupDashboard()">📦 BACKUP DASHBOARD</button>
                    <button class="btn" onclick="checkParticipantsPath()">📁 CHECK PARTICIPANTS PATH</button>
                    <button class="btn" onclick="testDashboardApi()">🔌 TEST DASHBOARD API</button>
                    <div id="repairResult" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📋 DIAGNOSTIC REPORT</div>
                <div class="card-body">
                    <button class="btn" onclick="generateReport()">📄 GENERATE REPORT</button>
                    <div id="reportResult" class="json-viewer" style="margin-top: 12px;"></div>
                </div>
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
    const targetPanel = document.getElementById('panel-' + panelId);
    if (targetPanel) targetPanel.classList.add('active');
    const activeTab = Array.from(document.querySelectorAll('.tab')).find(t => t.getAttribute('data-panel') === panelId);
    if (activeTab) activeTab.classList.add('active');
}

function toggleCard(header) {
    const body = header.nextElementSibling;
    if (body) body.classList.toggle('expanded');
}

function addLog(message, level = 'info') {
    const container = document.getElementById('liveLog');
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
        if (data[key] !== undefined && data[key] !== null) {
            formData.append(key, data[key]);
        }
    }
    
    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    });
    return await response.json();
}

// Initialize tabs
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        const panelId = this.getAttribute('data-panel');
        if (panelId) showPanel(panelId);
    });
});

// ============================================================
// NETWORK DIAGNOSTICS
// ============================================================
async function runDnsLookup() {
    addLog('Running DNS lookup...', 'info');
    const result = await apiCall('dns_lookup');
    const container = document.getElementById('dnsResults');
    
    if (result.status === 'success') {
        container.innerHTML = result.results.map(r => `
            <div class="trace-step ${r.resolves ? 'success' : 'error'}">
                <strong>${r.hostname}</strong><br>
                Resolves: ${r.resolves ? '✅ Yes' : '❌ No'}<br>
                ${r.ips.length ? `IPs: ${r.ips.join(', ')}` : ''}
            </div>
        `).join('');
        addLog(`DNS lookup complete: ${result.results.filter(r => r.resolves).length}/${result.results.length} resolve`, 'success');
    } else {
        container.innerHTML = '<div class="trace-step error">DNS lookup failed</div>';
    }
}

async function runPortScan() {
    const host = document.getElementById('scanHost').value;
    addLog(`Scanning ports on ${host}...`, 'info');
    const result = await apiCall('port_scan', { host: host });
    const container = document.getElementById('portResults');
    
    if (result.status === 'success') {
        container.innerHTML = result.results.map(r => `
            <div class="trace-step ${r.open ? 'success' : 'error'}">
                Port ${r.port} (${r.service}): ${r.open ? '✅ OPEN' : '❌ CLOSED'}
                ${r.error ? `<br>Error: ${r.error}` : ''}
            </div>
        `).join('');
        addLog(`Port scan complete: ${result.results.filter(r => r.open).length} open ports`, 'info');
    } else {
        container.innerHTML = '<div class="trace-step error">Port scan failed</div>';
    }
}

async function testApiDetailed() {
    const url = document.getElementById('apiTestUrl').value;
    const apiKey = document.getElementById('apiTestKey').value;
    let payload = document.getElementById('apiTestPayload').value;
    const container = document.getElementById('apiTestResult');
    
    if (!url) {
        container.innerHTML = '<div class="trace-step error">❌ Please enter an API URL</div>';
        return;
    }
    
    // Validate and parse JSON
    let payloadObj = {};
    if (payload && payload.trim()) {
        try {
            payloadObj = JSON.parse(payload);
        } catch(e) {
            container.innerHTML = `<div class="trace-step error">❌ Invalid JSON: ${e.message}</div>`;
            return;
        }
    }
    
    container.innerHTML = '<div class="trace-step info">⏳ Testing API (45s timeout)...</div>';
    addLog(`Testing API: ${url}`, 'info');
    if (apiKey) addLog(`Using API Key: ${apiKey.substring(0, 15)}...`, 'info');
    
    const result = await apiCall('test_api_detailed', {
        url: url,
        api_key: apiKey,
        payload: JSON.stringify(payloadObj),
        method: 'POST'
    });
    
    let html = '';
    
    if (result.http_code === 200) {
        html += `<div class="trace-step success">✅ SUCCESS (HTTP ${result.http_code}) - ${result.total_time_ms}ms</div>`;
        addLog(`API test SUCCESS: ${result.total_time_ms}ms`, 'success');
    } else if (result.http_code === 401) {
        html += `<div class="trace-step error">❌ UNAUTHORIZED (HTTP 401)</div>`;
        html += `<div class="trace-step warning">The API key is invalid or missing. Try: cazacom_test_key_2025</div>`;
        addLog(`API test FAILED: Unauthorized - Invalid API key`, 'error');
    } else if (result.http_code === 404) {
        html += `<div class="trace-step error">❌ NOT FOUND (HTTP 404)</div>`;
        html += `<div class="trace-step warning">The endpoint URL may need a .php extension</div>`;
        addLog(`API test FAILED: Not Found - Check URL`, 'error');
    } else if (result.http_code > 0) {
        html += `<div class="trace-step warning">⚠️ RESPONSE (HTTP ${result.http_code}) - ${result.total_time_ms}ms</div>`;
        addLog(`API test returned HTTP ${result.http_code}`, 'warning');
    } else if (result.error && result.error.includes('timed out')) {
        html += `<div class="trace-step error">❌ TIMEOUT (45s)</div>`;
        html += `<div class="trace-step warning">Railway service may be sleeping. Try again in 10 seconds.</div>`;
        addLog(`API test FAILED: Timeout - Service may be sleeping`, 'warning');
    } else {
        html += `<div class="trace-step error">❌ FAILED</div>`;
        html += `<div class="trace-step error">Error: ${result.error || 'Unknown error'}</div>`;
        addLog(`API test FAILED: ${result.error || 'Unknown'}`, 'error');
    }
    
    if (result.timing) {
        html += `<div class="trace-step info">⏱️ Timing: DNS=${result.timing.dns}ms, Connect=${result.timing.connect}ms, Transfer=${result.timing.starttransfer}ms</div>`;
    }
    
    if (result.redirect_url) {
        html += `<div class="trace-step warning">🔄 Redirected to: ${result.redirect_url}</div>`;
    }
    
    if (result.response) {
        html += `<div class="trace-step success">📦 Response: <pre style="margin-top: 8px; white-space: pre-wrap;">${JSON.stringify(result.response, null, 2)}</pre></div>`;
    }
    
    container.innerHTML = html;
}

async function runHealthChecks() {
    addLog('Running health checks...', 'info');
    const result = await apiCall('network_diagnostic');
    const container = document.getElementById('healthResults');
    
    if (result.status === 'success') {
        container.innerHTML = result.results.map(r => `
            <div class="trace-step ${r.reachable ? 'success' : 'error'}">
                <strong>${r.name}</strong><br>
                URL: ${r.url}<br>
                Status: ${r.reachable ? `✅ HTTP ${r.http_code} (${r.response_time}ms)` : `❌ UNREACHABLE - ${r.error || 'No response'}`}
            </div>
        `).join('');
        const reachable = result.results.filter(r => r.reachable).length;
        addLog(`Health checks: ${reachable}/${result.results.length} services reachable`, reachable === result.results.length ? 'success' : 'warning');
    } else {
        container.innerHTML = '<div class="trace-step error">Health check failed</div>';
    }
}

// ============================================================
// ENVIRONMENT & PARTICIPANTS
// ============================================================
async function refreshEnvVars() {
    addLog('Refreshing environment variables...', 'info');
    const result = await apiCall('get_env_vars');
    
    if (result.status === 'success') {
        const keysContainer = document.getElementById('apiKeysList');
        const envContainer = document.getElementById('envVarsList');
        
        const apiKeys = ['API_KEY_SYSTEM', 'API_KEY_CAZACOM', 'API_KEY_ZURUBANK', 'API_KEY_SACCUSSALIS'];
        keysContainer.innerHTML = apiKeys.map(key => `
            <div class="api-key-card">
                <strong>${key}</strong><br>
                <span style="color: #FFDA63; word-break: break-all;">${result.env_vars[key] || '❌ NOT SET'}</span>
                ${result.env_vars[key] ? `<button class="btn" style="margin-top: 6px;" onclick="testWithKey('${result.env_vars[key]}')">🔑 Test This Key</button>` : ''}
            </div>
        `).join('');
        
        envContainer.innerHTML = Object.entries(result.env_vars).map(([k, v]) => `
            <div style="margin-bottom: 4px; font-size: 10px;"><strong>${k}</strong>: ${v}</div>
        `).join('');
        
        addLog(`Loaded ${Object.keys(result.env_vars).length} environment variables`, 'success');
    }
}

async function testWithKey(apiKey) {
    document.getElementById('apiTestKey').value = apiKey;
    addLog(`Setting API key: ${apiKey.substring(0, 20)}...`, 'info');
    await testApiDetailed();
}

async function loadParticipants() {
    const result = await apiCall('get_participants_live');
    if (result.status === 'success') {
        const participants = result.participants;
        const grid = document.getElementById('participantsGrid');
        
        if (Object.keys(participants).length === 0) {
            grid.innerHTML = '<div class="trace-step warning">No participants found. Check participants.json file.</div>';
        } else {
            grid.innerHTML = Object.entries(participants).map(([code, p]) => `
                <div class="participant-card online">
                    <div><strong>${code}</strong> <span class="status-badge success">${p.country}</span></div>
                    <div style="font-size: 10px; margin-top: 6px;">Base URL: ${p.base_url || 'Not configured'}</div>
                    <div style="font-size: 10px;">Asset Types: ${(p.capabilities?.asset_types || []).join(', ')}</div>
                    <div style="font-size: 10px;">Endpoints: ${Object.keys(p.resource_endpoints || {}).length}</div>
                </div>
            `).join('');
        }
        
        addLog(`Loaded ${Object.keys(participants).length} participants`, 'success');
    } else {
        document.getElementById('participantsGrid').innerHTML = '<div class="trace-step error">Failed to load participants</div>';
    }
}

// ============================================================
// DATABASE FUNCTIONS
// ============================================================
async function checkDatabaseConnection() {
    addLog('Checking database connection...', 'info');
    const result = await apiCall('get_table_data', { table: 'users' });
    const container = document.getElementById('quickResult');
    
    if (result.status === 'success') {
        container.innerHTML = `<div class="trace-step success">✅ Database connected. Users table has ${result.count} records.</div>`;
        addLog(`Database connected, ${result.count} users found`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ Database error: ${result.message}</div>`;
        addLog(`Database error: ${result.message}`, 'error');
    }
}

async function loadTableData() {
    const table = document.getElementById('tableSelect').value;
    if (!table) {
        addLog('Please select a table', 'warning');
        return;
    }
    
    const result = await apiCall('get_table_data', { table: table });
    const container = document.getElementById('tableData');
    
    if (result.status === 'success') {
        container.innerHTML = `<div class="trace-step success">✅ Loaded ${result.count} records</div>
            <pre style="margin-top: 8px; white-space: pre-wrap;">${JSON.stringify(result.data, null, 2)}</pre>`;
        addLog(`Loaded ${result.count} records from ${table}`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ ${result.message}</div>`;
        addLog(`Failed to load ${table}: ${result.message}`, 'error');
    }
}

// ============================================================
// FILE BROWSER
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
                container.innerHTML = `<pre style="white-space: pre-wrap;">${escapeHtml(result.content)}</pre>`;
            }
        } else {
            container.innerHTML = `<pre style="white-space: pre-wrap;">${escapeHtml(result.content)}</pre>`;
        }
        addLog(`Viewed: ${filePath}`, 'info');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ ${result.message}</div>`;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================================
// REPAIR & DIAGNOSTIC FUNCTIONS
// ============================================================
async function backupDashboard() {
    const result = await apiCall('fix_dashboard');
    const container = document.getElementById('repairResult');
    if (result.status === 'backup_created') {
        container.innerHTML = `<div class="trace-step success">✅ Backup created at ${result.backup_path}</div>`;
        addLog(`Dashboard backup created`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ ${result.message}</div>`;
    }
}

async function checkParticipantsPath() {
    const paths = ['src/Core/Config/Countries/Botswana/participants.json'];
    const container = document.getElementById('repairResult');
    let html = '<div><strong>Checking paths:</strong></div>';
    
    for (const path of paths) {
        const result = await apiCall('get_file', { file: path });
        html += `<div class="trace-step ${result.status === 'success' ? 'success' : 'error'}">${result.status === 'success' ? '✅' : '❌'} ${path}</div>`;
        if (result.status === 'success') {
            try {
                const data = JSON.parse(result.content);
                const count = Object.keys(data.participants || data || {}).length;
                html += `<div style="margin-left: 20px;">→ ${count} participants found</div>`;
            } catch(e) {}
        }
    }
    container.innerHTML = html;
}

async function testDashboardApi() {
    const result = await apiCall('get_participants_live');
    const container = document.getElementById('repairResult');
    if (result.status === 'success') {
        container.innerHTML = `<div class="trace-step success">✅ API test successful: ${Object.keys(result.participants).length} participants</div>`;
        addLog(`Dashboard API test passed`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ API test failed: ${result.message}</div>`;
        addLog(`Dashboard API test failed`, 'error');
    }
}

async function traceSwap() {
    const swapRef = document.getElementById('traceSwapRef').value;
    const container = document.getElementById('traceResult');
    
    if (!swapRef) {
        container.innerHTML = '<div class="trace-step error">❌ Enter a swap reference</div>';
        return;
    }
    
    container.innerHTML = '<div class="trace-step info">⏳ Tracing...</div>';
    const result = await apiCall('trace_swap', { swap_ref: swapRef });
    
    if (result.status === 'success' && result.swap) {
        container.innerHTML = `
            <div class="trace-step success">✅ SWAP FOUND: ${result.swap.swap_reference}</div>
            <div class="trace-step info">📤 Source: ${result.swap.source_institution}</div>
            <div class="trace-step info">📥 Destination: ${result.swap.destination_institution} → ${result.swap.destination_identifier}</div>
            <div class="trace-step info">💰 Amount: ${result.swap.amount} ${result.swap.currency || 'BWP'}</div>
            <div class="trace-step info">📅 Created: ${result.swap.created_at}</div>
            <div class="trace-step ${result.swap.status === 'completed' ? 'success' : 'warning'}">📊 Status: ${result.swap.status}</div>
            ${result.settlement ? `<div class="trace-step success">🏦 Settlement: ${result.settlement.from_participant} → ${result.settlement.to_participant}</div>` : ''}
        `;
        addLog(`Traced swap: ${swapRef}`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ Swap not found: ${result.message || 'No such reference'}</div>`;
        addLog(`Swap not found: ${swapRef}`, 'error');
    }
}

async function generateReport() {
    addLog('Generating diagnostic report...', 'info');
    const container = document.getElementById('reportResult');
    
    const env = await apiCall('get_env_vars');
    const participants = await apiCall('get_participants_live');
    const network = await apiCall('network_diagnostic');
    
    const report = {
        timestamp: new Date().toISOString(),
        environment: {
            app_env: env.env_vars?.APP_ENV || 'unknown',
            db_host: env.env_vars?.PG_HOST || 'unknown'
        },
        api_keys: {
            system: env.env_vars?.API_KEY_SYSTEM ? 'set' : 'missing',
            cazacom: env.env_vars?.API_KEY_CAZACOM ? 'set' : 'missing',
            zurubank: env.env_vars?.API_KEY_ZURUBANK ? 'set' : 'missing'
        },
        participants_count: Object.keys(participants.participants || {}).length,
        services: network.results || []
    };
    
    container.innerHTML = `<pre style="white-space: pre-wrap;">${JSON.stringify(report, null, 2)}</pre>`;
    addLog('Diagnostic report generated', 'success');
}

async function testAllEndpoints() {
    addLog('Testing all endpoints...', 'info');
    await runHealthChecks();
}

async function runFullDiagnostic() {
    addLog('========== FULL DIAGNOSTIC START ==========', 'info');
    await runDnsLookup();
    await runPortScan();
    await runHealthChecks();
    await refreshEnvVars();
    await checkDatabaseConnection();
    await loadParticipants();
    addLog('========== FULL DIAGNOSTIC COMPLETE ==========', 'success');
}

async function updateSystemStatus() {
    const container = document.getElementById('systemStatus');
    const env = await apiCall('get_env_vars');
    const participants = await apiCall('get_participants_live');
    
    container.innerHTML = `
        <div class="trace-step info">🌍 Environment: ${env.env_vars?.APP_ENV || 'unknown'}</div>
        <div class="trace-step info">🗄️ Database: <?php echo $dbConnected ? 'Connected' : 'Disconnected'; ?></div>
        <div class="trace-step info">🏦 Participants: ${Object.keys(participants.participants || {}).length}</div>
        <div class="trace-step info">🔑 API Keys: ${Object.keys(env.env_vars || {}).filter(k => k.startsWith('API_KEY')).length}</div>
    `;
}

// ============================================================
// INITIALIZATION
// ============================================================
async function init() {
    // Set default active tab
    showPanel('dashboard');
    
    await loadFolder('', 'vouchmorphTree');
    await loadParticipants();
    await refreshEnvVars();
    await updateSystemStatus();
    
    // Load config files display
    const configHtml = `<?php 
        foreach ($availableCountries as $country) {
            echo "<div style='margin-bottom: 12px;'><strong>{$country}</strong><br>";
            foreach ($countryFiles[$country] as $file => $exists) {
                $icon = $exists ? '✅' : '❌';
                echo "<span style='margin-left: 16px;'>{$icon} {$file}</span><br>";
            }
            echo "</div>";
        }
    ?>`;
    document.getElementById('configFilesList').innerHTML = configHtml;
    
    const dbStatusHtml = `<?php echo $dbConnected ? 
        '<div class="trace-step success">✅ Database Connected</div>' : 
        '<div class="trace-step error">❌ Database Disconnected</div>'; ?>
        <div class="trace-step info">📊 Tables found: <?php echo count($tables); ?></div>
    `;
    document.getElementById('dbStatus').innerHTML = dbStatusHtml;
    
    addLog('✨ Complete Diagnostic Center ready', 'success');
    addLog(`📊 Found <?php echo count($allParticipants); ?> participants in config`, 'info');
    addLog(`🗄️ Database: <?php echo $dbConnected ? 'Connected' : 'Disconnected'; ?>`, 'info');
    
    // Set default test payload
    document.getElementById('apiTestPayload').value = JSON.stringify({
        source: {
            institution: "CAZACOM",
            asset_type: "MNO-WALLET",
            amount: 100,
            phone: "71234567",
            credentials: { pin: "1234" }
        },
        destination: {
            institution: "ZURUBANK",
            delivery_mode: "deposit",
            identifier: "10000001"
        }
    }, null, 2);
}

init();
</script>
</body>
</html>
