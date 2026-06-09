<?php
// public/health.php

header('Content-Type: application/json');

$status = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'php_version' => PHP_VERSION,
    'loaded_extensions' => get_loaded_extensions(),
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'pdo_pgsql_loaded' => in_array('pgsql', PDO::getAvailableDrivers()),
];

// Check DATABASE_URL
$databaseUrl = getenv('DATABASE_URL');
if ($databaseUrl) {
    $status['database_url'] = 'set';
    
    // Try to connect if driver is available
    if (in_array('pgsql', PDO::getAvailableDrivers())) {
        try {
            $pdo = new PDO($databaseUrl);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1');
            $status['database'] = 'connected';
            
            // Get database info
            $stmt = $pdo->query('SELECT current_database() as db, current_user as user');
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            $status['database_name'] = $info['db'] ?? 'unknown';
            $status['database_user'] = $info['user'] ?? 'unknown';
            
        } catch (Exception $e) {
            $status['database'] = 'error';
            $status['database_error'] = $e->getMessage();
            $status['status'] = 'degraded';
        }
    } else {
        $status['database'] = 'pdo_pgsql_missing';
        $status['status'] = 'degraded';
    }
} else {
    $status['database_url'] = 'not_set';
    $status['database'] = 'not_configured';
    $status['status'] = 'degraded';
}

if ($status['status'] === 'ok') {
    http_response_code(200);
} else {
    http_response_code(500);
}

echo json_encode($status, JSON_PRETTY_PRINT);
