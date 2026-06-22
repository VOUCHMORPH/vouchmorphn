<?php
declare(strict_types=1);

namespace Domain\Services;

use PDO;
use Exception;
use RuntimeException;

class ForexService
{
    private PDO $db;
    private array $config;
    private array $participants;
    private array $partnerBanks = [];
    private ?FeeService $feeService = null;
    private array $rateCache = [];
    
    public function __construct(PDO $db, array $config, array $participants, ?FeeService $feeService = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->participants = $participants;
        $this->feeService = $feeService;
        
        // Initialize partner banks from participants
        $this->initializePartnerBanks();
        
        // Create FX cache table if it doesn't exist
        $this->initializeCacheTable();
    }
    
    /**
     * Initialize partner banks from participants configuration
     */
    private function initializePartnerBanks(): void
    {
        $this->partnerBanks = [];
        
        foreach ($this->participants as $code => $participant) {
            // Only include banks that have FX endpoints configured
            if (isset($participant['fx_enabled']) && $participant['fx_enabled'] === true) {
                $this->partnerBanks[] = [
                    'code' => $code,
                    'name' => $participant['name'] ?? $code,
                    'base_url' => $participant['base_url'] ?? '',
                    'fx_endpoint' => $participant['fx_endpoint'] ?? '/api/v1/fx/rate',
                    'api_key' => $participant['api_key'] ?? null,
                    'country' => $participant['country'] ?? 'Botswana',
                    'currency' => $participant['currency'] ?? 'BWP'
                ];
            }
        }
        
        // Also add default banks if configured
        if (isset($this->config['fx_partners'])) {
            foreach ($this->config['fx_partners'] as $partner) {
                $this->partnerBanks[] = $partner;
            }
        }
        
        error_log("[ForexService] Initialized with " . count($this->partnerBanks) . " partner banks");
    }
    
   private function initializeCacheTable(): void
{
    try {
        // Create fx_cached_rates with proper PostgreSQL syntax
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_cached_rates (
                id SERIAL PRIMARY KEY,
                currency_pair VARCHAR(7) NOT NULL,
                rate DECIMAL(20,6) NOT NULL,
                rate_type VARCHAR(20) DEFAULT 'wholesale',
                source VARCHAR(50),
                fetched_at TIMESTAMP DEFAULT NOW(),
                expires_at TIMESTAMP DEFAULT NOW() + INTERVAL '1 hour'
            )
        ");
        
        // Create indexes separately (PostgreSQL syntax)
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fx_pair_type ON fx_cached_rates (currency_pair, rate_type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fx_expires ON fx_cached_rates (expires_at)");
        
        // Create fx_profit_records
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fx_profit_records (
                id SERIAL PRIMARY KEY,
                swap_reference VARCHAR(100),
                currency_pair VARCHAR(7) NOT NULL,
                wholesale_rate DECIMAL(20,6) NOT NULL,
                client_rate DECIMAL(20,6) NOT NULL,
                profit_per_unit DECIMAL(20,6) NOT NULL,
                amount DECIMAL(20,2),
                client_tier VARCHAR(20),
                recorded_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fx_profit_pair ON fx_profit_records (currency_pair)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_fx_profit_recorded ON fx_profit_records (recorded_at)");
        
        error_log("[ForexService] Cache tables created successfully");
        
    } catch (Exception $e) {
        error_log("[ForexService] Failed to create cache table: " . $e->getMessage());
    }
}
    
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
        
        // Step 3: Round to reasonable decimal places
        $clientRate = round($clientRate, 6);
        
        error_log("[ForexService] Client rate: {$from}→{$to} = {$clientRate} (wholesale: {$wholesaleRate}, markup: {$markupPercent})");
        
        return $clientRate;
    }
    
    /**
     * Get wholesale rate from partner (what VouchMorph pays)
     * This is the rate VouchMorph GETS from banks
     */
    public function getWholesaleRate(string $from, string $to): float
    {
        // Check cache first
        $cachedRate = $this->getCachedRate($from, $to, 'wholesale');
        if ($cachedRate !== null) {
            error_log("[ForexService] Using cached wholesale rate: {$from}→{$to} = {$cachedRate}");
            return $cachedRate;
        }
        
        // Get the best rate from all partner banks
        $bestWholesaleRate = $this->getBestPartnerRate($from, $to);
        
        if ($bestWholesaleRate !== null) {
            $this->cacheRate($from, $to, $bestWholesaleRate, 'wholesale', 'partner_bank');
            return $bestWholesaleRate;
        }
        
        // Fallback to market rate with partner discount
        $marketRate = $this->getMarketRate($from, $to);
        $partnerDiscount = $this->getPartnerDiscount($from, $to);
        $calculatedRate = $marketRate * (1 + $partnerDiscount);
        
        $this->cacheRate($from, $to, $calculatedRate, 'wholesale', 'market_fallback');
        
        error_log("[ForexService] Using calculated wholesale rate: {$from}→{$to} = {$calculatedRate}");
        return $calculatedRate;
    }
    
    /**
     * Get best rate from all partner banks
     * VouchMorph aggregates multiple bank quotes and takes the best
     */
    private function getBestPartnerRate(string $from, string $to): ?float
    {
        $bestRate = null;
        $bestSource = null;
        
        foreach ($this->partnerBanks as $bank) {
            $rate = $this->fetchRateFromBank($bank, $from, $to);
            if ($rate !== null && ($bestRate === null || $rate > $bestRate)) {
                $bestRate = $rate;
                $bestSource = $bank['code'];
            }
        }
        
        if ($bestRate !== null) {
            error_log("[ForexService] Best partner rate from {$bestSource}: {$from}→{$to} = {$bestRate}");
        }
        
        return $bestRate;
    }
    
    /**
     * Fetch rate from a specific bank via API
     */
    private function fetchRateFromBank(array $bank, string $from, string $to): ?float
    {
        if (empty($bank['base_url'])) {
            return null;
        }
        
        $url = rtrim($bank['base_url'], '/') . $bank['fx_endpoint'];
        $url .= '?' . http_build_query([
            'from' => $from,
            'to' => $to
        ]);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ]);
        
