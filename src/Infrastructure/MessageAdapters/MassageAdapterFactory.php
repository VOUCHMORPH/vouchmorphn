<?php
namespace VouchMorph\Infrastructure\MessageAdapters;

class MessageAdapterFactory {
    private array $globalConfig;
    private array $countryConfigs = [];
    private array $instances = [];
    private string $currentCountry;
    
    public function __construct(string $countryCode = null) {
        $this->globalConfig = require __DIR__ . '/../../config/message_adapters.php';
        
        if ($countryCode) {
            $this->setCountry($countryCode);
        }
    }
    
    public function setCountry(string $countryCode): self {
        $this->currentCountry = strtolower($countryCode);
        
        // Load country-specific config
        $configPath = __DIR__ . "/../../Config/countries/{$this->currentCountry}/bank_formats.php";
        
        if (!file_exists($configPath)) {
            throw new \Exception("No bank format config for country: {$countryCode}");
        }
        
        $this->countryConfigs[$this->currentCountry] = require $configPath;
        
        return $this;
    }
    
    public function getAdapterForBank(string $bankCode): MessageAdapterInterface {
        if (!$this->currentCountry) {
            throw new \Exception("Country not set. Call setCountry() first.");
        }
        
        $countryConfig = $this->countryConfigs[$this->currentCountry];
        $bankFormats = $countryConfig['bank_formats'];
        
        // Find which format this bank uses in this country
        $formatType = $bankFormats[$bankCode] ?? $countryConfig['default_format'];
        
        if (!isset($this->globalConfig['adapters'][$formatType])) {
            throw new \Exception("No adapter for format: {$formatType} in country: {$this->currentCountry}");
        }
        
        return $this->getAdapter($formatType);
    }
    
    public function getAdapter(string $formatType): MessageAdapterInterface {
        // Return cached instance
        $cacheKey = $this->currentCountry . '_' . $formatType;
        
        if (isset($this->instances[$cacheKey])) {
            return $this->instances[$cacheKey];
        }
        
        // Instantiate adapter (pass country for context if needed)
        $adapterClass = $this->globalConfig['adapters'][$formatType]['class'];
        $this->instances[$cacheKey] = new $adapterClass($this->currentCountry);
        
        return $this->instances[$cacheKey];
    }
    
    public function getSupportedBanks(): array {
        $countryConfig = $this->countryConfigs[$this->currentCountry];
        return array_keys($countryConfig['bank_formats']);
    }
}
