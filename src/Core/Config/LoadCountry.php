<?php
declare(strict_types=1);

namespace Core\Config;

final class LoadCountry
{
    public static function getConfig(): array
    {
        $countryMeta = require __DIR__ . '/SystemCountry.php';

        $country = defined('SYSTEM_COUNTRY')
            ? SYSTEM_COUNTRY
            : ($countryMeta['name'] ?? 'Botswana');

        $countrySlug = defined('SYSTEM_COUNTRY_SLUG')
            ? SYSTEM_COUNTRY_SLUG
            : ($countryMeta['slug'] ?? strtolower($country));
        
        // Get country code (BW, NG, KE, etc.)
        $countryCode = defined('SYSTEM_COUNTRY_CODE')
            ? SYSTEM_COUNTRY_CODE
            : ($countryMeta['code'] ?? 'BW');

        // Project root path
        $projectRoot = dirname(__DIR__, 3);
        
        // Config file paths - ALL in src/Core/Config/Countries/{country}/
        $configFile       = $projectRoot . "/src/Core/Config/Countries/{$country}/config.php";
        $databaseFile     = $projectRoot . "/src/Core/Config/Countries/{$country}/database.php";
        $participantsFile = $projectRoot . "/src/Core/Config/Countries/{$country}/participants.json";
        $feesFile         = $projectRoot . "/src/Core/Config/Countries/{$country}/fees.json";

        $countryConfig = [];
        
        // Load main config
        if (file_exists($configFile)) {
            $countryConfig = require $configFile;
            error_log("Loaded config from: {$configFile}");
        } else {
            error_log("Config file not found: {$configFile}");
        }

        // Load participants
        if (file_exists($participantsFile)) {
            $participantsConfig = json_decode((string) file_get_contents($participantsFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['participants'] = $participantsConfig['participants'] ?? [];
                $countryConfig['api_keys']     = $participantsConfig['api_keys'] ?? [];
                error_log("Loaded participants from: {$participantsFile}");
            } else {
                error_log("JSON parse error in participants file: " . json_last_error_msg());
            }
        } else {
            error_log("Participants file not found: {$participantsFile}");
            $countryConfig['participants'] = [];
            $countryConfig['api_keys'] = [];
        }

        // Load fees
        if (file_exists($feesFile)) {
            $feesConfig = json_decode((string) file_get_contents($feesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['fees'] = self::resolveFees($feesConfig);
                error_log("Loaded fees from: {$feesFile}");
            } else {
                error_log("JSON parse error in fees file: " . json_last_error_msg());
            }
        } else {
            error_log("Fees file not found: {$feesFile}");
            $countryConfig['fees'] = [];
        }

        // ============================================================
        // DATABASE CONFIGURATION - Each country has its own database
        // ============================================================
        // Load database configuration for this specific country
        if (file_exists($databaseFile)) {
            $dbConfig = require $databaseFile;
            $countryConfig['db']['swap'] = $dbConfig;
            error_log("Loaded database for {$country} from: {$databaseFile}");
        } else {
            error_log("Database file not found: {$databaseFile}, using environment variables for {$countryCode}");
            
            // Fallback: Build database config from environment variables
            // Each country should have its own environment variables
            $dbName = getenv("DB_NAME_{$countryCode}") ?: getenv('DB_NAME') ?: "swap_system_" . strtolower($countryCode);
            $dbHost = getenv("DB_HOST_{$countryCode}") ?: getenv('DB_HOST') ?: 'localhost';
            $dbPort = getenv("DB_PORT_{$countryCode}") ?: getenv('DB_PORT') ?: '5432';
            $dbUser = getenv("DB_USER_{$countryCode}") ?: getenv('DB_USER') ?: 'postgres';
            $dbPass = getenv("DB_PASS_{$countryCode}") ?: getenv('DB_PASSWORD') ?: '';
            
            // Also check for DATABASE_URL specific to this country
            $databaseUrl = getenv("DATABASE_URL_{$countryCode}") ?: getenv('DATABASE_URL');
            if ($databaseUrl) {
                $db = parse_url($databaseUrl);
                $countryConfig['db']['swap'] = [
                    'type' => 'pgsql',
                    'host' => $db['host'] ?? $dbHost,
                    'port' => (int)($db['port'] ?? $dbPort),
                    'database' => ltrim($db['path'] ?? '', '/'),
                    'username' => $db['user'] ?? $dbUser,
                    'password' => $db['pass'] ?? $dbPass,
                ];
            } else {
                $countryConfig['db']['swap'] = [
                    'type' => 'pgsql',
                    'host' => $dbHost,
                    'port' => (int)$dbPort,
                    'database' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPass,
                ];
            }
        }

        // ============================================================
        // SOURCE PROVIDER CONFIGURATION (CazaCom, etc.) - API based
        // ============================================================
        // Source providers are accessed via API, not direct database
        $countryConfig['source_providers'] = [];
        
        // Load source provider config from participants if available
        if (isset($countryConfig['participants'])) {
            foreach ($countryConfig['participants'] as $providerName => $providerData) {
                if (isset($providerData['type']) && $providerData['type'] === 'SOURCE_PROVIDER') {
                    $countryConfig['source_providers'][$providerName] = [
                        'type' => 'api',
                        'name' => $providerData['name'] ?? $providerName,
                        'api_config' => [
                            'base_url' => $providerData['base_url'] ?? getenv("{$providerName}_API_URL"),
                            'api_key' => $providerData['api_key'] ?? getenv("{$providerName}_API_KEY"),
                            'api_secret' => $providerData['api_secret'] ?? getenv("{$providerName}_API_SECRET"),
                            'timeout' => $providerData['timeout'] ?? 30,
                        ],
                        'endpoints' => $providerData['endpoints'] ?? [],
                    ];
                }
            }
        }
        
        // Default source provider (CazaCom for Botswana)
        $countryConfig['default_source_provider'] = 'CAZACOM';
        
        // If no source providers configured, add default CazaCom API config
        if (empty($countryConfig['source_providers'])) {
            $countryConfig['source_providers']['CAZACOM'] = [
                'type' => 'api',
                'name' => 'CazaCom Botswana',
                'api_config' => [
                    'base_url' => getenv('CAZACOM_API_URL') ?: 'https://api.cazacom.co.bw/v1',
                    'api_key' => getenv('CAZACOM_API_KEY') ?: '',
                    'api_secret' => getenv('CAZACOM_API_SECRET') ?: '',
                    'timeout' => (int)(getenv('CAZACOM_API_TIMEOUT') ?: 30),
                ],
                'endpoints' => [
                    'verify_user' => '/users/verify',
                    'get_user_by_phone' => '/users/phone/{phone}',
                    'get_user_by_id' => '/users/id/{id}',
                    'get_user_by_email' => '/users/email/{email}',
                ],
            ];
        }

        // Format decimal values
        if (isset($countryConfig['settings']['swap_fee'])) {
            $countryConfig['settings']['swap_fee'] = self::decimal($countryConfig['settings']['swap_fee']);
        }

        if (isset($countryConfig['fees']['regulatory']['vat_rate'])) {
            $countryConfig['fees']['regulatory']['vat_rate'] = self::decimal($countryConfig['fees']['regulatory']['vat_rate']);
        }

        $GLOBALS['country_config'] = $countryConfig;

        if (!defined('COUNTRY_CONFIG')) {
            define('COUNTRY_CONFIG', json_encode($countryConfig, JSON_UNESCAPED_SLASHES));
        }

        return $countryConfig;
    }

    private static function decimal($value): string
    {
        if (!is_numeric($value)) {
            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }
        return number_format((float) $value, 6, '.', '');
    }

    private static function resolveFees(array $feeConfig): array
    {
        $resolved = [];

        if (isset($feeConfig['fees'])) {
            foreach ($feeConfig['fees'] as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $subKey => $subValue) {
                        if (is_numeric($subValue) && !is_string($subValue)) {
                            $value[$subKey] = self::decimal($subValue);
                        }
                    }
                    $resolved[$key] = $value;
                } else {
                    $resolved[$key] = is_numeric($value) ? self::decimal($value) : $value;
                }
            }
        }

        foreach (['metadata', 'regulatory', 'limits', 'currency', 'aliases', 'rules'] as $section) {
            if (isset($feeConfig[$section])) {
                $resolved[$section] = $feeConfig[$section];
            }
        }

        return $resolved;
    }
}

// For direct inclusion (non-namespace usage)
if (!function_exists('loadCountryConfig')) {
    function loadCountryConfig(): array
    {
        return \Core\Config\LoadCountry::getConfig();
    }
}

// If this file is included directly (not via namespace), return config
if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return \Core\Config\LoadCountry::getConfig();
}
