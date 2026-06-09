<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<pre>";

echo "================================================================================\n";
echo "  🔍 CLEAN DATABASE DIAGNOSTIC\n";
echo "================================================================================\n\n";

function parseDatabaseUrl(string $url): array {
    $parts = parse_url($url);

    if (!$parts) {
        throw new Exception("Invalid DATABASE_URL");
    }

    return [
        'host' => $parts['host'] ?? null,
        'port' => $parts['port'] ?? 5432,
        'db'   => ltrim($parts['path'] ?? '', '/'),
        'user' => $parts['user'] ?? null,
        'pass' => $parts['pass'] ?? null,
    ];
}

$url = getenv('DATABASE_URL');

if (!$url) {
    die("❌ DATABASE_URL not set\n");
}

echo "[🔍] Raw URL detected\n";

try {
    $cfg = parseDatabaseUrl($url);

    echo "[🔧] Host: {$cfg['host']}\n";
    echo "[🔧] DB: {$cfg['db']}\n";
    echo "[🔧] User: {$cfg['user']}\n\n";

    $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']}";

    echo "[🔍] DSN: {$dsn}\n";

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "[✅] DATABASE CONNECTED\n\n";

    $stmt = $pdo->query("SELECT version()");
    $version = $stmt->fetchColumn();

    echo "[📊] PostgreSQL Version:\n{$version}\n\n";

    echo "[📋] PDO DRIVERS:\n";
    print_r(PDO::getAvailableDrivers());

} catch (Throwable $e) {
    echo "[❌] ERROR: " . $e->getMessage() . "\n";
}

echo "\n================================================================================\n";
echo "TEST COMPLETE\n";
echo "================================================================================\n";

echo "</pre>";
