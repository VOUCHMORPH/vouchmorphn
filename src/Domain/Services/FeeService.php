<?php
declare(strict_types=1);

namespace Domain\Services;

/**
 * Comprehensive Fee Service for VouchMorph
 * Handles ALL 4 scenarios:
 * 1. Domestic, Same Currency
 * 2. Domestic, Different Currency  
 * 3. Cross-border, Same Currency
 * 4. Cross-border, Different Currency
 */
class FeeService
{
    private array $feesConfig;
    private string $defaultCurrency;
    
    public function __construct(array $feesConfig, string $defaultCurrency = 'BWP')
    {
        $this->feesConfig = $feesConfig;
        $this->defaultCurrency = $defaultCurrency;
    }
    
    /**
     * Calculate ALL applicable fees based on country AND currency
     * 
     * FEE LOGIC MATRIX:
     * ┌─────────────────────────────────────────────────────────────────┐
     * │                    SAME COUNTRY      DIFFERENT COUNTRY          │
     * ├─────────────────────────────────────────────────────────────────┤
     * │ SAME CURRENCY    │ Swap + Processing  │ Swap + Cross-border     │
     * │                  │ + Settlement       │ + Processing + Settlement│
     * │                  │ NO FX              │ NO FX                   │
     * ├─────────────────────────────────────────────────────────────────┤
     * │ DIFFERENT        │ Swap + FX          │ Swap + FX               │
     * │ CURRENCY         │ + Processing       │ + Cross-border          │
     * │                  │ + Settlement       │ + Processing + Settlement│
     * └─────────────────────────────────────────────────────────────────┘
     */
    public function calculateAllFees(
        float $amount,
        string $transactionType,
        string $sourceCurrency,
        string $destinationCurrency,
        string $sourceCountry,
        string $destinationCountry
    ): array {
        $sameCurrency = strtoupper($sourceCurrency) === strtoupper($destinationCurrency);
        $sameCountry = strtoupper($sourceCountry) === strtoupper($destinationCountry);
        
        // Determine scenario
        if ($sameCountry && $sameCurrency) {
            $scenario = 'DOMESTIC_SAME_CURRENCY';
        } elseif ($sameCountry && !$sameCurrency) {
            $scenario = 'DOMESTIC_DIFFERENT_CURRENCY';
        } elseif (!$sameCountry && $sameCurrency) {
            $scenario = 'CROSS_BORDER_SAME_CURRENCY';
        } else {
            $scenario = 'CROSS_BORDER_DIFFERENT_CURRENCY';
        }
        
        $fees = [];
        $fees['scenario'] = $scenario;
        
        // 1. Base Swap Fee (ALWAYS applies in all scenarios)
        $fees['swap_fee'] = $this->getSwapFee($amount, $transactionType);
        
        // 2. FX Fee (applies when currencies differ, regardless of country)
        $fees['fx_fee'] = $sameCurrency ? 0 : $this->calculateFxFee($amount);
        
        // 3. Cross-border Fee (applies when countries differ, regardless of currency)
        $fees['cross_border_fee'] = $sameCountry ? 0 : $this->calculateCrossBorderFee($amount);
        
        // 4. Processing Fee (ALWAYS applies in all scenarios)
        $fees['processing_fee'] = $this->getProcessingFee($amount, $transactionType);
        
        // 5. Settlement Fee (ALWAYS applies in all scenarios)
        $fees['settlement_fee'] = $this->getSettlementFee($amount, $transactionType);
        
        // 6. Subtotal before VAT
        $subtotalBeforeVat = $fees['swap_fee'] + $fees['processing_fee'] + $fees['settlement_fee'];
        
        // Add FX fee and cross-border fee to VATable amount based on config
        $vatableAmount = $subtotalBeforeVat;
        
        if ($this->feesConfig['regulatory']['fx_vatable'] ?? false) {
            $vatableAmount += $fees['fx_fee'];
        }
        if ($this->feesConfig['regulatory']['cross_border_vatable'] ?? false) {
            $vatableAmount += $fees['cross_border_fee'];
        }
        
        $vatRate = $this->getVatRate();
        $fees['vat'] = $vatableAmount * $vatRate;
        $fees['vat_rate'] = $vatRate;
        
        // 7. Total fees
        $fees['total_fees'] = $subtotalBeforeVat + $fees['fx_fee'] + $fees['cross_border_fee'] + $fees['vat'];
        
        // 8. Net amount
        $fees['net_amount'] = max(0, $amount - $fees['total_fees']);
        $fees['gross_amount'] = $amount;
        
        // 9. Context for debugging
        $fees['context'] = [
            'same_country' => $sameCountry,
            'same_currency' => $sameCurrency,
            'source_country' => $sourceCountry,
            'destination_country' => $destinationCountry,
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'scenario' => $scenario
        ];
        
        return $fees;
    }
    
