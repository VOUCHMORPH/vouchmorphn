<?php
declare(strict_types=1);

namespace Core\Config;

final class LoadCountry
{
    public static function getConfig(?string $countryOverride = null): array
    {
        $countryMeta = require __DIR__ . '/SystemCountry.php';

        // Existing zero-arg callers (SwapService's own constructor, and
        // every other call site in the codebase) keep working exactly as
        // before — $countryOverride defaults to null, falling through to
        // the same SYSTEM_COUNTRY/SystemCountry.php resolution as always.
        // New callers (Mojaloop's index.php, ParticipantsHandler,
        // PartiesHandler) can now pass an explicit country instead.
        $countryName = $countryOverride
            ?? (defined('SYSTEM_COUNTRY') ? SYSTEM_COUNTRY : ($countryMeta['name'] ?? 'Botswana'));

        $countryCode = defined('SYSTEM_COUNTRY_CODE')
            ? SYSTEM_COUNTRY_CODE
            : ($countryMeta['code'] ?? 'BW');

        $projectRoot = dirname(__DIR__, 3);
        $countryDir = $projectRoot . "/src/Core/Config/Countries/{$countryName}";
        
        error_log("[LoadCountry] Looking for config in: {$countryDir}");
        
        // Config files
        $databaseFile     = $countryDir . "/database.php";
        $participantsFile = $countryDir . "/participants.yaml";
        $feesFile         = $countryDir . "/fees.json";
        $atmNotesFile     = $countryDir . "/atm_notes.json";
        $cardsFile        = $countryDir . "/cards.json";
        $commFile         = $countryDir . "/communication.json";

        $countryConfig = [
            'country' => $countryName,
            'country_code' => $countryCode,
            'currency' => 'BWP'
        ];
        
        // 1. Load participants from YAML
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

        // 2. Load fees.json - PUT PRODUCTS AT TOP LEVEL
        if (file_exists($feesFile)) {
            $feesConfig = json_decode(file_get_contents($feesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['fees'] = $feesConfig;
                
                // Put products at top level
                $productKeys = ['CASHOUT', 'DEPOSIT', 'CARD_LOAD'];
                foreach ($productKeys as $key) {
                    if (isset($feesConfig[$key])) {
                        $countryConfig[$key] = $feesConfig[$key];
                    }
                }
                
                if (isset($feesConfig['regulatory'])) {
                    $countryConfig['regulatory'] = $feesConfig['regulatory'];
                }
                
                error_log("[LoadCountry] Loaded fees from: {$feesFile}");
            } else {
                error_log("[LoadCountry] JSON parse error in fees file: " . json_last_error_msg());
                $countryConfig['fees'] = [];
            }
        } else {
            error_log("[LoadCountry] Fees file not found: {$feesFile}");
            $countryConfig['fees'] = [];
        }

        // 3. Load atm_notes.json
        if (file_exists($atmNotesFile)) {
            $atmNotesConfig = json_decode(file_get_contents($atmNotesFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['atm_notes'] = $atmNotesConfig;
                error_log("[LoadCountry] Loaded ATM notes from: {$atmNotesFile}");
            }
        }

        // 4. Load cards.json
        if (file_exists($cardsFile)) {
            $cardsConfig = json_decode(file_get_contents($cardsFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['card_config'] = $cardsConfig;
                error_log("[LoadCountry] Loaded card config from: {$cardsFile}");
            }
        }

        // 5. Load communication.json
        if (file_exists($commFile)) {
            $commConfig = json_decode(file_get_contents($commFile), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $countryConfig['communication'] = $commConfig;
                error_log("[LoadCountry] Loaded communication config from: {$commFile}");
            }
        }

        // 6. Database configuration
        if (file_exists($databaseFile)) {
            $dbConfig = require $databaseFile;
            $countryConfig['db']['swap'] = $dbConfig;
            error_log("[LoadCountry] Loaded database for {$countryName} from: {$databaseFile}");
        } else {
            error_log("[LoadCountry] Database file not found: {$databaseFile}");
            $countryConfig['db'] = [];
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
        if (function_exists('yaml_parse_file')) {
            $data = yaml_parse_file($path);
            if ($data !== false) {
                return $data;
            }
        }
        
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            return \Symfony\Component\Yaml\Yaml::parseFile($path);
        }
        
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
}

if (!function_exists('loadCountryConfig')) {
    function loadCountryConfig(?string $countryOverride = null): array
    {
        return \Core\Config\LoadCountry::getConfig($countryOverride);
    }
}

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return \Core\Config\LoadCountry::getConfig();
}
