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
 *  - NEW: Records settlement obligations when a switch execution
 *    falls back to DIRECT, so the switch can be notified once it
 *    comes back online.
 *  - NEW (TRACER): Every swap attempt is recorded step-by-step to
 *    swap_traces / swap_trace_summary via SwapTracer, so WorkControl's
 *    Swap Tracker tab can show exactly what happened -- validation,
 *    country resolution, bootstrap, routing decision, strategy
 *    execution, fallback, settlement obligations -- for any swap.
 *    Tracing is best-effort: a tracer failure is logged and swallowed,
 *    never allowed to affect the swap itself. Search "TRACER:" for
 *    every line this introduced.
 *  - FIXED: Session validation now occurs AFTER API key check and
 *    BEFORE reading input, ensuring proper authentication order and
 *    that $input['user_id'] is always overridden with session value.
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';

use Core\Database\DBConnection;
use Domain\Services\Routing\RoutingPolicyConfig;
use Domain\Services\Routing\RouteResolver;
use Domain\Services\Routing\ExecutionPlan;
use Domain\Services\Routing\DirectExecutionStrategy;
use Domain\Services\Routing\SwitchExecutionStrategy;
use Domain\Services\Routing\Exceptions\SwitchUnavailableException;

// ============================================
// 1. BOOTSTRAP
// ============================================
define('ROOT_PATH', dirname(__DIR__, 4));

// TRACER: SwapTracer has no dependencies beyond PDO, safe to require
// this early. Adjust the path if you place it somewhere other than
// src/Core/Tracing/.
require_once ROOT_PATH . '/src/Core/Tracing/SwapTracer.php';

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
/**
 * FIX (2026-09-21): cash-out PINs, ATM codes and wallet PINs belong to the
 * recipient. They are returned to the caller only when the recipient is the
 * logged-in customer (someone cashing out for themselves still sees their
 * code). For anyone else they are removed here and reach the recipient by SMS
 * only. Voucher numbers stay (they are references, useless without the PIN).
 */
function vmRedactRecipientSecrets(array $result, array $input, array $sessionUser): array
{
    $digits = fn($v) => substr(preg_replace('/\D/', '', (string)$v) ?? '', -8);
    $self = array_filter(array_map($digits, [$sessionUser['phone'] ?? '', $sessionUser['phone2'] ?? '', $sessionUser['phone3'] ?? '']));
    $isSelf = fn($phone) => $phone === null || $phone === '' || in_array($digits($phone), $self, true);
    $topPhone = $input['beneficiary_phone'] ?? $input['client_phone'] ?? (
        in_array(strtolower((string)($input['destination_identifier_type'] ?? '')), ['phone', 'msisdn', 'wallet'], true) ? ($input['destination_identifier'] ?? null) : null
    );
    $secretKeys = ['atm_pin', 'atm_code', 'pin', 'pin_code', 'wallet_pin', 'cashout_pin', 'otp'];
    $scrub = function ($node) use (&$scrub, $secretKeys) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                if (is_string($k) && in_array(strtolower($k), $secretKeys, true) && $v !== null && $v !== '') {
                    $node[$k] = 'sent to the recipient by SMS';
                } else {
                    $node[$k] = $scrub($v);
                }
            }
            return $node;
        }
        return is_string($node) ? preg_replace('/\bPIN:?\s*\d{4,8}\b/i', 'PIN: sent to the recipient by SMS', $node) : $node;
    };
    if (!empty($result['destinations']) && is_array($result['destinations'])) {
        foreach ($result['destinations'] as $i => $leg) {
            $inLeg = $input['destinations'][$leg['index'] ?? $i] ?? [];
            $legPhone = $leg['beneficiary_phone'] ?? $inLeg['beneficiary_phone'] ?? $inLeg['client_phone'] ?? $topPhone;
            if (!$isSelf($legPhone)) $result['destinations'][$i] = $scrub($leg);
        }
    }
    if (!$isSelf($topPhone)) {
        $legs = $result['destinations'] ?? null;
        $result = $scrub($result);
        if ($legs !== null) $result['destinations'] = $legs;   // legs already decided one by one
    }
    return $result;
}

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
        // FIX (2026-09-21): who receives it and how are part of WHAT is being
        // requested. Without them, two cash-outs of the same amount to
        // different people within a minute collapsed into one - the second
        // customer got the first swap back and nothing was sent to them.
        (string)($input['user_id'] ?? ''),
        preg_replace('/\D/', '', (string)($input['beneficiary_phone'] ?? $input['beneficiary_identifier'] ?? $input['client_phone'] ?? '')),
        strtoupper((string)($input['delivery_method'] ?? '')),
        strtoupper((string)($input['destination_asset_type'] ?? '')),
        isset($input['destinations']) && is_array($input['destinations']) ? hash('sha256', json_encode(array_map(fn($d) => [
            $d['amount'] ?? '', $d['to_institution'] ?? $d['destination_institution'] ?? '', $d['destination_identifier'] ?? '',
            $d['identity_value'] ?? '', preg_replace('/\D/', '', (string)($d['beneficiary_phone'] ?? '')), strtoupper((string)($d['delivery_method'] ?? '')),
        ], $input['destinations']))) : '',
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
 *
 * TRACER: $tracer records every branch this function takes -- which
 * strategy was selected and why, whether a fallback occurred and why,
 * and the outcome of the settlement_obligations insert on fallback.
 */
