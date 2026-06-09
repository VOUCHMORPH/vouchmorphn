<?php

/**
 * VouchMorph Bootstrap File
 * Uses existing LoadCountry and CountryRegistry classes
 */

// ============================================================================
// 1. DEFINE PATHS
// ============================================================================

define('ROOT_PATH', dirname(__DIR__));
define('SRC_PATH', ROOT_PATH . '/src');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('VENDOR_PATH', ROOT_PATH . '/vendor');

// ============================================================================
// 2. LOAD COMPOSER AUTOLOADER
// ============================================================================

$autoloader = VENDOR_PATH . '/autoload.php';
if (!file_exists($autoloader)) {
    die("Composer autoloader not found at: $autoloader. Please run 'composer install'.");
}
require_once $autoloader;

// ============================================================================
// 3. LOAD ENVIRONMENT VARIABLES
// ============================================================================

if (class_exists('Dotenv\Dotenv') && file_exists(ROOT_PATH . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(ROOT_PATH);
    $dotenv->load();
}

// ============================================================================
// 4. LOAD COUNTRY SYSTEM (USING YOUR EXISTING CLASSES)
// ============================================================================

// Load SystemCountry to determine which country is running
$systemCountry = require SRC_PATH . '/Core/Config/SystemCountry.php';

// Now use LoadCountry to get all configuration for this country
$countryConfig = \Core\Config\LoadCountry::getConfig();

// Extract values from loaded config (THESE ARE AVAILABLE NOW)
$countryCode = $countryConfig['country_code'];
$countryName = $countryConfig['country'];
$countrySlug = strtolower($countryName);

// Database configuration from LoadCountry
$dbConfig = $countryConfig['db']['swap'];

error_log("[Bootstrap] Running for country: {$countryName} ({$countryCode})");

// ============================================================================
// 5. TIMEZONE SETTING (MOVE BEFORE DATABASE CONNECTION)
// ============================================================================

// Define timezone function that accepts countryCode as parameter
function getValidTimezone(string $countryCode = null): string
{
    $envKeys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
    foreach ($envKeys as $key) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value && is_string($value) && in_array($value, timezone_identifiers_list())) {
            return $value;
        }
    }
    
    // Use country timezone from registry if available
    if ($countryCode) {
        $registry = \Core\Config\CountryRegistry::getInstance();
        $countryInfo = $registry->getCountry($countryCode);
        if (isset($countryInfo['config']['timezone'])) {
            return $countryInfo['config']['timezone'];
        }
    }
    
    return 'UTC';
}

$timezone = getValidTimezone($countryCode);
date_default_timezone_set($timezone);

// ============================================================================
// 6. CREATE DATABASE CONNECTION
// ============================================================================

$db = null;

try {
    $host = $dbConfig['host'];
    $port = $dbConfig['port'];
    $database = $dbConfig['database'];
    $username = $dbConfig['username'];
    $password = $dbConfig['password'];
    
    $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
    
    // Add SSL for Railway connections
    if (strpos($host, 'railway.internal') !== false || getenv('RAILWAY_ENVIRONMENT')) {
        $dsn .= ';sslmode=require';
    }
    
    error_log("[Bootstrap] Connecting to database: {$host}:{$port}/{$database}");
    
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    $db->exec("SET NAMES 'UTF8'");
    $db->exec("SET timezone = '{$timezone}'");
    
    error_log("[Bootstrap] Database connection successful for {$countryName}");
    
} catch (PDOException $e) {
    error_log("[Bootstrap] Database connection failed: " . $e->getMessage());
    $db = null;
}

// ============================================================================
// 7. EXTRACT CONFIGURATIONS FROM LoadCountry RESULT
// ============================================================================

$participants = $countryConfig['participants'] ?? [];
$fees = $countryConfig['fees'] ?? [];
$atmNotes = $countryConfig['atm_notes'] ?? [];
$cardConfig = $countryConfig['card_config'] ?? [];
$communication = $countryConfig['communication'] ?? [];

// ============================================================================
// 8. APPLICATION SETTINGS
// ============================================================================

$settings = [
    'app_name' => $_ENV['APP_NAME'] ?? getenv('APP_NAME') ?: 'VouchMorph',
    'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production',
    'app_url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost',
    'timezone' => $timezone,
    'country_code' => $countryCode,
    'country_name' => $countryName,
    'country_config' => $countryConfig,
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!',
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '',
    'currency' => $countryConfig['currency'] ?? ($countryCode === 'BW' ? 'BWP' : 'USD'),
];

