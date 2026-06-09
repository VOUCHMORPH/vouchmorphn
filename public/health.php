<?php
header('Content-Type: application/json');

$status = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'extensions' => [
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'pgsql' => extension_loaded('pgsql'),
    ],
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'database_url' => getenv('DATABASE_URL') ? 'set' : 'not set',
];

$status['pdo_pgsql_loaded'] = in_array('pgsql', PDO::getAvailableDrivers());

// Try DB connection ONLY if driver exists
if (getenv('DATABASE_URL') && $status['pdo_pgsql_loaded']) {
    try {
        $pdo = new PDO(getenv('DATABASE_URL'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query('SELECT 1');

        $status['database'] = 'connected';
    } catch (Exception $e) {
        $status['database'] = 'error';
        $status['database_error'] = $e->getMessage();
        $status['status'] = 'degraded';
    }
} else {
    $status['database'] = 'not tested';
    $status['status'] = 'degraded';

    if (!getenv('DATABASE_URL')) {
        $status['reason'] = 'DATABASE_URL missing';
    }

    if (!$status['pdo_pgsql_loaded']) {
        $status['reason'] = 'pdo_pgsql driver not loaded';
    }
}

http_response_code($status['status'] === 'ok' ? 200 : 500);

echo json_encode($status, JSON_PRETTY_PRINT);
