<?php
declare(strict_types=1);

/**
 * Cron entry point — expires OPEN/READY card contribution sessions
 * whose expires_at has passed. Same pattern as
 * cancel_expired_hooks.php, but operates one layer up: a session
 * expiring means "this particular target amount wasn't met in time,"
 * NOT "release the hook." The underlying card_pool_hooks /
 * card_pool_hook_sources holds live on their own independent
 * expiry/unhook lifecycle (handled by cancel_expired_hooks.php),
 * regardless of whether any contribution session was ever built on
 * top of them.
 *
 * Run this alongside cancel_expired_hooks.php, not instead of it.
 * Suggested schedule: every 1-2 minutes.
 */

define('ROOT_PATH', dirname(__DIR__, 2));

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardContributionSessionService.php';
require_once ROOT_PATH . '/src/Domain/Services/ContributionCalculator.php';

use Domain\Services\CardContributionSessionService;
use Domain\Services\ContributionCalculator;

$db = $container->get(PDO::class);
$service = new CardContributionSessionService($db, new ContributionCalculator());

$expired = $service->expireStaleSessions();

echo "Expired " . count($expired) . " contribution session(s)\n";
foreach ($expired as $s) {
    echo "  - id={$s['id']} ref={$s['session_reference']}\n";
}
