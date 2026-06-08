<?php
// src/Core/Factories/CommunicationFactory.php

namespace Core\Factories;

use Infrastructure\SMS\SmsGatewayClient;
use Infrastructure\SMS\Contracts\ProviderInterface;
use Security\Encryption\KeyVault;
use Exception;

class CommunicationFactory
{
    private static $keyVault = null;
    private static $configCache = [];
    
    /**
     * Create communication provider (SMS, USSD, etc)
     * Uses communication.json from config/countries/{country}/communication.json
     * 
     * @param string $provider The communication provider type (sms, ussd, etc)
     * @param string $telco Optional specific telco (cazacom, mascom, orange)
     * @return ProviderInterface
     * @throws Exception
     */
    public static function create(string $provider, ?string $telco = null): ProviderInterface
    {
        // Load KeyVault for secrets
        if (self::$keyVault === null) {
            self::$keyVault = KeyVault::getInstance();
        }
        
        // Load country configuration
        $config = self::loadCountryConfig();
        
        // If specific telco requested, use its config
        if ($telco && isset($config['telcos'][$telco])) {
            $telcoConfig = $config['telcos'][$telco];
            $providerConfig = self::buildProviderConfig($telcoConfig, $provider);
        } else {
            // Use default provider config
            $providerKey = strtolower($provider);
            if (!isset($config[$providerKey])) {
                throw new Exception("Communication provider '{$provider}' not configured");
            }
            $providerConfig = $config[$providerKey];
        }
        
        // Inject API key from Vault
        $apiKeyEnv = $providerConfig['api_key_env'] ?? null;
        if ($apiKeyEnv) {
            $apiKey = self::$keyVault->get($apiKeyEnv);
            if ($apiKey) {
                $providerConfig['api_key'] = $apiKey;
            }
        }
        
        // Inject base URL from Vault if not set
        if (empty($providerConfig['base_url'])) {
            $baseUrlEnv = $providerConfig['base_url_env'] ?? null;
            if ($baseUrlEnv) {
                $providerConfig['base_url'] = self::$keyVault->get($baseUrlEnv);
            }
        }
        
        return self::instantiateProvider($provider, $providerConfig);
    }
    
    /**
     * Create provider for specific phone number (auto-detects telco)
     * 
     * @param string $provider Type (sms, ussd, airtime)
     * @param string $phoneNumber Phone number to route
     * @return ProviderInterface
     * @throws Exception
     */
    public static function createForPhone(string $provider, string $phoneNumber): ProviderInterface
    {
        $telco = self::detectTelcoByPhone($phoneNumber);
        return self::create($provider, $telco);
    }
    
    /**
     * Detect telco by phone number prefix
     * 
     * @param string $phoneNumber
     * @return string|null
     */
    public static function detectTelcoByPhone(string $phoneNumber): ?string
    {
        // Clean phone number
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);
        // Remove country code if present
        if (substr($clean, 0, 3) === '267') {
            $clean = substr($clean, 3);
        }
        
        $config = self::loadCountryConfig();
        $telcos = $config['telcos'] ?? [];
        