    /**
     * Get base swap fee (varies by transaction type)
     */
    private function getSwapFee(float $amount, string $transactionType): float
    {
        $feeKey = $this->getFeeKeyForTransaction($transactionType);
        
        if (isset($this->feesConfig['fees'][$feeKey]['total_amount'])) {
            return (float)$this->feesConfig['fees'][$feeKey]['total_amount'];
        }
        
        // Fallback defaults (should never happen with proper config)
        $defaults = [
            'CASHOUT_SWAP_FEE' => 10.00,
            'DEPOSIT_SWAP_FEE' => 6.00,
            'CARD_LOAD_FEE' => 6.00,
            'CARD_ISSUANCE_FEE' => 50.00
        ];
        
        return $defaults[$feeKey] ?? 5.00;
    }
    
    /**
     * Calculate FX fee (percentage of amount)
     * Applied when currencies differ
     */
    private function calculateFxFee(float $amount): float
    {
        $fxFeePercent = $this->feesConfig['fx']['fee_percent'] ?? 0.015; // Default 1.5%
        return $amount * $fxFeePercent;
    }
    
    /**
     * Calculate cross-border fee (percentage of amount)
     * Applied when countries differ
     */
    private function calculateCrossBorderFee(float $amount): float
    {
        $crossBorderFeePercent = $this->feesConfig['cross_border']['fee_percent'] ?? 0.005; // Default 0.5%
        return $amount * $crossBorderFeePercent;
    }
    
    /**
     * Get processing fee (flat or percentage)
     */
    private function getProcessingFee(float $amount, string $transactionType): float
    {
        // Can be flat fee or percentage based on config
        $processingFeeType = $this->feesConfig['fees']['processing_fee_type'] ?? 'flat';
        
        if ($processingFeeType === 'percentage') {
            $processingFeePercent = $this->feesConfig['fees']['processing_fee_percent'] ?? 0.005;
            return $amount * $processingFeePercent;
        }
        
        // Default flat fee
        return (float)($this->feesConfig['fees']['processing_fee'] ?? 2.00);
    }
    
    /**
     * Get settlement fee
     */
    private function getSettlementFee(float $amount, string $transactionType): float
    {
        $settlementFeePercent = $this->feesConfig['fees']['settlement_fee_percent'] ?? 0.001;
        return $amount * $settlementFeePercent;
    }
    
    /**
     * Get VAT rate from config
     */
    private function getVatRate(): float
    {
        return (float)($this->feesConfig['regulatory']['vat_rate'] ?? 0.12);
    }
    
    /**
     * Map transaction type to fee key
     */
    private function getFeeKeyForTransaction(string $transactionType): string
    {
        $map = [
            'CASHOUT' => 'CASHOUT_SWAP_FEE',
            'DEPOSIT' => 'DEPOSIT_SWAP_FEE',
            'CARD_LOAD' => 'CARD_LOAD_FEE',
            'CARD_ISSUANCE' => 'CARD_ISSUANCE_FEE'
        ];
        
        return $map[$transactionType] ?? 'DEPOSIT_SWAP_FEE';
    }
    
    /**
     * Get human-readable fee breakdown
     */
    public function getFeeBreakdown(array $calculatedFees): array
    {
        $breakdown = [];
        
        // Scenario description
        $scenarioDesc = [
            'DOMESTIC_SAME_CURRENCY' => 'Domestic transfer, same currency',
            'DOMESTIC_DIFFERENT_CURRENCY' => 'Domestic transfer, foreign currency',
            'CROSS_BORDER_SAME_CURRENCY' => 'Cross-border transfer, same currency',
            'CROSS_BORDER_DIFFERENT_CURRENCY' => 'Cross-border transfer, foreign currency'
        ];
        
        $breakdown['scenario'] = $scenarioDesc[$calculatedFees['scenario']] ?? $calculatedFees['scenario'];
        $breakdown['gross_amount'] = $calculatedFees['gross_amount'];
        $breakdown['fees'] = [];
        
        if ($calculatedFees['swap_fee'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'Transaction Fee',
                'amount' => $calculatedFees['swap_fee'],
                'currency' => $this->defaultCurrency
            ];
        }
        
        if ($calculatedFees['fx_fee'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'Foreign Exchange Fee',
                'amount' => $calculatedFees['fx_fee'],
                'currency' => $this->defaultCurrency
            ];
        }
        
        if ($calculatedFees['cross_border_fee'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'Cross-Border Fee',
                'amount' => $calculatedFees['cross_border_fee'],
                'currency' => $this->defaultCurrency
            ];
        }
        
        if ($calculatedFees['processing_fee'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'Processing Fee',
                'amount' => $calculatedFees['processing_fee'],
                'currency' => $this->defaultCurrency
            ];
        }
        
        if ($calculatedFees['settlement_fee'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'Settlement Fee',
                'amount' => $calculatedFees['settlement_fee'],
                'currency' => $this->defaultCurrency
            ];
        }
        
        if ($calculatedFees['vat'] > 0) {
            $breakdown['fees'][] = [
                'name' => 'VAT',
                'amount' => $calculatedFees['vat'],
                'currency' => $this->defaultCurrency,
                'rate' => ($calculatedFees['vat_rate'] * 100) . '%'
            ];
        }
        
        $breakdown['total_fees'] = $calculatedFees['total_fees'];
        $breakdown['net_amount'] = $calculatedFees['net_amount'];
        
        return $breakdown;
    }
}
