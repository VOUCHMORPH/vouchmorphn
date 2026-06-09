<?php
// public/health.php

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

// Try to connect to database if DATABASE_URL is set
if (getenv('DATABASE_URL')) {
    try {
        $pdo = new PDO(getenv('DATABASE_URL'));
        $pdo->query('SELECT 1');
        $status['database'] = 'connected';
    } catch (Exception $e) {
        $status['database'] = 'error';
        $status['database_error'] = $e->getMessage();
        $status['status'] = 'degraded';
    }
} else {
    $status['database'] = 'not configured';
    $status['status'] = 'degraded';
}

if ($status['status'] === 'ok') {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($status, JSON_PRETTY_PRINT);
