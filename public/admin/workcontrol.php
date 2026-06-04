<?php
/**
 * VouchMorph Diagnostic Center
 * FULLY DYNAMIC - No hardcoded values
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));

// ============================================================
// AUTO-DISCOVER FUNCTIONS (WITHOUT DATABASE DEPENDENCY)
// ============================================================

/**
 * Discover all country configurations by scanning the Countries directory
 */
function discoverCountries() {
    $countries = [];
    $possiblePaths = [
        PROJECT_ROOT . '/src/Core/Config/Countries/',
        PROJECT_ROOT . '/src/Core/Config/countries/',
        PROJECT_ROOT . '/src/CORE_CONFIG/countries/'
    ];
    
    foreach ($possiblePaths as $basePath) {
        if (is_dir($basePath)) {
            foreach (scandir($basePath) as $item) {
                if ($item !== '.' && $item !== '..' && is_dir($basePath . $item)) {
                    $countryPath = $basePath . $item;
                    $countries[$item] = [
                        'name' => $item,
                        'path' => $countryPath,
                        'has_participants' => file_exists($countryPath . '/participants.json'),
                        'has_fees' => file_exists($countryPath . '/fees.json'),
                        'has_config' => file_exists($countryPath . '/config.php'),
                        'has_env' => file_exists($countryPath . '/.env'),
                        'has_database' => file_exists($countryPath . '/database.php'),
                        'has_atm_notes' => file_exists($countryPath . '/atm_notes.json')
                    ];
                }
            }
            break;
        }
    }
    
    return $countries;
}

/**
 * Load participants from a specific country's config
 */
function loadParticipantsForCountry($countryPath) {
    $participantsFile = $countryPath . '/participants.json';
    if (!file_exists($participantsFile)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($participantsFile), true);
    $participants = $data['participants'] ?? $data ?? [];
    
    // Normalize participant data
    foreach ($participants as $code => &$p) {
        $p['code'] = $code;
        if (!isset($p['base_url']) && isset($p['baseUrl'])) {
            $p['base_url'] = $p['baseUrl'];
        }
        if (!isset($p['capabilities'])) {
            $p['capabilities'] = ['asset_types' => []];
        }
        if (!isset($p['resource_endpoints'])) {
            $p['resource_endpoints'] = [];
        }
        if (!isset($p['status'])) {
            $p['status'] = 'ACTIVE';
        }
    }
    
    return $participants;
}

/**
 * Load all participants from all countries
 */
function loadAllParticipants() {
    $allParticipants = [];
    $countries = discoverCountries();
    
    foreach ($countries as $countryName => $countryInfo) {
        if ($countryInfo['has_participants']) {
            $participants = loadParticipantsForCountry($countryInfo['path']);
            foreach ($participants as $code => $p) {
                $allParticipants[$code] = array_merge($p, [
                    'discovered_country' => $countryName,
                    'country_path' => $countryInfo['path']
                ]);
            }
        }
    }
    
    return $allParticipants;
}

/**
 * Load environment variables from a .env file
 */
function loadEnvFile($filePath) {
    if (!file_exists($filePath)) return [];
    
    $env = [];
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $value = trim($value, '"\'');
            $env[$key] = $value;
        }
    }
    return $env;
}

/**
 * Load all environment variables from all country .env files
 */
function loadAllEnvVars() {
    $allEnv = [];
    $countries = discoverCountries();
    
    foreach ($countries as $countryInfo) {
        if ($countryInfo['has_env']) {
            $env = loadEnvFile($countryInfo['path'] . '/.env');
            foreach ($env as $key => $value) {
                if (!isset($allEnv[$key])) {
                    $allEnv[$key] = $value;
                }
            }
        }
    }
    
    return $allEnv;
}

/**
 * Discover all API routes by scanning directories
 */