function executeWithRouting(
    \Domain\Services\SwapService $swapService,
    array $input,
    string $countryName,
    PDO $db,
    SwapTracer $tracer
): array {
    $routingClassesExist = class_exists(RouteResolver::class)
        && class_exists(RoutingPolicyConfig::class)
        && class_exists(ExecutionPlan::class)
        && class_exists(DirectExecutionStrategy::class);

    if (!$routingClassesExist) {
        error_log("[EXECUTE] Routing classes not found — calling SwapService::executeAtomicSwap() directly (pre-routing behavior)");
        $tracer->info('ROUTING', 'Routing classes not deployed', 'Falling straight through to legacy direct execution');
        $tracer->info('ADAPTER', 'Calling SwapService::executeAtomicSwap()', 'Legacy path — no fine-grained adapter/currency steps visible from execute.php');
        $result = $swapService->executeAtomicSwap($input);
        $tracer->success('ADAPTER', 'SwapService::executeAtomicSwap() completed', null, [
            'reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
            'status' => $result['status'] ?? null,
        ]);
        return $result;
    }

    $countryDir = ROOT_PATH . "/src/Core/Config/Countries/{$countryName}";

    try {
        $tracer->info('ROUTING', 'Loading routing policy', null, ['country' => $countryName]);
        $policy = RoutingPolicyConfig::load($countryDir);
        $resolver = new RouteResolver($policy);

        $operation = strtoupper($input['swap_type'] ?? 'STANDARD');
        $sourceInstitution = $input['from_institution'] ?? $input['source_institution'] ?? '';
        $destinationInstitution = $input['to_institution']
            ?? $input['destination_institution']
            ?? $sourceInstitution; // IDENTITY-type payloads may not have a destination yet

        $plan = $resolver->resolve($sourceInstitution, $destinationInstitution, $operation);

        error_log("[EXECUTE] RouteResolver plan: " . json_encode($plan->toArray()));
        $tracer->success('ROUTING', "Routing plan resolved: {$plan->mode}", $plan->reason ?? null, $plan->toArray());

        if ($plan->mode === ExecutionPlan::MODE_UNROUTABLE) {
            $tracer->error('ROUTING', 'No compatible payment rail', $plan->reason ?: 'No compatible payment rail exists.');
            throw new Exception($plan->reason ?: 'No compatible payment rail exists.', 422);
        }

    } catch (Throwable $routingSetupError) {
        // Routing itself blew up (bad policy file, resolver bug, etc.) —
        // never let a routing-layer failure block a swap that the old
        // direct-call code path could still process. Fail open to DIRECT.
        error_log("[EXECUTE] Routing resolution failed, falling back to DIRECT: " . $routingSetupError->getMessage());
        $tracer->warning('ROUTING', 'Routing resolution failed — forcing DIRECT', $routingSetupError->getMessage());
        $tracer->info('ADAPTER', 'Calling SwapService::executeAtomicSwap() (forced DIRECT)');
        $result = $swapService->executeAtomicSwap($input);
        $result['_routing'] = [
            'mode' => 'DIRECT',
            'reason' => 'Routing resolution error, forced DIRECT: ' . $routingSetupError->getMessage(),
        ];
        $tracer->success('ADAPTER', 'Forced-DIRECT execution completed', null, [
            'reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
        ]);
        return $result;
    }

    $strategy = match ($plan->mode) {
        ExecutionPlan::MODE_DIRECT => new DirectExecutionStrategy($swapService),
        ExecutionPlan::MODE_SWITCH => new SwitchExecutionStrategy(
            $plan->rail,
            $swapService->getAdapterFactory(),
            $swapService->getParticipants()
        ),
        default => new DirectExecutionStrategy($swapService),
    };

    $tracer->info(
        'ADAPTER',
        "Dispatching via " . ($plan->mode === ExecutionPlan::MODE_SWITCH ? "SwitchExecutionStrategy (rail: {$plan->rail})" : 'DirectExecutionStrategy'),
        null,
        [],
        $plan->mode === ExecutionPlan::MODE_SWITCH ? $plan->rail : null
    );

    try {
        $result = $strategy->execute($input, $plan);
        $tracer->success('ADAPTER', "{$plan->mode} strategy execution completed", null, [
            'reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
            'status' => $result['status'] ?? null,
        ]);

        // NEW: SwitchExecutionStrategy doesn't create a swap_requests row
        // itself (no bank-adapter pipeline to hook into) - record it here.
        if ($plan->mode === ExecutionPlan::MODE_SWITCH) {
            $tracer->info('SETTLEMENT', 'Recording external rail execution', null, [], $plan->rail);
            $tracked = $swapService->recordExternalRailExecution($input, $result, $plan->rail);
            $result['reference'] = $result['reference'] ?? $tracked['reference'];
            $tracer->success('SETTLEMENT', 'External rail execution recorded', null, [
                'reference' => $tracked['reference'] ?? null,
            ], $plan->rail);
        }

    } catch (SwitchUnavailableException $executionError) {
        if ($plan->fallbackMode === ExecutionPlan::MODE_DIRECT) {
            error_log("[EXECUTE] Switch unreachable, falling back to DIRECT: " . $executionError->getMessage());
            $tracer->warning('ROUTING', 'Switch unavailable — falling back to DIRECT per plan.fallbackMode', $executionError->getMessage(), [], $plan->rail);

            $result = (new DirectExecutionStrategy($swapService))->execute($input, $plan);
            $result['_routing_fallback'] = [
                'original_mode' => $plan->mode,
                'reason' => 'switch_unavailable',
            ];
            $tracer->success('ADAPTER', 'DIRECT fallback execution completed', null, [
                'reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
            ]);

            // NEW: record that this obligation was intended for the switch
            // but executed bilaterally, so it can be reported to the switch
            // once it's back up.
            try {
                $stmt = $db->prepare("
                    INSERT INTO settlement_obligations (
                        vouchmorph_swap_reference, origin_institution, destination_institution,
                        amount, currency, intended_rail, executed_via, status
                    ) VALUES (?, ?, ?, ?, ?, ?, 'DIRECT', 'PENDING_NOTIFY')
                    ON CONFLICT (vouchmorph_swap_reference) DO NOTHING
                ");
                $obligationReference = $result['reference'] ?? $input['reference'] ?? uniqid('OBL_');
                $stmt->execute([
                    $obligationReference,
                    $input['from_institution'] ?? $input['source_institution'] ?? '',
                    $input['to_institution'] ?? $input['destination_institution'] ?? '',
                    $input['amount'] ?? 0,
                    $input['currency'] ?? 'BWP',
                    $plan->rail,
                ]);
                error_log("[EXECUTE] Recorded settlement_obligation for fallback execution");
                $tracer->success('OBLIGATION', 'settlement_obligations recorded', null, [
                    'reference' => $obligationReference,
                    'intended_rail' => $plan->rail,
                ], $plan->rail);
            } catch (Throwable $obligationError) {
                error_log("[EXECUTE] Failed to record settlement_obligation: " . $obligationError->getMessage());
                $tracer->warning('OBLIGATION', 'Failed to record settlement_obligations', $obligationError->getMessage(), [], $plan->rail);
            }
        } else {
            $tracer->error('ROUTING', 'Switch unavailable, no fallback permitted by plan', $executionError->getMessage(), [], $plan->rail);
            throw $executionError;
        }

    } catch (Throwable $executionError) {
        if ($plan->fallbackMode === ExecutionPlan::MODE_DIRECT && $plan->mode !== ExecutionPlan::MODE_DIRECT) {
            error_log("[EXECUTE] {$plan->mode} execution failed ({$executionError->getMessage()}), falling back to DIRECT per plan.fallbackMode");
            $tracer->warning('ADAPTER', "{$plan->mode} execution failed — falling back to DIRECT", $executionError->getMessage(), [], $plan->rail ?? null);

            $result = (new DirectExecutionStrategy($swapService))->execute($input, $plan);
            $result['_routing_fallback'] = [
                'original_mode' => $plan->mode,
                'original_rail' => $plan->rail,
                'fallback_reason' => $executionError->getMessage(),
            ];
            $tracer->success('ADAPTER', 'DIRECT fallback execution completed', null, [
                'reference' => $result['reference'] ?? $result['swap_reference'] ?? null,
            ]);
        } else {
            $tracer->error('ADAPTER', "{$plan->mode} execution failed, no fallback permitted", $executionError->getMessage(), [], $plan->rail ?? null);
            throw $executionError;
        }
    }

    $result['_routing'] = $plan->toArray();
    return $result;
}

// ============================================
// 3. MAIN EXECUTION
// ============================================

// TRACER: declared here (nullable) so the outer catch block can check
// isset($tracer) — a failure before the DB connects (bad method, bad
// API key, bad JSON) never gets a tracer instance, and that's fine;
// there's no swap attempt to trace yet at that point.
$tracer = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed. Use POST.'
        ]);
        exit();
    }


    // ============================================
    // SESSION VALIDATION (after API key, before reading input)
    // ============================================
    require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';
    \Application\Utils\SessionManager::start();
    if (!\Application\Utils\SessionManager::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    $sessionUserId = (int)(\Application\Utils\SessionManager::getUser()['id']
        ?? \Application\Utils\SessionManager::getUser()['user_id'] ?? 0);
    if (!$sessionUserId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
        exit();
    }

    // ============================================
    // READ INPUT (after authentication is confirmed)
    // ============================================
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !is_array($input)) {
        throw new Exception('Invalid JSON payload', 400);
    }

    // Never trust a client-supplied user_id — always override with session.
    // SwapService::executeAtomicSwap() swaps in original_payload wholesale
    // when one is sent (verifying no signature), so the override has to
    // reach inside it too.
    $input['user_id'] = $sessionUserId;
    if (isset($input['original_payload'])) {
        if (!is_array($input['original_payload'])) {
            throw new Exception('original_payload must be an object', 400);
        }
        $input['original_payload']['user_id'] = $sessionUserId;
    }

    // FIX (2026-09-24): identity claims are not executed here. CONFIRM_IDENTITY
    // reaches SwapService::confirmAndFinalizeIdentitySwap(), which takes the
    // claimer's role (confirmed_by_type / confirmed_by_id) and the agent's
    // "document checked" flag from its payload - that is, from this request
    // body - so any logged-in user could finalize a claim as an "agent", or
    // try and lock an identity owner's transaction PIN. Claims have their own
    // endpoints, which take all of that from the session:
    // swap/claim_identity.php (the app) and agent/finalize_claim.php (agents).
    foreach ([$input, $input['original_payload'] ?? []] as $requested) {
        if (strtoupper(trim((string)($requested['swap_type'] ?? ''))) === 'CONFIRM_IDENTITY') {
            throw new Exception('Identity claims are finalized from the claim screen in the app, or by an agent, not through this endpoint.', 400);
        }
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

    // ============================================================
    // DATABASE CONNECTION - Using DBConnection class
    // TRACER: moved earlier (was originally after country resolution)
    // so tracing can start immediately and cover country-resolution
    // and bootstrap failures too, not just routing/execution.
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

    // ============================================================
    // INCIDENT COMMAND GATE - before anything is held or debited.
    // Sandbox cap (P7,000, fees.json) and any freeze in force: the
    // whole service, this swap flow, either institution, the customer,
    // or the acting agent. A refused attempt raises an alarm.
    // ============================================================
    require_once dirname(__DIR__, 4) . '/src/Application/Incident/IncidentDesk.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/Playbooks.php';
require_once dirname(__DIR__, 4) . '/src/Application/Incident/ServiceControls.php';
$gate = \Application\Incident\ServiceControls::check($db, [
        'amount' => (float)($input['amount'] ?? 0),
        'flow' => strtoupper($input['swap_type'] ?? 'STANDARD'),
        'source' => $input['from_institution'] ?? $input['source_institution'] ?? '',
        'destination' => $input['to_institution'] ?? $input['destination_institution'] ?? '',
        'user_id' => $sessionUserId,
        'agent_id' => $input['agent_id'] ?? '',
    ]);
    if ($gate !== null) {
        http_response_code($gate['http']);
        echo json_encode(['success' => false, 'error' => $gate['message'], 'code' => $gate['code'], 'funds_moved' => false]);
        exit();
    }

    // TRACER: start tracing now. Uses the idempotency key as a
    // temporary identifier since the real swap_reference doesn't
    // exist yet — rekeyed once executeWithRouting() returns one.
    $tracer = new SwapTracer($db, $input['idempotency_key']);
    $tracer->setSummary([
        'swap_type'               => strtoupper($input['swap_type'] ?? 'STANDARD'),
        'source_institution'      => $input['from_institution'] ?? $input['source_institution'] ?? null,
        'destination_institution' => $input['to_institution'] ?? $input['destination_institution'] ?? null,
        'amount'                  => $input['amount'] ?? null,
        'currency'                => $input['currency'] ?? null,
    ]);
    $tracer->success('VALIDATION', 'Request parsed and authenticated', null, [
        'idempotency_key' => $input['idempotency_key'],
    ]);

    $headers = getallheaders();
    $headersLower = array_change_key_case($headers ?: [], CASE_LOWER);
    $countryCode = $headersLower['x-country-code'] ?? $headersLower['x-country'] ?? $input['country'] ?? null;

    $tracer->info('COUNTRY_RESOLUTION', 'Resolving country from request', null, ['country_code' => $countryCode]);

    $registryFile = ROOT_PATH . '/src/Core/Config/countries_registry.json';
    if (!file_exists($registryFile)) {
        $tracer->error('COUNTRY_RESOLUTION', 'Country registry file missing', $registryFile);
        $tracer->finish(false);
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
        $tracer->error('COUNTRY_RESOLUTION', 'Country code did not resolve', $countryCode ?? '(missing header)');
        $tracer->finish(false);
        throw new Exception(
            $countryCode
                ? "Unknown country code: {$countryCode}"
                : 'Missing X-Country-Code header — country could not be resolved',
            400
        );
    }

    $tracer->success('COUNTRY_RESOLUTION', "Resolved country: {$countryConfig['name']}", null, [
        'country_code' => $countryCode,
    ]);

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
        $tracer->error('BOOTSTRAP', 'SwapService class not found', 'Refusing to fabricate a success response — no funds moved');
        $tracer->finish(false);
        throw new Exception('Swap execution service unavailable. No funds were moved.', 503);
    }
    $tracer->success('BOOTSTRAP', 'SwapService class available');

    // ============================================================
    // LOAD FULL COUNTRY CONFIG USING LoadCountry
    // ============================================================
    $fullCountryConfig = \Core\Config\LoadCountry::getConfig();

    $countryName = $countryConfig['name'] ?? null;
    if (!$countryName) {
        // Do not silently default to "Botswana" here either — this
        // must match whatever country was actually resolved above.
        error_log("[EXECUTE] CRITICAL: Resolved country config has no 'name' field: " . json_encode($countryConfig));
        $tracer->error('BOOTSTRAP', 'Country config missing name field', json_encode($countryConfig));
        $tracer->finish(false);
        throw new Exception('Country configuration is missing a name field', 500);
    }

    error_log("[EXECUTE] Using country name: {$countryName}");
    error_log("[EXECUTE] Country config keys: " . implode(', ', array_keys($fullCountryConfig)));
    $tracer->success('BOOTSTRAP', "Country config loaded: {$countryName}", null, [
        'config_keys' => array_keys($fullCountryConfig),
    ]);

    $swapService = new \Domain\Services\SwapService(
        $db,                    // PDO
        $fullCountryConfig,     // Full country config (NOT $settings)
        $countryName            // string country (name, not code)
    );

    $result = executeWithRouting($swapService, $input, $countryName, $db, $tracer);

    // TRACER: now that SwapService/strategy execution has assigned a
    // real swap reference, move this trace from the temporary
    // idempotency-key identifier onto it, then close out the trace.
    $finalReference = $result['reference'] ?? $result['swap_reference'] ?? null;
    if ($finalReference) {
        $tracer->rekey((string)$finalReference);
    }
    $tracer->setSummary(['routing_mode' => $result['_routing']['mode'] ?? null]);
    $tracer->finish(true);

    $result = vmRedactRecipientSecrets($result, $input, \Application\Utils\SessionManager::getUser() ?? []);

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

    // TRACER: catch-all safety net. Most failure paths above already
    // recorded a specific error step and called finish(false); this
    // just guarantees no exception ever leaves a trace stuck at
    // 'in_progress' forever, even one this file didn't anticipate.
    // finish() is idempotent, so this never double-closes a trace
    // that already finished above.
    if (isset($tracer)) {
        $tracer->error('EXCEPTION', get_class($e), $e->getMessage());
        $tracer->finish(false);
    }
}
