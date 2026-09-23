<?php
declare(strict_types=1);

namespace Domain\Services\Fees;

use PDO;
use Throwable;

/**
 * FeeLedger - records, for every service performed on a swap leg, which
 * institution performed it and the share of the fee it is entitled to
 * (Section 23 Rev. 2, fees.json). Reads the split from fees.json so the
 * ledger and the fee the customer was shown always agree.
 *
 *   HOLD_PLACED     swap levy -> VouchMorph;  13% -> source
 *   DELIVERED       50% -> destination;       35% -> VouchMorph
 *   CODE_GENERATED  10% of 50% -> destination
 *   CASH_DISPENSED  90% of 50% -> destination; 35% -> VouchMorph
 *   SETTLED         2% -> the party that performed the settlement
 *
 * Idempotent: one row per (leg, share); recording an event twice changes
 * nothing. Never throws into the money path: a ledger failure is logged and
 * the swap carries on (the monitor and the accountant see the gap).
 */
final class FeeLedger
{
    public const VOUCHMORPH = 'VOUCHMORPH';

    public function __construct(private PDO $db, private array $feesConfig) {}

    /** Loads fees.json for the country when the caller has no config at hand. */
    public static function fromCountryConfig(PDO $db, string $country = 'Botswana'): self
    {
        $file = dirname(__DIR__, 3) . "/Core/Config/Countries/{$country}/fees.json";
        $cfg = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
        return new self($db, $cfg);
    }

    /** The shares for one product: general fee, levy, and the split of the rest. */
    public function shares(string $product): array
    {
        $p = $this->feesConfig[$product === 'IDENTITY' ? 'CASHOUT' : $product] ?? [];
        $fee = (float)($p['fee_components']['F1']['amount'] ?? 0);
        $levy = (float)($p['fee_components']['F7']['amount'] ?? 0);
        $split = $p['distribution']['split'] ?? [];
        $pool = round($fee - $levy, 2);
        $source = round($pool * (float)($split['source_institution_percent'] ?? 0) / 100, 2);
        $platform = round($pool * (float)($split['platform_percent'] ?? 0) / 100, 2);
        $dest = round($pool * (float)($split['destination_institution_percent'] ?? 0) / 100, 2);
        $settlement = round($pool - $source - $platform - $dest, 2);   // 2%, takes any rounding remainder
        $codePct = (float)($p['destination_split']['generate_code_fee_percent'] ?? 10);
        $code = round($dest * $codePct / 100, 2);
        return [
            'fee' => $fee, 'levy' => $levy, 'pool' => $pool,
            'source' => $source, 'platform' => $platform, 'destination' => $dest, 'settlement' => $settlement,
            'code' => $code, 'cash' => round($dest - $code, 2),
            'pct' => [
                'source' => (float)($split['source_institution_percent'] ?? 0), 'platform' => (float)($split['platform_percent'] ?? 0),
                'destination' => (float)($split['destination_institution_percent'] ?? 0), 'settlement' => (float)($split['settlement_percent'] ?? 0),
            ],
        ];
    }

