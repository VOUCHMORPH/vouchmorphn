<?php

/**
 * VouchMorph Bootstrap File
 * SINGLE SOURCE OF TRUTH - Uses only DATABASE_URL for database connections
 */

// ============================================================================
// 1. DEFINE PATHS
// ============================================================================

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
// 5. TIMEZONE SETTING
// ============================================================================
//
// HARDENED: the previous version could, under some code paths, finish
// this section without $timezone ever actually being assigned (e.g. an
// exception thrown mid-function, or a stale/partially-deployed copy of
// this file during a rolling deploy). When that happened, PHP raised
// "Undefined variable $timezone" as a WARNING (not fatal), execution
// continued with $timezone effectively '', and
// `$db->exec("SET timezone = ''")` further down then made Postgres
// reject the connection outright with SQLSTATE[22023] — turning a
// harmless timezone hiccup into a full outage where $db became null
// and every container consumer failed with a confusing
// "Service not found in container: PDO" error instead of the real
// cause.
//
// getValidTimezone() below is now wrapped so it CANNOT return anything
// but a valid, non-empty timezone string — even in the exception path —
// and $timezone is assigned via a defensive fallback assignment as a
// second line of defense, so this section can never leave $timezone
// undefined.

function getValidTimezone(?string $countryCode = null): string
{
    $fallback = 'UTC';

    try {
        $envKeys = ['APP_TIMEZONE', 'TIMEZONE', 'TZ'];
        foreach ($envKeys as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value && is_string($value) && in_array($value, timezone_identifiers_list(), true)) {
                return $value;
            }
        }

        // Simple mapping based on country code — no CountryRegistry call
        // (avoids the memory-leak-prone path that used to live here).
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

        return $fallback;

    } catch (\Throwable $e) {
        // Whatever went wrong, NEVER let this function fail to return a
        // valid timezone string — that's what silently poisoned the DB
        // connection before.
        error_log("[Bootstrap] getValidTimezone() threw, falling back to {$fallback}: " . $e->getMessage());
        return $fallback;
    }
}

// Defensive assignment: even if the call above somehow returned an
// empty/invalid value, coalesce to UTC rather than let $timezone end
// up '' or undefined.
$timezone = getValidTimezone($countryCode);
if (empty($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
    error_log("[Bootstrap] getValidTimezone() returned an invalid value ('" . var_export($timezone, true) . "') - forcing UTC");
    $timezone = 'UTC';
}

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

    // $timezone is guaranteed non-empty and valid by this point (see
    // section 5 above), so this can no longer receive an empty string.
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
        // FIX: previously used isset(), which returns FALSE for a
        // registered value that is null (e.g. PDO::class when the DB
        // connection failed). That made a real "database not connected"
        // condition surface as the misleading "Service not found in
        // container: PDO" — sending anyone debugging it toward the
        // wrong subsystem. array_key_exists() correctly distinguishes
        // "never registered" from "registered as null".
        if (array_key_exists($id, $this->instances)) {
            if ($this->instances[$id] === null) {
                throw new \Exception(
                    "Service '{$id}' is registered but null — most likely the database " .
                    "connection failed at bootstrap (check earlier '[Bootstrap] Database " .
                    "connection failed' log lines for the real cause) rather than this " .
                    "service being missing."
                );
            }
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
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]);
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

$container->setFactory('Domain\Services\Settlement\HybridSettlementStrategy', function ($c) {
    return new \Domain\Services\Settlement\HybridSettlementStrategy(
        $c->get(PDO::class)
    );
});

$container->setFactory('Domain\Services\ContributionCalculator', function ($c) {
    return new \Domain\Services\ContributionCalculator();
});

$container->setFactory('Domain\Services\MultiSourceFeeCalculator', function ($c) {
    return new \Domain\Services\MultiSourceFeeCalculator(
        $c->get('countryConfig'),
        $c->get('countryCode')
    );
});

// ============================================================
// CIRCULAR DEPENDENCY FIX
// ============================================================
// FeeService and ForexService each optionally/required reference the
// other. Container::get() only caches an instance AFTER its factory
// closure returns, so having each factory call $c->get() on the other
// creates infinite recursion the first time either is resolved:
//   get(FeeService) -> factory -> get(ForexService) -> factory ->
//   get(FeeService) [not cached yet, still mid-construction] -> factory
//   -> get(ForexService) -> ... forever, until PHP OOMs.
// This is exactly what caused "Allowed memory size ... exhausted" here.
//
// Fix: make construction ONE-DIRECTIONAL (ForexService depends on
// FeeService at construction time; FeeService does NOT depend on
// ForexService at construction time), then wire the back-reference
// afterward via FeeService::setForexService() — no more requesting
// each other mid-construction, so no more recursion.
// ============================================================

