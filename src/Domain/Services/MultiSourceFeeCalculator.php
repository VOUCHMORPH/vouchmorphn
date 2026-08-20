<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * MultiSourceFeeCalculator - REWRITTEN to match the confirmed multi-source
 * fee spec (source: conversation 2026-08-20, agreed formula below).
 *
 * ============================================================
 * THE FORMULA (Pool = F1 - F7, N = source count)
 * ============================================================
 *   - Each source gets a FIXED cut: Pool x source_institution_percent
 *     (e.g. 15%) - NOT divided across sources. Every contributing
 *     source earns the same fixed amount regardless of N or how much
 *     it individually contributed.
 *   - Swap levy (F7) is charged PER SOURCE, not once per swap:
 *     levy_total = F7 x N.
 *   - Destination gets its normal share PLUS a bonus per additional
 *     source: Pool x destination_percent + Pool x extra_source_bonus_percent x (N-1)
 *   - Platform (VouchMorph) gets the same shape:
 *     Pool x platform_percent + Pool x extra_source_bonus_percent x (N-1)
 *   - For CASHOUT specifically, destination's share further splits into
 *     an immediate "generate code" portion and a deferred "completion"
 *     portion, using the SAME destination_split percentages fees.json
 *     already defines for single-source cashouts
 *     (generate_code_fee_percent / cashout_fee_percent).
 *
 * TIMING:
 *   - Charged immediately (at hold/verify time): levy_total, every
 *     source's fixed cut, and - for CASHOUT only - the destination's
 *     generate-code portion.
 *   - Charged at swap completion: platform's cut, and destination's
 *     remaining share (all of it for DEPOSIT; the completion portion
 *     for CASHOUT).
 *
 * F8 (the old flat "P1 per extra source, capped at 15" fee) is RETIRED
 * as a separate charge. This formula already scales cost with N on its
 * own; max_total_fee is kept as the ceiling on the sum of everything
 * distributed (levy + all source cuts + destination + platform), with
 * proportional scale-down if that sum would exceed the cap - this is
 * what F8's cap was actually protecting against, just done properly
 * instead of via a flat per-source add-on.
 *
 * CONFIG: extra_source_bonus_percent defaults to 10 (destination and
 * platform both use the same 10% bonus per additional source) unless
 * fees.json's multi_source block overrides it - see
 * DEFAULT_EXTRA_SOURCE_BONUS_PERCENT below. Add
 * "multi_source": {"extra_source_bonus_percent": X} to a fee type's
 * block in fees.json to override per swap type.
 */
class MultiSourceFeeCalculator
{
    private const DEFAULT_EXTRA_SOURCE_BONUS_PERCENT = 10.0;

    private array $feeConfig = [];
    private string $countryCode;
    private float $maxTotalFee = 15.00;

    public function __construct(array $fullConfig, string $countryCode = 'BW')
    {
        $this->countryCode = $countryCode;
        $this->feeConfig = $fullConfig;

        // max_total_fee is read per-fee-type in calculateFees() now (each
        // type's own "multi_source" block can override it), but keep a
        // global fallback for any type that doesn't define one.
        if (isset($fullConfig['multi_source']['max_total_fee'])) {
            $this->maxTotalFee = (float)$fullConfig['multi_source']['max_total_fee'];
        }
    }

