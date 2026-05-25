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

        $configFile       = dirname(__DIR__, 3) . "/src/Core/Config/Countries/{$country}/config.php";
        $participantsFile = dirname(__DIR__, 3) . "/config/countries/{$countrySlug}/participants.json";
        $feesFile         = dirname(__DIR__, 3) . "/config/countries/{$countrySlug}/fees.json";

        $countryConfig = [];
        if (file_exists($configFile)) {
            $countryConfig = require $configFile;
        }

        // Check for participants file in alternative location
        if (!file_exists($participantsFile)) {
            // Try alternative path
            $altParticipantsFile = dirname(__DIR__, 3) . "/src/Core/Config/Countries/{$country}/participants.json";
            if (file_exists($altParticipantsFile)) {
                $participantsFile = $altParticipantsFile;
            } else {
                error_log("Participants file error: Missing {$participantsFile}");
                // Don't die, use default
                $participantsConfig = ['participants' => [], 'api_keys' => []];
            }
        }

        if (file_exists($participantsFile)) {
            $participantsConfig = json_decode((string) file_get_contents($participantsFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON parse error in participants file: " . json_last_error_msg());
                $participantsConfig = ['participants' => [], 'api_keys' => []];
            }
            $countryConfig['participants'] = $participantsConfig['participants'] ?? [];
            $countryConfig['api_keys']     = $participantsConfig['api_keys'] ?? [];
        } else {
            $countryConfig['participants'] = [];
            $countryConfig['api_keys'] = [];
        }

        if (!file_exists($feesFile)) {
            error_log("Fees file missing: {$feesFile}");
            $feesConfig = ['fees' => []];
        } else {
            $feesConfig = json_decode((string) file_get_contents($feesFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON parse error in fees file: " . json_last_error_msg());
                $feesConfig = ['fees' => []];
            }
        }

        $countryConfig['fees'] = self::resolveFees($feesConfig);

        // Database configuration from environment or config file
        if (!isset($countryConfig['db']['swap']) || empty($countryConfig['db']['swap'])) {
            $dbName = getenv('PG_NAME') ?: getenv('PG_DB_CORE') ?: ('swap_system_' . strtolower($country));
            
            $countryConfig['db']['swap'] = [
                'type'     => 'pgsql',
                'name'     => $dbName,
                'database' => $dbName,
                'host'     => getenv('PG_HOST') ?: '127.0.0.1',
                'port'     => (int) (getenv('PG_PORT') ?: 5432),
                'user'     => getenv('PG_USER') ?: 'postgres',
                'password' => getenv('PG_PASS') ?: '',
            ];
        }

        if (!isset($countryConfig['db']['source_client_key'])) {
            $countryConfig['db']['source_client_key'] = 'cazacom';
        }

        $sourceKey = $countryConfig['db']['source_client_key'];
        if (!isset($countryConfig['db'][$sourceKey]) || empty($countryConfig['db'][$sourceKey])) {
            $countryConfig['db'][$sourceKey] = [
                'type'     => 'pgsql',
                'name'     => getenv('SOURCE_DB_NAME') ?: 'cazacom_db',
                'database' => getenv('SOURCE_DB_NAME') ?: 'cazacom_db',
                'host'     => getenv('SOURCE_DB_HOST') ?: (getenv('PG_HOST') ?: '127.0.0.1'),
                'port'     => (int) (getenv('SOURCE_DB_PORT') ?: (getenv('PG_PORT') ?: 5432)),
                'user'     => getenv('SOURCE_DB_USER') ?: (getenv('PG_USER') ?: 'postgres'),
                'password' => getenv('SOURCE_DB_PASS') ?: (getenv('PG_PASS') ?: ''),
            ];
        }

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
