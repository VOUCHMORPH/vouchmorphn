<?php
declare(strict_types=1);

namespace Domain\Services;

class MultiSourceFeeCalculator
{
    private array $feeConfig = [];
    private array $regulatoryConfig = [];
    private string $countryCode;
    private float $extraSourceFeeMultiplier = 1.00;
    private float $maxTotalFee = 15.00;

    public function __construct(array $fullConfig, string $countryCode = 'BW')
    {
        $this->countryCode = $countryCode;

        // $fullConfig is the caller's $this->feesConfig (fees.json's raw
        // content: CASHOUT / DEPOSIT / CARD_LOAD at the root) - it is NOT
        // the broader app config, so no further unwrapping is done here.
        $this->feeConfig = $fullConfig;

        // NOTE: 'regulatory' and 'multi_source' sub-keys don't exist in
        // fees.json's structure, so these will always fall back to their
        // defaults below given the current caller. If real override
        // values are needed, they'll need to be threaded in separately
        // from the broader app config, not from this $fullConfig.
        $this->regulatoryConfig = $fullConfig['regulatory'] ?? ['vat_rate' => 0.12, 'reporting_currency' => 'BWP'];

        if (isset($fullConfig['multi_source'])) {
            $this->extraSourceFeeMultiplier = $fullConfig['multi_source']['extra_source_fee'] ?? 1.00;
            $this->maxTotalFee = $fullConfig['multi_source']['max_total_fee'] ?? 15.00;
        }
    }

    /**
     * Calculate the total fee, its component breakdown, and the
     * per-source/platform/destination split for a multi-source swap.
     *
     * @param int    $sourceCount          Number of sources contributing to this swap
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
        $baseFeeConfig = $this->getBaseFeeConfig($deliveryMode);

        if (!$baseFeeConfig) {
            throw new \RuntimeException(
                "No fee configuration found for delivery mode: {$deliveryMode} (country: {$this->countryCode})"
            );
        }

        // Base fee + swap levy come from fee_components, matching the
        // structure confirmed in fees.json (F1 = base fee, F7 = levy) -
        // same paths SwapService's single-source code already reads
        // directly (e.g. $this->feesConfig['CASHOUT']['fee_components']['F7']['amount']).
        $baseFee = (float)($baseFeeConfig['fee_components']['F1']['amount'] ?? 0);
        $swapLevy = (float)($baseFeeConfig['fee_components']['F7']['amount'] ?? 0);

        // Extra sources beyond the first add an additional charge,
        // scaled by the configured multiplier.
        $extraSources = max(0, $sourceCount - 1);
        $extraSourceFee = $extraSources * $this->extraSourceFeeMultiplier;

        $totalFee = $baseFee + $swapLevy + $extraSourceFee;

        // Cap at the configured maximum
        if ($totalFee > $this->maxTotalFee) {
            $totalFee = $this->maxTotalFee;
        }

        $splitDistribution = $this->calculateSplitDistribution($baseFeeConfig, $totalFee);
        $perSourceFees = $this->calculatePerSourceFees($sourceCount, $splitDistribution);

        return [
            'total_fee' => round($totalFee, 2),
            'base_fee' => round($baseFee, 2),
            'swap_levy' => round($swapLevy, 2),
            'extra_source_fee' => round($extraSourceFee, 2),
            'source_count' => $sourceCount,
            'delivery_mode' => $deliveryMode,
            'currency' => $destinationCurrency,
            'split_distribution' => $splitDistribution,
            'per_source_fees' => $perSourceFees,
        ];
    }

    /**
     * Look up the fee schedule block for a given delivery mode.
     *
     * Key names match fees.json's actual root-level keys directly
     * (CASHOUT / DEPOSIT / CARD_LOAD) - confirmed against SwapService's
     * existing single-source fee-reading code, which reads the same
     * structure via $this->feesConfig['CASHOUT'] / ['DEPOSIT'] etc.
     */
    private function getBaseFeeConfig(string $deliveryMode): ?array
    {
        $feeKey = match ($deliveryMode) {
            'cashout' => 'CASHOUT',
            'card_load' => 'CARD_LOAD',
            'card' => 'CARD_LOAD', // no separate CARD_ISSUANCE entry exists in fees.json - reuses CARD_LOAD
            default => 'DEPOSIT',
        };

        return $this->feeConfig[$feeKey] ?? null;
    }

    /**
     * Splits the total fee between platform, source institution(s), and
     * destination institution, using the percentages configured under
     * distribution.split in fees.json (confirmed structure, matching
     * SwapService's existing reads of
     * ['distribution']['split']['destination_institution_percent']).
     */
    private function calculateSplitDistribution(array $baseFeeConfig, float $totalFee): array
    {
        $splitConfig = $baseFeeConfig['distribution']['split'] ?? [
            'platform_percent' => 35,
            'source_institution_percent' => 15,
            'destination_institution_percent' => 50,
        ];

        $platformShare = round($totalFee * (($splitConfig['platform_percent'] ?? 35) / 100), 2);
        $sourceShare = round($totalFee * (($splitConfig['source_institution_percent'] ?? 15) / 100), 2);
        $destinationShare = round($totalFee * (($splitConfig['destination_institution_percent'] ?? 50) / 100), 2);

        // Correct any rounding drift on the platform share so the three
        // shares sum exactly to $totalFee
        $roundingError = $totalFee - ($platformShare + $sourceShare + $destinationShare);
        $platformShare = round($platformShare + $roundingError, 2);

        return [
            'platform_share' => $platformShare,
            'source_share' => $sourceShare,
            'destination_share' => $destinationShare,
            'split_config' => $splitConfig,
        ];
    }

    /**
     * Divides the total "source share" of the fee evenly across all
     * contributing sources, keyed by contribution index (0-based) so
     * PoolCoordinator::invoice() can map each amount back to the
     * corresponding entry in $contributions.
     */
    private function calculatePerSourceFees(int $sourceCount, array $splitDistribution): array
    {
        if ($sourceCount <= 0) {
            return [];
        }

        $sourceShare = $splitDistribution['source_share'] ?? 0;
        $perSource = round($sourceShare / $sourceCount, 2);

        $result = [];
        $runningTotal = 0;
        for ($i = 0; $i < $sourceCount; $i++) {
            $result[$i] = $perSource;
            $runningTotal += $perSource;
        }

        // Correct rounding drift on the last source so amounts sum
        // exactly to $sourceShare
        if ($sourceCount > 0) {
            $roundingError = $sourceShare - $runningTotal;
            $result[$sourceCount - 1] = round($result[$sourceCount - 1] + $roundingError, 2);
        }

        return $result;
    }
}
