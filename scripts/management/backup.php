<?php
declare(strict_types=1);

/**
 * Database backup via pg_dump, using the same DATABASE_URL every other
 * part of this app connects with (see Core\Database\DBConnection).
 *
 * The old version of this script ran mysqldump against a MySQL config
 * this application no longer uses (it's Postgres), required itself
 * recursively, and stored output under directories that don't exist
 * (APP_LAYER/logs, CORE_CONFIG/env, DATA_PERSISTENCE_LAYER/config).
 *
 * Usage: php scripts/management/backup.php
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

function parseDatabaseUrl(string $url): array
{
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) {
        throw new RuntimeException("Invalid DATABASE_URL format: {$url}");
    }
    return [
        'host' => $parsed['host'],
        'port' => $parsed['port'] ?? 5432,
        'dbname' => ltrim($parsed['path'] ?? '', '/'),
        'user' => $parsed['user'] ?? 'postgres',
        'password' => $parsed['pass'] ?? '',
    ];
}

$databaseUrl = getenv('DATABASE_URL');
if (!$databaseUrl) {
    fwrite(STDERR, "DATABASE_URL environment variable is required.\n");
    exit(1);
}

$db = parseDatabaseUrl($databaseUrl);

exec('command -v pg_dump', $checkOutput, $checkReturn);
if ($checkReturn !== 0) {
    fwrite(STDERR, "pg_dump not found. This script is meant to run somewhere with the " .
        "postgresql-client package installed (an operator's machine or a dedicated " .
        "maintenance container) - the app's own web/worker/cron images only carry " .
        "libpq-dev headers, not the client binaries.\n");
    exit(1);
}

$backupDir = STORAGE_PATH . '/backups';
if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Failed to create backup directory: {$backupDir}\n");
    exit(1);
}

$dumpFile = $backupDir . '/' . $db['dbname'] . '_' . date('Y-m-d_H-i-s') . '.sql';

// PGPASSWORD as an env var for the subprocess, never on the command
// line - a command-line password is visible to any other user on the
// box via `ps aux`.
$command = sprintf(
    'PGPASSWORD=%s pg_dump -h %s -p %s -U %s -d %s -F c -f %s 2>&1',
    escapeshellarg($db['password']),
    escapeshellarg($db['host']),
    escapeshellarg((string)$db['port']),
    escapeshellarg($db['user']),
    escapeshellarg($db['dbname']),
    escapeshellarg($dumpFile)
);

exec($command, $output, $returnVar);

$logFile = STORAGE_PATH . '/logs/backup.log';
@mkdir(dirname($logFile), 0700, true);

if ($returnVar !== 0) {
    $message = date('Y-m-d H:i:s') . " - Backup FAILED for {$db['dbname']}: " . implode("\n", $output) . "\n";
    file_put_contents($logFile, $message, FILE_APPEND);
    fwrite(STDERR, $message);
    exit(1);
}

$message = date('Y-m-d H:i:s') . " - Backup completed for {$db['dbname']} at {$dumpFile}\n";
file_put_contents($logFile, $message, FILE_APPEND);
echo $message;
