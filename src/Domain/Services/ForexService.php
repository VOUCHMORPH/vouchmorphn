<?php
/**
 * Complete ForexService with Profit Model
 * 
 * VouchMorph's FX Revenue Model:
 * 1. Partner with banks to get wholesale rates
 * 2. Mark up rates for clients
 * 3. Keep the spread as revenue
 * 4. Split revenue with partners (revenue share)
 */
class ForexService
{
    // ... existing code ...
    
    /**
     * Get client rate with VouchMorph markup
     * This is the rate the CLIENT sees and pays
     */
    public function getClientRate(string $from, string $to, string $clientTier = 'retail'): float
    {
        // Step 1: Get wholesale rate from partner
        $wholesaleRate = $this->getWholesaleRate($from, $to);
        
        // Step 2: Apply VouchMorph markup based on client tier
        $markupPercent = $this->getClientMarkup($clientTier);
        $clientRate = $wholesaleRate * (1 - $markupPercent);
        
        // Step 3: Store for profit tracking
        $this->logFxProfit($from, $to, $wholesaleRate, $clientRate, $clientTier);
        
        return $clientRate;
    }
    
    /**
     * Get wholesale rate from partner (what VouchMorph pays)
     * This is the rate VouchMorph GETS from banks
     */
    public function getWholesaleRate(string $from, string $to): float
    {
        // Get the best rate from all partner banks
        $bestWholesaleRate = $this->getBestPartnerRate($from, $to);
        
        if ($bestWholesaleRate !== null) {
            return $bestWholesaleRate;
        }
        
        // Fallback to market rate minus partner discount
        $marketRate = $this->getMarketRate($from, $to);
        $partnerDiscount = $this->getPartnerDiscount($from, $to);
        
        return $marketRate * (1 + $partnerDiscount);
    }
    
    /**
     * Get best rate from all partner banks
     * VouchMorph aggregates multiple bank quotes and takes the best
     */
    private function getBestPartnerRate(string $from, string $to): ?float
    {
        $bestRate = null;
        
        foreach ($this->partnerBanks as $bank) {
            $rate = $this->fetchRateFromBank($bank, $from, $to);
            if ($rate !== null && ($bestRate === null || $rate > $bestRate)) {
                $bestRate = $rate;
            }
        }
        
        return $bestRate;
    }
    
    /**
     * Get client markup based on tier
     */
    private function getClientMarkup(string $clientTier): float
    {
        $markups = [
            'retail' => 0.03,      // 3% - standard clients
            'premium' => 0.02,     // 2% - high volume clients
            'business' => 0.015,   // 1.5% - business accounts
            'partner' => 0.005,    // 0.5% - partner institutions
            'internal' => 0.001    // 0.1% - VouchMorph internal
        ];
        
        return $markups[$clientTier] ?? $markups['retail'];
    }
    
    /**
     * Get partner discount (what banks give VouchMorph)
     */
    private function getPartnerDiscount(string $from, string $to): float
    {
        // Partner agreements - banks give VouchMorph better rates
        // because VouchMorph brings volume
        
        $discounts = [
            'ZURUBANK' => 0.008,    // 0.8% better than market
            'SACCUSSALIS' => 0.007,  // 0.7% better than market
            'default' => 0.005       // 0.5% baseline
        ];
        
        // Check corridor-specific discounts
        $corridor = $from . '_' . $to;
        if (isset($this->config['fx_discounts'][$corridor])) {
            return $this->config['fx_discounts'][$corridor];
        }
        
        return $discounts['default'];
    }
    
    /**
     * Calculate and log VouchMorph's FX profit
     */
    private function logFxProfit(string $from, string $to, float $wholesaleRate, float $clientRate, string $clientTier): void
    {
        $profitPerUnit = $wholesaleRate - $clientRate;
        $profitPercent = ($profitPerUnit / $wholesaleRate) * 100;
        
        $this->logFxEvent('PROFIT_CALCULATION', [
            'currency_pair' => "{$from}/{$to}",
            'wholesale_rate' => $wholesaleRate,
            'client_rate' => $clientRate,
            'profit_per_unit' => $profitPerUnit,
            'profit_percent' => $profitPercent,
            'client_tier' => $clientTier,
            'estimated_profit_per_1000' => $profitPerUnit * 1000
        ]);
        
        // Store in database for reporting
        $this->recordFxProfit($from, $to, $wholesaleRate, $clientRate, $profitPerUnit, $clientTier);
    }
    
    /**
     * Record FX profit in database for financial reporting
     */
    private function recordFxProfit(string $from, string $to, float $wholesaleRate, float $clientRate, float $profitPerUnit, string $clientTier): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO fx_profit_records 
                (currency_pair, wholesale_rate, client_rate, profit_per_unit, client_tier, recorded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute(["{$from}/{$to}", $wholesaleRate, $clientRate, $profitPerUnit, $clientTier]);
        } catch (Exception $e) {
            $this->logFxEvent('PROFIT_RECORD_FAILED', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get VouchMorph's total FX profit for a period
     */
    public function getTotalFxProfit(string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                SUM(swap.amount * fxp.profit_per_unit) as total_profit,
                SUM(swap.amount) as total_volume,
                COUNT(*) as transaction_count,
                AVG(fxp.profit_per_unit) as avg_profit_per_unit
            FROM swap_requests swap
            JOIN fx_quotes fxp ON swap.swap_uuid = fxp.swap_reference
            WHERE swap.created_at BETWEEN :from AND :to
            AND swap.from_currency != swap.to_currency
        ");
        
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