// ============================================================================
// 9. DEPENDENCY INJECTION CONTAINER
// ============================================================================

class Container
{
    private array $instances = [];
    private array $factories = [];
    
    public function set(string $id, $instance): void
    {
        $this->instances[$id] = $instance;
    }
    
    public function setFactory(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }
    
    public function get(string $id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        if (isset($this->factories[$id])) {
            $instance = ($this->factories[$id])($this);
            $this->instances[$id] = $instance;
            return $instance;
        }
        
        throw new \Exception("Service not found in container: " . $id);
    }
    
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }
}

$container = new Container();

// ============================================================================
// 10. REGISTER CORE SERVICES
// ============================================================================

$container->set(PDO::class, $db);
$container->set('settings', $settings);
$container->set('countryConfig', $countryConfig);
$container->set('countryCode', $countryCode);
$container->set('countryName', $countryName);
$container->set('participants', $participants);
$container->set('fees', $fees);
$container->set('atmNotes', $atmNotes);
$container->set('cardConfig', $cardConfig);
$container->set('communication', $communication);

// ============================================================================
// 11. REGISTER DOMAIN SERVICES
// ============================================================================

$container->setFactory('Domain\Services\Settlement\HybridSettlementStrategy', function($c) {
    return new \Domain\Services\Settlement\HybridSettlementStrategy(
        $c->get(PDO::class)
    );
});

$container->setFactory('Domain\Services\ContributionCalculator', function($c) {
    return new \Domain\Services\ContributionCalculator();
});

$container->setFactory('Domain\Services\MultiSourceFeeCalculator', function($c) {
    return new \Domain\Services\MultiSourceFeeCalculator(
        $c->get('countryConfig'),
        $c->get('countryCode')
    );
});

$container->setFactory('Domain\Services\FeeService', function($c) {
    $feesConfig = $c->get('fees');
    $currency = $c->get('settings')['currency'] ?? 'BWP';
    return new \Domain\Services\FeeService($feesConfig, $currency);
});

$container->setFactory('Domain\Services\ForexService', function($c) {
    return new \Domain\Services\ForexService(
        $c->get(PDO::class),
        $c->get('countryConfig'),
        $c->get('participants'),
        $c->get('Domain\Services\FeeService')
    );
});

$container->setFactory('Domain\Services\CardService', function($c) {
    $vouchmorphConfig = $c->get('participants')['vouchmorph'] ?? [];
    return new \Domain\Services\CardService(
        $c->get(PDO::class),
        $c->get('countryCode'),
        $vouchmorphConfig
    );
});

$container->setFactory('Domain\Services\SwapService', function($c) {
    $fullConfig = [
        'participants' => $c->get('participants'),
        'fees' => $c->get('fees'),
        'currency' => $c->get('settings')['currency'] ?? 'BWP',
        'atm_notes' => $c->get('atmNotes'),
        'communication' => $c->get('communication'),
        'multi_source' => ['enabled' => true, 'extra_source_fee' => 1.00, 'max_total_fee' => 15.00]
    ];
    
    return new \Domain\Services\SwapService(
        $c->get(PDO::class),
        $c->get('settings'),
        $c->get('countryCode'),
        $c->get('settings')['encryption_key'],
        $fullConfig
    );
});

$container->setFactory('Domain\Services\MultiSourceSwapExecutor', function($c) {
    return new \Domain\Services\MultiSourceSwapExecutor(
        $c->get(PDO::class),
        $c->get('Domain\Services\SwapService'),
        $c->get('Domain\Services\Settlement\HybridSettlementStrategy'),
        $c->get('countryConfig'),
        $c->get('countryCode')
    );
});

// ============================================================================
// 12. DEFINE CONSTANTS FOR APPLICATION USE (NOW AFTER ALL VALUES ARE AVAILABLE)
// ============================================================================

if (!defined('COUNTRY_CODE')) {
    define('COUNTRY_CODE', $countryCode);
}
if (!defined('COUNTRY_NAME')) {
    define('COUNTRY_NAME', $countryName);
}
if (!defined('COUNTRY_SLUG')) {
    define('COUNTRY_SLUG', $countrySlug);
}
if (!defined('COUNTRY_CONFIG_PATH')) {
    define('COUNTRY_CONFIG_PATH', SRC_PATH . '/Core/Config/Countries/' . $countryName);
}

// ============================================================================
// 13. RETURN CONTAINER
// ============================================================================

error_log("[Bootstrap] VouchMorph initialized for {$countryName} ({$countryCode})");
error_log("[Bootstrap] Database: " . ($db ? 'Connected' : 'Not connected'));

return $container;
