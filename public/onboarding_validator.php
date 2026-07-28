<?php

/**
 * onboarding_validator.php
 * ============================================================
 * Run this against ANY institution code before flipping its status
 * from sandbox -> staging -> live. Fails loudly and specifically -
 * never silently reports "clean" for something that was actually
 * untested, which is the exact failure mode that cost a lot of this
 * project's earlier debugging session.
 *
 * USAGE
 *   php onboarding_validator.php Botswana FNB
 *   php onboarding_validator.php Botswana KWIK
 *
 * NOTE ON CONFIG LOADING — read before relying on this script:
 * This assumes participants.yaml is loaded via the SAME
 * Core\Config\LoadCountry::getConfig($country) call already used by
 * SwapService and the admin dashboard test scripts, so this
 * validator can never drift from what production actually reads.
 * endpoints.yaml's loader was NOT confirmed by name this session -
 * this script tries a couple of likely class names
 * (Core\Config\LoadEndpoints, Core\Config\EndpointConfig) and falls
 * back to a minimal built-in YAML reader ONLY for this validator if
 * neither exists. That fallback is a stopgap for checking config
 * shape, not something to depend on elsewhere - confirm which loader
 * GenericBankClient actually uses and swap this script to call it
 * directly once known.
 * ============================================================
 */

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));
require_once PROJECT_ROOT . '/../vendor/autoload.php';
require_once PROJECT_ROOT . '/../src/Core/Config/LoadCountry.php';

use Core\Config\LoadCountry;

$country = $argv[1] ?? null;
$institutionCode = $argv[2] ?? null;

if (!$country || !$institutionCode) {
    fwrite(STDERR, "Usage: php onboarding_validator.php <Country> <INSTITUTION_CODE>\n");
    exit(1);
}

$errors = [];
$warnings = [];
$passed = [];

function check(bool $condition, string $label, array &$errors, array &$passed): void
{
    if ($condition) {
        $passed[] = $label;
    } else {
        $errors[] = $label;
    }
}

// ============================================================
// 1. LOAD participants.yaml VIA THE REAL LOADER
// ============================================================
$countryConfig = LoadCountry::getConfig($country);
$participants = $countryConfig['participants'] ?? $countryConfig ?? [];

if (!isset($participants[$institutionCode])) {
    fwrite(STDERR, "FATAL: '{$institutionCode}' not found in participants.yaml for {$country}.\n");
    fwrite(STDERR, "Add the participants.yaml entry (see ONBOARDING_PLAYBOOK.md section 3) before running this.\n");
    exit(1);
}
$participant = $participants[$institutionCode];

// ============================================================
// 2. REQUIRED participants.yaml FIELDS
// ============================================================
check(isset($participant['name']), "participants.yaml: 'name' present", $errors, $passed);
check(in_array($participant['type'] ?? null, ['BANK', 'MNO', 'SWITCH'], true), "participants.yaml: 'type' is BANK/MNO/SWITCH", $errors, $passed);
check(in_array($participant['status'] ?? null, ['sandbox', 'staging', 'live'], true), "participants.yaml: 'status' is sandbox/staging/live", $errors, $passed);
check(!empty($participant['asset_types'] ?? []), "participants.yaml: 'asset_types' non-empty", $errors, $passed);
check(!empty($participant['adapter'] ?? ''), "participants.yaml: 'adapter' specified", $errors, $passed);
check(!empty($participant['credentials_env_prefix'] ?? ''), "participants.yaml: 'credentials_env_prefix' specified", $errors, $passed);

if (($participant['type'] ?? null) === 'SWITCH') {
    check(($participant['adapter'] ?? '') !== 'generic_bank', "SWITCH type does not use the plain generic_bank adapter (needs mojaloop_switch or a dedicated class)", $errors, $passed);
}

// ============================================================
// 3. CREDENTIALS — env vars under the declared prefix must exist
// ============================================================
$prefix = $participant['credentials_env_prefix'] ?? null;
if ($prefix) {
    $expectedSuffixes = ['API_KEY', 'SUBSCRIPTION_KEY', 'API_USER'];
    $foundAny = false;
    foreach ($expectedSuffixes as $suffix) {
        if (getenv("{$prefix}_{$suffix}") !== false) {
            $foundAny = true;
        }
    }
    check($foundAny, "At least one {$prefix}_* credential env var is set", $errors, $passed);
    if (!$foundAny) {
        $warnings[] = "Checked for: " . implode(', ', array_map(fn($s) => "{$prefix}_{$s}", $expectedSuffixes)) . " - none found. Set the real ones for this institution's actual auth scheme if different.";
    }
} else {
    $errors[] = "Cannot check credentials - credentials_env_prefix missing (see error above)";
}

