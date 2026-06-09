<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

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
    echo $e->getMessage();
    echo "</pre>";
}
