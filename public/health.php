<?php
// public/health.php - Enhanced health check with resilience for Railway

// Configuration
$maxRetries = 5;
$initialDelayMs = 100;  // Start with 100ms
$maxDelayMs = 2000;     // Max 2 seconds between retries
$totalTimeoutSeconds = 10;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'liveness';

// Database configuration from environment
$dbHost = getenv('DB_HOST') ?: 'postgres.railway.internal';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_DATABASE') ?: 'postgres';
$dbUser = getenv('DB_USERNAME') ?: 'postgres';
$dbPassword = getenv('DB_PASSWORD') ?: '';

$startTime = microtime(true);

// Initialize status response
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
    $dbHost, $dbPort, $dbName, $dbUser, $dbPassword,
    $maxRetries, $initialDelayMs, $maxDelayMs, $totalTimeoutSeconds
);

$status['checks']['database'] = $dbStatus;

// Determine overall status based on mode
if ($mode === 'liveness') {
    // Liveness: Keep container alive even if DB is temporarily down
    if ($dbStatus['status'] === 'pass') {
        $status['status'] = 'healthy';
        $status['message'] = 'All systems operational';
        $httpStatus = 200;
    } else {
        $status['status'] = 'degraded';
        $status['message'] = 'Application running, database retry in progress';
        $httpStatus = 200; // Still return 200 to prevent container restart
    }
} elseif ($mode === 'readiness') {
    // Readiness: Only ready when DB is up and accepting connections
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
    // Deep: Full health check (strict)
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

// Add performance metrics
$status['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
$status['server'] = gethostname();

// Send response headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');
http_response_code($httpStatus ?? 200);

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;

/**
 * Check database connection with retry logic and exponential backoff
 */
function checkDatabaseWithRetry($host, $port, $dbname, $user, $password, $maxRetries, $initialDelayMs, $maxDelayMs, $totalTimeoutSeconds) {
    $startTime = time();
    $attempt = 0;
    $lastError = null;
    $delayMs = $initialDelayMs;
    $attempts = [];
    
    while ($attempt < $maxRetries) {
        $attempt++;
        $attemptStart = microtime(true);
        
        // Check if we've exceeded total timeout
        if ((time() - $startTime) > $totalTimeoutSeconds) {
            return [
                'status' => 'fail',
                'message' => 'Connection timed out after ' . $totalTimeoutSeconds . ' seconds',
                'attempts' => $attempt - 1,
                'attempts_log' => $attempts,
                'last_error' => $lastError
            ];
        }
        
        // Try to connect
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
        
        // If this wasn't the last attempt, wait before retrying
        if ($attempt < $maxRetries) {
            usleep($delayMs * 1000); // Convert to microseconds
            
            // Exponential backoff with jitter to prevent thundering herd
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

/**
 * Single database connection attempt
 */
function checkDatabaseConnection($host, $port, $dbname, $user, $password) {
    $startTime = microtime(true);
    
    try {
        // Validate required parameters
        if (empty($host)) {
            return [
                'status' => 'fail',
                'message' => 'Database host not configured',
                'response_time_ms' => 0
            ];
        }
        
        // Build DSN with proper connection timeout
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};connect_timeout=2;options='--client_encoding=UTF8'";
        
        // Set PDO attributes for connection
        $options = [
            PDO::ATTR_TIMEOUT => 2,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        // Attempt connection
        $pdo = new PDO($dsn, $user, $password, $options);
        
        // Simple query to verify the connection is working
        $stmt = $pdo->query('SELECT 1 as check_result, NOW() as server_time, version() as pg_version');
        $result = $stmt->fetch();
        
        if ($result && $result['check_result'] == 1) {
            return [
                'status' => 'pass',
                'message' => 'Database connection successful',
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                'server_time' => $result['server_time'] ?? null,
                'pg_version' => $result['pg_version'] ?? null
            ];
        } else {
            return [
                'status' => 'fail',
                'message' => 'Database query returned unexpected result',
                'response_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];
        }
        
    } catch (PDOException $e) {
        $errorMessage = $e->getMessage();
        $errorType = 'unknown';
        
        // Classify error for better reporting
        if (strpos($errorMessage, 'Connection refused') !== false) {
            $errorType = 'connection_refused';
            $errorMessage = 'Connection refused - PostgreSQL may not be ready yet';
        } elseif (strpos($errorMessage, 'timeout') !== false) {
            $errorType = 'timeout';
            $errorMessage = 'Connection timeout - PostgreSQL not responding';
        } elseif (strpos($errorMessage, 'database') !== false && strpos($errorMessage, 'does not exist') !== false) {
            $errorType = 'database_not_found';
            $errorMessage = 'Database does not exist - check DB_DATABASE env var';
        } elseif (strpos($errorMessage, 'password') !== false || strpos($errorMessage, 'authentication') !== false) {
            $errorType = 'authentication';
            $errorMessage = 'Authentication failed - check DB_USERNAME and DB_PASSWORD';
        } elseif (strpos($errorMessage, 'could not translate host name') !== false) {
            $errorType = 'host_resolution';
            $errorMessage = 'Could not resolve host - check DB_HOST';
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
