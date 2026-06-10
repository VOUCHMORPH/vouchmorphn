<?php
declare(strict_types=1);

namespace Core\Config;

final class LoadCountry
{
    public static function getConfig(): array
    {
        $countryMeta = require __DIR__ . '/SystemCountry.php';

        // Get the country NAME (Botswana, Nigeria, etc.) for the directory
        $countryName = defined('SYSTEM_COUNTRY')
            ? SYSTEM_COUNTRY
            : ($countryMeta['name'] ?? 'Botswana');
        
        // Get the country CODE (BW, NG, KE, etc.) for other uses
        $countryCode = defined('SYSTEM_COUNTRY_CODE')
            ? SYSTEM_COUNTRY_CODE
            : ($countryMeta['code'] ?? 'BW');

        $countrySlug = defined('SYSTEM_COUNTRY_SLUG')
            ? SYSTEM_COUNTRY_SLUG
            : ($countryMeta['slug'] ?? strtolower($countryName));

        $projectRoot = dirname(__DIR__, 3);
        
        // USE THE COUNTRY NAME (Botswana) not the code (BW)
        $countryDir = $projectRoot . "/src/Core/Config/Countries/{$countryName}";
        
        error_log("[LoadCountry] Looking for config in: {$countryDir}");
        
        // All config files in the country directory
        $configFile       = $countryDir . "/config.php";
        $databaseFile     = $countryDir . "/database.php";
        $participantsFile = $countryDir . "/participants.yaml";
        $feesFile         = $countryDir . "/fees.json";
        $atmNotesFile     = $countryDir . "/atm_notes.json";
        $cardsFile        = $countryDir . "/cards.json";
        $commFile         = $countryDir . "/communication.json";

        $countryConfig = [];
        
        // 1. Load main config.php
        if (file_exists($configFile)) {
            $countryConfig = require $configFile;
            error_log("[LoadCountry] Loaded config from: {$configFile}");
        } else {
            error_log("[LoadCountry] Config file not found: {$configFile}");
        }

        // 2. Load participants from YAML
        if (file_exists($participantsFile)) {
            $participantsConfig = self::parseYamlFile($participantsFile);
            if (!empty($participantsConfig)) {
                $countryConfig['participants'] = $participantsConfig['participants'] ?? [];
                $countryConfig['api_keys']     = $participantsConfig['api_keys'] ?? [];
                error_log("[LoadCountry] Loaded participants from YAML: {$participantsFile}");
            } else {
                error_log("[LoadCountry] Failed to parse YAML participants file: {$participantsFile}");
                $countryConfig['participants'] = [];
            }
        } else {
            error_log("[LoadCountry] Participants file not found: {$participantsFile}");
            $countryConfig['participants'] = [];
        }

        // 3. Load fees.json
        if (file_exists($feesFile)) {
            $feesConfig = json_decode(file_get_contents($feesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['fees'] = self::resolveFees($feesConfig);
                error_log("[LoadCountry] Loaded fees from: {$feesFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in fees file: " . json_last_error_msg());
            }
        } else {
            error_log("[LoadCountry] Fees file not found: {$feesFile}");
            $countryConfig['fees'] = [];
        }

        // 4. Load atm_notes.json
        if (file_exists($atmNotesFile)) {
            $atmNotesConfig = json_decode(file_get_contents($atmNotesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['atm_notes'] = $atmNotesConfig;
                error_log("[LoadCountry] Loaded ATM notes from: {$atmNotesFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in ATM notes file: " . json_last_error_msg());
            }
        } else {
            error_log("[LoadCountry] ATM notes file not found: {$atmNotesFile}");
            $countryConfig['atm_notes'] = [];
        }

        // 5. Load cards.json
        if (file_exists($cardsFile)) {
            $cardsConfig = json_decode(file_get_contents($cardsFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['card_config'] = $cardsConfig;
                error_log("[LoadCountry] Loaded card config from: {$cardsFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in cards file: " . json_last_error_msg());
            }
        } else {
            error_log("[LoadCountry] Cards file not found: {$cardsFile}");
            $countryConfig['card_config'] = [];
        }

        // 6. Load communication.json
        if (file_exists($commFile)) {
            $commConfig = json_decode(file_get_contents($commFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['communication'] = $commConfig;
                error_log("[LoadCountry] Loaded communication config from: {$commFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in communication file: " . json_last_error_msg());
            }
        } else {
            error_log("[LoadCountry] Communication file not found: {$commFile}");
            $countryConfig['communication'] = [];
        }

        // 7. Database configuration
        if (file_exists($databaseFile)) {
            $dbConfig = require $databaseFile;
            $countryConfig['db']['swap'] = $dbConfig;
            error_log("[LoadCountry] Loaded database for {$countryName} from: {$databaseFile}");
        } else {
            error_log("[LoadCountry] Database file not found: {$databaseFile}, using environment variables");
            
            $dbName = getenv("DB_NAME_{$countryCode}") ?: getenv('DB_NAME') ?: "swap_system_" . strtolower($countryCode);
            $dbHost = getenv("DB_HOST_{$countryCode}") ?: getenv('DB_HOST') ?: 'localhost';
            $dbPort = getenv("DB_PORT_{$countryCode}") ?: getenv('DB_PORT') ?: '5432';
            $dbUser = getenv("DB_USER_{$countryCode}") ?: getenv('DB_USER') ?: 'postgres';
            $dbPass = getenv("DB_PASS_{$countryCode}") ?: getenv('DB_PASSWORD') ?: '';
            
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

        // Add country code and name to config
        $countryConfig['country_code'] = $countryCode;
        $countryConfig['country'] = $countryName;
        $countryConfig['currency'] = $countryConfig['currency'] ?? ($countryCode === 'BW' ? 'BWP' : 'USD');

        // Source providers configuration
        $countryConfig['source_providers'] = [];
        
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
        
        $countryConfig['default_source_provider'] = 'CAZACOM';
        
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

    /**
     * Parse YAML file using available parser
     */
    private static function parseYamlFile(string $path): array
    {
        // Try native YAML extension first
        if (function_exists('yaml_parse_file')) {
            $data = yaml_parse_file($path);
            if ($data !== false) {
                return $data;
            }
        }
        
        // Try Symfony YAML component
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parseFile($path);
        }
        
        // Fallback to manual parsing for participants.yaml
        return self::parseYamlManually($path);
    }
    
    /**
     * Manual YAML parser for participants.yaml structure
     */
    private static function parseYamlManually(string $path): array
    {
        $content = file_get_contents($path);
        $participants = [];
        $lines = explode("\n", $content);
        $inParticipants = false;
        $currentKey = null;
        
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (empty($line) || $line[0] === '#') continue;
            
            if (preg_match('/^participants:$/', $line)) {
                $inParticipants = true;
                continue;
            }
            
            if ($inParticipants && preg_match('/^  ([A-Z_]+):$/', $line, $matches)) {
                $currentKey = $matches[1];
                $participants[$currentKey] = [];
                continue;
            }
            
            if ($currentKey && preg_match('/^    ([a-z_]+): (.+)$/', $line, $matches)) {
                $value = trim($matches[2], '"\'');
                $participants[$currentKey][$matches[1]] = $value;
                continue;
            }
        }
        
        return ['participants' => $participants];
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

if (!function_exists('loadCountryConfig')) {
    function loadCountryConfig(): array
    {
        return \Core\Config\LoadCountry::getConfig();
    }
}

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return \Core\Config\LoadCountry::getConfig();
}
