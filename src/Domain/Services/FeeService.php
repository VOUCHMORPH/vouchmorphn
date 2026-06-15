<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * PRODUCT-BASED FEE SERVICE
 * 
 * Fees are defined by PRODUCTS (CASHOUT, DEPOSIT, etc.)
 * Each product references universal fee codes (F1-F100)
 * 
 * Mathematical Model:
 * - F1 = Total Customer Upfront Fee
 * - F7 = Swap Levy (regulatory fee)
 * - Pool_Dist = F1 - F7
 * - Cut_Platform = Pool_Dist × P_platform_percent
 * - Cut_Source = Pool_Dist × P_source_percent
 * - Share_Dest_Base = Pool_Dist × P_destination_percent
 * - Fee_Gen = Share_Dest_Base × P_generate_code_fee_percent
 * - Fee_Comp = Share_Dest_Base × P_cashout_fee_percent
 */
class FeeService
{
    private array $feeRegistry = [];
    private array $productConfig = [];
    private array $regulatoryConfig = [];
    private array $context = [];
    private string $defaultCurrency;
    private array $participants = [];
    private array $calculatedFees = [];
    
    public function __construct(array $feeRegistry, array $countryConfig, string $defaultCurrency = null)
    {
        $this->feeRegistry = $feeRegistry;
        
        // Handle both structures: with 'products' key or direct product keys
        if (isset($countryConfig['products'])) {
            $this->productConfig = $countryConfig['products'];
        } else {
            // Assume the config itself contains product keys (CASHOUT, DEPOSIT, etc.)
            $excludeKeys = ['regulatory', 'currency', 'country_code', 'country', 'currency_symbol', 'fee_structure', 'revenue_split', 'destination_fees'];
            $this->productConfig = [];
            foreach ($countryConfig as $key => $value) {
                if (!in_array($key, $excludeKeys) && is_array($value) && (isset($value['fee_components']) || isset($value['distribution']))) {
                    $this->productConfig[$key] = $value;
                }
            }
        }
        
        $this->regulatoryConfig = $countryConfig['regulatory'] ?? [];
        $this->defaultCurrency = $defaultCurrency ?? ($countryConfig['currency'] ?? 'BWP');
        
        error_log("[FeeService] Loaded " . count($this->productConfig) . " products: " . implode(', ', array_keys($this->productConfig)));
    }
    
    public function setParticipants(array $participants): void
    {
        $this->participants = $participants;
    }
    
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
        
        // Calculate each fee component from fee_components
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
        
        // VAT DISABLED FOR NOW - skip VAT calculation
        // $vatSlot = 'F81';
        // $vatConfig = $this->regulatoryConfig[$vatSlot] ?? null;
        // if ($vatConfig && isset($vatConfig['rate'])) {
        //     $vatableAmount = $slotAmounts['F1'] ?? 0;
        //     $slotAmounts[$vatSlot] = round($vatableAmount * ($vatConfig['rate'] / 100), 2);
        // }
        
        // Calculate total fees (ONLY F1 and F7 for now)
        $totalFees = $slotAmounts['F1'] + $slotAmounts['F7'];  // Customer fee + levy
        $netAmount = max(0, $amount - $slotAmounts['F1']);  // Net after customer fee only
        
        // Calculate distribution split (after levy fees)
        $distribution = $this->calculateDistribution($productConfig, $slotAmounts);
        
        $this->calculatedFees = [
            'gross_amount' => $amount,
            'net_amount' => $netAmount,
            'total_fees' => $totalFees,
            'slots' => $slotAmounts,
            'active_slots' => array_filter($slotAmounts, fn($v) => $v > 0),
            'distribution' => $distribution,
            'destination_split' => $distribution['destination_split'] ?? null,
            'earnings_rules' => $productConfig['earnings_rules'] ?? null,
            'product' => $product,
            'context' => $this->context
        ];
        
        error_log("[FeeService] Product: {$product}, Total Fees: {$totalFees}, Net: {$netAmount}");
        error_log("[FeeService] F1={$slotAmounts['F1']}, F7={$slotAmounts['F7']}, Pool_Dist=" . ($distribution['net_distributable_pool'] ?? 0));
        
