<?php
declare(strict_types=1);

/**
 * VouchMorph - Swap Execution API 
 * ZERO HARDCODING - Routes to SwapService
 *
 * PATCHED:
 *  - Exact API key comparison via hash_equals() instead of scraping
 *    every env var 32+ chars long as a "valid" key.
 *  - Refuses to fake a success response if SwapService is missing
 *    (previously silently returned success:true with a fabricated
 *    reference and never touched the DB).
 *  - Refuses to silently fall back to the default country's config
 *    when X-Country-Code doesn't resolve (previously executed the
 *    swap under whatever the registry's default_country was).
 *  - NEW: derives a deterministic idempotency_key from the request's
 *    semantic content when the caller doesn't supply one, so a
 *    retried/duplicated POST within the same short window collapses
 *    to a single processed swap instead of placing a second hold.
 *  - NEW: routes execution through RouteResolver -> ExecutionPlan
 *    instead of calling SwapService::executeAtomicSwap() directly.
 *    Today this always resolves to DIRECT (no routing_policy.php
 *    exists yet for any country), so behavior is IDENTICAL to before -
 *    this only becomes active once a country gets a routing policy
 *    file with a real switch listed in it.
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Core\Database\DBConnection;
use Domain\Services\Routing\RoutingPolicyConfig;
use Domain\Services\Routing\RouteResolver;
use Domain\Services\Routing\ExecutionPlan;
use Domain\Services\Routing\DirectExecutionStrategy;
use Domain\Services\Routing\SwitchExecutionStrategy;

// ============================================
// 1. BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Country-Code, X-Country");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

error_log("[EXECUTE] Checking psr/log: " . (interface_exists('Psr\Log\LoggerInterface') ? 'FOUND' : 'MISSING'));

// ============================================
// 2. API KEY VALIDATION (exact match, not env-scraping)
// ============================================

/**
 * Validates the provided key against the single configured
 * VOUCHMORPH_API_KEY using a constant-time comparison.
 *
 * Previously this compared against *every* environment variable
 * whose name matched /KEY|API|TOKEN|SECRET/i OR whose value was
 * >= 32 characters — which meant DB passwords, session secrets,
 * JWT signing keys, etc. were all silently accepted as valid API
 * keys. That is not acceptable in a regulated environment.
 */
function isValidApiKey(?string $providedKey): bool {
    $validKey = getenv('VOUCHMORPH_API_KEY') ?: '';

    if ($validKey === '') {
        // No key configured server-side. Fail closed — do NOT treat
        // this as "no auth required". If you need a deliberate
        // no-auth mode for local dev, gate it behind an explicit,
        // separately-named flag (e.g. VOUCHMORPH_ALLOW_NO_AUTH=1)
        // that is never set in any regulated environment.
        error_log("[EXECUTE] CRITICAL: VOUCHMORPH_API_KEY is not configured in this environment");
        return false;
    }

    if ($providedKey === null || $providedKey === '') {
        return false;
    }

    return hash_equals($validKey, $providedKey);
}

function getApiKeyFromRequest(): ?string {
    $headers = getallheaders();
    if ($headers) {
        $headersLower = array_change_key_case($headers, CASE_LOWER);

        if (isset($headersLower['x-api-key']) && !empty($headersLower['x-api-key'])) {
            return $headersLower['x-api-key'];
        }

        if (isset($headersLower['authorization']) && !empty($headersLower['authorization'])) {
            $auth = $headersLower['authorization'];
            if (strpos($auth, 'Bearer ') === 0) {
                return substr($auth, 7);
            }
            return $auth;
        }
    }

    if (isset($_SERVER['HTTP_X_API_KEY']) && !empty($_SERVER['HTTP_X_API_KEY'])) {
        return $_SERVER['HTTP_X_API_KEY'];
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if (strpos($auth, 'Bearer ') === 0) {
            return substr($auth, 7);
        }
        return $auth;
    }

    return null;
}

// ============================================
// 2b. IDEMPOTENCY KEY DERIVATION
// ============================================

/**
 * Derives a stable idempotency key from the semantic content of a
 * swap request when the caller didn't supply one. Deliberately
 * excludes 'reference' and any raw timestamp field from the input —
 * those are commonly generated fresh per-attempt by the caller
 * (e.g. via time()), and including them here would defeat the whole
 * point: two retries of "the same" request would each get a
 * different derived key and never collapse.
 *
 * Keyed on the fields that describe WHAT is being requested (type,
 * source, amount, destination/identity), plus a coarse 60-second time
 * bucket — so a genuine retry within the same window collapses to one
 * processed swap, while a legitimately new request (e.g. sending money
 * to the same person again an hour later) still gets its own key.
 */
