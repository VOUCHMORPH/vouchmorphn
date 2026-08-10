<?php

/**
 * VouchMorph Bootstrap File
 * SINGLE SOURCE OF TRUTH - Uses only DATABASE_URL for database connections
 */

// ============================================================================
// 1. DEFINE PATHS
// ============================================================================

// FIX: Check if ROOT_PATH is already defined to prevent warnings
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', ROOT_PATH . '/src');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', ROOT_PATH . '/config');
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', ROOT_PATH . '/public');
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', ROOT_PATH . '/storage');
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', ROOT_PATH . '/vendor');
}

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

// Extract values from loaded config
$countryCode = $countryConfig['country_code'];
$countryName = $countryConfig['country'];
$countrySlug = strtolower($countryName);

error_log("[Bootstrap] Running for country: {$countryName} ({$countryCode})");

// ============================================================================
// 5. TIMEZONE SETTING - FIXED
// ============================================================================

function getValidTimezone(string $countryCode = null): string
{
    // Check environment variables first
    $envKeys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
    foreach ($envKeys as $key) {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value && is_string($value) && in_array($value, timezone_identifiers_list())) {
            return $value;
        }
    }
    
    // Country code to timezone mapping
    if ($countryCode) {
        $timezoneMap = [
            'BW' => 'Africa/Gaborone',
            'ZA' => 'Africa/Johannesburg',
            'NA' => 'Africa/Windhoek',
            'ZM' => 'Africa/Lusaka',
            'ZW' => 'Africa/Harare',
            'MW' => 'Africa/Blantyre',
            'MZ' => 'Africa/Maputo',
            'LS' => 'Africa/Maseru',
            'SZ' => 'Africa/Mbabane',
            'KE' => 'Africa/Nairobi',
            'UG' => 'Africa/Kampala',
            'TZ' => 'Africa/Dar_es_Salaam',
            'NG' => 'Africa/Lagos',
            'GH' => 'Africa/Accra',
            'US' => 'America/New_York',
            'GB' => 'Europe/London',
        ];
        
        if (isset($timezoneMap[$countryCode])) {
            return $timezoneMap[$countryCode];
        }
    }
    
    return 'UTC';
}

// CALL THE FUNCTION TO SET $timezone
$timezone = getValidTimezone($countryCode);
date_default_timezone_set($timezone);
error_log("[Bootstrap] Timezone set to: {$timezone}");

// ============================================================================
// 6. CREATE DATABASE CONNECTION - SINGLE SOURCE OF TRUTH
// ============================================================================

$db = null;

try {
    // Use DBConnection class - ONLY reads DATABASE_URL
    $db = \Core\Database\DBConnection::getConnection();
    
    if (!$db) {
        throw new \Exception("DBConnection returned null");
    }
    
    // Set timezone on the connection
    $db->exec("SET timezone = '{$timezone}'");
    
    error_log("[Bootstrap] Database connection successful for {$countryName}");
    
} catch (\Exception $e) {
    error_log("[Bootstrap] Database connection failed: " . $e->getMessage());
    $db = null;
    
    // In production, don't die - let app handle gracefully
    if (getenv('APP_ENV') !== 'production') {
        die("Database connection failed: " . $e->getMessage());
    }
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
// 11. REGISTER DOMAIN SERVICES - FIXED
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

// ============================================================
// FIXED: FeeService factory with correct argument types
// ============================================================
$container->setFactory('Domain\Services\FeeService', function($c) {
    $feesConfig = $c->get('fees');
    $countryConfig = $c->get('countryConfig');
    $currency = $c->get('settings')['currency'] ?? 'BWP';

    // ForexService is optional to FeeService's constructor. Resolve it
    // defensively — if its own factory has an incompatible signature,
    // don't let that break FeeService for callers (like CardService)
    // that only need flat-fee lookups.
    $forexService = null;
    try {
        $forexService = $c->get('Domain\Services\ForexService');
    } catch (\Throwable $e) {
        error_log("[Bootstrap] FeeService factory: ForexService unavailable, continuing without it: " . $e->getMessage());
    }

    return new \Domain\Services\FeeService($feesConfig, $countryConfig, $currency, $forexService);
});

$container->setFactory('Domain\Services\ForexService', function($c) {
    return new \Domain\Services\ForexService(
        $c->get(PDO::class),
        $c->get('countryConfig'),
        $c->get('participants'),
        $c->get('Domain\Services\FeeService')
    );
});

// ============================================================
// FIXED: CardService factory with FeeService and ForexService injected
// ============================================================
$container->setFactory('Domain\Services\CardService', function($c) {
    $vouchmorphConfig = $c->get('participants')['vouchmorph'] ?? [];

    $feeService = null;
    try {
        $feeService = $c->get('Domain\Services\FeeService');
    } catch (\Throwable $e) {
        error_log("[Bootstrap] CardService factory: FeeService unavailable, activation fee will fall back to default: " . $e->getMessage());
    }

    $forexService = null;
    try {
        $forexService = $c->get('Domain\Services\ForexService');
    } catch (\Throwable $e) {
        error_log("[Bootstrap] CardService factory: ForexService unavailable, continuing without it: " . $e->getMessage());
    }

    return new \Domain\Services\CardService(
        $c->get(PDO::class),
        $c->get('countryCode'),
        $vouchmorphConfig,
        $feeService,
        $forexService
    );
});

// ============================================================
// FIXED: SwapService factory with FeeService and ForexService injected
// ============================================================
$container->setFactory('Domain\Services\SwapService', function($c) {
    $fullConfig = [
        'participants' => $c->get('participants'),
        'fees' => $c->get('fees'),
        'currency' => $c->get('settings')['currency'] ?? 'BWP',
        'atm_notes' => $c->get('atmNotes'),
        'communication' => $c->get('communication'),
        'multi_source' => ['enabled' => true, 'extra_source_fee' => 1.00, 'max_total_fee' => 15.00]
    ];

    // Get FeeService and ForexService to pass to SwapService constructor
    // so it can pass them to its internal CardService instance
    $feeService = null;
    try {
        $feeService = $c->get('Domain\Services\FeeService');
    } catch (\Throwable $e) {
        error_log("[Bootstrap] SwapService factory: FeeService unavailable: " . $e->getMessage());
    }

    $forexService = null;
    try {
        $forexService = $c->get('Domain\Services\ForexService');
    } catch (\Throwable $e) {
        error_log("[Bootstrap] SwapService factory: ForexService unavailable: " . $e->getMessage());
    }
 
    return new \Domain\Services\SwapService(
        $c->get(PDO::class),
        $fullConfig,
        $c->get('countryCode'),
        $feeService,
        $forexService
        // $logger intentionally omitted -> defaults to null ->
        // SwapService builds its own working default logger internally.
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
// 12. DEFINE CONSTANTS FOR APPLICATION USE
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
// 13. VERIFY DATABASE CONNECTION (OPTIONAL - FOR DEBUGGING)
// ============================================================================

if (getenv('APP_ENV') !== 'production') {
    $status = \Core\Database\DBConnection::getStatus();
    error_log("[Bootstrap] DB Status: " . json_encode($status));
}

// ============================================================================
// 14. RETURN CONTAINER
// ============================================================================

error_log("[Bootstrap] VouchMorph initialized for {$countryName} ({$countryCode})");
error_log("[Bootstrap] Database: " . ($db ? 'Connected' : 'Not connected'));

return $container;