        return $this->calculatedFees;
    }
    
    /**
     * Calculate distribution split (after levy fees)
     * 
     * Mathematical formulas:
     * - Pool_Dist = F1 - F7
     * - Cut_Platform = Pool_Dist × P_platform_percent
     * - Cut_Source = Pool_Dist × P_source_percent
     * - Share_Dest_Base = Pool_Dist × P_destination_percent
     * - Fee_Gen = Share_Dest_Base × P_generate_code_fee_percent
     * - Fee_Comp = Share_Dest_Base × P_cashout_fee_percent
     */
    private function calculateDistribution(array $productConfig, array $slotAmounts): array
    {
        $distributionConfig = $productConfig['distribution'] ?? [];
        $splitConfig = $distributionConfig['split'] ?? [];
        
        // Get fee amounts
        $customerFee = $slotAmounts['F1'] ?? 0;  // Total customer upfront fee
        $levyFee = $slotAmounts['F7'] ?? 0;      // Swap levy (regulatory fee)
        
        // Pool_Dist = F1 - F7 (distributable pool after removing levy)
        $netPool = $customerFee - $levyFee;
        
        // Get percentages from config
        $platformPercent = $splitConfig['platform_percent'] ?? 0;
        $sourcePercent = $splitConfig['source_institution_percent'] ?? 0;
        $destinationPercent = $splitConfig['destination_institution_percent'] ?? 0;
        
        // Calculate individual shares
        $platformShare = round($netPool * ($platformPercent / 100), 2);
        $sourceShare = round($netPool * ($sourcePercent / 100), 2);
        $destinationShare = round($netPool * ($destinationPercent / 100), 2);
        
        // Calculate destination split (Fee_Gen and Fee_Comp)
        $destinationSplitConfig = $productConfig['destination_split'] ?? null;
        $generateCodeFee = 0;
        $cashoutCompletionFee = 0;
        $generatePercent = 0;
        $cashoutPercent = 0;
        
        if ($destinationSplitConfig) {
            $generatePercent = $destinationSplitConfig['generate_code_fee_percent'] ?? 10;
            $cashoutPercent = $destinationSplitConfig['cashout_fee_percent'] ?? 90;
            $generateCodeFee = round($destinationShare * ($generatePercent / 100), 2);
            $cashoutCompletionFee = round($destinationShare * ($cashoutPercent / 100), 2);
        }
        
        return [
            // Levy information
            'levy_fees_total' => $levyFee,
            'levy_slots' => $distributionConfig['apply_after_fees'] ?? ['F7'],
            
            // Distributable pool
            'net_distributable_pool' => $netPool,
            
            // Platform share
            'platform' => [
                'percent' => $platformPercent,
                'amount' => $platformShare,
                'owner' => $splitConfig['platform_owner'] ?? 'VOUCHMORPH'
            ],
            
            // Source institution share
            'source_institution' => [
                'percent' => $sourcePercent,
                'amount' => $sourceShare,
                'owner' => $splitConfig['source_owner'] ?? 'SOURCE_INSTITUTION'
            ],
            
            // Destination institution base share
            'destination_institution' => [
                'percent' => $destinationPercent,
                'amount' => $destinationShare,
                'owner' => $splitConfig['destination_owner'] ?? 'DESTINATION_INSTITUTION'
            ],
            
            // Destination split details (for cashout products)
            'destination_split' => $destinationSplitConfig ? [
                'base_share' => $destinationShare,
                'generate_code_fee_percent' => $generatePercent,
                'generate_code_fee' => $generateCodeFee,
                'generate_code_earned_at' => $destinationSplitConfig['generate_code_earned_at'] ?? 'code_generation',
                'cashout_fee_percent' => $cashoutPercent,
                'cashout_completion_fee' => $cashoutCompletionFee,
                'cashout_earned_at' => $destinationSplitConfig['cashout_earned_at'] ?? 'cashout_completion',
                'description' => $destinationSplitConfig['description'] ?? ''
            ] : null
        ];
    }
    
    public function getDestinationSplit(float $destinationShare, array $productConfig): array
    {
        $destSplit = $productConfig['destination_split'] ?? null;
        if (!$destSplit) {
            return [
                'destination_share' => $destinationShare,
                'generate_code_fee' => 0,
                'cashout_completion_fee' => $destinationShare
            ];
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
    
    public function getEarningsTiming(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['earnings_rules'] ?? [];
    }
    
    public function getRetryRules(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['retry_rules'] ?? [];
    }
    
    public function getSwapOnSwapRules(string $product): array
    {
        $productConfig = $this->getProductConfig($product);
        return $productConfig['swap_on_swap'] ?? [];
    }
    
    private function getDefaultFeeResult(float $amount): array
    {
        return [
            'gross_amount' => $amount,
            'net_amount' => $amount,
            'total_fees' => 0,
            'slots' => [],
            'active_slots' => [],
            'distribution' => [],
            'destination_split' => null,
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
        
        // Return the net amount after customer fee (F1 only)
        return [
            'total_fee' => $result['slots']['F1'] ?? 0,  // Only customer fee for net amount calculation
            'breakdown' => $this->getBreakdown($result),
            'net_amount' => $result['net_amount'],  // amount - F1
            'gross_amount' => $result['gross_amount'],
            'distribution' => $result['distribution'],
            'destination_split' => $result['destination_split'],
            'earnings_rules' => $result['earnings_rules'],
            'swap_levy' => $result['slots']['F7'] ?? 0,
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
                'type' => $feeInfo['type'] ?? 'flat',
                'description' => $feeInfo['description'] ?? ''
            ];
        }
        
        // Add mathematical formula breakdown
        $distribution = $feeResult['distribution'] ?? [];
        if (!empty($distribution) && $distribution['net_distributable_pool'] > 0) {
            $breakdown[] = [
                'slot' => 'POOL_DIST',
                'code' => 'Pool_Dist',
                'name' => 'Distributable Pool',
                'owner' => 'SYSTEM',
                'amount' => $distribution['net_distributable_pool'],
                'currency' => $this->defaultCurrency,
                'type' => 'calculated',
                'formula' => 'F1 - F7'
            ];
            
            $breakdown[] = [
                'slot' => 'CUT_PLATFORM',
                'code' => 'Cut_Platform',
                'name' => 'Platform Revenue',
                'owner' => 'VOUCHMORPH',
                'amount' => $distribution['platform']['amount'],
                'currency' => $this->defaultCurrency,
                'type' => 'calculated',
                'formula' => 'Pool_Dist × platform_percent'
            ];
            
            $breakdown[] = [
                'slot' => 'CUT_SOURCE',
                'code' => 'Cut_Source',
                'name' => 'Source Institution Revenue',
                'owner' => $distribution['source_institution']['owner'],
                'amount' => $distribution['source_institution']['amount'],
                'currency' => $this->defaultCurrency,
                'type' => 'calculated',
                'formula' => 'Pool_Dist × source_percent'
            ];
            
            $breakdown[] = [
                'slot' => 'SHARE_DEST_BASE',
                'code' => 'Share_Dest_Base',
                'name' => 'Destination Base Share',
                'owner' => $distribution['destination_institution']['owner'],
                'amount' => $distribution['destination_institution']['amount'],
                'currency' => $this->defaultCurrency,
                'type' => 'calculated',
                'formula' => 'Pool_Dist × destination_percent'
            ];
            
            $destSplit = $feeResult['destination_split'] ?? null;
            if ($destSplit && isset($destSplit['generate_code_fee'])) {
                $breakdown[] = [
                    'slot' => 'FEE_GEN',
                    'code' => 'Fee_Gen',
                    'name' => 'Generate Code Fee',
                    'owner' => 'DESTINATION',
                    'amount' => $destSplit['generate_code_fee'],
                    'currency' => $this->defaultCurrency,
                    'type' => 'calculated',
                    'formula' => 'Share_Dest_Base × generate_code_fee_percent',
                    'earned_at' => $destSplit['generate_code_earned_at'] ?? 'code_generation'
                ];
                
                $breakdown[] = [
                    'slot' => 'FEE_COMP',
                    'code' => 'Fee_Comp',
                    'name' => 'Cashout Completion Fee',
                    'owner' => 'DESTINATION',
                    'amount' => $destSplit['cashout_completion_fee'],
                    'currency' => $this->defaultCurrency,
                    'type' => 'calculated',
                    'formula' => 'Share_Dest_Base × cashout_fee_percent',
                    'earned_at' => $destSplit['cashout_earned_at'] ?? 'cashout_completion'
                ];
            }
        }
        
        return $breakdown;
    }
}
