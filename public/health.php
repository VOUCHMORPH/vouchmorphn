<?php
// public/health.php - Enhanced health check using DATABASE_URL

$maxRetries = 5;
$initialDelayMs = 100;
$maxDelayMs = 2000;
$totalTimeoutSeconds = 10;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'liveness';

// Parse DATABASE_URL
$databaseUrl = getenv('DATABASE_URL');
$dbConfig = parseDatabaseUrl($databaseUrl);

// If DATABASE_URL parsing fails, try individual env vars
if (!$dbConfig) {
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: 'postgres.railway.internal',
        'port' => getenv('DB_PORT') ?: '5432',
        'dbname' => getenv('DB_DATABASE') ?: 'postgres',
        'user' => getenv('DB_USERNAME') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: ''
    ];
}

$startTime = microtime(true);

$status = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'service' => 'vouchmorph',
    'mode' => $mode,
    'checks' => [
        'php' => [
            'status' => 'pass',
            'message' => 'PHP is running',
            'version' => PHP_VERSION
        ]
    ]
];

// Check database with retry logic
$dbStatus = checkDatabaseWithRetry(
    $dbConfig['host'],
    $dbConfig['port'],
    $dbConfig['dbname'],
    $dbConfig['user'],
    $dbConfig['password'],
    $maxRetries,
    $initialDelayMs,
    $maxDelayMs,
    $totalTimeoutSeconds
);

$status['checks']['database'] = $dbStatus;
$status['checks']['database']['connection_config'] = [
    'host' => $dbConfig['host'],
    'dbname' => $dbConfig['dbname'],
    'user' => $dbConfig['user']
];

// Determine overall status based on mode
if ($mode === 'liveness') {
    if ($dbStatus['status'] === 'pass') {
        $status['status'] = 'healthy';
        $status['message'] = 'All systems operational';
        $httpStatus = 200;
    } else {
        $status['status'] = 'degraded';
        $status['message'] = 'Application running, database retry in progress';
        $httpStatus = 200;
    }
} elseif ($mode === 'readiness') {
    if ($dbStatus['status'] === 'pass') {
        $status['status'] = 'healthy';
        $status['message'] = 'Ready to accept traffic';
        $httpStatus = 200;
    } else {
        $status['status'] = 'unhealthy';
        $status['message'] = 'Database unavailable - not ready';
        $httpStatus = 503;
    }
} else {
    if ($dbStatus['status'] === 'pass') {
        $status['status'] = 'healthy';
        $status['message'] = 'All systems operational';
        $httpStatus = 200;
    } else {
        $status['status'] = 'unhealthy';
        $status['message'] = 'Database connection failed';
        $httpStatus = 503;
    }
}

$status['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
$status['server'] = gethostname();

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');
http_response_code($httpStatus ?? 200);

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

/**
 * Parse DATABASE_URL into components
 */
function parseDatabaseUrl($url) {
    if (empty($url)) {
        return null;
    }
    
    // Parse the URL
    $parsed = parse_url($url);
    if (!$parsed) {
        return null;
    }
    
    // Handle postgres:// or postgresql://
    $scheme = $parsed['scheme'] ?? '';
    if (!in_array($scheme, ['postgres', 'postgresql', 'pgsql'])) {
        return null;
    }
    
    return [
        'host' => $parsed['host'] ?? 'localhost',
        'port' => $parsed['port'] ?? '5432',
        'dbname' => ltrim($parsed['path'] ?? '', '/'),
        'user' => $parsed['user'] ?? '',
        'password' => $parsed['pass'] ?? ''
    ];
}

function checkDatabaseWithRetry($host, $port, $dbname, $user, $password, $maxRetries, $initialDelayMs, $maxDelayMs, $totalTimeoutSeconds) {
    $startTime = time();
    $attempt = 0;
    $lastError = null;
    $delayMs = $initialDelayMs;
    $attempts = [];
    
    while ($attempt < $maxRetries) {
        $attempt++;
        $attemptStart = microtime(true);
        
        if ((time() - $startTime) > $totalTimeoutSeconds) {
            return [
                'status' => 'fail',
                'message' => 'Connection timed out after ' . $totalTimeoutSeconds . ' seconds',
                'attempts' => $attempt - 1,
                'attempts_log' => $attempts,
                'last_error' => $lastError
            ];
        }
        
        $result = checkDatabaseConnection($host, $port, $dbname, $user, $password);
        $attemptTime = round((microtime(true) - $attemptStart) * 1000, 2);
        
        if ($result['status'] === 'pass') {
            return array_merge($result, [
                'attempts' => $attempt,
                'attempts_log' => $attempts,
                'final_attempt_time_ms' => $attemptTime
            ]);
        }
        
        $lastError = $result['message'];
        $attempts[] = [
            'attempt' => $attempt,
            'status' => 'fail',
            'error' => $lastError,
            'time_ms' => $attemptTime
        ];
        
        if ($attempt < $maxRetries) {
            usleep($delayMs * 1000);
            $delayMs = min($delayMs * 2 + rand(0, 100), $maxDelayMs);
        }
    }
    
    return [
        'status' => 'fail',
        'message' => 'Database connection failed after ' . $maxRetries . ' attempts',
        'attempts' => $maxRetries,
        'attempts_log' => $attempts,
        'last_error' => $lastError
    ];
}

function checkDatabaseConnection($host, $port, $dbname, $user, $password) {
    $startTime = microtime(true);
    
    try {
        if (empty($host) || empty($user) || empty($dbname)) {
            return [
                'status' => 'fail',
                'message' => 'Database configuration incomplete',
                'response_time_ms' => 0
            ];
        }
        
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=2;options='--client_encoding=UTF8'";
        
        $options = [
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO($dsn, $user, $password, $options);
        
        $stmt = $pdo->query('SELECT 1 as check_result, current_database() as db_name, current_user as db_user');
        $result = $stmt->fetch();
        
        if ($result && $result['check_result'] == 1) {
            return [
                'status' => 'pass',
                'message' => 'Database connection successful',
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'database' => $result['db_name'] ?? $dbname,
                'user' => $result['db_user'] ?? $user
            ];
        }
        
        return [
            'status' => 'fail',
            'message' => 'Query returned unexpected result',
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
        
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();
        $errorType = 'unknown';
        
        if (strpos($errorMessage, 'Connection refused') !== false) {
            $errorType = 'connection_refused';
            $errorMessage = 'Connection refused - PostgreSQL not ready';
        } elseif (strpos($errorMessage, 'timeout') !== false) {
            $errorType = 'timeout';
            $errorMessage = 'Connection timeout';
        } elseif (strpos($errorMessage, 'password') !== false || strpos($errorMessage, 'authentication') !== false) {
            $errorType = 'authentication';
            $errorMessage = 'Authentication failed - check database credentials';
        } elseif (strpos($errorMessage, 'database') !== false && strpos($errorMessage, 'does not exist') !== false) {
            $errorType = 'database_not_found';
            $errorMessage = 'Database does not exist';
        } elseif (strpos($errorMessage, 'could not translate host name') !== false) {
            $errorType = 'host_resolution';
            $errorMessage = 'Could not resolve host';
        }
        
        return [
            'status' => 'fail',
            'message' => $errorMessage,
            'error_type' => $errorType,
            'error_code' => $e->getCode(),
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
    } catch (Exception $e) {
        return [
            'status' => 'fail',
            'message' => 'Unexpected error: ' . $e->getMessage(),
            'error_code' => $e->getCode(),
            'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
        ];
    }
}
