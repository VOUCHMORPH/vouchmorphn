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
    private static $countryCodeMap = null;
    
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
        
        // Get country code to remove if present
        $countryName = self::getActiveCountry();
        $countryCode = self::getCountryCode($countryName);
        
        // Remove country code if present
        if ($countryCode && substr($clean, 0, strlen($countryCode)) === $countryCode) {
            $clean = substr($clean, strlen($countryCode));
        }
        
        // Also try with 0 prefix common in some countries
        if (substr($clean, 0, 1) === '0') {
            $clean = substr($clean, 1);
        }
        
        $config = self::loadCountryConfig();
        $telcos = $config['telcos'] ?? [];
        
        foreach ($telcos as $name => $telcoConfig) {
            // Skip disabled telcos
            if (isset($telcoConfig['enabled']) && $telcoConfig['enabled'] === false) {
                continue;
            }
            
            // Check if telco has SMS enabled
            if (isset($telcoConfig['sms_enabled']) && $telcoConfig['sms_enabled'] === false) {
                continue;
            }
            
            foreach ($telcoConfig['prefixes'] as $prefix) {
                if (strpos($clean, (string)$prefix) === 0) {
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
        // Determine which endpoint to use based on provider type
        $endpointKey = $provider . '_endpoint';
        $endpoint = $telcoConfig[$endpointKey] ?? $telcoConfig['endpoint'] ?? '/api.php?path=sms/send';
        
        // Build the provider config for SmsGatewayClient
        return [
            'provider' => $telcoConfig['name'],
            'base_url' => $telcoConfig['base_url'] ?? null,
            'base_url_env' => $telcoConfig['base_url_env'] ?? strtoupper($telcoConfig['name']) . '_BASE_URL',
            'api_key_env' => $telcoConfig['api_key_env'] ?? strtoupper($telcoConfig['name']) . '_API_KEY',
            'api_key_ref' => $telcoConfig['api_key_env'] ?? strtoupper($telcoConfig['name']) . '_API_KEY',
            'endpoints' => [
                'send' => $endpoint,
                'status' => $telcoConfig['status_endpoint'] ?? '/api.php?path=sms/status',
                'health' => $telcoConfig['health_endpoint'] ?? '/api.php?path=health'
            ],
            'method' => 'POST',
            'sender' => $telcoConfig['sender_id'] ?? 'VOUCHMORPH',
            'default_sender' => $telcoConfig['sender_id'] ?? 'VOUCHMORPH',
            'default_cost' => $telcoConfig['cost_per_sms'] ?? 0.1,
            'enabled' => $telcoConfig['sms_enabled'] ?? false,
            'timeout' => $telcoConfig['timeout'] ?? 30,
            'retry_attempts' => $telcoConfig['retry_attempts'] ?? 3,
            'api_key_header' => $telcoConfig['api_key_header'] ?? 'X-API-Key',
            'ssl_verify' => $telcoConfig['ssl_verify'] ?? true,
            'phone_format' => $telcoConfig['phone_format'] ?? [
                'strip_prefix' => 0,
                'add_prefix' => null,
                'add_plus' => false
            ],
            'authentication' => [
                'type' => 'header',
                'key' => $telcoConfig['api_key_header'] ?? 'X-API-Key'
            ],
            'payload_template' => $telcoConfig['payload_template'] ?? [
                'to' => '{to}',
                'message' => '{message}',
                'sender' => '{sender}',
                'reference' => '{message_id}'
            ],
            'response_mappings' => [
                'message_id' => $telcoConfig['response_message_id_path'] ?? 'message_id',
                'status' => $telcoConfig['response_status_path'] ?? 'status'
            ],
            'status_mappings' => $telcoConfig['status_mappings'] ?? [
                'sent' => 'sent',
                'delivered' => 'delivered',
                'failed' => 'failed',
                'pending' => 'pending'
            ],
            'circuit_breaker' => [
                'enabled' => true,
                'failure_threshold' => 5,
                'timeout_seconds' => 60
            ]
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
        
        // Primary path: src/Core/Config/Countries/{CountryName}/communication.json
        $primaryPath = dirname(__DIR__, 2) . "/Core/Config/Countries/{$country}/communication.json";
        
        // Alternative paths for flexibility
        $countryCode = self::getCountryCode($country);
        $altPaths = [
            dirname(__DIR__, 2) . "/Core/Config/Countries/{$countryCode}/communication.json",
            dirname(__DIR__, 3) . "/config/countries/" . strtolower($country) . "/communication.json",
            dirname(__DIR__, 4) . "/config/countries/" . strtolower($country) . "/communication.json",
            __DIR__ . "/../../../config/countries/" . strtolower($country) . "/communication.json",
        ];
        
        // Check primary path first
        $configFile = null;
        if (file_exists($primaryPath)) {
            $configFile = $primaryPath;
        } else {
            // Try alternative paths
            foreach ($altPaths as $path) {
                if (file_exists($path)) {
                    $configFile = $path;
                    break;
                }
            }
        }
        
        if (!$configFile) {
            throw new Exception("Communication config not found for country: {$country} (looked in: {$primaryPath})");
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
            // SystemCountry.php returns $resolved with 'name' key
            if (isset($country['name'])) {
                return $country['name'];
            }
            // Fallback for other formats
            if (isset($country['country'])) {
                return $country['country'];
            }
            if (isset($country[0])) {
                return $country[0];
            }
        }
        
        if (is_string($country)) {
            return $country;
        }
        
        throw new Exception("Active country not configured");
    }
    
    /**
     * Get country code from country name
     */
    private static function getCountryCode(string $countryName): ?string
    {
        if (self::$countryCodeMap === null) {
            self::$countryCodeMap = [
                'Botswana' => '267',
                'Nigeria' => '234',
                'Kenya' => '254',
                'SouthAfrica' => '27',
                'Uganda' => '256',
                'Tanzania' => '255',
                'Ghana' => '233',
                'Zambia' => '260',
                'Zimbabwe' => '263',
                'Namibia' => '264',
                'Cameroon' => '237',
                'Senegal' => '221',
                'CotedIvoire' => '225',
                'Mali' => '223',
                'Ethiopia' => '251',
                'Algeria' => '213',
                'Morocco' => '212',
                'Egypt' => '20',
                'Sudan' => '249',
                'Libya' => '218',
                'Tunisia' => '216',
                'Rwanda' => '250',
                'Burundi' => '257',
                'Malawi' => '265',
                'Lesotho' => '266',
                'Mauritania' => '222',
                'CentralAfricanRepublic' => '236',
                'Congo' => '242',
                'DRCongo' => '243',
                'Niger' => '227',
                'BurkinaFaso' => '226',
                'Gambia' => '220',
                'SierraLeone' => '232',
                'Liberia' => '231',
                'Guinea' => '224',
                'Togo' => '228',
                'Benin' => '229',
                'Somalia' => '252',
                'Djibouti' => '253',
                'Eritrea' => '291',
                'SaoTomeAndPrincipe' => '239',
                'EquatorialGuinea' => '240',
                'CapeVerde' => '238'
            ];
        }
        
        return self::$countryCodeMap[$countryName] ?? null;
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
            // Check if telco is enabled and SMS is enabled
            $isEnabled = ($telcoConfig['enabled'] ?? true) && ($telcoConfig['sms_enabled'] ?? false);
            if ($isEnabled) {
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