function generateDeterministicIdempotencyKey(array $input): string {
    $swapType = $input['swap_type'] ?? 'STANDARD';
    $keyParts = [
        $swapType,
        $input['from_institution'] ?? $input['source_institution'] ?? '',
        $input['source_identifier'] ?? '',
        $input['amount'] ?? '',
        $input['currency'] ?? '',
        $input['identity_type'] ?? '',
        $input['identity_value'] ?? '',
        $input['destination_identifier'] ?? '',
        $input['to_institution'] ?? $input['destination_institution'] ?? '',
        // 60-second bucket
        (string)floor(time() / 60),
    ];
    return 'AUTO_' . hash('sha256', implode('|', $keyParts));
}

// ============================================
// 2c. ROUTING DISPATCH
// ============================================

/**
 * Resolves an ExecutionPlan and runs the swap through the appropriate
 * strategy, falling back to DIRECT if a SWITCH strategy isn't
 * implemented yet or fails and the plan allows a fallback.
 *
 * If the Routing\* classes aren't present (e.g. not yet deployed to
 * this environment), falls straight through to the exact behavior
 * this endpoint had before routing existed — calling
 * SwapService::executeAtomicSwap() directly. This means dropping this
 * file into an environment that doesn't yet have the Routing/ classes
 * deployed does NOT break anything.
 */
function executeWithRouting(
    \Domain\Services\SwapService $swapService,
    array $input,
    string $countryName
): array {
    $routingClassesExist = class_exists(RouteResolver::class)
        && class_exists(RoutingPolicyConfig::class)
        && class_exists(ExecutionPlan::class)
        && class_exists(DirectExecutionStrategy::class);

    if (!$routingClassesExist) {
        error_log("[EXECUTE] Routing classes not found — calling SwapService::executeAtomicSwap() directly (pre-routing behavior)");
        return $swapService->executeAtomicSwap($input);
    }

    $countryDir = ROOT_PATH . "/src/Core/Config/Countries/{$countryName}";

    try {
        $policy = RoutingPolicyConfig::load($countryDir);
        $resolver = new RouteResolver($policy);

        $operation = strtoupper($input['swap_type'] ?? 'STANDARD');
        $sourceInstitution = $input['from_institution'] ?? $input['source_institution'] ?? '';
        $destinationInstitution = $input['to_institution']
            ?? $input['destination_institution']
            ?? $sourceInstitution; // IDENTITY-type payloads may not have a destination yet

        $plan = $resolver->resolve($sourceInstitution, $destinationInstitution, $operation);

        error_log("[EXECUTE] RouteResolver plan: " . json_encode($plan->toArray()));

        if ($plan->mode === ExecutionPlan::MODE_UNROUTABLE) {
            throw new Exception($plan->reason ?: 'No compatible payment rail exists.', 422);
        }

    } catch (Throwable $routingSetupError) {
        // Routing itself blew up (bad policy file, resolver bug, etc.) —
        // never let a routing-layer failure block a swap that the old
        // direct-call code path could still process. Fail open to DIRECT.
        error_log("[EXECUTE] Routing resolution failed, falling back to DIRECT: " . $routingSetupError->getMessage());
        $result = $swapService->executeAtomicSwap($input);
        $result['_routing'] = [
            'mode' => 'DIRECT',
            'reason' => 'Routing resolution error, forced DIRECT: ' . $routingSetupError->getMessage(),
        ];
        return $result;
    }

    $strategy = match ($plan->mode) {
        ExecutionPlan::MODE_DIRECT => new DirectExecutionStrategy($swapService),
        ExecutionPlan::MODE_SWITCH => new SwitchExecutionStrategy($plan->rail),
        default => new DirectExecutionStrategy($swapService), // defensive; UNROUTABLE already thrown above
    };

    try {
        $result = $strategy->execute($input, $plan);
    } catch (Throwable $executionError) {
        if ($plan->fallbackMode === ExecutionPlan::MODE_DIRECT && $plan->mode !== ExecutionPlan::MODE_DIRECT) {
            error_log("[EXECUTE] {$plan->mode} execution failed ({$executionError->getMessage()}), falling back to DIRECT per plan.fallbackMode");
            $result = (new DirectExecutionStrategy($swapService))->execute($input, $plan);
            $result['_routing_fallback'] = [
                'original_mode' => $plan->mode,
                'original_rail' => $plan->rail,
                'fallback_reason' => $executionError->getMessage(),
            ];
        } else {
            throw $executionError;
        }
    }

    $result['_routing'] = $plan->toArray();
    return $result;
}

