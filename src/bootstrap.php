<?php

/**
 * VouchMorph Bootstrap File
 * 
 * This file initializes the application, loads dependencies,
 * creates the Dependency Injection Container, and registers
 * all core services.
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
// 4. DEFINE COUNTRY AND PATHS
// ============================================================================

$countryCode = $_ENV['COUNTRY_CODE'] ?? getenv('COUNTRY_CODE') ?: 'botswana';
$countryLower = strtolower($countryCode);
$countryCapitalized = ucfirst($countryLower);

// CORRECT PATHS - using src/Core/Config/Countries/
$countryConfigPath = SRC_PATH . '/Core/Config/Countries/' . $countryCapitalized;
$countryConfigFile = $countryConfigPath . '/config.php';
$dbConfigFile = $countryConfigPath . '/database.php';

error_log("[Bootstrap] Loading config from: {$countryConfigPath}");

// ============================================================================
// 5. LOAD DATABASE CONFIGURATION
// ============================================================================

$dbConfig = [];
if (file_exists($dbConfigFile)) {
    $dbConfig = require $dbConfigFile;
    error_log("[Bootstrap] Loaded database config from: {$dbConfigFile}");
} else {
    error_log("[Bootstrap] Database config not found at: {$dbConfigFile}, using environment");
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '5432',
        'database' => getenv('DB_NAME') ?: 'vouchmorph',
        'user' => getenv('DB_USER') ?: 'postgres',
        'password' => getenv('DB_PASSWORD') ?: '',
        'driver' => 'pgsql'
    ];
}

// Create database connection
try {
    $host = $dbConfig['host'] ?? 'localhost';
    $port = $dbConfig['port'] ?? 5432;
    $database = $dbConfig['database'] ?? $dbConfig['dbname'] ?? 'vouchmorph';
    $username = $dbConfig['user'] ?? $dbConfig['username'] ?? 'postgres';
    $password = $dbConfig['password'] ?? $dbConfig['pass'] ?? '';
    
    $dsn = "pgsql:host=$host;port=$port;dbname=$database";
    
    $db = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    $db->exec("SET NAMES 'UTF8'");
    error_log("[Bootstrap] PostgreSQL connection established to database: $database");
    
} catch (PDOException $e) {
    error_log("[Bootstrap] Database connection failed: " . $e->getMessage());
    $db = null;
}

// ============================================================================
// 6. LOAD COUNTRY CONFIGURATION
// ============================================================================

$countryConfig = [];
if (file_exists($countryConfigFile)) {
    $countryConfig = require $countryConfigFile;
    error_log("[Bootstrap] Loaded country config from: {$countryConfigFile}");
}

// ============================================================================
// 7. LOAD COUNTRY-SPECIFIC JSON DATA
// ============================================================================

$banks = [];
$banksFile = $countryConfigPath . '/banks.json';
if (file_exists($banksFile)) {
    $banks = json_decode(file_get_contents($banksFile), true) ?? [];
}

$participants = [];
$participantsFile = $countryConfigPath . '/participants.json';
if (file_exists($participantsFile)) {
    $participantsData = json_decode(file_get_contents($participantsFile), true) ?? [];
    // Ensure participants is in the correct format for SwapService
    $participants = $participantsData['participants'] ?? $participantsData;
}

$fees = [];
$feesFile = $countryConfigPath . '/fees.json';
if (file_exists($feesFile)) {
    $fees = json_decode(file_get_contents($feesFile), true) ?? [];
}

$cards = [];
$cardsFile = $countryConfigPath . '/cards.json';
if (file_exists($cardsFile)) {
    $cards = json_decode(file_get_contents($cardsFile), true) ?? [];
}

$communication = [];
$commFile = $countryConfigPath . '/communication.json';
if (file_exists($commFile)) {
    $communication = json_decode(file_get_contents($commFile), true) ?? [];
}

$atmNotes = [];
$atmFile = $countryConfigPath . '/atm_notes.json';
if (file_exists($atmFile)) {
    $atmNotes = json_decode(file_get_contents($atmFile), true) ?? [];
}

// ============================================================================
// 8. TIMEZONE HELPER FUNCTION
// ============================================================================

function getValidTimezone(): string
{
    $envKeys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
    foreach ($envKeys as $key) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value && is_string($value) && in_array($value, timezone_identifiers_list())) {
            return $value;
        }
    }
    
    $countryCode = $_ENV['COUNTRY_CODE'] ?? getenv('COUNTRY_CODE') ?: '';
    if ($countryCode) {
        $countryZones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY);
        $countryUpper = strtoupper($countryCode);
        if (isset($countryZones[$countryUpper]) && !empty($countryZones[$countryUpper])) {
            return $countryZones[$countryUpper][0];
        }
    }
    
    $systemTimezone = ini_get('date.timezone');
    if ($systemTimezone && in_array($systemTimezone, timezone_identifiers_list())) {
        return $systemTimezone;
    }
    
    return 'UTC';
}

// ============================================================================
// 9. APPLICATION SETTINGS
// ============================================================================

$settings = [
    'app_name' => $_ENV['APP_NAME'] ?? getenv('APP_NAME') ?: 'VouchMorph',
    'app_env' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production',
    'app_url' => $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost',
    'timezone' => getValidTimezone(),
    'country_code' => $countryCode,
    'country_config' => $countryConfig,
    'encryption_key' => $_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY') ?: 'default-key-32-chars-long!!',
    'jwt_secret' => $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: '',
    'currency' => $countryConfig['currency'] ?? ($countryCode === 'botswana' ? 'BWP' : 'USD'),
];

// Set timezone
date_default_timezone_set($settings['timezone']);
if ($db) {
    $db->exec("SET timezone = '{$settings['timezone']}'");
}

// ============================================================================
// 10. DEPENDENCY INJECTION CONTAINER
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
// 11. REGISTER CORE SERVICES
// ============================================================================

$container->set(PDO::class, $db);
$container->set('settings', $settings);
$container->set('countryConfig', $countryConfig);
$container->set('countryCode', $countryCode);
$container->set('banks', $banks);
$container->set('participants', $participants);
$container->set('fees', $fees);
$container->set('cards', $cards);
$container->set('communication', $communication);
$container->set('atmNotes', $atmNotes);

// ============================================================================
// 12. REGISTER DOMAIN SERVICES (WITH CORRECT NAMESPACES)
// ============================================================================

// HybridSettlementStrategy
$container->setFactory('Domain\Services\Settlement\HybridSettlementStrategy', function($c) {
    return new \Domain\Services\Settlement\HybridSettlementStrategy(
        $c->get(PDO::class)
    );
});

// ContributionCalculator (NEW - for multi-source)
$container->setFactory('Domain\Services\ContributionCalculator', function($c) {
    return new \Domain\Services\ContributionCalculator();
});

// MultiSourceFeeCalculator (NEW - for multi-source)
$container->setFactory('Domain\Services\MultiSourceFeeCalculator', function($c) {
    return new \Domain\Services\MultiSourceFeeCalculator(
        $c->get('countryConfig'),
        $c->get('countryCode')
    );
});

// FeeService
$container->setFactory('Domain\Services\FeeService', function($c) {
    $feesConfig = $c->get('fees');
    $currency = $c->get('settings')['currency'] ?? 'BWP';
    return new \Domain\Services\FeeService($feesConfig, $currency);
});

// ForexService
$container->setFactory('Domain\Services\ForexService', function($c) {
    return new \Domain\Services\ForexService(
        $c->get(PDO::class),
        $c->get('countryConfig'),
        $c->get('participants'),
        $c->get('Domain\Services\FeeService')
    );
});

// CardService
$container->setFactory('Domain\Services\CardService', function($c) {
    $vouchmorphConfig = $c->get('participants')['vouchmorph'] ?? [];
    return new \Domain\Services\CardService(
        $c->get(PDO::class),
        $c->get('countryCode'),
        $vouchmorphConfig
    );
});

// SwapService (with correct namespace)
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

// MultiSourceSwapExecutor (NEW - for multi-source)
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
// 13. RETURN CONTAINER
// ============================================================================

error_log("[Bootstrap] VouchMorph initialized successfully. Country: {$countryCode}, Timezone: {$settings['timezone']}");
error_log("[Bootstrap] Multi-source services registered: ContributionCalculator, MultiSourceFeeCalculator, MultiSourceSwapExecutor");

return $container;
