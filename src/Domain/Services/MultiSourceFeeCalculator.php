<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * Multi-Source Fee Calculator
 * 
 * Pulls fees from country-specific configuration (fee.json)
 * Supports dynamic fee structures per country
 */
class MultiSourceFeeCalculator
{
    private array $feeConfig;
    private array $regulatoryConfig;
    private string $countryCode;
    
    // Multi-source specific multipliers (configurable)
    private float $extraSourceFeeMultiplier = 1.00; // P1 per extra source
    private float $maxTotalFee = 15.00; // Cap at P15
    
    public function __construct(array $fullConfig, string $countryCode = 'BW')
    {
        $this->countryCode = $countryCode;
        
        // Extract fee configuration
        $this->feeConfig = $fullConfig['fees'] ?? [];
        $this->regulatoryConfig = $fullConfig['regulatory'] ?? ['vat_rate' => 0.12, 'reporting_currency' => 'BWP'];
        
        // Load multi-source specific config if exists
        if (isset($fullConfig['multi_source'])) {
            $this->extraSourceFeeMultiplier = $fullConfig['multi_source']['extra_source_fee'] ?? 1.00;
            $this->maxTotalFee = $fullConfig['multi_source']['max_total_fee'] ?? 15.00;
        }
    }
    
    /**
     * Calculate fees for multi-source transaction
     * 
     * @param int $sourceCount Number of sources contributing
     * @param string $deliveryMode 'deposit', 'cashout', 'card_load', 'card'
     * @param float $destinationAmount Target amount for destination
     * @param string $sourceCurrency Source currency (defaults to reporting currency)
     * @param string $destinationCurrency Destination currency
     * @return array Fee calculation result
     */
    public function calculateFees(
        int $sourceCount,
        string $deliveryMode,
        float $destinationAmount,
        string $sourceCurrency = 'BWP',
        string $destinationCurrency = 'BWP'
    ): array {
        // Get base fee from configuration based on delivery mode
        $baseFeeConfig = $this->getBaseFeeConfig($deliveryMode);
        
        if (!$baseFeeConfig) {
            throw new \RuntimeException("No fee configuration found for delivery mode: {$deliveryMode}");
        }
        
        $baseFee = $baseFeeConfig['total_amount'] ?? 0;
        $swapLevy = $baseFeeConfig['swap_levy'] ?? 0;
        
        // Calculate multi-source extra fees
        $extraSourcesCount = max(0, $sourceCount - 1);
        $extraFees = $extraSourcesCount * $this->extraSourceFeeMultiplier;
        
        // Calculate total fees (base + extra)
        $totalFees = $baseFee + $extraFees;
        
        // Apply cap if configured
        if ($this->maxTotalFee > 0 && $totalFees > $this->maxTotalFee) {
            $totalFees = $this->maxTotalFee;
        }
        
        // Get VAT rate from regulatory config
        $vatRate = $this->regulatoryConfig['vat_rate'] ?? 0.12;
        $vatAmount = $totalFees * $vatRate;
        
        // Distribute fees across sources
        $feeDistribution = $this->distributeFeesAcrossSources(
            $sourceCount,
            $baseFee,
            $extraFees,
            $totalFees,
            $swapLevy
        );
        
        // Calculate split distribution based on config
        $splitDistribution = $this->calculateSplitDistribution(
            $totalFees,
            $swapLevy,
            $baseFeeConfig
        );
        
        return [
            'total_fees' => $totalFees,
            'swap_levy' => $swapLevy,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'currency' => $this->regulatoryConfig['reporting_currency'] ?? 'BWP',
            'breakdown' => [
                'base_fee' => $baseFee,
                'base_fee_config' => [
                    'fee_type' => $deliveryMode,
                    'description' => $baseFeeConfig['description'] ?? ''
                ],
                'extra_source_fee' => $this->extraSourceFeeMultiplier,
                'extra_sources_count' => $extraSourcesCount,
                'extra_fees_total' => $extraFees,
                'cap_applied' => ($this->maxTotalFee > 0 && ($baseFee + $extraFees) > $this->maxTotalFee),
                'max_fee_cap' => $this->maxTotalFee
            ],
            'per_source_fees' => $feeDistribution,
            'split_distribution' => $splitDistribution,
            'net_destination_amount' => $destinationAmount - $totalFees
        ];
    }
    
    /**
     * Get base fee configuration for delivery mode
     */
    private function getBaseFeeConfig(string $deliveryMode): ?array
    {
        $feeKey = match($deliveryMode) {
            'cashout' => 'CASHOUT_SWAP_FEE',
            'card_load' => 'CARD_LOAD_FEE',
            'card' => 'CARD_ISSUANCE_FEE',
            default => 'DEPOSIT_SWAP_FEE'
        };
        
        return $this->feeConfig[$feeKey] ?? null;
    }
    
