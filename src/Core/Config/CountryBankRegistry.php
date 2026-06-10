<?php

declare(strict_types=1);

namespace CORE_CONFIG;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
require_once __DIR__ . '/system_country.php';
require_once __DIR__ . '/load_country.php'; // sets SYSTEM_COUNTRY and $countryConfig

class CountryBankRegistry
{
    protected static array $cache = [];
    protected static ?array $participants = null;

    /**
     * Load participants from YAML file if not already loaded
     */
    protected static function loadParticipants(): void
    {
        if (self::$participants !== null) return;

        $country = SYSTEM_COUNTRY;
        $yamlPath = __DIR__ . "/countries/{$country}/participants.yaml";

        if (!file_exists($yamlPath)) {
            throw new \Exception("Participants file missing for country {$country}: {$yamlPath}");
        }

        self::loadFromYaml($yamlPath);
    }

    /**
     * Load participants from YAML file
     */
    protected static function loadFromYaml(string $yamlPath): void
    {
        // Check if yaml extension is available
        if (function_exists('yaml_parse_file')) {
            $data = yaml_parse_file($yamlPath);
            if ($data === false) {
                throw new \Exception("Failed to parse YAML file: {$yamlPath}");
            }
            self::$participants = $data['participants'] ?? [];
            return;
        }
        
        // Fallback to symfony/yaml if available via composer
        if (class_exists('\Symfony\Component\Yaml\Yaml')) {
            $yaml = \Symfony\Component\Yaml\Yaml::parseFile($yamlPath);
            self::$participants = $yaml['participants'] ?? [];
            return;
        }
        
        throw new \Exception("YAML extension not loaded and Symfony YAML component not found. Please install ext-yaml or require symfony/yaml");
    }

    /**
     * Get a specific bank/participant
     */
    public static function get(string $bankCode): array
    {
        $bankCode = strtoupper($bankCode);

        if (isset(self::$cache[$bankCode])) {
            return self::$cache[$bankCode];
        }

        self::loadParticipants();

        if (!isset(self::$participants[$bankCode])) {
            throw new \Exception("Bank {$bankCode} not registered in country " . SYSTEM_COUNTRY);
        }

        self::$cache[$bankCode] = self::$participants[$bankCode];
        return self::$cache[$bankCode];
    }

    /**
     * Get all participants
     */
    public static function all(): array
    {
        self::loadParticipants();
        return self::$participants;
    }

    /**
     * Reload participants (clear cache)
     */
    public static function reload(): void
    {
        self::$cache = [];
        self::$participants = null;
        self::loadParticipants();
    }
}