// ============================================
// 3. MAIN EXECUTION
// ============================================

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed. Use POST.'
        ]);
        exit();
    }

    $providedKey = getApiKeyFromRequest();

    if (!isValidApiKey($providedKey)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid API key'
        ]);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid JSON payload', 400);
    }

    // ============================================================
    // NEW: enforce idempotency even when the caller doesn't supply
    // idempotency_key. SwapService::executeAtomicSwap() already
    // checks/stores idempotency results when the key is present in
    // the payload — this just guarantees a key always exists.
    // ============================================================
    if (empty($input['idempotency_key']) && empty($input['idempotencyKey'])) {
        $input['idempotency_key'] = generateDeterministicIdempotencyKey($input);
        error_log("[EXECUTE] No idempotency_key supplied by caller - derived: {$input['idempotency_key']}");
    }

    $headers = getallheaders();
    $headersLower = array_change_key_case($headers ?: [], CASE_LOWER);
    $countryCode = $headersLower['x-country-code'] ?? $headersLower['x-country'] ?? $input['country'] ?? null;

    $registryFile = ROOT_PATH . '/src/Core/Config/countries_registry.json';
    if (!file_exists($registryFile)) {
        throw new Exception('Country registry not found', 500);
    }

    $registry = json_decode(file_get_contents($registryFile), true);
    $countryConfig = null;

    if ($countryCode) {
        foreach ($registry['countries'] as $name => $config) {
            if (strtolower($name) === strtolower($countryCode) ||
                strtolower($config['code']) === strtolower($countryCode)) {
                $countryConfig = $config;
                break;
            }
        }
    }

    if (!$countryConfig) {
        // PATCHED: previously fell back to the registry's default
        // country and executed the swap under that country's
        // participants/fees/currency without telling anyone. That
        // means a missing or mistyped X-Country-Code header could
        // execute a swap under the wrong country's rules entirely.
        // This must be a hard error, not a silent substitution.
        error_log("[EXECUTE] CRITICAL: Could not resolve country for code '" . ($countryCode ?? 'null') . "' — refusing to fall back to a default");
        throw new Exception(
            $countryCode
                ? "Unknown country code: {$countryCode}"
                : 'Missing X-Country-Code header — country could not be resolved',
            400
        );
    }

    // ============================================================
    // DATABASE CONNECTION - Using DBConnection class
    // ============================================================
    require_once ROOT_PATH . '/src/Core/Database/DBConnection.php';

    try {
        $db = DBConnection::getConnection();

        if (!$db) {
            throw new Exception("Database connection failed - DATABASE_URL not set or invalid");
        }

        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        error_log("[EXECUTE] Database connected successfully via DBConnection");

    } catch (Throwable $e) {
        error_log("[EXECUTE] DB ERROR: " . $e->getMessage());
        throw new Exception("Database connection failed: " . $e->getMessage());
    }

    $composerPath = ROOT_PATH . '/vendor/autoload.php';
    if (file_exists($composerPath)) {
        require_once $composerPath;
    }

    // PATCHED: previously, if SwapService wasn't loaded, this branch
    // returned success:true with a freshly generated fake reference
    // and never touched the database. That means a broken deploy or
    // autoload misconfiguration would silently report every swap as
    // completed while moving zero funds. This is now a hard failure.
    if (!class_exists('Domain\Services\SwapService')) {
        error_log("[EXECUTE] CRITICAL: Domain\\Services\\SwapService class not found — refusing to fabricate a success response");
        throw new Exception('Swap execution service unavailable. No funds were moved.', 503);
    }

    // ============================================================
    // LOAD FULL COUNTRY CONFIG USING LoadCountry
    // ============================================================
    $fullCountryConfig = \Core\Config\LoadCountry::getConfig();

    $countryName = $countryConfig['name'] ?? null;
    if (!$countryName) {
        // Do not silently default to "Botswana" here either — this
        // must match whatever country was actually resolved above.
        error_log("[EXECUTE] CRITICAL: Resolved country config has no 'name' field: " . json_encode($countryConfig));
        throw new Exception('Country configuration is missing a name field', 500);
    }

    error_log("[EXECUTE] Using country name: {$countryName}");
    error_log("[EXECUTE] Country config keys: " . implode(', ', array_keys($fullCountryConfig)));

    $swapService = new \Domain\Services\SwapService(
        $db,                    // PDO
        $fullCountryConfig,     // Full country config (NOT $settings)
        $countryName            // string country (name, not code)
    );

    $result = executeWithRouting($swapService, $input, $countryName);

    echo json_encode([
        'success' => true,
        'status' => $result['status'] ?? 'completed',
        'swap_reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
        'data' => $result
    ]);

} catch (Exception $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    http_response_code($code);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    error_log("[Execute] Error: " . $e->getMessage());
}
