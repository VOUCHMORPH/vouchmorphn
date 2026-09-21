<?php
declare(strict_types=1);
/**
 * cron/release_expired_card_hooks.php
 *
 * Releases card-pool hooks whose time has run out. Nothing did this before:
 * cancel_expired_hooks.php only expires contribution sessions, so expired
 * pools stayed HOOKED for weeks (50 pools, P1.46m on 21 Sep 2026) and their
 * money stayed held at the source institutions.
 *
 * For each HOOKED pool past its expiry (plus a 5-minute margin for a swipe
 * already in progress), CardService::releaseHook() asks every source
 * institution to release its hold and marks the sources RELEASED and the pool
 * UNHOOKED. If an institution refuses or reports it already released the
 * hold itself, that source stays HELD and the pool becomes UNHOOK_PARTIAL;
 * the incident monitor raises an alarm so someone confirms it with the
 * institution.
 *
 * Runs every 5 minutes from scripts/run_scheduled_jobs.php.
 */
if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Domain/Services/CardService.php';

use Domain\Services\CardService;

$db = $container->get(PDO::class);
if (!$db->query("SELECT pg_try_advisory_lock(7743012)")->fetchColumn()) { exit(0); }
$swapService = $container->get('Domain\\Services\\SwapService');
$cardService = new CardService($db, $container->get('countryCode'), $container->get('countryConfig'));

$pools = $db->query("
    SELECT hook_reference, user_id, total_held_amount, expires_at
    FROM card_pool_hooks
    WHERE status = 'HOOKED' AND expires_at < NOW() - INTERVAL '5 minutes'
    ORDER BY expires_at
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

$stats = ['expired_pools' => count($pools), 'unhooked' => 0, 'partial' => 0, 'errors' => 0, 'amount_released' => 0.0];
foreach ($pools as $p) {
    try {
        $r = $cardService->releaseHook($p['hook_reference'], (int)$p['user_id'], $swapService);
        $status = $r['status'] ?? 'UNKNOWN';
        if ($status === 'UNHOOKED') {
            $stats['unhooked']++;
            $stats['amount_released'] += (float)$p['total_held_amount'];
        } elseif ($status === 'UNHOOK_PARTIAL') {
            $stats['partial']++;
        } else {
            $stats['errors']++;
        }
        fwrite(STDOUT, sprintf("[card-hooks] %s expired %s -> %s (released %d, failed %d)\n",
            $p['hook_reference'], $p['expires_at'], $status, count($r['released'] ?? []), count($r['failed'] ?? [])));
    } catch (Throwable $e) {
        $stats['errors']++;
        fwrite(STDOUT, "[card-hooks] {$p['hook_reference']} FAILED: " . $e->getMessage() . "\n");
    }
}
$db->query("SELECT pg_advisory_unlock(7743012)");
echo json_encode($stats) . PHP_EOL;