    /**
     * Records one service event for a leg.
     * @param array $ctx swap_reference, leg_reference, product, source_institution,
     *                   destination_institution, settling_institution (SETTLED only), currency
     * @return int rows written (0 when already recorded)
     */
    public function record(string $event, array $ctx): int
    {
        try {
            $product = strtoupper((string)($ctx['product'] ?? 'DEPOSIT'));
            $s = $this->shares($product);
            if ($s['fee'] <= 0) return 0;
            $source = strtoupper((string)($ctx['source_institution'] ?? ''));
            $dest = strtoupper((string)($ctx['destination_institution'] ?? ''));
            $rows = match ($event) {
                'HOLD_PLACED' => [['SWAP_LEVY', self::VOUCHMORPH, $s['levy'], null], ['SOURCE_SHARE', $source, $s['source'], $s['pct']['source']]],
                'DELIVERED' => [['DESTINATION_SHARE', $dest, $s['destination'], $s['pct']['destination']], ['PLATFORM_SHARE', self::VOUCHMORPH, $s['platform'], $s['pct']['platform']]],
                'CODE_GENERATED' => [['DESTINATION_CODE_FEE', $dest, $s['code'], null]],
                'CASH_DISPENSED' => [['DESTINATION_CASHOUT_FEE', $dest, $s['cash'], null], ['PLATFORM_SHARE', self::VOUCHMORPH, $s['platform'], $s['pct']['platform']]],
                'SETTLED' => [['SETTLEMENT_FEE', strtoupper((string)($ctx['settling_institution'] ?? $source)), $s['settlement'], $s['pct']['settlement']]],
                default => [],
            };
            $ins = $this->db->prepare("
                INSERT INTO fee_ledger (swap_reference, leg_reference, product, event, fee_role, institution, payer_institution,
                                        general_fee, percent_of_pool, amount, currency, status, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (leg_reference, fee_role) DO NOTHING
            ");
            $n = 0;
            foreach ($rows as [$role, $institution, $amount, $pct]) {
                if ($institution === '' || $amount <= 0) continue;
                // The payer already holds the customer's fee: its own shares need no payment.
                $status = $institution === $source ? 'RETAINED_BY_PAYER' : 'EARNED';
                $ins->execute([
                    (string)$ctx['swap_reference'], (string)($ctx['leg_reference'] ?? $ctx['swap_reference']), $product, $event, $role,
                    $institution, $source, $s['fee'], $pct, $amount, $ctx['currency'] ?? 'BWP', $status, $ctx['note'] ?? null,
                ]);
                $n += $ins->rowCount();
            }
            return $n;
        } catch (Throwable $e) {
            error_log("[FeeLedger] could not record {$event} for " . ($ctx['leg_reference'] ?? $ctx['swap_reference'] ?? '?') . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The receiving bank confirmed the leg's settlement: the 2% settlement fee is
     * earned by whoever performed it (by default the paying settling bank).
     * Product and source come from the leg's own earlier entries.
     */
    public function settleLeg(string $legReference, string $settlingInstitution): int
    {
        try {
            // FIX (2026-09-22): a multi-source claim already recorded a settlement
            // fee per source when it completed. Mark that row paid and record WHO
            // settled, instead of writing a second row for the same fee under a
            // different leg reference.
            $settler = strtoupper($settlingInstitution);
            $existing = $this->db->prepare("
                UPDATE fee_ledger SET status = 'PAID', paid_at = COALESCE(paid_at, now()), settled_by = ?, settled_at = now()
                WHERE fee_role = 'SETTLEMENT_FEE' AND institution = ? AND status IN ('EARNED', 'ADVISED')
                  AND swap_reference = (SELECT swap_reference FROM fee_ledger WHERE leg_reference = ? LIMIT 1)
            ");
            $existing->execute([$settler, $settler, $legReference]);
            if ($existing->rowCount() > 0) {
                return $existing->rowCount();
            }
            $st = $this->db->prepare("SELECT swap_reference, product, payer_institution, currency FROM fee_ledger WHERE leg_reference = ? AND status <> 'REVERSED' ORDER BY entry_id LIMIT 1");
            $st->execute([$legReference]);
            $leg = $st->fetch(PDO::FETCH_ASSOC);
            if (!$leg) return 0;
            $written = $this->record('SETTLED', [
                'swap_reference' => $leg['swap_reference'], 'leg_reference' => $legReference, 'product' => $leg['product'],
                'source_institution' => $leg['payer_institution'], 'settling_institution' => $settlingInstitution, 'currency' => $leg['currency'],
            ]);
            if ($written > 0) {
                $this->db->prepare("UPDATE fee_ledger SET settled_by = ?, settled_at = now() WHERE leg_reference = ? AND fee_role = 'SETTLEMENT_FEE'")
                    ->execute([$settler, $legReference]);
            }
            return $written;
        } catch (Throwable $e) {
            error_log("[FeeLedger] could not settle {$legReference}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Records one explicitly computed share (multi-source claims, where the
     * amounts come from the multi-source rule rather than single-swap shares).
     * Idempotent per (leg_reference, fee_role).
     */
    public function recordShare(string $swapReference, string $legReference, string $product, string $event, string $role,
                                string $institution, string $payer, float $generalFee, ?float $pct, float $amount, string $currency = 'BWP', ?string $note = null): int
    {
        try {
            if ($amount <= 0 || $institution === '') return 0;
            $institution = strtoupper($institution); $payer = strtoupper($payer);
            $st = $this->db->prepare("
                INSERT INTO fee_ledger (swap_reference, leg_reference, product, event, fee_role, institution, payer_institution,
                                        general_fee, percent_of_pool, amount, currency, status, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (leg_reference, fee_role) DO NOTHING
            ");
            $st->execute([$swapReference, $legReference, $product, $event, $role, $institution, $payer, round($generalFee, 2), $pct,
                          round($amount, 2), $currency, $institution === $payer ? 'RETAINED_BY_PAYER' : 'EARNED', $note]);
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log("[FeeLedger] could not record {$role} for {$legReference}: " . $e->getMessage());
            return 0;
        }
    }

    /** A platform failure: nothing is charged for the leg (Section 23.3). */
    public function reverse(string $legReference, string $reason): int
    {
        try {
            $st = $this->db->prepare("
                UPDATE fee_ledger SET status = 'REVERSED', reversed_at = NOW(), note = ?
                WHERE leg_reference = ? AND status IN ('EARNED', 'RETAINED_BY_PAYER')
            ");
            $st->execute([mb_substr('Reversed: ' . $reason, 0, 500), $legReference]);
            return $st->rowCount();
        } catch (Throwable $e) {
            error_log("[FeeLedger] could not reverse {$legReference}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * The hold expired without completion: earned stage fees stay earned, but the
     * institution released the whole hold, so they were not collected. Marked
     * UNCOLLECTED for the accountant until partial capture at expiry exists.
     */
    public function markUncollected(string $legReference, string $reason): int
    {
        try {
            $st = $this->db->prepare("
                UPDATE fee_ledger SET status = 'UNCOLLECTED', note = ?
                WHERE leg_reference = ? AND status IN ('EARNED', 'RETAINED_BY_PAYER')
            ");
            $st->execute([mb_substr($reason, 0, 500), $legReference]);
            return $st->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
