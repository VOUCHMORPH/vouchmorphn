<?php
/**
 * VouchMorph WorkControl - Administrative Dashboard
 * Version: 3.0 - Code Integrity Scanner Integrated
 */

session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_login.php');
    exit;
}

define('PROJECT_ROOT', dirname(__DIR__, 2));
define('MAX_SCAN_FILES', 500);

// ============================================================
// SWAP TRACE DB CONNECTION
// ============================================================
// TODO: point this at your actual platform-level DB connection.
// If you already have a shared PDO bootstrap elsewhere in the project
// (e.g. Core\Database\DBConnection, used in swap/execute.php), prefer
// requiring and using that instead of building a second connection here.
function getTraceDb(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    try {
        $host = getenv('TRACE_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TRACE_DB_PORT') ?: '5432';
        $name = getenv('TRACE_DB_NAME') ?: 'vouchmorph';
        $user = getenv('TRACE_DB_USER') ?: 'postgres';
        $pass = getenv('TRACE_DB_PASS') ?: '';

        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$name}",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $pdo;
    } catch (\Throwable $e) {
        error_log('[WorkControl] Trace DB connection failed: ' . $e->getMessage());
        return null;
    }
}

// ============================================================
// PATH SANITIZATION (Security)
// ============================================================
function sanitizePath($path) {
    $path = str_replace('..', '', $path);
    $path = ltrim($path, '/');
    return $path;
}

function isPathAllowed($path) {
    $allowedDirs = ['public', 'src', 'tests', 'scripts', 'storage'];
    $pathParts = explode('/', ltrim($path, '/'));
    return in_array($pathParts[0], $allowedDirs);
}

// ============================================================
// DISCOVERY FUNCTIONS
// ============================================================
function discoverCountries() {
    $countries = [];
    $basePaths = [
        PROJECT_ROOT . '/src/Core/Config/Countries/',
        PROJECT_ROOT . '/src/Core/Config/countries/'
    ];
    
    foreach ($basePaths as $basePath) {
        if (is_dir($basePath)) {
            foreach (scandir($basePath) as $item) {
                if ($item === '.' || $item === '..' || !is_dir($basePath . $item)) continue;
                
                $countryPath = $basePath . $item;
                $countries[$item] = [
                    'name' => $item,
                    'path' => $countryPath,
                    'has_participants' => file_exists($countryPath . '/participants.yaml'),
                    'has_fees' => file_exists($countryPath . '/fees.json'),
                    'has_config' => file_exists($countryPath . '/config.php'),
                    'has_env' => file_exists($countryPath . '/.env'),
                    'has_database' => file_exists($countryPath . '/database.php')
                ];
            }
            break;
        }
    }
    return $countries;
}

function loadParticipantsForCountry($countryPath) {
    $participantsFile = $countryPath . '/participants.yaml';
    if (!file_exists($participantsFile)) return [];
    
    $data = json_decode(file_get_contents($participantsFile), true);
    $participants = $data['participants'] ?? $data ?? [];
    
    foreach ($participants as $code => &$p) {
        $p['code'] = $code;
        $p['base_url'] = $p['base_url'] ?? $p['baseUrl'] ?? null;
        $p['capabilities'] = $p['capabilities'] ?? ['asset_types' => []];
        $p['resource_endpoints'] = $p['resource_endpoints'] ?? [];
        $p['status'] = $p['status'] ?? 'ACTIVE';
    }
    
    return $participants;
}

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

function loadAllEnvVars() {
    $allEnv = [];
    $countries = discoverCountries();
    
    foreach ($countries as $countryInfo) {
        if ($countryInfo['has_env']) {
            $envFile = $countryInfo['path'] . '/.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, '#') === 0) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $key = trim($parts[0]);
                        $value = trim(trim($parts[1]), '"\'');
                        if (!isset($allEnv[$key])) $allEnv[$key] = $value;
                    }
                }
            }
        }
    }
    return $allEnv;
}

