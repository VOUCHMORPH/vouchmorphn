<?php
declare(strict_types=1);

namespace Application\Incident;

use PDO;
use Throwable;

/**
 * The gate every money movement passes before anything is held, debited or
 * credited. Two checks:
 *   1. The sandbox cap (fees.json limits.max_single_transaction, P7,000).
 *      Before this, only card top-ups were capped; swaps of P499,994 went
 *      through.
 *   2. Freezes in force (Incident Command): the whole service, either
 *      institution, the swap flow, the customer, or the acting agent.
 *
 * Returns null when the transaction may proceed, otherwise
 * ['code' => ..., 'message' => customer-facing text, 'http' => 403|423].
 * A blocked attempt raises an alarm (the control worked; nothing moved).
 */
final class ServiceControls
{
    private static ?array $cache = null;

    public static function capLimit(): float
    {
        $file = dirname(__DIR__, 2) . '/Core/Config/Countries/Botswana/fees.json';
        $cfg = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        return (float)($cfg['limits']['max_single_transaction'] ?? 7000.00);
    }

    /** Active freezes, loaded once per request. If the table does not exist yet, nothing is frozen. */
    public static function active(PDO $db): array
    {
        if (self::$cache !== null) return self::$cache;
        try {
            self::$cache = $db->query("SELECT control_id, scope, target, customer_message FROM ic_controls WHERE resumed_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[ServiceControls] controls table unavailable (run 2026_09_22_incident_command.sql): ' . $e->getMessage());
            self::$cache = [];
        }
        return self::$cache;
    }

    /**
     * @param array $ctx amount, flow (swap type), source, destination, user_id, agent_id
     */
    public static function check(PDO $db, array $ctx): ?array
    {
        $amount = (float)($ctx['amount'] ?? 0);
        $cap = self::capLimit();
        if ($amount > $cap) {
            self::alarm($db, 'CAP_BLOCKED', 'CAP_BLOCKED:' . ($ctx['user_id'] ?? '?') . ':' . gmdate('YmdH'),
                sprintf('Blocked a P%s %s above the P%s sandbox cap', number_format($amount, 2), strtoupper((string)($ctx['flow'] ?? 'transaction')), number_format($cap, 2)), $ctx);
            return ['code' => 'LIMIT_EXCEEDED', 'http' => 403,
                    'message' => sprintf('This amount is above the P%s limit per transaction during the pilot. Please send a smaller amount.', number_format($cap, 0))];
        }

        $flow = strtoupper((string)($ctx['flow'] ?? ''));
        $want = [
            ['SERVICE', '*', 'VouchMorph is paused for maintenance. Your money is safe with your bank. Please try again later.'],
            ['FLOW', $flow, 'This service is temporarily paused. Your money is safe. Please try again later.'],
            ['INSTITUTION', strtoupper((string)($ctx['source'] ?? '')), 'Transfers from this institution are temporarily paused. Your money is safe with it. Please try again later.'],
            ['INSTITUTION', strtoupper((string)($ctx['destination'] ?? '')), 'Transfers to this institution are temporarily paused. Please try again later.'],
            ['CLIENT', (string)($ctx['user_id'] ?? ''), 'Your account cannot make transactions right now. Please contact VouchMorph support.'],
            ['AGENT', (string)($ctx['agent_id'] ?? ''), 'This agent is not authorised to process transactions right now.'],
        ];
        foreach (self::active($db) as $c) {
            foreach ($want as [$scope, $target, $default]) {
                if ($target === '' || $c['scope'] !== $scope || $c['target'] !== $target) continue;
                self::alarm($db, 'CAP_BLOCKED', "FROZEN_BLOCK:{$c['control_id']}:" . gmdate('YmdH'),
                    "A transaction was refused because {$scope} {$target} is frozen", $ctx + ['control_id' => (int)$c['control_id']]);
                return ['code' => $scope . '_FROZEN', 'http' => 423, 'message' => $c['customer_message'] ?: $default];
            }
        }
        return null;
    }

    private static function alarm(PDO $db, string $rule, string $key, string $title, array $ctx): void
    {
        try {
            (new IncidentDesk($db))->raiseAlert($rule, $key, $title, ['attempt' => $ctx]);
        } catch (Throwable $e) {
            error_log('[ServiceControls] could not raise alarm: ' . $e->getMessage());
        }
    }
}
