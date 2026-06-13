<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * PRODUCT-BASED FEE SERVICE
 * 
 * Fees are defined by PRODUCTS (CASHOUT, DEPOSIT, etc.)
 * Each product references universal fee codes (F1-F100)
 * 
 * This makes the system:
 * 1. Country-agnostic - only fees.json changes per country
 * 2. Participant-flexible - participants can override fees
 * 3. Regulatory-compliant - easily add new fees without code changes
 */
class FeeService
{
    private array $feeRegistry = [];      // Universal fee types (F1-F100)
    private array $productConfig = [];    // Country product configurations
    private array $regulatoryConfig = [];
    private array $context = [];
    private string $defaultCurrency;
    private array $participants = [];
    private array $calculatedFees = [];
    
    public function __construct(array $feeRegistry, array $countryConfig, string $defaultCurrency = 'BWP')
    {
        $this->feeRegistry = $feeRegistry;
        $this->productConfig = $countryConfig['products'] ?? [];
        $this->regulatoryConfig = $countryConfig['regulatory'] ?? [];
        $this->defaultCurrency = $defaultCurrency;
        
        error_log("[FeeService] Loaded " . count($this->productConfig) . " products");
    }
    
    /**
     * Set participants for lookup
     */
    public function setParticipants(array $participants): void
    {
        $this->participants = $participants;
    }
    
    /**
     * Set transaction context
     */
    private function setContext(array $payload): void
    {
        $sourceInst = $payload['source_institution'] ?? $payload['from_institution'] ?? 'UNKNOWN';
        $destInst = $payload['destination_institution'] ?? $payload['to_institution'] ?? 'UNKNOWN';
        
        $this->context = [
            'product' => $payload['swap_type'] ?? 'CASHOUT',
            'source_country' => $this->getParticipantCountry($sourceInst),
            'destination_country' => $this->getParticipantCountry($destInst),
            'source_currency' => $payload['currency'] ?? $this->defaultCurrency,
            'destination_currency' => $payload['destination_currency'] ?? $payload['currency'] ?? $this->defaultCurrency,
            'source_type' => $payload['asset_type'] ?? $payload['source_type'] ?? 'ACCOUNT',
            'is_multi_source' => $payload['is_multi_source'] ?? false,
            'source_count' => count($payload['sources'] ?? []),
            'is_retry' => $payload['is_retry'] ?? false,
            'retry_count' => $payload['retry_count'] ?? 0
        ];
        
        $this->context['currencies_differ'] = strtoupper($this->context['source_currency']) !== strtoupper($this->context['destination_currency']);
        $this->context['countries_differ'] = strtoupper($this->context['source_country']) !== strtoupper($this->context['destination_country']);
    }
    
