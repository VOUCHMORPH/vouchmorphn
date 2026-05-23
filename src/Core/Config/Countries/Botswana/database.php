<?php
// Database configuration for Railway and local development

// Parse DATABASE_URL if available
$database_url = getenv('DATABASE_URL');

if ($database_url) {
    // Parse Railway's DATABASE_URL
    $db = parse_url($database_url);
    
    $host = $db['host'];
    $port = $db['port'] ?? '5432';
    $database = ltrim($db['path'], '/');
    $username = $db['user'];
    $password = $db['pass'];
} else {
    // Fallback for local development
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '5432';
    $database = getenv('DB_NAME') ?: 'swap_system_bw';
    $username = getenv('DB_USER') ?: 'postgres';
    $password = getenv('DB_PASSWORD') ?: 'StrongPassword!';
}

// Define constants for backward compatibility
define('DB_HOST', $host);
define('DB_PORT', $port);
define('DB_NAME', $database);
define('DB_USER', $username);
define('DB_PASSWORD', $password);
define('DB_DRIVER', 'pgsql');

// CRITICAL: Return array for bootstrap.php
return [
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $username,
    'password' => $password,
    'driver' => 'pgsql',
    'charset' => 'utf8',
    'collation' => 'utf8_unicode_ci',
    'prefix' => '',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
];

// Keep connection function for other parts of the app
function getDBConnection() {
    try {
        $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}
