<?php
// Database configuration for Railway and local development
// src/Core/Config/Countries/Botswana/database.php

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

// Define constants ONLY if not already defined
if (!defined('DB_HOST')) define('DB_HOST', $host);
if (!defined('DB_PORT')) define('DB_PORT', $port);
if (!defined('DB_NAME')) define('DB_NAME', $database);
if (!defined('DB_USER')) define('DB_USER', $username);
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $password);
if (!defined('DB_DRIVER')) define('DB_DRIVER', 'pgsql');

// Return configuration array
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
