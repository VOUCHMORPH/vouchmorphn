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
// Second pass: pools left UNHOOK_PARTIAL (a source's release did not go
// through). Ask again; a bank that has since expired or released the hold
// itself now counts as released, so these clear without anyone phoning the
// bank. A source that still fails stays HELD and keeps its alarm.
$stats['partial_retried'] = 0;
$stats['partial_cleared'] = 0;
$partial = $db->query("
    SELECT h.id AS hook_id, h.hook_reference, s.id AS source_id, s.institution, s.asset_type, s.hold_reference, s.held_amount
    FROM card_pool_hooks h JOIN card_pool_hook_sources s ON s.hook_id = h.id AND s.status = 'HELD'
    WHERE h.status = 'UNHOOK_PARTIAL'
    ORDER BY h.id
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($partial as $src) {
    $stats['partial_retried']++;
    try {
        $r = $swapService->releaseHold(['institution' => $src['institution'], 'asset_type' => $src['asset_type']], $src['institution'], null, $src['hold_reference']);
        if (($r['released'] ?? $r['success'] ?? false) === true) {
            $db->prepare("UPDATE card_pool_hook_sources SET status = 'RELEASED', released_at = NOW() WHERE id = ? AND status = 'HELD'")->execute([$src['source_id']]);
            // and VouchMorph's own hold record, so the limit view stops counting money the bank has already freed
            $db->prepare("UPDATE hold_transactions SET status = 'RELEASED', released_at = NOW(), updated_at = NOW()
                          WHERE hold_reference = ? AND UPPER(status) NOT IN ('DEBITED', 'RELEASED', 'EXPIRED')")->execute([$src['hold_reference']]);
            $left = $db->prepare("SELECT COUNT(*) FROM card_pool_hook_sources WHERE hook_id = ? AND status = 'HELD'");
            $left->execute([$src['hook_id']]);
            if ((int)$left->fetchColumn() === 0) {
                $db->prepare("UPDATE card_pool_hooks SET status = 'UNHOOKED', total_held_amount = 0, unhooked_at = COALESCE(unhooked_at, NOW()) WHERE id = ? AND status = 'UNHOOK_PARTIAL'")->execute([$src['hook_id']]);
                $stats['partial_cleared']++;
            }
            fwrite(STDOUT, "[card-hooks] {$src['hook_reference']} source {$src['source_id']} ({$src['institution']}) released" . (!empty($r['already_released_by_institution']) ? ' (the institution had already released it)' : '') . "\n");
        }
    } catch (Throwable $e) {
        fwrite(STDOUT, "[card-hooks] {$src['hook_reference']} source {$src['source_id']} still not released: " . $e->getMessage() . "\n");
    }
}

// Sweep: hold records still ACTIVE for pools the banks released (UNHOOKED / UNHOOK_PARTIAL
// with the source marked RELEASED). These inflate the outstanding-holds figure the Bank sees.
$swept = $db->exec("
    UPDATE hold_transactions t SET status = 'RELEASED', released_at = NOW(), updated_at = NOW()
    FROM card_pool_hook_sources s JOIN card_pool_hooks h ON h.id = s.hook_id
    WHERE t.hold_reference = s.hold_reference AND s.status = 'RELEASED'
      AND h.status IN ('UNHOOKED', 'UNHOOK_PARTIAL')
      AND UPPER(t.status) NOT IN ('DEBITED', 'RELEASED', 'EXPIRED')
");
$stats['stale_hold_records_cleared'] = (int)$swept;

$db->query("SELECT pg_advisory_unlock(7743012)");
echo json_encode($stats) . PHP_EOL;