function discoverApiRoutes() {
    $routes = [];
    $apiPaths = [
        PROJECT_ROOT . '/public/api',
        PROJECT_ROOT . '/api'
    ];
    
    foreach ($apiPaths as $basePath) {
        if (!is_dir($basePath)) continue;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $relativePath = str_replace(PROJECT_ROOT, '', $file->getPathname());
                $urlPath = str_replace('/public', '', $relativePath);
                $urlPath = str_replace('.php', '', $urlPath);
                
                // Detect HTTP method from file content
                $content = file_get_contents($file->getPathname());
                $method = 'GET';
                if (strpos($content, '$_POST') !== false || strpos($content, 'POST') !== false) {
                    $method = 'POST';
                }
                if (strpos($content, '$_GET') !== false && $method === 'GET') {
                    $method = 'BOTH';
                }
                
                $routes[] = [
                    'method' => $method,
                    'url' => $urlPath,
                    'file' => $relativePath,
                    'size' => $file->getSize()
                ];
            }
        }
    }
    
    return $routes;
}

// ============================================================
// DISCOVER DATA FOR INITIAL PAGE LOAD
// ============================================================
$countries = discoverCountries();
$allParticipants = loadAllParticipants();
$apiRoutes = discoverApiRoutes();
$allEnvVars = loadAllEnvVars();

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
    
    // Get participants for a specific country
    if ($action === 'get_participants_for_country') {
        $country = $_POST['country'] ?? '';
        $countryPath = null;
        
        foreach ($countries as $name => $info) {
            if ($name === $country) {
                $countryPath = $info['path'];
                break;
            }
        }
        
        if ($countryPath) {
            $participants = loadParticipantsForCountry($countryPath);
            $result = ['status' => 'success', 'participants' => $participants, 'country' => $country];
        } else {
            $result = ['status' => 'error', 'message' => 'Country not found: ' . $country];
        }
        echo json_encode($result);
        exit;
    }
    
    // Get all participants
    if ($action === 'get_all_participants') {
        $result = ['status' => 'success', 'participants' => $allParticipants];
        echo json_encode($result);
        exit;
    }
    
    // Get all countries
    if ($action === 'get_countries') {
        $result = ['status' => 'success', 'countries' => $countries];
        echo json_encode($result);
        exit;
    }
    
    // Get environment variables
    if ($action === 'get_env') {
        $result = ['status' => 'success', 'env' => $allEnvVars];
        echo json_encode($result);
        exit;
    }
    
    // Test API endpoint
    if ($action === 'test_api') {
        $url = $_POST['url'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        $payload = json_decode($_POST['payload'] ?? '{}', true);
        
        if (empty($url)) {
            echo json_encode(['status' => 'error', 'message' => 'URL required']);
            exit;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'X-API-Key: ' . $apiKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $start = microtime(true);
        $response = curl_exec($ch);
        $time = round((microtime(true) - $start) * 1000, 2);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($ch);
        
        echo json_encode([
            'status' => 'success',
            'http_code' => $httpCode,
            'response_time' => $time,
            'redirect_url' => $redirectUrl,
            'response' => $body ? json_decode($body, true) : null,
            'error' => $error
        ]);
        exit;
    }
    
    // Network check - test all participant health endpoints
    if ($action === 'network_check') {
        $targets = [];
        foreach ($allParticipants as $code => $p) {
            if (!empty($p['base_url'])) {
                $targets[$code] = rtrim($p['base_url'], '/') . '/health';
            }
        }
        
        // Add main VouchMorph health check
        $targets['VouchMorph'] = 'https://vouchmorphn-production.up.railway.app/health';
        
        $results = [];
        foreach ($targets as $name => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            $start = microtime(true);
            curl_exec($ch);
            $time = round((microtime(true) - $start) * 1000, 2);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            $results[] = [
                'name' => $name,
                'url' => $url,
                'reachable' => $httpCode > 0,
                'http_code' => $httpCode,
                'time' => $time,
                'error' => $error
            ];
        }
        
        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }
    
    // Trace swap (database dependent - safe fallback)
    if ($action === 'trace_swap') {
        $swapRef = $_POST['swap_ref'] ?? '';
        
        // Try to load database connection if available
        $swap = null;
        $settlement = null;
        
        try {
            // Try to include the DBConnection class
            $dbConnPath = PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
            if (file_exists($dbConnPath)) {
                require_once $dbConnPath;
                
                if (class_exists('Core\Database\DBConnection')) {
                    $db = Core\Database\DBConnection::getInstance();
                    $stmt = $db->prepare("SELECT * FROM swap_transactions WHERE swap_reference = :ref");
                    $stmt->execute(['ref' => $swapRef]);
                    $swap = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $stmt = $db->prepare("SELECT * FROM settlement_obligations WHERE swap_reference = :ref");
                    $stmt->execute(['ref' => $swapRef]);
                    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
                }
            }
        } catch (Exception $e) {
            // Database not available - return empty
            error_log("Database error in trace_swap: " . $e->getMessage());
        }
        
        echo json_encode(['status' => 'success', 'swap' => $swap, 'settlement' => $settlement]);
        exit;
    }
    
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · DIAGNOSTIC CENTER</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        
        .stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .stat {
            background: #1a1a1a;
            padding: 8px 16px;
            text-align: center;
        }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 9px; color: #888; }
        
        .tabs {
            display: flex;
            gap: 4px;
            background: #1a1a1a;
            padding: 8px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 10px 20px;
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
            font-size: 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card-header:hover { background: #222; }
        .card-body { padding: 16px; display: none; max-height: 500px; overflow-y: auto; }
        .card-body.expanded { display: block; }
        
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
        .btn-primary { background: #FFDA63; color: #000; }
        
        input, select, textarea {
            background: #1a1a1a;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 8px 12px;
            font-family: monospace;
            font-size: 11px;
            width: 100%;
            margin-bottom: 12px;
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
        
        .json-viewer {
            background: #0a0a0a;
            padding: 12px;
            font-family: monospace;
            font-size: 10px;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .participant-item, .country-item {
            background: #0a0a0a;
            padding: 10px;
            margin-bottom: 8px;
            border-left: 2px solid #FFDA63;
        }
        
        .log-area {
            background: #0a0a0a;
            padding: 12px;
            height: 200px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 10px;
        }
        .log-entry { padding: 4px 0; border-bottom: 1px solid #1a1a1a; }
        
        @media (max-width: 1024px) {
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">VOUCHMORPH <span>DIAGNOSTIC CENTER</span></div>
        <div class="stats">
            <div class="stat"><div class="stat-value" id="statCountries"><?php echo count($countries); ?></div><div class="stat-label">COUNTRIES</div></div>
            <div class="stat"><div class="stat-value" id="statParticipants"><?php echo count($allParticipants); ?></div><div class="stat-label">PARTICIPANTS</div></div>
            <div class="stat"><div class="stat-value" id="statRoutes"><?php echo count($apiRoutes); ?></div><div class="stat-label">API ROUTES</div></div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab active" data-panel="dashboard">📊 DASHBOARD</button>
        <button class="tab" data-panel="countries">🌍 COUNTRIES</button>
        <button class="tab" data-panel="participants">🏦 PARTICIPANTS</button>
        <button class="tab" data-panel="api">🔌 API TESTER</button>
        <button class="tab" data-panel="routes">🔄 ROUTES</button>
        <button class="tab" data-panel="env">📋 ENVIRONMENT</button>
        <button class="tab" data-panel="trace">🔍 SWAP TRACE</button>
    </div>
    
    <!-- DASHBOARD PANEL -->
    <div id="panel-dashboard" class="panel active">
        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📊 SYSTEM OVERVIEW</div>
                <div class="card-body expanded" id="systemOverview"></div>
            </div>
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📡 ACTIVITY LOG</div>
                <div class="card-body expanded">
                    <div class="log-area" id="logArea">
                        <div class="log-entry">✨ Diagnostic Center ready</div>
                        <div class="log-entry">📊 Found <?php echo count($countries); ?> countries</div>
                        <div class="log-entry">🏦 Found <?php echo count($allParticipants); ?> participants</div>
                        <div class="log-entry">🔄 Found <?php echo count($apiRoutes); ?> API routes</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">⚡ QUICK ACTIONS</div>
            <div class="card-body expanded">
                <button class="btn" onclick="runHealthCheck()">🏥 HEALTH CHECK</button>
                <button class="btn" onclick="refreshAllData()">🔄 REFRESH DATA</button>
                <div id="quickResult" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- COUNTRIES PANEL -->
    <div id="panel-countries" class="panel">
        <div class="grid-2" id="countriesList">
            <?php foreach ($countries as $countryName => $info): ?>
            <div class="country-item">
                <strong>📍 <?php echo htmlspecialchars($countryName); ?></strong><br>
                <span style="font-size: 10px;"><?php echo htmlspecialchars($info['path']); ?></span><br>
                <span style="font-size: 10px;">
                    <?php echo $info['has_participants'] ? '✅ participants.json' : '❌ participants.json'; ?> |
                    <?php echo $info['has_fees'] ? '✅ fees.json' : '❌ fees.json'; ?> |
                    <?php echo $info['has_config'] ? '✅ config.php' : '❌ config.php'; ?> |
                    <?php echo $info['has_env'] ? '✅ .env' : '❌ .env'; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- PARTICIPANTS PANEL -->
    <div id="panel-participants" class="panel">
        <div class="grid-2" id="participantsList">
            <?php foreach ($allParticipants as $code => $p): ?>
            <div class="participant-item">
                <strong><?php echo htmlspecialchars($code); ?></strong>
                <span class="status-badge info" style="font-size: 9px;"><?php echo htmlspecialchars($p['discovered_country'] ?? 'Unknown'); ?></span><br>
                <span style="font-size: 10px;">Type: <?php echo htmlspecialchars($p['type'] ?? 'Unknown'); ?></span><br>
                <span style="font-size: 10px;">Base URL: <?php echo htmlspecialchars($p['base_url'] ?? 'Not configured'); ?></span><br>
                <span style="font-size: 10px;">Asset Types: <?php echo implode(', ', $p['capabilities']['asset_types'] ?? []); ?></span><br>
                <span style="font-size: 10px;">Status: <?php echo htmlspecialchars($p['status'] ?? 'Unknown'); ?></span>
                <button class="btn" style="margin-top: 6px;" onclick="testParticipant('<?php echo htmlspecialchars($code); ?>')">🔌 Test API</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- API TESTER PANEL -->
    <div id="panel-api" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🧪 API ENDPOINT TESTER</div>
            <div class="card-body expanded">
                <div class="grid-2">
                    <div>
                        <label class="form-label">COUNTRY</label>
                        <select id="apiCountrySelect" onchange="loadParticipantsForCountry()">
                            <option value="">Select Country...</option>
                            <?php foreach ($countries as $countryName => $info): ?>
                                <option value="<?php echo htmlspecialchars($countryName); ?>"><?php echo htmlspecialchars($countryName); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">PARTICIPANT</label>
                        <select id="apiParticipantSelect" onchange="loadEndpointsForParticipant()">
                            <option value="">Select Participant...</option>
                        </select>
                    </div>
                </div>
                
                <div class="grid-2">
                    <div>
                        <label class="form-label">ENDPOINT</label>
                        <select id="apiEndpointSelect" onchange="updateApiUrl()">
                            <option value="">Select Endpoint...</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">FULL URL</label>
                        <input type="text" id="apiUrlInput" placeholder="Full URL will appear here" readonly>
                    </div>
                </div>
                
                <label class="form-label">API KEY (X-API-Key)</label>
                <input type="text" id="apiKeyInput" placeholder="Enter API key">
                
                <label class="form-label">REQUEST PAYLOAD (JSON)</label>
                <textarea id="apiPayloadInput" rows="4" placeholder='{"test": true}'></textarea>
                
                <button class="btn btn-primary" onclick="testApi()">🚀 SEND REQUEST</button>
                <div id="apiResult" class="json-viewer" style="margin-top: 16px;"></div>
            </div>
        </div>
    </div>
    
    <!-- ROUTES PANEL -->
    <div id="panel-routes" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔄 DISCOVERED API ROUTES</div>
            <div class="card-body expanded">
                <input type="text" id="routeFilter" placeholder="Filter routes..." onkeyup="filterRoutes()" style="margin-bottom: 12px;">
                <div id="routesList">
                    <?php foreach ($apiRoutes as $route): ?>
                    <div class="trace-step info route-item" data-url="<?php echo htmlspecialchars($route['url']); ?>">
                        <strong><?php echo $route['method']; ?></strong> 
                        <?php echo htmlspecialchars($route['url']); ?>
                        <span style="font-size: 9px; color: #888;">(<?php echo round($route['size'] / 1024, 2); ?> KB)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ENVIRONMENT PANEL -->
    <div id="panel-env" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📋 ENVIRONMENT VARIABLES</div>
            <div class="card-body expanded" id="envList">
                <?php foreach ($allEnvVars as $key => $value): ?>
                    <?php if (strpos($key, 'API_KEY') === 0 || strpos($key, 'PG_') === 0 || strpos($key, 'APP_') === 0 || strpos($key, 'BASE_URL') !== false): ?>
                    <div class="trace-step info env-item" data-key="<?php echo htmlspecialchars($key); ?>">
                        <strong><?php echo htmlspecialchars($key); ?></strong>: 
                        <?php echo htmlspecialchars(substr($value, 0, 50)) . (strlen($value) > 50 ? '...' : ''); ?>
                        <?php if (strpos($key, 'API_KEY') === 0): ?>
                            <button class="btn" style="margin-left: 8px;" onclick="testWithKey('<?php echo htmlspecialchars(addslashes($value)); ?>')">🔑 Test</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- SWAP TRACE PANEL -->
    <div id="panel-trace" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔍 SWAP TRANSACTION TRACE</div>
            <div class="card-body expanded">
                <input type="text" id="swapReference" placeholder="Enter swap reference (e.g., VM-ABCD-123456)">
                <button class="btn btn-primary" onclick="traceSwap()">🔍 TRACE SWAP</button>
                <div id="traceResult" class="json-viewer" style="margin-top: 16px;"></div>
            </div>
        </div>
    </div>
</div>

<script>
// ============================================================
// UTILITY FUNCTIONS
// ============================================================
let allParticipantsData = <?php echo json_encode($allParticipants); ?>;
let countriesData = <?php echo json_encode($countries); ?>;

function showPanel(panelId) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const targetPanel = document.getElementById('panel-' + panelId);
    if (targetPanel) targetPanel.classList.add('active');
    if (event && event.target) event.target.classList.add('active');
}

function toggleCard(header) {
    const body = header.nextElementSibling;
    if (body) body.classList.toggle('expanded');
}

function addLog(message, type = 'info') {
    const logArea = document.getElementById('logArea');
    const timestamp = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `log-entry log-${type}`;
    div.style.color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : (type === 'warning' ? '#f59e0b' : '#3b82f6'));
    div.style.borderBottom = '1px solid #1a1a1a';
    div.style.padding = '4px 0';
    div.style.fontSize = '10px';
    div.innerHTML = `[${timestamp}] ${message}`;
    logArea.appendChild(div);
    logArea.scrollTop = logArea.scrollHeight;
    while (logArea.children.length > 100) logArea.removeChild(logArea.firstChild);
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
    tab.addEventListener('click', function(e) {
        const panelId = this.getAttribute('data-panel');
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        const targetPanel = document.getElementById('panel-' + panelId);
        if (targetPanel) targetPanel.classList.add('active');
        this.classList.add('active');
    });
});

// ============================================================
// API TESTING FUNCTIONS
// ============================================================
async function loadParticipantsForCountry() {
    const country = document.getElementById('apiCountrySelect').value;
    if (!country) return;
    
    addLog(`Loading participants for ${country}...`, 'info');
    const result = await apiCall('get_participants_for_country', { country: country });
    
    if (result.status === 'success') {
        const select = document.getElementById('apiParticipantSelect');
        select.innerHTML = '<option value="">Select Participant...</option>';
        
        for (const [code, p] of Object.entries(result.participants)) {
            select.innerHTML += `<option value="${code}" data-base-url="${p.base_url || ''}" data-endpoints='${JSON.stringify(p.resource_endpoints || {})}'>${code} (${p.type || 'Unknown'})</option>`;
        }
        addLog(`Loaded ${Object.keys(result.participants).length} participants`, 'success');
    } else {
        addLog(`Error loading participants: ${result.message}`, 'error');
    }
}

function loadEndpointsForParticipant() {
    const select = document.getElementById('apiParticipantSelect');
    const selectedOption = select.options[select.selectedIndex];
    const baseUrl = selectedOption.getAttribute('data-base-url') || '';
    let endpoints = {};
    
    try {
        endpoints = JSON.parse(selectedOption.getAttribute('data-endpoints') || '{}');
    } catch(e) {}
    
    const endpointSelect = document.getElementById('apiEndpointSelect');
    endpointSelect.innerHTML = '<option value="">Select Endpoint...</option>';
    endpointSelect.innerHTML += '<option value="health">Health Check (/health)</option>';
    
    for (const [name, path] of Object.entries(endpoints)) {
        endpointSelect.innerHTML += `<option value="${path}">${name} (${path})</option>`;
    }
    
    // Store base URL for later
    document.getElementById('apiParticipantSelect').setAttribute('data-current-base-url', baseUrl);
    
    // Set default payload based on participant type
    const participantCode = select.value;
    if (participantCode === 'CAZACOM') {
        document.getElementById('apiPayloadInput').value = JSON.stringify({ phone: "71234567", pin: "1234" }, null, 2);
    } else if (participantCode === 'ZURUBANK') {
        document.getElementById('apiPayloadInput').value = JSON.stringify({ reference: "TEST-001", asset_type: "ACCOUNT", amount: 100, account_number: "10000001" }, null, 2);
    } else {
        document.getElementById('apiPayloadInput').value = JSON.stringify({ test: true }, null, 2);
    }
    
    updateApiUrl();
    addLog(`Loaded endpoints for ${participantCode}`, 'info');
}

function updateApiUrl() {
    const baseUrl = document.getElementById('apiParticipantSelect').getAttribute('data-current-base-url') || '';
    const endpoint = document.getElementById('apiEndpointSelect').value;
    const urlInput = document.getElementById('apiUrlInput');
    
    if (baseUrl && endpoint) {
        let url = baseUrl.replace(/\/$/, '');
        if (endpoint === 'health') {
            url += '/health';
        } else {
            url += endpoint;
        }
        urlInput.value = url;
    } else if (baseUrl) {
        urlInput.value = baseUrl;
    } else {
        urlInput.value = '';
    }
}

async function testApi() {
    const url = document.getElementById('apiUrlInput').value;
    const apiKey = document.getElementById('apiKeyInput').value;
    let payload = document.getElementById('apiPayloadInput').value;
    const resultDiv = document.getElementById('apiResult');
    
    if (!url) {
        resultDiv.innerHTML = '<div class="trace-step error">❌ Please select a country, participant, and endpoint</div>';
        return;
    }
    
    // Parse payload
    let payloadObj = {};
    if (payload && payload.trim()) {
        try {
            payloadObj = JSON.parse(payload);
        } catch(e) {
            resultDiv.innerHTML = `<div class="trace-step error">❌ Invalid JSON: ${e.message}</div>`;
            return;
        }
    }
    
    resultDiv.innerHTML = '<div class="trace-step info">⏳ Testing API (30s timeout)...</div>';
    addLog(`Testing: ${url}`, 'info');
    if (apiKey) addLog(`Using API key: ${apiKey.substring(0, 15)}...`, 'info');
    
    const result = await apiCall('test_api', {
        url: url,
        api_key: apiKey,
        payload: JSON.stringify(payloadObj)
    });
    
    let html = '';
    
    if (result.http_code === 200) {
        html += `<div class="trace-step success">✅ SUCCESS (HTTP ${result.http_code}) - ${result.response_time}ms</div>`;
        addLog(`✅ API test SUCCESS: ${result.response_time}ms`, 'success');
    } else if (result.http_code === 401) {
        html += `<div class="trace-step error">❌ UNAUTHORIZED (HTTP 401)</div>`;
        html += `<div class="trace-step warning">Invalid API key. Check the participant's .env file for the correct key.</div>`;
        addLog(`❌ API test FAILED: Unauthorized`, 'error');
    } else if (result.http_code === 404) {
        html += `<div class="trace-step error">❌ NOT FOUND (HTTP 404)</div>`;
        html += `<div class="trace-step warning">Endpoint may need .php extension or path is incorrect</div>`;
        addLog(`❌ API test FAILED: Not Found`, 'error');
    } else if (result.http_code > 0) {
        html += `<div class="trace-step warning">⚠️ RESPONSE (HTTP ${result.http_code}) - ${result.response_time}ms</div>`;
        addLog(`API returned HTTP ${result.http_code}`, 'warning');
    } else if (result.error && result.error.includes('timed out')) {
        html += `<div class="trace-step error">❌ TIMEOUT</div>`;
        html += `<div class="trace-step warning">Service may be sleeping. Try again in 10 seconds.</div>`;
        addLog(`❌ Timeout - Service may be sleeping`, 'warning');
    } else {
        html += `<div class="trace-step error">❌ FAILED</div>`;
        html += `<div class="trace-step error">Error: ${result.error || 'Unknown'}</div>`;
        addLog(`❌ Test failed: ${result.error || 'Unknown'}`, 'error');
    }
    
    if (result.redirect_url) {
        html += `<div class="trace-step warning">🔄 Redirected to: ${result.redirect_url}</div>`;
    }
    
    if (result.response) {
        html += `<div class="trace-step success">📦 Response: <pre style="margin-top: 8px; white-space: pre-wrap;">${JSON.stringify(result.response, null, 2)}</pre></div>`;
    }
    
    resultDiv.innerHTML = html;
}

async function testParticipant(participantCode) {
    // Find participant in the data
    if (allParticipantsData[participantCode]) {
        const country = allParticipantsData[participantCode].discovered_country;
        if (country) {
            document.getElementById('apiCountrySelect').value = country;
            await loadParticipantsForCountry();
            setTimeout(() => {
                document.getElementById('apiParticipantSelect').value = participantCode;
                loadEndpointsForParticipant();
                document.getElementById('apiEndpointSelect').value = 'health';
                updateApiUrl();
                testApi();
            }, 500);
            return;
        }
    }
    addLog(`Participant ${participantCode} not found or has no country`, 'error');
}

function testWithKey(apiKey) {
    document.getElementById('apiKeyInput').value = apiKey;
    addLog(`Set API key: ${apiKey.substring(0, 20)}...`, 'info');
    testApi();
}

// ============================================================
// HEALTH AND DIAGNOSTICS
// ============================================================
async function runHealthCheck() {
    addLog('Running health check...', 'info');
    const result = await apiCall('network_check');
    const container = document.getElementById('quickResult');
    
    if (result.status === 'success') {
        let html = '<div class="trace-step info">🏥 HEALTH CHECK RESULTS</div>';
        let reachableCount = 0;
        
        for (const r of result.results) {
            if (r.reachable) reachableCount++;
            html += `<div class="trace-step ${r.reachable ? 'success' : 'error'}">
                <strong>${r.name}</strong><br>
                ${r.reachable ? `✅ HTTP ${r.http_code} (${r.time}ms)` : `❌ ${r.error || 'Unreachable'}`}
            </div>`;
        }
        
        html += `<div class="trace-step info">📊 Summary: ${reachableCount}/${result.results.length} services reachable</div>`;
        container.innerHTML = html;
        addLog(`Health check complete: ${reachableCount}/${result.results.length} reachable`, reachableCount === result.results.length ? 'success' : 'warning');
    }
}

function refreshAllData() {
    location.reload();
}

function filterRoutes() {
    const filter = document.getElementById('routeFilter').value.toLowerCase();
    const routes = document.querySelectorAll('.route-item');
    
    routes.forEach(route => {
        const url = route.getAttribute('data-url') || '';
        if (url.toLowerCase().includes(filter)) {
            route.style.display = 'block';
        } else {
            route.style.display = 'none';
        }
    });
}

// ============================================================
// SWAP TRACE
// ============================================================
async function traceSwap() {
    const swapRef = document.getElementById('swapReference').value;
    const container = document.getElementById('traceResult');
    
    if (!swapRef) {
        container.innerHTML = '<div class="trace-step error">❌ Enter a swap reference</div>';
        return;
    }
    
    container.innerHTML = '<div class="trace-step info">⏳ Tracing swap...</div>';
    addLog(`Tracing swap: ${swapRef}`, 'info');
    
    const result = await apiCall('trace_swap', { swap_ref: swapRef });
    
    if (result.status === 'success' && result.swap) {
        container.innerHTML = `
            <div class="trace-step success">✅ SWAP FOUND: ${result.swap.swap_reference}</div>
            <div class="trace-step info">📤 Source: ${result.swap.source_institution || 'N/A'}</div>
            <div class="trace-step info">📥 Destination: ${result.swap.destination_institution || 'N/A'}</div>
            <div class="trace-step info">💰 Amount: ${result.swap.amount} ${result.swap.currency || 'BWP'}</div>
            <div class="trace-step info">📅 Created: ${result.swap.created_at}</div>
            <div class="trace-step ${result.swap.status === 'completed' ? 'success' : 'warning'}">📊 Status: ${result.swap.status}</div>
            ${result.settlement ? `<div class="trace-step success">🏦 Settlement: ${result.settlement.from_participant} → ${result.settlement.to_participant} (${result.settlement.amount} BWP)</div>` : ''}
        `;
        addLog(`Swap traced successfully`, 'success');
    } else {
        container.innerHTML = `<div class="trace-step error">❌ Swap not found: ${result.message || 'No such reference'}</div>`;
        addLog(`Swap not found: ${swapRef}`, 'error');
    }
}

// ============================================================
// INITIALIZATION
// ============================================================
async function updateSystemOverview() {
    const container = document.getElementById('systemOverview');
    const env = await apiCall('get_env');
    
    container.innerHTML = `
        <div class="trace-step info">🌍 System: VouchMorph Diagnostic Center</div>
        <div class="trace-step info">📂 Countries: ${Object.keys(countriesData).length}</div>
        <div class="trace-step info">🏦 Participants: ${Object.keys(allParticipantsData).length}</div>
        <div class="trace-step info">🔄 API Routes: <?php echo count($apiRoutes); ?></div>
        <div class="trace-step info">🔑 API Keys: ${env.status === 'success' ? Object.keys(env.env || {}).filter(k => k.includes('API_KEY')).length : 0}</div>
    `;
}

// Expand all cards by default
document.querySelectorAll('.card-header').forEach(header => {
    const body = header.nextElementSibling;
    if (body) body.classList.add('expanded');
});

updateSystemOverview();
</script>
</body>
</html>
