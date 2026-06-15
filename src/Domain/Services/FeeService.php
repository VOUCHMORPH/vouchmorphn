<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * PRODUCT-BASED FEE SERVICE WITH FOREX SUPPORT
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
    private ?ForexService $forexService = null;
    
    public function __construct(
        array $feeRegistry, 
        array $countryConfig, 
        string $defaultCurrency = null, 
        ?ForexService $forexService = null
    ) {
        $this->feeRegistry = $feeRegistry;
        $this->forexService = $forexService;
        
        // Handle both structures: with 'products' key or direct product keys
        if (isset($countryConfig['products'])) {
            $this->productConfig = $countryConfig['products'];
        } else {
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
    
    public function setForexService(ForexService $forexService): void
    {
        $this->forexService = $forexService;
    }
    
    public function setParticipants(array $participants): void
    {
        $this->participants = $participants;
    }
    
    private function setContext(array $payload): void
    {
        $sourceInst = $payload['source_institution'] ?? $payload['from_institution'] ?? 'UNKNOWN';
        $destInst = $payload['destination_institution'] ?? $payload['to_institution'] ?? 'UNKNOWN';
        
        $sourceCurrency = $this->getParticipantCurrency($sourceInst);
        $destinationCurrency = $payload['destination_currency'] ?? $this->getParticipantCurrency($destInst);
        
        if (empty($sourceCurrency)) {
            $sourceCurrency = $payload['currency'] ?? $this->defaultCurrency;
        }
        
        if (empty($destinationCurrency)) {
            $destinationCurrency = $payload['destination_currency'] ?? $payload['currency'] ?? $this->defaultCurrency;
        }
        
        // Get supported currencies for validation
        $sourceSupportedCurrencies = $this->getParticipantSupportedCurrencies($sourceInst);
        $destSupportedCurrencies = $this->getParticipantSupportedCurrencies($destInst);
        
        // Validate that requested currencies are supported
        if (!empty($sourceSupportedCurrencies) && !in_array($sourceCurrency, $sourceSupportedCurrencies)) {
            error_log("[FeeService] Warning: {$sourceInst} does not support {$sourceCurrency}");
            error_log("  Supported: " . implode(', ', $sourceSupportedCurrencies));
        }
        
        if (!empty($destSupportedCurrencies) && !in_array($destinationCurrency, $destSupportedCurrencies)) {
            error_log("[FeeService] Warning: {$destInst} does not support {$destinationCurrency}");
            error_log("  Supported: " . implode(', ', $destSupportedCurrencies));
        }
        
        $this->context = [
            'product' => $payload['swap_type'] ?? 'CASHOUT',
            'source_institution' => $sourceInst,
            'destination_institution' => $destInst,
            'source_country' => $this->getParticipantCountry($sourceInst),
            'destination_country' => $this->getParticipantCountry($destInst),
            'source_currency' => strtoupper($sourceCurrency),
            'destination_currency' => strtoupper($destinationCurrency),
            'source_supported_currencies' => $sourceSupportedCurrencies,
            'destination_supported_currencies' => $destSupportedCurrencies,
            'client_tier' => $payload['client_tier'] ?? 'retail',
            'is_multi_source' => $payload['is_multi_source'] ?? false,
            'source_count' => count($payload['sources'] ?? []),
            'is_retry' => $payload['is_retry'] ?? false,
            'retry_count' => $payload['retry_count'] ?? 0,
            'exchange_rate' => 1.0,
            'wholesale_rate' => 1.0,
            'forex_applied' => false,
            'vouchmorph_fx_profit' => 0.0
        ];
        
        // Calculate exchange rate if currencies differ
        if ($this->context['source_currency'] !== $this->context['destination_currency']) {
            $this->context['forex_applied'] = true;
            
            if ($this->forexService) {
                // Get the client rate (what customer sees)
                $this->context['exchange_rate'] = $this->forexService->getClientRate(
                    $this->context['source_currency'],
                    $this->context['destination_currency'],
                    $this->context['client_tier']
                );
                
                // Get the wholesale rate (what VouchMorph gets from banks)
                $this->context['wholesale_rate'] = $this->forexService->getWholesaleRate(
                    $this->context['source_currency'],
                    $this->context['destination_currency']
                );
                
                // Calculate VouchMorph's profit on this transaction
                $this->context['vouchmorph_fx_profit'] = $this->context['wholesale_rate'] - $this->context['exchange_rate'];
            }
            
            error_log("[FeeService] Forex: {$this->context['source_currency']} → {$this->context['destination_currency']}");
            error_log("  Client rate: {$this->context['exchange_rate']}");
            error_log("  Wholesale rate: {$this->context['wholesale_rate']}");
            error_log("  VouchMorph profit per unit: {$this->context['vouchmorph_fx_profit']}");
        }
    }
    
    /**
     * Get participant country from participants.yaml
     * Country codes: BW = Botswana, ZA = South Africa, etc.
     */
    private function getParticipantCountry(string $institution): string
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                $countryCode = $participant['country'] ?? 'BW';
                return $this->getCountryNameFromCode($countryCode);
            }
            if (isset($participant['provider_code']) && strtoupper($participant['provider_code']) === strtoupper($institution)) {
                $countryCode = $participant['country'] ?? 'BW';
                return $this->getCountryNameFromCode($countryCode);
            }
        }
        return 'Botswana';
    }
    
    /**
     * Convert country code to full name
     */
    private function getCountryNameFromCode(string $code): string
    {
        $countries = [
            'BW' => 'Botswana',
            'ZA' => 'South Africa',
            'NA' => 'Namibia',
            'ZM' => 'Zambia',
            'ZW' => 'Zimbabwe',
            'MZ' => 'Mozambique',
            'KE' => 'Kenya',
            'NG' => 'Nigeria',
            'GH' => 'Ghana',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'EU' => 'European Union'
        ];
        
        return $countries[strtoupper($code)] ?? $code;
    }
    
    /**
     * Get participant primary currency from participants.yaml
     * Primary currency is from limits.currency
     */
    private function getParticipantCurrency(string $institution): ?string
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                // Get primary currency from limits
                if (isset($participant['limits']['currency'])) {
                    return $participant['limits']['currency'];
                }
                // Fallback to first supported currency
                if (isset($participant['cross_border']['supported_currencies'][0])) {
                    return $participant['cross_border']['supported_currencies'][0];
                }
                return null;
            }
            if (isset($participant['provider_code']) && strtoupper($participant['provider_code']) === strtoupper($institution)) {
                if (isset($participant['limits']['currency'])) {
                    return $participant['limits']['currency'];
                }
                if (isset($participant['cross_border']['supported_currencies'][0])) {
                    return $participant['cross_border']['supported_currencies'][0];
                }
                return null;
            }
        }
        return null;
    }
    
    /**
     * Get all supported currencies for a participant from participants.yaml
     * Supported currencies are from cross_border.supported_currencies
     */
    private function getParticipantSupportedCurrencies(string $institution): array
    {
        foreach ($this->participants as $code => $participant) {
            if (strtoupper($code) === strtoupper($institution)) {
                if (isset($participant['cross_border']['supported_currencies'])) {
                    return $participant['cross_border']['supported_currencies'];
                }
                // Fallback to primary currency
                if (isset($participant['limits']['currency'])) {
                    return [$participant['limits']['currency']];
                }
                return [];
            }
            if (isset($participant['provider_code']) && strtoupper($participant['provider_code']) === strtoupper($institution)) {
                if (isset($participant['cross_border']['supported_currencies'])) {
                    return $participant['cross_border']['supported_currencies'];
                }
                if (isset($participant['limits']['currency'])) {
                    return [$participant['limits']['currency']];
                }
                return [];
            }
        }
        return [];
    }
    
    private function getProductConfig(string $product): ?array
    {
        return $this->productConfig[$product] ?? null;
    }
    
    /**
     * Apply forex conversion using ForexService
     */
    public function applyForex(float $amount, string $fromCurrency, string $toCurrency, string $clientTier = 'retail'): array
    {
        if ($fromCurrency === $toCurrency) {
            return [
                'amount' => $amount,
                'rate' => 1.0,
                'wholesale_rate' => 1.0,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'applied' => false,
                'vouchmorph_profit' => 0.0
            ];
        }
        
        $clientRate = 1.0;
        $wholesaleRate = 1.0;
        
        if ($this->forexService) {
            $clientRate = $this->forexService->getClientRate($fromCurrency, $toCurrency, $clientTier);
            $wholesaleRate = $this->forexService->getWholesaleRate($fromCurrency, $toCurrency);
        }
        
        $convertedAmount = round($amount * $clientRate, 2);
        $profitPerUnit = $wholesaleRate - $clientRate;
        $totalProfit = round($amount * $profitPerUnit, 2);
        
        error_log("[FeeService] Forex conversion: {$amount} {$fromCurrency} → {$convertedAmount} {$toCurrency}");
        error_log("  Client rate: {$clientRate}, Wholesale rate: {$wholesaleRate}, Profit: {$totalProfit}");
        
        return [
            'amount' => $convertedAmount,
            'rate' => $clientRate,
            'wholesale_rate' => $wholesaleRate,
            'from_currency' => $fromCurrency,
            'to_currency' => $toCurrency,
            'applied' => true,
            'vouchmorph_profit' => $totalProfit,
            'profit_per_unit' => $profitPerUnit
        ];
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
        
        // Initialize slots
        $slotAmounts = [];
        for ($i = 1; $i <= 100; $i++) {
            $slotAmounts["F{$i}"] = 0;
        }
        
        // Calculate fee components from config
        $feeComponents = $productConfig['fee_components'] ?? [];
        foreach ($feeComponents as $slotKey => $component) {
            $amountValue = $component['amount'] ?? 0;
            $slotAmounts[$slotKey] = $amountValue;
        }
        
        // Handle multi-source
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
            
            foreach ($slotAmounts as $slot => $value) {
                if ($slot !== $retrySlot && $value > 0) {
                    $slotAmounts[$slot] = 0;
                }
            }
            $slotAmounts[$retrySlot] = $retryAmount;
        }
        
        // Calculate totals in source currency
        $totalFees = $slotAmounts['F1'] + $slotAmounts['F7'];
        $netAmountSourceCurrency = max(0, $amount - $slotAmounts['F1']);
        
        // Apply forex to get destination currency amount
        $forexResult = $this->applyForex(
            $netAmountSourceCurrency,
            $this->context['source_currency'],
            $this->context['destination_currency'],
            $this->context['client_tier']
        );
        
        $netAmountDestCurrency = $forexResult['amount'];
        
        // Calculate distribution
        $distribution = $this->calculateDistribution($productConfig, $slotAmounts);
        
        $this->calculatedFees = [
            'gross_amount' => $amount,
            'gross_amount_currency' => $this->context['source_currency'],
            'net_amount_source_currency' => $netAmountSourceCurrency,
            'net_amount_destination_currency' => $netAmountDestCurrency,
            'net_amount_currency' => $this->context['destination_currency'],
            'total_fees' => $totalFees,
            'total_fees_currency' => $this->context['source_currency'],
            'slots' => $slotAmounts,
            'active_slots' => array_filter($slotAmounts, fn($v) => $v > 0),
            'distribution' => $distribution,
            'destination_split' => $distribution['destination_split'] ?? null,
            'earnings_rules' => $productConfig['earnings_rules'] ?? null,
            'product' => $product,
            'context' => $this->context,
            'forex' => $forexResult
        ];
        
        error_log("[FeeService] Product: {$product}");
        error_log("  Amount: {$amount} {$this->context['source_currency']}");
        error_log("  Fee: {$slotAmounts['F1']} {$this->context['source_currency']}");
        error_log("  Net after fee: {$netAmountSourceCurrency} {$this->context['source_currency']}");
        error_log("  After forex: {$netAmountDestCurrency} {$this->context['destination_currency']}");
        
        return $this->calculatedFees;
    }
    
    private function calculateDistribution(array $productConfig, array $slotAmounts): array
    {
        $distributionConfig = $productConfig['distribution'] ?? [];
        $splitConfig = $distributionConfig['split'] ?? [];
        
        $customerFee = $slotAmounts['F1'] ?? 0;
        $levyFee = $slotAmounts['F7'] ?? 0;
        
        $netPool = $customerFee - $levyFee;
        
        $platformPercent = $splitConfig['platform_percent'] ?? 0;
        $sourcePercent = $splitConfig['source_institution_percent'] ?? 0;
        $destinationPercent = $splitConfig['destination_institution_percent'] ?? 0;
        
        $platformShare = round($netPool * ($platformPercent / 100), 2);
        $sourceShare = round($netPool * ($sourcePercent / 100), 2);
        $destinationShare = round($netPool * ($destinationPercent / 100), 2);
        
        $destinationSplitConfig = $productConfig['destination_split'] ?? null;
        $generateCodeFee = 0;
        $cashoutCompletionFee = 0;
        
        if ($destinationSplitConfig) {
            $generatePercent = $destinationSplitConfig['generate_code_fee_percent'] ?? 10;
            $cashoutPercent = $destinationSplitConfig['cashout_fee_percent'] ?? 90;
            $generateCodeFee = round($destinationShare * ($generatePercent / 100), 2);
            $cashoutCompletionFee = round($destinationShare * ($cashoutPercent / 100), 2);
        }
        
        return [
            'levy_fees_total' => $levyFee,
            'levy_slots' => $distributionConfig['apply_after_fees'] ?? ['F7'],
            'net_distributable_pool' => $netPool,
            'platform' => [
                'percent' => $platformPercent,
                'amount' => $platformShare,
                'owner' => $splitConfig['platform_owner'] ?? 'VOUCHMORPH'
            ],
            'source_institution' => [
                'percent' => $sourcePercent,
                'amount' => $sourceShare,
                'owner' => $splitConfig['source_owner'] ?? 'SOURCE_INSTITUTION'
            ],
            'destination_institution' => [
                'percent' => $destinationPercent,
                'amount' => $destinationShare,
                'owner' => $splitConfig['destination_owner'] ?? 'DESTINATION_INSTITUTION'
            ],
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
    
    private function getDefaultFeeResult(float $amount): array
    {
        return [
            'gross_amount' => $amount,
            'gross_amount_currency' => $this->context['source_currency'] ?? $this->defaultCurrency,
            'net_amount_source_currency' => $amount,
            'net_amount_destination_currency' => $amount,
            'net_amount_currency' => $this->context['destination_currency'] ?? $this->defaultCurrency,
            'total_fees' => 0,
            'slots' => [],
            'active_slots' => [],
            'distribution' => [],
            'destination_split' => null,
            'product' => 'UNKNOWN',
            'context' => $this->context,
            'forex' => ['applied' => false],
            'warning' => 'No fee configuration found'
        ];
    }
    
    public function calculateFees(string $transactionType, float $amount, array $payload = []): array
    {
        $payload['swap_type'] = $transactionType;
        $result = $this->calculateProductFees($amount, $payload);
        
        return [
            'total_fee' => $result['slots']['F1'] ?? 0,
            'total_fee_currency' => $result['gross_amount_currency'],
            'breakdown' => $this->getBreakdown($result),
            'net_amount_source_currency' => $result['net_amount_source_currency'],
            'net_amount_destination_currency' => $result['net_amount_destination_currency'],
            'net_amount' => $result['net_amount_destination_currency'],
            'gross_amount' => $result['gross_amount'],
            'gross_amount_currency' => $result['gross_amount_currency'],
            'distribution' => $result['distribution'],
            'destination_split' => $result['destination_split'],
            'earnings_rules' => $result['earnings_rules'],
            'swap_levy' => $result['slots']['F7'] ?? 0,
            'context' => $result['context'],
            'forex' => $result['forex'],
            'fees' => $result
        ];
    }
    
    public function getBreakdown(array $feeResult): array
    {
        $breakdown = [];
        $currency = $feeResult['gross_amount_currency'] ?? $this->defaultCurrency;
        
        foreach ($feeResult['active_slots'] as $slotKey => $amount) {
            $breakdown[] = [
                'slot' => $slotKey,
                'code' => $slotKey,
                'name' => $this->feeRegistry[$slotKey]['name'] ?? "Fee {$slotKey}",
                'owner' => $this->feeRegistry[$slotKey]['owner'] ?? 'UNKNOWN',
                'amount' => $amount,
                'currency' => $currency,
                'description' => $this->feeRegistry[$slotKey]['description'] ?? ''
            ];
        }
        
        $forex = $feeResult['forex'] ?? [];
        if ($forex['applied'] ?? false) {
            $breakdown[] = [
                'slot' => 'FOREX',
                'code' => 'Forex',
                'name' => 'Currency Conversion',
                'owner' => 'VOUCHMORPH',
                'amount' => $forex['rate'],
                'currency' => 'RATE',
                'description' => "{$forex['from_currency']} → {$forex['to_currency']} at rate {$forex['rate']}"
            ];
            
            if (($forex['vouchmorph_profit'] ?? 0) > 0) {
                $breakdown[] = [
                    'slot' => 'FX_PROFIT',
                    'code' => 'FxProfit',
                    'name' => 'FX Profit',
                    'owner' => 'VOUCHMORPH',
                    'amount' => $forex['vouchmorph_profit'],
                    'currency' => $forex['to_currency'],
                    'description' => "VouchMorph profit from currency conversion"
                ];
            }
        }
        
        $distribution = $feeResult['distribution'] ?? [];
        if (!empty($distribution) && $distribution['net_distributable_pool'] > 0) {
            $breakdown[] = [
                'slot' => 'POOL_DIST',
                'name' => 'Distributable Pool',
                'owner' => 'SYSTEM',
                'amount' => $distribution['net_distributable_pool'],
                'currency' => $currency,
                'formula' => 'F1 - F7'
            ];
            
            $breakdown[] = [
                'slot' => 'CUT_PLATFORM',
                'name' => 'Platform Revenue',
                'owner' => 'VOUCHMORPH',
                'amount' => $distribution['platform']['amount'],
                'currency' => $currency
            ];
            
            $breakdown[] = [
                'slot' => 'CUT_SOURCE',
                'name' => 'Source Revenue',
                'owner' => $distribution['source_institution']['owner'],
                'amount' => $distribution['source_institution']['amount'],
                'currency' => $currency
            ];
            
            $breakdown[] = [
                'slot' => 'SHARE_DEST_BASE',
                'name' => 'Destination Base Share',
                'owner' => $distribution['destination_institution']['owner'],
                'amount' => $distribution['destination_institution']['amount'],
                'currency' => $currency
            ];
            
            $destSplit = $feeResult['destination_split'] ?? null;
            if ($destSplit) {
                $breakdown[] = [
                    'slot' => 'FEE_GEN',
                    'name' => 'Generate Code Fee',
                    'owner' => 'DESTINATION',
                    'amount' => $destSplit['generate_code_fee'],
                    'currency' => $currency,
                    'earned_at' => $destSplit['generate_code_earned_at']
                ];
                
                $breakdown[] = [
                    'slot' => 'FEE_COMP',
                    'name' => 'Cashout Completion Fee',
                    'owner' => 'DESTINATION',
                    'amount' => $destSplit['cashout_completion_fee'],
                    'currency' => $currency,
                    'earned_at' => $destSplit['cashout_earned_at']
                ];
            }
        }
        
        return $breakdown;
    }
}