    /**
     * Distribute fees across sources
     * 
     * Distribution logic:
     * - First source pays the base fee
     * - All sources share extra fees equally
     * - Swap levy is applied to first source
     */
    private function distributeFeesAcrossSources(
        int $sourceCount,
        float $baseFee,
        float $extraFees,
        float $totalFees,
        float $swapLevy = 0
    ): array {
        $distribution = [];
        
        if ($sourceCount === 0) {
            return $distribution;
        }
        
        // First source pays base fee + swap levy
        $distribution[0] = $baseFee + $swapLevy;
        
        if ($sourceCount === 1) {
            return $distribution;
        }
        
        // Distribute extra fees equally among ALL sources
        $extraPerSource = $extraFees / $sourceCount;
        
        for ($i = 0; $i < $sourceCount; $i++) {
            if ($i === 0) {
                // First source already has base fee, add its share of extra
                $distribution[$i] += $extraPerSource;
            } else {
                $distribution[$i] = $extraPerSource;
            }
        }
        
        // Adjust for rounding errors
        $totalDistributed = array_sum($distribution);
        if (abs($totalDistributed - $totalFees - $swapLevy) > 0.01) {
            $distribution[0] += ($totalFees + $swapLevy - $totalDistributed);
        }
        
        return $distribution;
    }
    
    /**
     * Calculate split distribution based on fee configuration
     * This mirrors the logic from FeeService but for multi-source
     */
    private function calculateSplitDistribution(
        float $totalFees,
        float $swapLevy,
        array $baseFeeConfig
    ): array {
        $afterLevy = $totalFees - $swapLevy;
        
        $splitConfig = $baseFeeConfig['split_after_levy'] ?? [
            'platform_percent' => 35,
            'source_institution_percent' => 15,
            'destination_institution_percent' => 50
        ];
        
        $platformShare = $afterLevy * ($splitConfig['platform_percent'] / 100);
        $sourceShare = $afterLevy * ($splitConfig['source_institution_percent'] / 100);
        $destinationShare = $afterLevy * ($splitConfig['destination_institution_percent'] / 100);
        
        $distribution = [
            'swap_levy' => $swapLevy,
            'platform_share' => $platformShare,
            'platform_percent' => $splitConfig['platform_percent'],
            'source_institution_share' => $sourceShare,
            'source_institution_percent' => $splitConfig['source_institution_percent'],
            'destination_institution_share' => $destinationShare,
            'destination_institution_percent' => $splitConfig['destination_institution_percent']
        ];
        
        // Add destination split if it exists (for cashout)
        if (isset($baseFeeConfig['destination_split'])) {
            $destSplit = $baseFeeConfig['destination_split'];
            $distribution['destination_split'] = [
                'generate_code_fee' => $destinationShare * ($destSplit['generate_code_fee_percent'] / 100),
                'generate_code_fee_percent' => $destSplit['generate_code_fee_percent'],
                'cashout_fee' => $destinationShare * ($destSplit['cashout_fee_percent'] / 100),
                'cashout_fee_percent' => $destSplit['cashout_fee_percent'],
                'description' => $destSplit['description'] ?? ''
            ];
        }
        
        return $distribution;
    }
    
    /**
     * Calculate retry fees for multi-source cashout
     */
    public function calculateRetryFees(
        int $sourceCount,
        int $retryCount,
        string $deliveryMode,
        float $destinationAmount
    ): array {
        $baseCalculation = $this->calculateFees($sourceCount, $deliveryMode, $destinationAmount);
        
        $baseFeeConfig = $this->getBaseFeeConfig($deliveryMode);
        $generateCodeFee = $baseFeeConfig['retry_fee']['generate_code_fee'] ?? 0.45;
        
        $isFreeRetry = ($retryCount === 1);
        $isPaidRetry = ($retryCount >= 2);
        
        if ($isFreeRetry) {
            // Free retry: VouchMorph pays generate code fee
            $totalFees = 0;
            $retryType = 'FREE_RETRY';
            $feePaidBy = 'vouchmorph';
        } elseif ($isPaidRetry) {
            // Paid retry: Client pays generate code fee only
            $totalFees = $generateCodeFee;
            $retryType = 'PAID_RETRY';
            $feePaidBy = 'client';
        } else {
            // First attempt: normal fee structure
            return $baseCalculation;
        }
        
        $vatRate = $this->regulatoryConfig['vat_rate'] ?? 0.12;
        $vatAmount = $totalFees * $vatRate;
        
        return [
            'total_fees' => $totalFees,
            'vat_amount' => $vatAmount,
            'retry_type' => $retryType,
            'fee_paid_by' => $feePaidBy,
            'generate_code_fee' => $generateCodeFee,
            'is_free_retry' => $isFreeRetry,
            'is_paid_retry' => $isPaidRetry,
            'currency' => $this->regulatoryConfig['reporting_currency'] ?? 'BWP',
            'net_destination_amount' => $destinationAmount - $totalFees
        ];
    }
}