// FeeService's real constructor is
// (array $feeRegistry, array $countryConfig, string $defaultCurrency = null, ?ForexService $forexService = null)
// Built WITHOUT ForexService here on purpose — see note above. The
// back-reference is set by the ForexService factory below, once both
// exist.
$container->setFactory('Domain\Services\FeeService', function ($c) {
    $feesConfig = $c->get('fees');
    $countryConfig = $c->get('countryConfig');
    $currency = $c->get('settings')['currency'] ?? 'BWP';

    return new \Domain\Services\FeeService($feesConfig, $countryConfig, $currency, null);
});

$container->setFactory('Domain\Services\ForexService', function ($c) {
    // Safe to resolve FeeService here: FeeService's factory (above)
    // never calls back into ForexService, so this cannot recurse.
    $feeService = $c->get('Domain\Services\FeeService');

    $forexService = new \Domain\Services\ForexService(
        $c->get(PDO::class),
        $c->get('countryConfig'),
        $c->get('participants'),
        $feeService
    );

    // Wire the back-reference now that both instances fully exist.
    try {
        $feeService->setForexService($forexService);
    } catch (\Throwable $e) {
        error_log("[Bootstrap] Could not wire ForexService back into FeeService: " . $e->getMessage());
    }

    return $forexService;
});

// CardService now receives FeeService/ForexService so activation and
// load fees are sourced from fees.json instead of falling back to
// hardcoded defaults. Safe to resolve both here: neither of their
// factories calls back into the container mid-construction (see the
// circular-dependency fix above), so this cannot recurse.
$container->setFactory('Domain\Services\CardService', function ($c) {
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

$container->setFactory('Domain\Services\SwapService', function ($c) {
    $fullConfig = [
        'participants' => $c->get('participants'),
        'fees' => $c->get('fees'),
        'currency' => $c->get('settings')['currency'] ?? 'BWP',
        'atm_notes' => $c->get('atmNotes'),
        'communication' => $c->get('communication'),
        'multi_source' => ['enabled' => true, 'extra_source_fee' => 1.00, 'max_total_fee' => 15.00],
    ];

    // NOTE: SwapService's constructor signature as originally shared is
    // (PDO $swapDB, array $config, string $country, $logger = null) —
    // it does NOT currently accept FeeService/ForexService as
    // constructor params; it builds its own internal FeeService/
    // ForexService instances from $config. If SwapService's
    // constructor is later changed to accept them (e.g. to fix the
    // internal CardService-with-no-FeeService issue noted separately),
    // resolve them here the same safe way CardService does above and
    // pass them through — do not call $c->get() for either inside
    // SwapService's own code paths that run during ITS construction,
    // to avoid reintroducing the same circular recursion.
    return new \Domain\Services\SwapService(
        $c->get(PDO::class),
        $fullConfig,
        $c->get('countryCode')
        // $logger intentionally omitted -> defaults to null ->
        // SwapService builds its own working default logger internally.
    );
});

$container->setFactory('Infrastructure\Crypto\CertificateManager', function ($c) {
    return \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
});

$container->setFactory('Infrastructure\Crypto\SignatureVerifier', function ($c) {
    return new \Infrastructure\Crypto\SignatureVerifier($c->get(PDO::class));
});

$container->setFactory('Infrastructure\Crypto\AggregateSigner', function ($c) {
    return new \Infrastructure\Crypto\AggregateSigner(
        $c->get('Infrastructure\Crypto\CertificateManager'),
        $c->get('Infrastructure\Crypto\SignatureVerifier')
    );
});

$container->setFactory('Domain\Services\MultiSource\PoolCoordinator', function ($c) {
    return new \Domain\Services\MultiSource\PoolCoordinator(
        $c->get(PDO::class),
        $c->get('Domain\Services\SwapService'),
        $c->get('Domain\Services\Settlement\HybridSettlementStrategy'),
        $c->get('Infrastructure\Crypto\AggregateSigner'),
        $c->get('countryConfig'),
        $c->get('countryCode')
    );
});

$container->setFactory('Domain\Services\MultiSourceSwapExecutor', function ($c) {
    return new \Domain\Services\MultiSource\MultiSourceSwapExecutor(
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