        foreach ($telcos as $name => $telcoConfig) {
            if (!$telcoConfig['enabled'] ?? true) {
                continue;
            }
            foreach ($telcoConfig['prefixes'] as $prefix) {
                if (strpos($clean, $prefix) === 0) {
                    return $name;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Build provider config from telco config
     */
    private static function buildProviderConfig(array $telcoConfig, string $provider): array
    {
        $providerKey = $provider . '_gateway';
        
        return [
            'provider' => $telcoConfig['name'],
            'base_url' => $telcoConfig['base_url'] ?? null,
            'base_url_env' => $telcoConfig['base_url_env'] ?? strtoupper($telcoConfig['name']) . '_BASE_URL',
            'api_key_env' => $telcoConfig['api_key_env'] ?? strtoupper($telcoConfig['name']) . '_API_KEY',
            'sms_endpoint' => $telcoConfig['sms_endpoint'] ?? '/api.php?path=sms/send',
            'default_sender' => 'VOUCHMORPH',
            'default_cost' => 0.1,
            'enabled' => $telcoConfig['sms_enabled'] ?? false,
            'timeout' => 10,
            'retry_attempts' => 3,
            'api_key_header' => 'X-API-Key'
        ];
    }
    
    /**
     * Load country configuration
     */
    private static function loadCountryConfig(): array
    {
        $country = self::getActiveCountry();
        $cacheKey = 'comm_config_' . $country;
        
        if (isset(self::$configCache[$cacheKey])) {
            return self::$configCache[$cacheKey];
        }
        
        $possiblePaths = [
            dirname(__DIR__, 4) . "/config/countries/" . strtolower($country) . "/communication.json",
            dirname(__DIR__, 3) . "/config/countries/" . strtolower($country) . "/communication.json",
            __DIR__ . "/../../../config/countries/" . strtolower($country) . "/communication.json",
        ];
        
        $configFile = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $configFile = $path;
                break;
            }
        }
        
        if (!$configFile) {
            throw new Exception("Communication config not found for country: {$country}");
        }
        
        $jsonContent = file_get_contents($configFile);
        $config = json_decode($jsonContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in communication.json: " . json_last_error_msg());
        }
        
        self::$configCache[$cacheKey] = $config;
        return $config;
    }
    
    /**
     * Get active country
     */
    private static function getActiveCountry(): string
    {
        $systemCountryPath = __DIR__ . '/../Config/SystemCountry.php';
        
        if (!file_exists($systemCountryPath)) {
            $systemCountryPath = dirname(__DIR__, 3) . '/src/Core/Config/SystemCountry.php';
        }
        
        if (!file_exists($systemCountryPath)) {
            throw new Exception("System country configuration not found");
        }
        
        $country = require $systemCountryPath;
        
        if (is_array($country)) {
            $country = $country['country'] ?? $country[0] ?? null;
        }
        
        if (!$country) {
            throw new Exception("Active country not configured");
        }
        
        return $country;
    }
    
    /**
     * Instantiate the appropriate communication provider
     */
    private static function instantiateProvider(string $type, array $config): ProviderInterface
    {
        switch ($type) {
            case 'sms':
                if (!class_exists('Infrastructure\SMS\SmsGatewayClient')) {
                    throw new Exception("SmsGatewayClient class not found");
                }
                return new SmsGatewayClient($config);
                
            case 'ussd':
                if (class_exists('Infrastructure\USSD\UssdGatewayClient')) {
                    return new \Infrastructure\USSD\UssdGatewayClient($config);
                }
                throw new Exception("USSD provider not implemented yet");
                
            case 'airtime':
                if (class_exists('Infrastructure\Airtime\AirtimeGatewayClient')) {
                    return new \Infrastructure\Airtime\AirtimeGatewayClient($config);
                }
                throw new Exception("Airtime provider not implemented yet");
                
            default:
                throw new Exception("Unsupported provider type: {$type}");
        }
    }
    
    /**
     * Get all enabled telcos for current country
     */
    public static function getEnabledTelcos(): array
    {
        $config = self::loadCountryConfig();
        $telcos = $config['telcos'] ?? [];
        
        $enabled = [];
        foreach ($telcos as $name => $telcoConfig) {
            if ($telcoConfig['enabled'] ?? true) {
                $enabled[$name] = $telcoConfig;
            }
        }
        
        return $enabled;
    }
    
    /**
     * Get available communication providers for current country
     */
    public static function getAvailableProviders(): array
    {
        try {
            return self::loadCountryConfig();
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Get specific provider configuration without instantiating
     */
    public static function getProviderConfig(string $provider, ?string $telco = null): ?array
    {
        try {
            $config = self::loadCountryConfig();
            
            if ($telco && isset($config['telcos'][$telco])) {
                return self::buildProviderConfig($config['telcos'][$telco], $provider);
            }
            
            $providerKey = strtolower($provider);
            return $config[$providerKey] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }
}
