<?php
declare(strict_types=1);

/**
 * Cron entry point — expires OPEN/READY card contribution sessions
 * whose expires_at has passed. Mirrors the pattern used by
 * cancelExpiredCashouts()/cancelExpiredIdentitySwaps() elsewhere.
 *
 * Deliberately does NOT touch card_pool_hooks/card_pool_hook_sources —
 * a session expiring just means "this particular target wasn't met in
 * time," not "release the hook." The hook and its holds live on their
 * own expiry/unhook lifecycle, independent of any session built on
 * top of them.
 *
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