function discoverApiRoutes() {
    $routes = [];
    $apiPaths = [PROJECT_ROOT . '/public/api', PROJECT_ROOT . '/api'];
    
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
                
                $content = file_get_contents($file->getPathname());
                $method = 'GET';
                if (strpos($content, '$_POST') !== false) $method = 'POST';
                if (strpos($content, '$_GET') !== false && $method === 'GET') $method = 'BOTH';
                
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
// CODE INTEGRITY SCANNER
// ============================================================
function getAllProjectFiles() {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(PROJECT_ROOT, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = str_replace(PROJECT_ROOT, '', $file->getPathname());
        }
    }
    return $files;
}

function scanPhpSyntax($files) {
    $results = ['ok' => [], 'errors' => []];
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;
        
        $fullPath = PROJECT_ROOT . '/' . ltrim($file, '/');
        if (!file_exists($fullPath)) continue;
        
        exec("php -l " . escapeshellarg($fullPath) . " 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            $results['ok'][] = $file;
        } else {
            $results['errors'][] = [
                'file' => $file,
                'message' => implode("\n", $output)
            ];
        }
        $output = [];
    }
    return $results;
}

function scanMissingIncludes($files) {
    $issues = [];
    $patterns = [
        '/(?:require|include)(?:_once)?\s*[\'"]([^\'"]+)[\'"]/',
        '/(?:require|include)(?:_once)?\s*\([\'"]([^\'"]+)[\'"]\)/'
    ];
    
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') continue;
        
        $fullPath = PROJECT_ROOT . '/' . ltrim($file, '/');
        if (!file_exists($fullPath)) continue;
        
        $content = file_get_contents($fullPath);
        $dir = dirname($fullPath);
        
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches);
            foreach ($matches[1] as $included) {
                $resolved = realpath($dir . '/' . $included);
                if (!$resolved || !file_exists($resolved)) {
                    $issues[] = ['file' => $file, 'missing' => $included];
                }
            }
        }
    }
    return $issues;
}

function scanJsonConfigs($files) {
    $issues = [];
    $jsonFiles = ['participants.yaml', 'fees.json', 'banks.json', 'cards.json', 'communication.json', 'countries_registry.json'];
    
    foreach ($files as $file) {
        if (in_array(basename($file), $jsonFiles)) {
            $fullPath = PROJECT_ROOT . '/' . ltrim($file, '/');
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $issues[] = ['file' => $file, 'error' => json_last_error_msg()];
                }
            }
        }
    }
    return $issues;
}

function validateRoutes($routes) {
    $results = ['ok' => [], 'missing' => []];
    foreach ($routes as $route) {
        $fullPath = PROJECT_ROOT . '/' . ltrim($route['file'], '/');
        if (file_exists($fullPath) && is_readable($fullPath)) {
            $results['ok'][] = $route['url'];
        } else {
            $results['missing'][] = ['url' => $route['url'], 'file' => $route['file']];
        }
    }
    return $results;
}

// ============================================================
// INITIAL DATA
// ============================================================
$countries = discoverCountries();
$allParticipants = loadAllParticipants();
$apiRoutes = discoverApiRoutes();
$allEnvVars = loadAllEnvVars();
$allFiles = getAllProjectFiles();