// ============================================================
// 4. endpoints.yaml — try known loaders, fall back to a minimal
//    reader ONLY for this validator (see note at top of file)
// ============================================================
$endpointsConfig = null;
$endpointsLoaderUsed = null;

foreach (['Core\\Config\\LoadEndpoints', 'Core\\Config\\EndpointConfig'] as $candidateClass) {
    if (class_exists($candidateClass) && method_exists($candidateClass, 'getConfig')) {
        $endpointsConfig = $candidateClass::getConfig($country);
        $endpointsLoaderUsed = $candidateClass;
        break;
    }
}

if ($endpointsConfig === null) {
    $warnings[] = "No known endpoints.yaml loader class found (tried LoadEndpoints, EndpointConfig) - " .
                  "using a minimal built-in reader for THIS VALIDATION ONLY. Confirm the real loader " .
                  "GenericBankClient actually uses and update this script to call it directly.";
    $endpointsPath = PROJECT_ROOT . "/src/Core/Config/Countries/{$country}/endpoints.yaml";
    if (file_exists($endpointsPath) && function_exists('yaml_parse_file')) {
        $endpointsConfig = yaml_parse_file($endpointsPath);
    } elseif (file_exists($endpointsPath) && class_exists('Symfony\\Component\\Yaml\\Yaml')) {
        $endpointsConfig = \Symfony\Component\Yaml\Yaml::parseFile($endpointsPath);
    } else {
        $warnings[] = "Could not parse endpoints.yaml (no ext-yaml, no symfony/yaml in composer.json, " .
                      "and no confirmed custom loader). Endpoint-shape checks below are SKIPPED, not passed.";
    }
}

if ($endpointsConfig !== null && isset($endpointsConfig[$institutionCode])) {
    $endpointBlock = $endpointsConfig[$institutionCode];
    check(!empty($endpointBlock['base_url'] ?? ''), "endpoints.yaml: 'base_url' present", $errors, $passed);
    check(in_array($endpointBlock['auth_type'] ?? null, ['api_key_header', 'basic', 'oauth2_client_credentials'], true), "endpoints.yaml: 'auth_type' is a recognized scheme", $errors, $passed);
    check(!empty($endpointBlock['endpoints'] ?? []), "endpoints.yaml: 'endpoints' block non-empty", $errors, $passed);
    check(!empty($endpointBlock['field_mapping'] ?? []), "endpoints.yaml: 'field_mapping' present", $errors, $passed);

    $requiredActions = ['verify_account', 'place_hold', 'debit', 'credit', 'release_hold', 'get_balance'];
    foreach ($requiredActions as $action) {
        check(isset($endpointBlock['endpoints'][$action]), "endpoints.yaml: '{$action}' endpoint defined", $errors, $passed);
    }
} elseif ($endpointsConfig !== null) {
    $errors[] = "'{$institutionCode}' not found in endpoints.yaml for {$country}";
}

// ============================================================
// 5. STATUS GATE — never let this pass as ready for LIVE with errors
// ============================================================
$currentStatus = $participant['status'] ?? 'sandbox';

echo "============================================================\n";
echo "ONBOARDING VALIDATION: {$institutionCode} ({$country})\n";
echo "Current status: {$currentStatus}\n";
echo "============================================================\n\n";

echo "PASSED (" . count($passed) . "):\n";
foreach ($passed as $p) {
    echo "  ✅ {$p}\n";
}

if (!empty($warnings)) {
    echo "\nWARNINGS (" . count($warnings) . "):\n";
    foreach ($warnings as $w) {
        echo "  ⚠️  {$w}\n";
    }
}

if (!empty($errors)) {
    echo "\nFAILED (" . count($errors) . "):\n";
    foreach ($errors as $e) {
        echo "  ❌ {$e}\n";
    }
    echo "\n🚫 NOT READY. Fix the above before promoting status beyond 'sandbox'.\n";
    echo "   Next: build/run the standalone test harness for {$institutionCode}\n";
    echo "   (see ONBOARDING_PLAYBOOK.md section 7) before touching SwapService.\n";
    exit(1);
}

echo "\n✅ Config is complete for {$institutionCode}.\n";
echo "   This checks CONFIGURATION SHAPE ONLY - it does not confirm the\n";
echo "   institution's real API actually responds correctly. Run the\n";
echo "   per-institution test harness (ONBOARDING_PLAYBOOK.md section 7)\n";
echo "   against their real sandbox before promoting status to 'live'.\n";
exit(0);
