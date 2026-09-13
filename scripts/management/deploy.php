<?php
declare(strict_types=1);

/**
 * Post-deploy verification and version stamp.
 *
 * The old version of this script assumed a manual deployment model
 * (copy new files out of an UPDATES/ folder, which doesn't exist) with
 * a hardcoded plaintext MySQL root password. Neither matches how this
 * application actually ships: a Docker image is built and Railway
 * replaces the running container with it. There's nothing for a PHP
 * script to "deploy" - the file copy already happened when the image
 * was built.
 *
 * What's actually useful to run once the new container is up: confirm
 * the database is reachable, and record which build is now live so a
 * later incident has an answer to "what's actually running right now."
 *
 * Usage: php scripts/management/deploy.php
 */

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use Core\Database\DBConnection;

$logFile = STORAGE_PATH . '/logs/deploy.log';
@mkdir(dirname($logFile), 0700, true);

function logDeploy(string $logFile, string $message): void
{
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] {$message}\n", FILE_APPEND);
    echo $message . "\n";
}

$db = DBConnection::getConnection();
if (!$db) {
    logDeploy($logFile, 'Database connectivity check FAILED - no connection');
    exit(1);
}
logDeploy($logFile, 'Database connectivity check OK');

// Railway sets this automatically for every deploy; fall back to a
// timestamp if it's not present (e.g. running this by hand locally).
$version = getenv('RAILWAY_GIT_COMMIT_SHA') ?: ('local_' . date('Ymd_His'));

$versionFile = STORAGE_PATH . '/version.txt';
file_put_contents($versionFile, $version);
logDeploy($logFile, "Deployed version recorded: {$version}");

echo "Deploy verification complete. Version: {$version}\n";