// ============================================================
// AJAX HANDLERS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    // Run full code audit
    if ($action === 'run_audit') {
        $syntaxResults = scanPhpSyntax($allFiles);
        $includeIssues = scanMissingIncludes($allFiles);
        $jsonIssues = scanJsonConfigs($allFiles);
        $routeResults = validateRoutes($apiRoutes);
        
        $totalFiles = count($allFiles);
        $errors = count($syntaxResults['errors']) + count($jsonIssues);
        $warnings = count($includeIssues) + count($routeResults['missing']);
        $score = round((($totalFiles - $errors - $warnings) / $totalFiles) * 100);
        
        echo json_encode([
            'status' => 'success',
            'summary' => [
                'files_scanned' => $totalFiles,
                'php_files' => count($syntaxResults['ok']) + count($syntaxResults['errors']),
                'errors' => $errors,
                'warnings' => $warnings,
                'score' => $score,
                'grade' => $score >= 95 ? 'A+' : ($score >= 90 ? 'A' : ($score >= 80 ? 'B' : ($score >= 70 ? 'C' : 'F')))
            ],
            'details' => [
                'syntax_errors' => $syntaxResults['errors'],
                'syntax_ok' => $syntaxResults['ok'],
                'include_issues' => $includeIssues,
                'json_issues' => $jsonIssues,
                'routes_missing' => $routeResults['missing'],
                'routes_ok' => $routeResults['ok']
            ]
        ]);
        exit;
    }
    
    // Get participants for country
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
            echo json_encode(['status' => 'success', 'participants' => $participants, 'country' => $country]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Country not found']);
        }
        exit;
    }
    
    // Network health check
    if ($action === 'network_check') {
        $targets = ['VouchMorph' => 'https://vouchmorphn-production.up.railway.app/health'];
        foreach ($allParticipants as $code => $p) {
            if (!empty($p['base_url'])) {
                $targets[$code] = rtrim($p['base_url'], '/') . '/health';
            }
        }
        
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
                'reachable' => $httpCode > 0,
                'http_code' => $httpCode,
                'time' => $time,
                'error' => $error
            ];
        }
        echo json_encode(['status' => 'success', 'results' => $results]);
        exit;
    }
    
    // Get all participants
    if ($action === 'get_all_participants') {
        echo json_encode(['status' => 'success', 'participants' => $allParticipants]);
        exit;
    }
    
    // Get countries
    if ($action === 'get_countries') {
        echo json_encode(['status' => 'success', 'countries' => $countries]);
        exit;
    }
    
    // Get environment variables
    if ($action === 'get_env') {
        $safeEnv = [];
        foreach ($allEnvVars as $key => $value) {
            if (strpos($key, 'PASSWORD') !== false || strpos($key, 'SECRET') !== false) {
                $safeEnv[$key] = '********';
            } else {
                $safeEnv[$key] = substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '');
            }
        }
        echo json_encode(['status' => 'success', 'env' => $safeEnv]);
        exit;
    }
    
    // ============================================================
    // SWAP TRACKER
    // ============================================================

    // Recent swaps, with optional status filter
    if ($action === 'get_recent_swaps') {
        $db = getTraceDb();
        if (!$db) {
            echo json_encode(['status' => 'error', 'message' => 'Trace database unavailable']);
            exit;
        }

        $statusFilter = $_POST['status'] ?? 'all'; // all | success | failed | in_progress
        $limit = min((int)($_POST['limit'] ?? 50), 200);

        $where = '';
        $params = [];
        if (in_array($statusFilter, ['success', 'failed', 'in_progress'], true)) {
            $where = 'WHERE overall_status = :status';
            $params['status'] = $statusFilter;
        }

        $stmt = $db->prepare(
            "SELECT swap_reference, swap_type, source_institution, destination_institution,
                    routing_mode, overall_status, first_error_step, total_steps,
                    total_duration_ms, amount, currency, started_at, completed_at
             FROM swap_trace_summary
             $where
             ORDER BY started_at DESC
             LIMIT :limit"
        );
        foreach ($params as $k => $v) $stmt->bindValue(":$k", $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        echo json_encode(['status' => 'success', 'swaps' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Full step-by-step trace for one swap
    if ($action === 'get_swap_trace') {
        $db = getTraceDb();
        if (!$db) {
            echo json_encode(['status' => 'error', 'message' => 'Trace database unavailable']);
            exit;
        }

        $reference = $_POST['reference'] ?? '';
        if ($reference === '') {
            echo json_encode(['status' => 'error', 'message' => 'Missing swap reference']);
            exit;
        }

        $summaryStmt = $db->prepare("SELECT * FROM swap_trace_summary WHERE swap_reference = :ref");
        $summaryStmt->execute(['ref' => $reference]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

        if (!$summary) {
            echo json_encode(['status' => 'error', 'message' => 'No trace found for that reference']);
            exit;
        }

        $stepsStmt = $db->prepare(
            "SELECT step_index, category, step_name, status, message, details, institution, duration_ms, created_at
             FROM swap_traces
             WHERE swap_reference = :ref
             ORDER BY step_index ASC"
        );
        $stepsStmt->execute(['ref' => $reference]);
        $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($steps as &$step) {
            if ($step['details']) {
                $step['details'] = json_decode($step['details'], true);
            }
        }

        echo json_encode(['status' => 'success', 'summary' => $summary, 'steps' => $steps]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>VouchMorph · WorkControl</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'IBM Plex Mono', monospace; background: #0a0a0a; color: #e0e0e0; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { background: #001B44; border-bottom: 3px solid #FFDA63; padding: 20px 30px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .logo { font-size: 1.2rem; font-weight: 700; color: #fff; }
        .logo span { color: #FFDA63; }
        
        .stats { display: flex; gap: 20px; flex-wrap: wrap; }
        .stat { background: #1a1a1a; padding: 8px 16px; text-align: center; border-radius: 4px; }
        .stat-value { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 9px; color: #888; text-transform: uppercase; }
        .stat-value.critical { color: #ef4444; }
        .stat-value.warning { color: #f59e0b; }
        .stat-value.success { color: #10b981; }
        
        .tabs { display: flex; gap: 4px; background: #1a1a1a; padding: 8px; margin-bottom: 24px; flex-wrap: wrap; border-radius: 8px; }
        .tab { padding: 10px 20px; background: #0a0a0a; border: none; color: #888; cursor: pointer; font-family: monospace; font-size: 12px; border-radius: 4px; transition: all 0.2s; }
        .tab.active { background: #001B44; color: #FFDA63; }
        .tab:hover { background: #1a1a1a; color: #fff; }
        
        .panel { display: none; }
        .panel.active { display: block; }
        
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 20px; }
        .card { background: #111; border: 1px solid #333; border-radius: 8px; overflow: hidden; }
        .card-header { background: #1a1a1a; padding: 12px 16px; border-bottom: 1px solid #333; font-weight: 600; font-size: 12px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .card-header:hover { background: #222; }
        .card-body { padding: 16px; display: none; max-height: 500px; overflow-y: auto; }
        .card-body.expanded { display: block; }
        
        .btn { padding: 6px 12px; background: transparent; border: 1px solid #FFDA63; color: #FFDA63; cursor: pointer; font-family: monospace; font-size: 10px; border-radius: 4px; margin: 2px; }
        .btn:hover { background: #FFDA63; color: #000; }
        .btn-primary { background: #FFDA63; color: #000; }
        
        .trace-step { padding: 8px; margin: 6px 0; border-left: 3px solid; background: #1a1a1a; font-size: 11px; border-radius: 4px; }
        .trace-step.success { border-left-color: #10b981; }
        .trace-step.error { border-left-color: #ef4444; }
        .trace-step.info { border-left-color: #3b82f6; }
        .trace-step.warning { border-left-color: #f59e0b; }
        
        .json-viewer { background: #0a0a0a; padding: 12px; font-family: monospace; font-size: 10px; overflow-x: auto; white-space: pre-wrap; max-height: 300px; overflow-y: auto; border-radius: 4px; }
        .participant-item, .country-item { background: #0a0a0a; padding: 10px; margin-bottom: 8px; border-left: 2px solid #FFDA63; border-radius: 4px; }
        
        @media (max-width: 1024px) { .grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="logo">VOUCHMORPH <span>WORKCONTROL</span></div>
        <div class="stats">
            <div class="stat"><div class="stat-value" id="statCountries"><?php echo count($countries); ?></div><div class="stat-label">COUNTRIES</div></div>
            <div class="stat"><div class="stat-value" id="statParticipants"><?php echo count($allParticipants); ?></div><div class="stat-label">PARTICIPANTS</div></div>
            <div class="stat"><div class="stat-value" id="statRoutes"><?php echo count($apiRoutes); ?></div><div class="stat-label">ROUTES</div></div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab active" data-panel="dashboard">📊 DASHBOARD</button>
        <button class="tab" data-panel="audit">🔍 CODE AUDIT</button>
        <button class="tab" data-panel="countries">🌍 COUNTRIES</button>
        <button class="tab" data-panel="participants">🏦 PARTICIPANTS</button>
        <button class="tab" data-panel="health">🏥 HEALTH</button>
        <button class="tab" data-panel="env">📋 ENV</button>
        <button class="tab" data-panel="tracker">🔬 SWAP TRACKER</button>
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
                    <div id="logArea" style="height: 300px; overflow-y: auto; background: #0a0a0a; padding: 12px; font-size: 10px;">
                        <div class="trace-step info">✨ WorkControl v3.0 ready</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">⚡ QUICK ACTIONS</div>
            <div class="card-body expanded">
                <button class="btn" onclick="runHealthCheck()">🏥 HEALTH CHECK</button>
                <button class="btn" onclick="location.reload()">🔄 REFRESH</button>
            </div>
        </div>
    </div>
    
    <!-- CODE AUDIT PANEL -->
    <div id="panel-audit" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔍 FULL PROJECT AUDIT</div>
            <div class="card-body expanded">
                <button class="btn btn-primary" onclick="runFullAudit()">🚀 RUN AUDIT</button>
                <div id="auditSummary" style="margin-top: 20px;"></div>
                <div id="auditResults" style="margin-top: 20px;"></div>
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
                    <?php echo $info['has_participants'] ? '✅ participants.yaml' : '❌ participants.yaml'; ?> |
                    <?php echo $info['has_fees'] ? '✅ fees.json' : '❌ fees.json'; ?> |
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
                <strong><?php echo htmlspecialchars($code); ?></strong><br>
                <span style="font-size: 10px;">Country: <?php echo htmlspecialchars($p['discovered_country'] ?? 'Unknown'); ?></span><br>
                <span style="font-size: 10px;">Type: <?php echo htmlspecialchars($p['type'] ?? 'Unknown'); ?></span><br>
                <span style="font-size: 10px;">Base URL: <?php echo htmlspecialchars($p['base_url'] ?? 'Not configured'); ?></span><br>
                <span style="font-size: 10px;">Status: <?php echo htmlspecialchars($p['status'] ?? 'Unknown'); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- HEALTH PANEL -->
    <div id="panel-health" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🏥 NETWORK HEALTH CHECK</div>
            <div class="card-body expanded">
                <button class="btn btn-primary" onclick="runHealthCheck()">🏥 RUN CHECK</button>
                <div id="healthResults" class="json-viewer" style="margin-top: 12px;"></div>
            </div>
        </div>
    </div>
    
    <!-- ENVIRONMENT PANEL -->
    <div id="panel-env" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">📋 ENVIRONMENT VARIABLES</div>
            <div class="card-body expanded" id="envList"></div>
        </div>
    </div>

    <!-- SWAP TRACKER PANEL -->
    <div id="panel-tracker" class="panel">
        <div class="card">
            <div class="card-header" onclick="toggleCard(this)">🔎 LOOKUP BY REFERENCE</div>
            <div class="card-body expanded">
                <input type="text" id="traceRefInput" placeholder="SWAP_1785960245_e61c491ceadde6fd"
                       style="background:#0a0a0a; border:1px solid #333; color:#e0e0e0; padding:8px 12px; font-family:monospace; font-size:12px; width:340px; border-radius:4px;">
                <button class="btn btn-primary" onclick="lookupSwapTrace()">TRACE</button>
            </div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">📜 RECENT SWAPS</div>
                <div class="card-body expanded">
                    <div style="margin-bottom: 12px;">
                        <button class="btn btn-primary" data-status="all" onclick="loadRecentSwaps('all', this)">ALL</button>
                        <button class="btn" data-status="failed" onclick="loadRecentSwaps('failed', this)">FAILED</button>
                        <button class="btn" data-status="success" onclick="loadRecentSwaps('success', this)">SUCCESS</button>
                        <button class="btn" data-status="in_progress" onclick="loadRecentSwaps('in_progress', this)">IN PROGRESS</button>
                        <button class="btn" onclick="loadRecentSwaps(currentSwapFilter)">🔄</button>
                    </div>
                    <div id="recentSwapsList"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header" onclick="toggleCard(this)">🧬 SWAP DETAIL</div>
                <div class="card-body expanded" id="swapTraceDetail">
                    <div class="trace-step info">Select a swap from the list, or paste a reference above.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Global data
let participants = <?php echo json_encode(array_values($allParticipants)); ?>;

function addLog(msg, type = 'info') {
    const logArea = document.getElementById('logArea');
    const timestamp = new Date().toLocaleTimeString();
    const div = document.createElement('div');
    div.className = `trace-step ${type}`;
    div.innerHTML = `[${timestamp}] ${msg}`;
    logArea.appendChild(div);
    logArea.scrollTop = logArea.scrollHeight;
}

async function apiCall(action, data = {}) {
    const formData = new FormData();
    formData.append('action', action);
    for (let key in data) formData.append(key, data[key]);
    
    const response = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    });
    return await response.json();
}

function toggleCard(header) {
    header.nextElementSibling.classList.toggle('expanded');
}

// Tab switching
document.querySelectorAll('.tab').forEach(tab => {
    tab.addEventListener('click', function() {
        const panelId = this.dataset.panel;
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        document.getElementById('panel-' + panelId).classList.add('active');
        this.classList.add('active');
    });
});

// ============================================================
// CODE AUDIT
// ============================================================
async function runFullAudit() {
    const resultsDiv = document.getElementById('auditResults');
    const summaryDiv = document.getElementById('auditSummary');
    
    resultsDiv.innerHTML = '<div class="trace-step info">⏳ Scanning files...</div>';
    addLog('Starting full code audit...', 'info');
    
    const result = await apiCall('run_audit');
    
    if (result.status === 'success') {
        const s = result.summary;
        
        summaryDiv.innerHTML = `
            <div class="stats">
                <div class="stat"><div class="stat-value ${s.score >= 90 ? 'success' : (s.score >= 70 ? 'warning' : 'critical')}">${s.score}%</div><div class="stat-label">SCORE</div></div>
                <div class="stat"><div class="stat-value">${s.files_scanned}</div><div class="stat-label">FILES</div></div>
                <div class="stat"><div class="stat-value ${s.errors > 0 ? 'critical' : 'success'}">${s.errors}</div><div class="stat-label">ERRORS</div></div>
                <div class="stat"><div class="stat-value ${s.warnings > 0 ? 'warning' : 'success'}">${s.warnings}</div><div class="stat-label">WARNINGS</div></div>
                <div class="stat"><div class="stat-value">${s.grade}</div><div class="stat-label">GRADE</div></div>
            </div>
        `;
        
        let html = '';
        
        if (result.details.syntax_errors.length > 0) {
            html += `<div class="card" style="margin-top: 16px;"><div class="card-header" onclick="toggleCard(this)">❌ SYNTAX ERRORS (${result.details.syntax_errors.length})</div><div class="card-body">`;
            result.details.syntax_errors.forEach(err => {
                html += `<div class="trace-step error"><strong>${err.file}</strong><br><pre style="font-size: 10px;">${err.message}</pre></div>`;
            });
            html += `</div></div>`;
        }
        
        if (result.details.include_issues.length > 0) {
            html += `<div class="card" style="margin-top: 16px;"><div class="card-header" onclick="toggleCard(this)">⚠️ MISSING INCLUDES (${result.details.include_issues.length})</div><div class="card-body">`;
            result.details.include_issues.forEach(inc => {
                html += `<div class="trace-step warning"><strong>${inc.file}</strong><br>Missing: ${inc.missing}</div>`;
            });
            html += `</div></div>`;
        }
        
        if (result.details.json_issues.length > 0) {
            html += `<div class="card" style="margin-top: 16px;"><div class="card-header" onclick="toggleCard(this)">❌ JSON ERRORS (${result.details.json_issues.length})</div><div class="card-body">`;
            result.details.json_issues.forEach(json => {
                html += `<div class="trace-step error"><strong>${json.file}</strong><br>${json.error}</div>`;
            });
            html += `</div></div>`;
        }
        
        if (result.details.routes_missing.length > 0) {
            html += `<div class="card" style="margin-top: 16px;"><div class="card-header" onclick="toggleCard(this)">🔌 MISSING ROUTES (${result.details.routes_missing.length})</div><div class="card-body">`;
            result.details.routes_missing.forEach(route => {
                html += `<div class="trace-step error"><strong>${route.url}</strong><br>File: ${route.file}</div>`;
            });
            html += `</div></div>`;
        }
        
        if (result.details.syntax_errors.length === 0 && result.details.json_issues.length === 0) {
            html += `<div class="trace-step success" style="margin-top: 16px;">✅ No critical errors found.</div>`;
        }
        
        resultsDiv.innerHTML = html;
        addLog(`Audit complete: ${s.score}% - ${s.grade} (${s.errors} errors, ${s.warnings} warnings)`, s.errors > 0 ? 'error' : 'success');
    } else {
        resultsDiv.innerHTML = '<div class="trace-step error">❌ Audit failed</div>';
        addLog('Audit failed', 'error');
    }
}

// ============================================================
// HEALTH CHECK
// ============================================================
async function runHealthCheck() {
    addLog('Running health check...', 'info');
    const result = await apiCall('network_check');
    const container = document.getElementById('healthResults');
    
    if (result.status === 'success') {
        let html = '<div class="trace-step info">🏥 HEALTH CHECK RESULTS</div>';
        let reachable = 0;
        
        for (const r of result.results) {
            if (r.reachable) reachable++;
            html += `<div class="trace-step ${r.reachable ? 'success' : 'error'}">
                <strong>${r.name}</strong><br>
                ${r.reachable ? `✅ HTTP ${r.http_code} (${r.time}ms)` : `❌ ${r.error || 'Unreachable'}`}
            </div>`;
        }
        
        html += `<div class="trace-step info">📊 ${reachable}/${result.results.length} reachable</div>`;
        container.innerHTML = html;
        addLog(`Health check: ${reachable}/${result.results.length} reachable`, 'info');
    }
}

// ============================================================
// ENVIRONMENT
// ============================================================
async function loadEnvironment() {
    const result = await apiCall('get_env');
    if (result.status === 'success') {
        const container = document.getElementById('envList');
        let html = '';
        for (const [key, value] of Object.entries(result.env)) {
            html += `<div class="trace-step info"><strong>${key}</strong>: ${value}</div>`;
        }
        container.innerHTML = html || '<div class="trace-step info">No environment variables found</div>';
    }
}

// ============================================================
// SWAP TRACKER
// ============================================================
let currentSwapFilter = 'all';

function fmtDuration(ms) {
    if (ms === null || ms === undefined) return '';
    if (ms < 1000) return `${Number(ms).toFixed(1)}ms`;
    return `${(ms / 1000).toFixed(2)}s`;
}

function statusBadge(status) {
    const colors = { success: '#10b981', failed: '#ef4444', in_progress: '#f59e0b' };
    const labels = { success: 'SUCCESS', failed: 'FAILED', in_progress: 'IN PROGRESS' };
    const c = colors[status] || '#888';
    return `<span style="color:${c}; font-weight:700;">${labels[status] || status}</span>`;
}

async function loadRecentSwaps(status = 'all', btn = null) {
    currentSwapFilter = status;
    if (btn) {
        document.querySelectorAll('#panel-tracker .card:nth-child(2) .btn[data-status]').forEach(b => b.classList.remove('btn-primary'));
        btn.classList.add('btn-primary');
    }

    const container = document.getElementById('recentSwapsList');
    container.innerHTML = '<div class="trace-step info">⏳ Loading...</div>';

    const result = await apiCall('get_recent_swaps', { status, limit: 50 });
    if (result.status !== 'success') {
        container.innerHTML = `<div class="trace-step error">❌ ${result.message || 'Failed to load'}</div>`;
        return;
    }

    if (result.swaps.length === 0) {
        container.innerHTML = '<div class="trace-step info">No swaps found for this filter.</div>';
        return;
    }

    let html = '';
    result.swaps.forEach(s => {
        html += `<div class="trace-step ${s.overall_status === 'failed' ? 'error' : (s.overall_status === 'success' ? 'success' : 'warning')}"
                      style="cursor:pointer;" onclick="loadSwapTrace('${s.swap_reference}')">
            <strong>${s.swap_reference}</strong> ${statusBadge(s.overall_status)}<br>
            <span style="font-size:10px; color:#888;">
                ${s.swap_type || '-'} · ${s.source_institution || '?'} → ${s.destination_institution || '?'}
                · ${s.routing_mode || '-'} · ${s.amount ?? '-'} ${s.currency || ''}
                · ${s.total_steps} steps${s.total_duration_ms ? ' · ' + fmtDuration(s.total_duration_ms) : ''}
            </span>
            ${s.first_error_step ? `<br><span style="font-size:10px; color:#ef4444;">First error: ${s.first_error_step}</span>` : ''}
        </div>`;
    });
    container.innerHTML = html;
}

function lookupSwapTrace() {
    const ref = document.getElementById('traceRefInput').value.trim();
    if (!ref) return;
    loadSwapTrace(ref);
}

async function loadSwapTrace(reference) {
    const container = document.getElementById('swapTraceDetail');
    container.innerHTML = '<div class="trace-step info">⏳ Loading trace...</div>';
    document.getElementById('traceRefInput').value = reference;

    const result = await apiCall('get_swap_trace', { reference });
    if (result.status !== 'success') {
        container.innerHTML = `<div class="trace-step error">❌ ${result.message || 'Failed to load trace'}</div>`;
        return;
    }

    const s = result.summary;
    let html = `<div class="trace-step info" style="margin-bottom:12px;">
        <strong>${s.swap_reference}</strong> ${statusBadge(s.overall_status)}<br>
        <span style="font-size:10px; color:#888;">
            ${s.swap_type || '-'} · ${s.source_institution || '?'} → ${s.destination_institution || '?'}
            · routing: ${s.routing_mode || '-'} · ${s.amount ?? '-'} ${s.currency || ''}<br>
            started: ${s.started_at || '-'} · completed: ${s.completed_at || '-'}
            · total: ${fmtDuration(s.total_duration_ms)} · ${s.total_steps} steps
        </span>
    </div>`;

    if (result.steps.length === 0) {
        html += '<div class="trace-step warning">No individual steps recorded for this swap.</div>';
    } else {
        result.steps.forEach(step => {
            const detailsHtml = step.details
                ? `<div class="json-viewer" style="margin-top:6px;">${JSON.stringify(step.details, null, 2)}</div>`
                : '';
            html += `<div class="trace-step ${step.status}">
                <strong>#${step.step_index} [${step.category}] ${step.step_name}</strong>
                ${step.institution ? `<span style="font-size:10px; color:#888;"> · ${step.institution}</span>` : ''}
                <span style="font-size:10px; color:#888; float:right;">${fmtDuration(step.duration_ms)}</span>
                ${step.message ? `<br><span style="font-size:11px;">${step.message}</span>` : ''}
                ${detailsHtml}
            </div>`;
        });
    }

    container.innerHTML = html;
}

// ============================================================
// INIT
// ============================================================
document.querySelectorAll('.card-header').forEach(header => {
    header.nextElementSibling.classList.add('expanded');
});

loadEnvironment();

// Auto-run audit on first load (optional - remove if you prefer manual)
// setTimeout(() => runFullAudit(), 2000);
</script>
</body>
</html>