        // Add API key if available
        if (!empty($bank['api_key'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Authorization: Bearer ' . $bank['api_key']
            ]);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            
            // Try different response formats
            $rate = $data['rate'] ?? 
                    $data['mid_rate'] ?? 
                    $data['exchange_rate'] ?? 
                    $data['bid'] ?? 
                    null;
            
            if ($rate !== null) {
                return (float)$rate;
            }
        }
        
        error_log("[ForexService] Failed to fetch rate from {$bank['code']}: HTTP {$httpCode}, Error: {$curlError}");
        
        // Try config file as fallback for this bank
        return $this->getConfiguredRate($bank, $from, $to);
    }
    
    /**
     * Get configured rate from rates config file
     */
    private function getConfiguredRate(array $bank, string $from, string $to): ?float
    {
        $ratesPath = __DIR__ . '/../../Core/Config/Rates/fx_rates.json';
        
        if (file_exists($ratesPath)) {
            $rates = json_decode(file_get_contents($ratesPath), true);
            if ($rates) {
                $bankCode = $bank['code'];
                $key = "{$bankCode}_{$from}_{$to}";
                
                if (isset($rates[$key])) {
                    return (float)$rates[$key];
                }
                
                // Try default rate for this corridor
                $defaultKey = "default_{$from}_{$to}";
                if (isset($rates[$defaultKey])) {
                    return (float)$rates[$defaultKey];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Get market rate from external provider (OpenExchangeRates, Fixer.io, etc.)
     */
    private function getMarketRate(string $from, string $to): float
    {
        // Check cache first
        $cachedRate = $this->getCachedRate($from, $to, 'market');
        if ($cachedRate !== null) {
            return $cachedRate;
        }
        
        // Try OpenExchangeRates
        $apiKey = getenv('OPENEXCHANGE_APP_ID');
        if ($apiKey) {
            $url = "https://openexchangerates.org/api/latest.json?app_id={$apiKey}&base={$from}&symbols={$to}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                if (isset($data['rates'][$to])) {
                    $rate = (float)$data['rates'][$to];
                    $this->cacheRate($from, $to, $rate, 'market', 'openexchangerates');
                    error_log("[ForexService] Market rate from OpenExchangeRates: {$from}→{$to} = {$rate}");
                    return $rate;
                }
            }
        }
        
        // Try Fixer.io as fallback
        $fixerKey = getenv('FIXER_API_KEY');
        if ($fixerKey) {
            $url = "http://data.fixer.io/api/latest?access_key={$fixerKey}&base={$from}&symbols={$to}";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if (isset($data['rates'][$to])) {
                    $rate = (float)$data['rates'][$to];
                    $this->cacheRate($from, $to, $rate, 'market', 'fixer');
                    return $rate;
                }
            }
        }
        
        // Fallback to hardcoded rates
        return $this->getFallbackRate($from, $to);
    }
    
    /**
     * Get fallback rates from hardcoded values (last resort)
     */
    private function getFallbackRate(string $from, string $to): float
    {
        $fallbackRates = [
            'BWP_ZAR' => 1.35,
            'ZAR_BWP' => 0.7407,
            'BWP_USD' => 0.074,
            'USD_BWP' => 13.50,
            'BWP_EUR' => 0.068,
            'EUR_BWP' => 14.70,
            'BWP_GBP' => 0.058,
            'GBP_BWP' => 17.20,
            'ZAR_USD' => 0.055,
            'USD_ZAR' => 18.20,
        ];
        
        $key = "{$from}_{$to}";
        $rate = $fallbackRates[$key] ?? 1.0;
        
        error_log("[ForexService] Using fallback rate: {$from}→{$to} = {$rate}");
        return $rate;
    }
    
    /**
     * Get cached rate from database
     */
    private function getCachedRate(string $from, string $to, string $rateType = 'wholesale'): ?float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT rate FROM fx_cached_rates 
                WHERE currency_pair = :pair 
                AND rate_type = :type
                AND expires_at > NOW()
                ORDER BY fetched_at DESC LIMIT 1
            ");
            $stmt->execute([
                ':pair' => "{$from}/{$to}",
                ':type' => $rateType
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                return (float)$row['rate'];
            }
        } catch (Exception $e) {
            error_log("[ForexService] Failed to get cached rate: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Cache rate in database
     */
    private function cacheRate(string $from, string $to, float $rate, string $rateType = 'wholesale', string $source = 'api'): void
    {
        try {
            // Clean old expired rates for this pair
            $stmt = $this->db->prepare("
                DELETE FROM fx_cached_rates 
                WHERE currency_pair = :pair AND rate_type = :type AND expires_at < NOW()
            ");
            $stmt->execute([
                ':pair' => "{$from}/{$to}",
                ':type' => $rateType
            ]);
            
            // Insert new rate
            $stmt = $this->db->prepare("
                INSERT INTO fx_cached_rates (currency_pair, rate, rate_type, source, fetched_at, expires_at)
                VALUES (:pair, :rate, :type, :source, NOW(), NOW() + INTERVAL '1 hour')
            ");
            $stmt->execute([
                ':pair' => "{$from}/{$to}",
                ':rate' => $rate,
                ':type' => $rateType,
                ':source' => $source
            ]);
            
            error_log("[ForexService] Cached {$rateType} rate: {$from}/{$to} = {$rate} (source: {$source})");
        } catch (Exception $e) {
            error_log("[ForexService] Failed to cache rate: " . $e->getMessage());
        }
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
        $discounts = [
            'ZURUBANK' => 0.008,    // 0.8% better than market
            'SACCUSSALIS' => 0.007,  // 0.7% better than market
            'default' => 0.005       // 0.5% baseline
        ];
        
        // Check corridor-specific discounts from config
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
    }
    
    /**
     * Log FX event
     */
    private function logFxEvent(string $event, array $data): void
    {
        error_log("[ForexService][{$event}] " . json_encode($data));
    }
    
    /**
     * Record FX profit in database for financial reporting
     */
    public function recordFxProfit(string $from, string $to, float $wholesaleRate, float $clientRate, float $profitPerUnit, string $clientTier, ?string $swapReference = null, ?float $amount = null): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO fx_profit_records 
                (swap_reference, currency_pair, wholesale_rate, client_rate, profit_per_unit, amount, client_tier, recorded_at)
                VALUES (:ref, :pair, :wholesale, :client, :profit, :amount, :tier, NOW())
            ");
            $stmt->execute([
                ':ref' => $swapReference,
                ':pair' => "{$from}/{$to}",
                ':wholesale' => $wholesaleRate,
                ':client' => $clientRate,
                ':profit' => $profitPerUnit,
                ':amount' => $amount,
                ':tier' => $clientTier
            ]);
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
                SUM(profit_per_unit * COALESCE(amount, 0)) as total_profit,
                SUM(COALESCE(amount, 0)) as total_volume,
                COUNT(*) as transaction_count,
                AVG(profit_per_unit) as avg_profit_per_unit,
                AVG(wholesale_rate) as avg_wholesale_rate,
                AVG(client_rate) as avg_client_rate
            FROM fx_profit_records
            WHERE recorded_at BETWEEN :from AND :to
        ");
        
        $stmt->execute([':from' => $fromDate, ':to' => $toDate]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ?: [
            'total_profit' => 0,
            'total_volume' => 0,
            'transaction_count' => 0,
            'avg_profit_per_unit' => 0,
            'avg_wholesale_rate' => 0,
            'avg_client_rate' => 0
        ];
    }
    
    /**
     * Refresh all cached rates (call via cron job)
     */
    public function refreshAllRates(): void
    {
        $pairs = [
            ['BWP', 'ZAR'], ['ZAR', 'BWP'],
            ['BWP', 'USD'], ['USD', 'BWP'],
            ['BWP', 'EUR'], ['EUR', 'BWP'],
            ['ZAR', 'USD'], ['USD', 'ZAR']
        ];
        
        foreach ($pairs as [$from, $to]) {
            // Refresh wholesale rate
            $wholesaleRate = $this->getBestPartnerRate($from, $to);
            if ($wholesaleRate === null) {
                $marketRate = $this->getMarketRate($from, $to);
                $discount = $this->getPartnerDiscount($from, $to);
                $wholesaleRate = $marketRate * (1 + $discount);
            }
            
            if ($wholesaleRate) {
                $this->cacheRate($from, $to, $wholesaleRate, 'wholesale', 'refresh');
            }
            
            // Refresh market rate
            $marketRate = $this->getMarketRate($from, $to);
            if ($marketRate) {
                $this->cacheRate($from, $to, $marketRate, 'market', 'refresh');
            }
            
            error_log("[ForexService] Refreshed rates for {$from}/{$to}: wholesale={$wholesaleRate}, market={$marketRate}");
        }
    }
    
    /**
     * Get exchange rate (simple wrapper for getClientRate)
     */
    public function getExchangeRate(string $from, string $to, string $clientTier = 'retail'): float
    {
        return $this->getClientRate($from, $to, $clientTier);
    }
}