    /**
     * @param int    $sourceCount          Number of sources contributing (N)
     * @param string $deliveryMode         'cashout' | 'card_load' | 'card' | 'deposit' (default)
     * @param float  $destinationAmount    Total amount being delivered to the destination
     * @param string $sourceCurrency       Currency of the source contributions
     * @param string $destinationCurrency  Currency of the destination
     */
    public function calculateFees(
        int $sourceCount,
        string $deliveryMode,
        float $destinationAmount,
        string $sourceCurrency = 'BWP',
        string $destinationCurrency = 'BWP'
    ): array {
        if ($sourceCount < 1) {
            throw new \RuntimeException("sourceCount must be at least 1, got {$sourceCount}");
        }

        $baseFeeConfig = $this->getBaseFeeConfig($deliveryMode);
        if (!$baseFeeConfig) {
            throw new \RuntimeException(
                "No fee configuration found for delivery mode: {$deliveryMode} (country: {$this->countryCode})"
            );
        }

        $F1 = (float)($baseFeeConfig['fee_components']['F1']['amount'] ?? 0);
        $F7 = (float)($baseFeeConfig['fee_components']['F7']['amount'] ?? 0);
        $pool = $F1 - $F7;

        if ($pool < 0) {
            throw new \RuntimeException(
                "Invalid fee config for {$deliveryMode}: F1 ({$F1}) is less than F7 ({$F7}), pool would be negative"
            );
        }

        $splitConfig = $baseFeeConfig['distribution']['split'] ?? [
            'platform_percent' => 35,
            'source_institution_percent' => 15,
            'destination_institution_percent' => 50,
        ];
        $platformPercent = (float)($splitConfig['platform_percent'] ?? 35);
        $sourcePercent = (float)($splitConfig['source_institution_percent'] ?? 15);
        $destinationPercent = (float)($splitConfig['destination_institution_percent'] ?? 50);

        $extraSourceBonusPercent = (float)(
            $baseFeeConfig['multi_source']['extra_source_bonus_percent']
            ?? self::DEFAULT_EXTRA_SOURCE_BONUS_PERCENT
        );
        $maxTotalFee = (float)($baseFeeConfig['multi_source']['max_total_fee'] ?? $this->maxTotalFee);

        $extraSources = max(0, $sourceCount - 1);

        // ------------------------------------------------------------
        // Core formula
        // ------------------------------------------------------------
        $perSourceCut = round($pool * ($sourcePercent / 100), 2);
        $totalSourceCut = round($perSourceCut * $sourceCount, 2);

        $levyPerUnit = $F7;
        $levyTotal = round($F7 * $sourceCount, 2);

        $destinationCut = round(
            $pool * ($destinationPercent / 100) + $pool * ($extraSourceBonusPercent / 100) * $extraSources,
            2
        );
        $platformCut = round(
            $pool * ($platformPercent / 100) + $pool * ($extraSourceBonusPercent / 100) * $extraSources,
            2
        );

        $totalFeeUncapped = round($totalSourceCut + $destinationCut + $platformCut + $levyTotal, 2);

        // ------------------------------------------------------------
        // Cap enforcement - proportional scale-down of every component
        // if the uncapped total exceeds max_total_fee. Levy is NOT
        // scaled (it's a fixed per-source regulatory-style charge, not
        // a revenue share) - only the three revenue components share
        // the squeeze.
        // ------------------------------------------------------------
        $scaleFactor = 1.0;
        $totalFee = $totalFeeUncapped;
        $capped = false;

        if ($totalFeeUncapped > $maxTotalFee) {
            $capped = true;
            $revenueUncapped = $totalSourceCut + $destinationCut + $platformCut;
            $revenueBudget = max(0, $maxTotalFee - $levyTotal);

            if ($revenueUncapped > 0) {
                $scaleFactor = $revenueBudget / $revenueUncapped;
            } else {
                $scaleFactor = 0.0;
            }

            $perSourceCut = round($perSourceCut * $scaleFactor, 2);
            $totalSourceCut = round($perSourceCut * $sourceCount, 2);
            $destinationCut = round($destinationCut * $scaleFactor, 2);
            $platformCut = round($platformCut * $scaleFactor, 2);
            $totalFee = round($totalSourceCut + $destinationCut + $platformCut + $levyTotal, 2);
        }

        // ------------------------------------------------------------
        // CASHOUT: split destination's cut into immediate (generate
        // code) vs deferred (completion), using the same percentages
        // already defined for single-source cashout in fees.json.
        // DEPOSIT/CARD_LOAD: destination's entire cut is deferred to
        // completion (there's no separate "code generation" event).
        // ------------------------------------------------------------
        $destSplitConfig = $baseFeeConfig['destination_split'] ?? null;
        $destinationImmediate = 0.0;
        $destinationDeferred = $destinationCut;

        if ($deliveryMode === 'cashout' && $destSplitConfig) {
            $generateCodePercent = (float)($destSplitConfig['generate_code_fee_percent'] ?? 10);
            $destinationImmediate = round($destinationCut * ($generateCodePercent / 100), 2);
            $destinationDeferred = round($destinationCut - $destinationImmediate, 2);
        }

        $perSourceFees = $this->buildPerSourceFees($sourceCount, $perSourceCut);

        $immediateCharge = round($levyTotal + $totalSourceCut + $destinationImmediate, 2);
        $deferredCharge = round($platformCut + $destinationDeferred, 2);

        return [
            'total_fee' => $totalFee,
            'pool' => round($pool, 2),
            'base_fee' => round($F1, 2),
            'swap_levy_per_source' => round($levyPerUnit, 2),
            'swap_levy_total' => $levyTotal,
            'source_count' => $sourceCount,
            'delivery_mode' => $deliveryMode,
            'currency' => $destinationCurrency,

            'per_source_cut' => $perSourceCut,
            'total_source_cut' => $totalSourceCut,
            'per_source_fees' => $perSourceFees,

            'destination_cut' => $destinationCut,
            'destination_immediate' => $destinationImmediate,
            'destination_deferred' => $destinationDeferred,

            'platform_cut' => $platformCut,

            'immediate_charge' => $immediateCharge,
            'deferred_charge' => $deferredCharge,

            'extra_source_bonus_percent' => $extraSourceBonusPercent,
            'capped' => $capped,
            'cap_scale_factor' => $capped ? round($scaleFactor, 4) : 1.0,
            'max_total_fee' => $maxTotalFee,
            'total_fee_uncapped' => $totalFeeUncapped,

            // Retained for backward-compat with any caller reading the
            // old shape - reflects the SAME numbers as above under the
            // old key names.
            'split_distribution' => [
                'platform_share' => $platformCut,
                'source_share' => $totalSourceCut,
                'destination_share' => $destinationCut,
                'split_config' => $splitConfig,
            ],
        ];
    }

    private function getBaseFeeConfig(string $deliveryMode): ?array
    {
        $feeKey = match ($deliveryMode) {
            'cashout' => 'CASHOUT',
            'card_load' => 'CARD_LOAD',
            'card' => 'CARD_LOAD',
            default => 'DEPOSIT',
        };

        return $this->feeConfig[$feeKey] ?? null;
    }

    /**
     * Every source gets the SAME fixed cut - this is now a trivial
     * fan-out, not a division. Kept as its own method so callers that
     * want a keyed-by-index map (PoolCoordinator::invoice(), same as
     * before) don't need to change.
     */
    private function buildPerSourceFees(int $sourceCount, float $perSourceCut): array
    {
        $result = [];
        for ($i = 0; $i < $sourceCount; $i++) {
            $result[$i] = $perSourceCut;
        }
        return $result;
    }
}
