<?php
declare(strict_types=1);

// Check if PDO_PGSQL is available BEFORE trying to connect
if (!extension_loaded('pdo_pgsql')) {
    echo "<pre>";
    echo "❌ PDO_PGSQL EXTENSION NOT LOADED\n";
    echo "Available extensions: " . implode(", ", get_loaded_extensions()) . "\n";
    echo "Available PDO drivers: " . implode(", ", PDO::getAvailableDrivers()) . "\n";
    echo "\nFIX: Install pdo_pgsql extension on Railway:\n";
    echo "  1. Update nixpacks.toml:\n";
    echo "     [phases.setup]\n";
    echo "     nixPkgs = [\"php83\", \"php83Extensions.pdo_pgsql\", \"php83Extensions.pgsql\"]\n";
    echo "  2. Or run in console: docker-php-ext-install pdo_pgsql\n";
    echo "</pre>";
    exit(1);
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tests/System/SwapServiceFullTestSuite.php';

/**
 * Safe PostgreSQL connection bootstrap
 */
function connectDatabase(): PDO
{
    $url = getenv('DATABASE_URL');

    if (!$url) {
        throw new RuntimeException("DATABASE_URL not set");
    }

    $parts = parse_url($url);

    if (!$parts) {
        throw new RuntimeException("Invalid DATABASE_URL format");
    }

    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? 5432;
    $db   = ltrim($parts['path'] ?? '', '/');
    $user = $parts['user'] ?? '';
    $pass = $parts['pass'] ?? '';

    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

try {
    $db = connectDatabase();

    echo "<pre>";
    echo "✅ DATABASE CONNECTED\n\n";

    $test = new SwapServiceFullTestSuite($db);
    $test->run();

    echo "</pre>";

} catch (Throwable $e) {
    echo "<pre>";
    echo "❌ DATABASE CONNECTION FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nPDO Drivers available: " . implode(", ", PDO::getAvailableDrivers()) . "\n";
    echo "</pre>";
}