    /**
     * Get participant country
     */
    private function getParticipantCountry(string $institution): string
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                return $participant['country'] ?? 'Botswana';
            }
            if (isset($participant['provider_code']) && strtoupper($participant['provider_code']) === strtoupper($institution)) {
                return $participant['country'] ?? 'Botswana';
            }
        }
        return 'Botswana';
    }
    
    /**
     * Get product configuration
     */
    private function getProductConfig(string $product): ?array
    {
        return $this->productConfig[$product] ?? null;
    }
    
    /**
     * Calculate all fees for a product
     */
    public function calculateProductFees(float $amount, array $payload = []): array
    {
        $this->setContext($payload);
        $product = $this->context['product'];
        
        $productConfig = $this->getProductConfig($product);
        if (!$productConfig) {
            error_log("[FeeService] No configuration for product: {$product}");
            return $this->getDefaultFeeResult($amount);
        }
        
        // Initialize all slots to zero (F1-F100)
        $slotAmounts = [];
        for ($i = 1; $i <= 100; $i++) {
            $slotAmounts["F{$i}"] = 0;
        }
        
        // Calculate each fee component
        $feeComponents = $productConfig['fee_components'] ?? [];
        foreach ($feeComponents as $slotKey => $component) {
            $amountValue = $component['amount'] ?? 0;
            $slotAmounts[$slotKey] = $amountValue;
        }
        
        // Handle multi-source extra fee
        if ($this->context['is_multi_source'] && ($this->context['source_count'] ?? 1) > 1) {
            $multiSourceConfig = $this->productConfig['multi_source'] ?? [];
            if (!empty($multiSourceConfig)) {
                $extraFeeSlot = $multiSourceConfig['fee_type'] ?? 'F8';
                $extraFeeAmount = $multiSourceConfig['extra_source_fee'] ?? 1.00;
                $extraCount = ($this->context['source_count'] ?? 1) - 1;
                $calculatedExtra = $extraCount * $extraFeeAmount;
                $maxTotal = $multiSourceConfig['max_total_fee'] ?? 15.00;
                $slotAmounts[$extraFeeSlot] = min($calculatedExtra, $maxTotal);
            }
        }
        
        // Handle retry fees
        if ($this->context['is_retry'] && isset($productConfig['retry_rules'])) {
            $retryRules = $productConfig['retry_rules'];
            $retrySlot = $retryRules['fee_type'] ?? 'F10';
            $retryAmount = $retryRules['amount'] ?? 0.45;
            
            // Clear non-retry fees
            foreach ($slotAmounts as $slot => $value) {
                if ($slot !== $retrySlot && $value > 0) {
                    $slotAmounts[$slot] = 0;
                }
            }
            $slotAmounts[$retrySlot] = $retryAmount;
        }
        
        // Apply VAT
        $vatSlot = 'F81';
        $vatConfig = $this->regulatoryConfig[$vatSlot] ?? null;
        if ($vatConfig && isset($vatConfig['rate'])) {
            $vatableAmount = 0;
            $appliesToFees = $vatConfig['applies_to_fees'] ?? ['F1', 'F9'];
            foreach ($appliesToFees as $feeSlot) {
                $vatableAmount += $slotAmounts[$feeSlot] ?? 0;
            }
            $slotAmounts[$vatSlot] = $vatableAmount * ($vatConfig['rate'] / 100);
        }
        
        // Calculate totals
        $totalFees = array_sum($slotAmounts);
        $netAmount = max(0, $amount - $totalFees);
        
        // Apply distribution split (after levy fees)
        $distribution = $this->calculateDistribution($totalFees, $productConfig);
        
        $this->calculatedFees = [
            'gross_amount' => $amount,
            'net_amount' => $netAmount,
            'total_fees' => $totalFees,
            'slots' => $slotAmounts,
            'active_slots' => array_filter($slotAmounts, fn($v) => $v > 0),
            'distribution' => $distribution,
            'destination_split' => $productConfig['destination_split'] ?? null,
            'earnings_rules' => $productConfig['earnings_rules'] ?? null,
            'product' => $product,
            'context' => $this->context
        ];
        
        error_log("[FeeService] Product: {$product}, Total Fees: {$totalFees}, Net: {$netAmount}");
        
        return $this->calculatedFees;
    }
    
    /**
     * Calculate distribution split (after levy fees)
     */
    private function calculateDistribution(float $totalFees, array $productConfig): array
    {
        $distributionConfig = $productConfig['distribution'] ?? [];
        $applyAfterFees = $distributionConfig['apply_after_fees'] ?? [];
        $splitConfig = $distributionConfig['split'] ?? [];
        
        // Calculate net pool after removing levy fees
        $levyAmount = 0;
        foreach ($applyAfterFees as $levySlot) {
            $levyAmount += $this->calculatedFees['slots'][$levySlot] ?? 0;
        }
        
        $netPool = $totalFees - $levyAmount;
        
        $platformPercent = $splitConfig['platform_percent'] ?? 0;
        $sourcePercent = $splitConfig['source_institution_percent'] ?? 0;
        $destinationPercent = $splitConfig['destination_institution_percent'] ?? 0;
        
        return [
            'levy_fees_total' => $levyAmount,
            'net_distributable_pool' => $netPool,
            'platform' => [
                'percent' => $platformPercent,
                'amount' => round($netPool * ($platformPercent / 100), 2)
            ],
            'source_institution' => [
                'percent' => $sourcePercent,
                'amount' => round($netPool * ($sourcePercent / 100), 2)
            ],
            'destination_institution' => [
                'percent' => $destinationPercent,
                'amount' => round($netPool * ($destinationPercent / 100), 2)
            ]
        ];
    }
    
    /**
     * Get destination split for cashout products
     */
    public function getDestinationSplit(float $destinationShare, array $productConfig): array
    {
        $destSplit = $productConfig['destination_split'] ?? null;
        if (!$destSplit) {
            return ['destination_share' => $destinationShare];
        }
        
        $generatePercent = $destSplit['generate_code_fee_percent'] ?? 10;
        $cashoutPercent = $destSplit['cashout_fee_percent'] ?? 90;
        
        return [
            'destination_share' => $destinationShare,
            'generate_code_fee' => round($destinationShare * ($generatePercent / 100), 2),
            'generate_code_fee_percent' => $generatePercent,
            'cashout_completion_fee' => round($destinationShare * ($cashoutPercent / 100), 2),
            'cashout_fee_percent' => $cashoutPercent,
            'description' => $destSplit['description'] ?? ''
        ];
    }
    
    /**
     * Get earnings timing for different fee components
     */
    public function getEarningsTiming(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['earnings_rules'] ?? [];
    }
    
    /**
     * Get retry rules for a product
     */
    public function getRetryRules(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['retry_rules'] ?? [];
    }
    
    /**
     * Get swap-on-swap (free retry) rules
     */
    public function getSwapOnSwapRules(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['swap_on_swap'] ?? [];
    }
    
    /**
     * Get default fee result when no config found
     */
    private function getDefaultFeeResult(float $amount): array
    {
        return [
            'gross_amount' => $amount,
            'net_amount' => $amount,
            'total_fees' => 0,
            'slots' => [],
            'active_slots' => [],
            'distribution' => [],
            'product' => 'UNKNOWN',
            'context' => $this->context,
            'warning' => 'No fee configuration found'
        ];
    }
    
    /**
     * Wrapper for SwapService compatibility
     */
    public function calculateFees(string $transactionType, float $amount, array $payload = []): array
    {
        $payload['swap_type'] = $transactionType;
        $result = $this->calculateProductFees($amount, $payload);
        
        return [
            'total_fee' => $result['total_fees'],
            'breakdown' => $this->getBreakdown($result),
            'net_amount' => $result['net_amount'],
            'gross_amount' => $result['gross_amount'],
            'distribution' => $result['distribution'],
            'destination_split' => $result['destination_split'],
            'earnings_rules' => $result['earnings_rules'],
            'fees' => $result
        ];
    }
    
    /**
     * Get human-readable breakdown
     */
    public function getBreakdown(array $feeResult): array
    {
        $breakdown = [];
        
        foreach ($feeResult['active_slots'] as $slotKey => $amount) {
            $feeInfo = $this->feeRegistry[$slotKey] ?? null;
            $breakdown[] = [
                'slot' => $slotKey,
                'code' => $slotKey,
                'name' => $feeInfo['name'] ?? "Fee {$slotKey}",
                'owner' => $feeInfo['owner'] ?? 'UNKNOWN',
                'amount' => $amount,
                'currency' => $this->defaultCurrency,
                'type' => $feeInfo['type'] ?? 'flat'
            ];
        }
        
        return $breakdown;
    }
}
