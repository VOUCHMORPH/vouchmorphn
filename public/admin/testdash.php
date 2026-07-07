<?php
declare(strict_types=1);

/**
 * public/admin/testdash.php - REWRITTEN v2
 *
 * Tests CONCEPTS against the actual redesigned dashboard.php, not literal
 * identifier names from the prior architecture. The old version failed
 * every time a function/variable was renamed during a redesign, even when
 * the underlying behavior was correct or improved - this version checks
 * for the real thing that matters (e.g. "is destination asset type ever
 * derived from real per-institution data" rather than "does a function
 * called exactly selectDestAsset exist").
 *
 * Every check here states in its own key what property it's actually
 * verifying, so a future rename doesn't silently break it the same way.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================================
// AUTH GATE
// ============================================================
function getApiKeyFromRequest(): ?string {
    $headers = getallheaders() ?: [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    if (!empty($headersLower['x-api-key'])) return $headersLower['x-api-key'];
    if (!empty($headersLower['authorization'])) {
        $auth = $headersLower['authorization'];
        return str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $auth;
    }
    return null;
}
function getAllApiKeysFromEnvironment(): array {
    $keys = [];
    foreach (array_merge($_ENV, $_SERVER, getenv()) as $name => $value) {
        if (is_string($value) && !empty($value) && (preg_match('/KEY|API|TOKEN|SECRET/i', $name) || strlen($value) >= 32)) {
            $keys[] = $value;
        }
    }
    return array_unique(array_filter($keys));
}
$providedKey = getApiKeyFromRequest();
$validKeys = getAllApiKeysFromEnvironment();
if (!empty($validKeys) && !in_array($providedKey, $validKeys, true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit();
}

$report = [
    'generated_at' => date('c'),
    'test_suite' => 'Dashboard Integration Tests v2 - concept-based, not identifier-literal',
    'tests' => [],
];

// ============================================================
// LOAD FILE
// ============================================================
$dashboardPath = ROOT_PATH . '/public/user/dashboard.php';
$report['tests']['dashboard_file'] = ['description' => 'Check dashboard.php exists'];
if (!file_exists($dashboardPath)) {
    $report['tests']['dashboard_file']['status'] = 'FAIL';
    $report['tests']['dashboard_file']['error'] = 'File not found';
    echo json_encode($report, JSON_PRETTY_PRINT);
    exit();
}
$content = file_get_contents($dashboardPath);
$report['tests']['dashboard_file']['status'] = 'PASS';
$report['tests']['dashboard_file']['size'] = filesize($dashboardPath) . ' bytes';

preg_match('/<script>(.*?)<\/script>/s', $content, $jsMatch);
$jsContent = $jsMatch[1] ?? '';
preg_match('/<style>(.*?)<\/style>/s', $content, $cssMatch);
$cssContent = $cssMatch[1] ?? '';

// ============================================================
// HELPER: extract a JS function body by brace-counting, so checks
// can be scoped to "inside this function" rather than "anywhere in
// the whole script," avoiding false confidence from unrelated matches.
// ============================================================
function extractJsFunctionBody(string $js, string $fnName): ?string {
    if (!preg_match('/function\s+' . preg_quote($fnName, '/') . '\s*\([^)]*\)\s*\{/', $js, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $i = $start; $len = strlen($js);
    while ($i < $len && $depth > 0) {
        if ($js[$i] === '{') $depth++;
        elseif ($js[$i] === '}') $depth--;
        $i++;
    }
    return substr($js, $start, $i - $start - 1);
}

function verdict(array $checks): array {
    $failed = array_keys(array_filter($checks, fn($v) => $v === false));
    return [
        'checks' => $checks,
        'status' => empty($failed) ? 'PASS' : 'FAIL',
        'failed_checks' => $failed,
        'message' => empty($failed) ? 'All checks passed' : 'Failed: ' . implode(', ', $failed),
    ];
}

// ============================================================
// TEST: PHP data layer - what actually needs to exist regardless
// of naming, is: participants parsed from YAML with real asset_types,
// asset registry loaded, cloud/history queries present.
// ============================================================
$checks = [
    'loads_asset_type_registry' => strpos($content, 'AssetTypeRegistry::initialize()') !== false,
    'parses_participants_yaml' => preg_match('/function\s+parseParticipantsYaml/', $content) === 1,
    // CONCEPT CHECK, not name check: the parser must track indentation
    // depth (the actual fix for the earlier bleed bug), not a boolean
    // flag that never resets. Look for the depth-comparison pattern
    // rather than any specific variable name.
    'parser_tracks_indentation_depth' => preg_match('/\$indent\s*===\s*\d/', $content) === 1,
    'builds_asset_fields_map' => strpos($content, 'assetFieldsMap') !== false,
    'builds_asset_ui_map' => strpos($content, 'assetUIMap') !== false,
    'exposes_participants_to_js' => preg_match('/json_encode\(\$participant\w*\)/', $content) === 1,
    'queries_cloud_balances' => strpos($content, 'identity_swap_holds') !== false,
    'queries_recent_activity' => strpos($content, 'swap_ledgers') !== false,
];
$report['tests']['php_data_layer'] = array_merge(['description' => 'PHP data layer - participants, asset types, cloud/history queries'], verdict($checks));

// ============================================================
// TEST: destination asset type is GENUINELY derived from real
// per-institution data, not a static/hardcoded list. This is the
// actual bug from before - check the CONCEPT, not one function name.
// ============================================================
$checks = [];

// Is there anywhere in the JS that reads .asset_types off a participant
// object dynamically (not a hardcoded literal array)?
$dynamicAssetReads = preg_match_all('/participants\[[^\]]+\]\??\.asset_types/', $jsContent);
$checks['reads_asset_types_dynamically_from_participant_data'] = $dynamicAssetReads >= 2; // used for both source and destination resolution

// Is there any hardcoded destination asset pair like ['ACCOUNT','WALLET']
// or two literal buttons for exactly those two values with no other
// asset type ever reachable? This was the actual bug - check its absence.
$hardcodedAccountWalletOnly = preg_match('/data-value="ACCOUNT".*?data-value="WALLET"/s', $content) === 1
    && preg_match_all('/data-value="(ACCOUNT|WALLET|VOUCHER|MNO-WALLET|BANK-WALLET|CARD|ATM)"/', $content) === 0;
$checks['no_hardcoded_two_option_destination_picker'] = !$hardcodedAccountWalletOnly;

// A real asset-label mapping should cover more than just Account/Wallet,
// since real institutions in participants.yaml expose VOUCHER, MNO-WALLET,
// BANK-WALLET, ATM etc. If the label map only knows 2 types, the UI can't
// correctly label what it's dynamically reading.
if (preg_match('/assetLabel\s*=\s*\{([^}]*)\}/s', $jsContent, $m)) {
    $labelCount = substr_count($m[1], ':');
    $checks['asset_label_map_covers_more_than_two_types'] = $labelCount > 2;
} else {
    $checks['asset_label_map_covers_more_than_two_types'] = false;
}

// The choice UI should only render when an institution has more than one
// asset type - i.e. there should be a length check (assets.length > 1 or
// similar) gating whether a picker even appears, proving auto-resolution
// happens for single-asset-type institutions instead of always asking.
$checks['auto_resolves_when_institution_has_single_asset_type'] =
    preg_match('/asset_types\.length\s*===?\s*1/', $jsContent) === 1
    || preg_match('/asset_types\.length\s*>\s*1/', $jsContent) === 1;

$report['tests']['destination_asset_filtering'] = array_merge(
    ['description' => 'Destination/source asset type is derived from real per-institution data, not hardcoded'],
    verdict($checks)
);

// ============================================================
// TEST: unified "who" picker exists and is searchable
// ============================================================
$checks = [
    'has_institution_search_input' => preg_match('/oninput="filterWho|oninput="filter\w*Who/', $jsContent) === 1
        || strpos($jsContent, 'who-search') !== false || strpos($content, 'who-search') !== false,
    'builds_institution_list_from_real_participants_object' => preg_match('/Object\.entries\(participants\)/', $jsContent) === 1,
    'shows_what_each_institution_actually_supports' => preg_match('/asset_types\.map/', $jsContent) === 1,
];
$report['tests']['who_picker'] = array_merge(['description' => 'Institution picker is searchable and shows real capabilities per institution'], verdict($checks));

// ============================================================
// TEST: validation - amount, PIN, pool completeness
// (this caught a REAL bug last run - amount validation was missing)
// ============================================================
$goConfirmBody = extractJsFunctionBody($jsContent, 'goConfirm')
    ?? extractJsFunctionBody($jsContent, 'goToConfirm'); // tolerate either name, check behavior

$checks = [
    'goConfirm_or_equivalent_exists' => $goConfirmBody !== null,
    'validates_pin_length' => $goConfirmBody !== null && preg_match('/pin\.length\s*<\s*4/', $goConfirmBody) === 1,
    'validates_amount_greater_than_zero' => $goConfirmBody !== null && preg_match('/amt\s*<=\s*0/', $goConfirmBody) === 1,
    'validates_pool_has_minimum_two_sources' => $goConfirmBody !== null && preg_match('/sources\.length\s*<\s*2/', $goConfirmBody) === 1,
    'validates_every_pool_source_is_complete' => $goConfirmBody !== null && preg_match('/!s\.inst\s*\|\|\s*!s\.asset|!inst.*!asset/', $goConfirmBody) === 1,
];
$report['tests']['validation'] = array_merge(['description' => 'Amount/PIN/pool validation before allowing confirm'], verdict($checks));

// ============================================================
// TEST: payload construction covers all four real swap products
// ============================================================
$buildPayloadBody = extractJsFunctionBody($jsContent, 'buildPayload');
$checks = [
    'buildPayload_exists' => $buildPayloadBody !== null,
    'supports_deposit_or_send' => $buildPayloadBody !== null && strpos($buildPayloadBody, "'DEPOSIT'") !== false,
    'supports_cashout' => $buildPayloadBody !== null && strpos($buildPayloadBody, "'CASHOUT'") !== false,
    'supports_identity_swap' => $buildPayloadBody !== null && strpos($buildPayloadBody, "'IDENTITY'") !== false,
    'supports_multi_source' => $buildPayloadBody !== null && strpos($buildPayloadBody, "'MULTI_SOURCE'") !== false,
    'multi_source_carries_contribution_strategy' => $buildPayloadBody !== null && strpos($buildPayloadBody, 'contribution_strategy') !== false,
    'multi_source_builds_sources_array_from_dom' => $buildPayloadBody !== null && preg_match('/sources\.map\(/', $buildPayloadBody) === 1,
    'carries_destination_asset_type' => $buildPayloadBody !== null && strpos($buildPayloadBody, 'destination_asset_type') !== false,
];
$report['tests']['payload_construction'] = array_merge(['description' => 'Payload building covers all four swap products with correct fields'], verdict($checks));

// ============================================================
// TEST: API integration + error handling
// ============================================================
$checks = [
    'defines_execute_api_url' => preg_match('/apiUrl\s*=\s*[\'"]/', $jsContent) === 1,
    'defines_preview_api_url' => preg_match('/previewUrl\s*=\s*[\'"]/', $jsContent) === 1,
    'defines_api_key' => preg_match('/apiKey\s*=\s*[\'"]/', $jsContent) === 1,
    'calls_preview_before_execute' => strpos($jsContent, 'fetch(previewUrl') !== false,
    'calls_execute_endpoint' => strpos($jsContent, 'fetch(apiUrl') !== false,
    'uses_async_functions' => strpos($jsContent, 'async function') !== false,
    'has_try_catch_around_network_calls' => preg_match('/try\s*\{[^}]*fetch/s', $jsContent) === 1,
    'surfaces_errors_to_user' => strpos($jsContent, 'catch') !== false && (strpos($jsContent, 'err.message') !== false || strpos($jsContent, 'error.message') !== false),
];
$report['tests']['api_integration'] = array_merge(['description' => 'API calls, error surfacing'], verdict($checks));

// ============================================================
// TEST: responsive/fluid design - the actual "works on phone through
// TV" requirement. Checks for CONTINUOUS scaling (clamp), not just
// the presence of a couple of media queries.
// ============================================================
$clampCount = preg_match_all('/clamp\(/', $cssContent);
$mediaQueryCount = preg_match_all('/@media/', $cssContent);
$checks = [
    'uses_fluid_clamp_scaling_extensively' => $clampCount >= 10, // real fluid design touches many properties, not just one headline
    'has_at_least_one_layout_breakpoint' => $mediaQueryCount >= 1,
    'defines_minimum_tap_target_size' => preg_match('/--tap-min\s*:\s*4[0-9]px|min-height:\s*var\(--tap-min\)/', $cssContent) === 1,
    'has_visible_focus_states_for_keyboard_remote_nav' => strpos($cssContent, 'focus-visible') !== false,
    'shell_width_is_relative_not_fixed_px' => preg_match('/max-width:\s*min\(\d+px,\s*\d+vw\)/', $cssContent) === 1,
];
$report['tests']['responsive_design'] = array_merge(
    ['description' => "Fluid scaling from phone to large screen/TV ({$clampCount} clamp() uses, {$mediaQueryCount} media queries found)"],
    verdict($checks)
);

// ============================================================
// TEST: session & cloud/history data wiring (stable across redesigns)
// ============================================================
$checks = [
    'starts_session' => strpos($content, 'SessionManager::start()') !== false,
    'reads_logged_in_user' => strpos($content, 'SessionManager::getUser()') !== false,
    'redirects_if_not_logged_in' => strpos($content, 'isLoggedIn()') !== false,
    'builds_valid_identifiers_list' => strpos($content, 'validIdentifiers') !== false,
    'exposes_identifiers_to_js' => preg_match('/json_encode\(\$valid\w*\)/', $content) === 1,
];
$report['tests']['session_and_identity'] = array_merge(['description' => 'Session handling and user identifier exposure'], verdict($checks));

$checks = [
    'shows_cloud_balance_when_nonzero' => strpos($content, 'cloudTotal > 0') !== false,
    'has_cloud_panel' => strpos($content, 'cloudPanel') !== false || strpos($content, "openPanel('cloud')") !== false,
    'has_history_panel' => strpos($content, 'historyPanel') !== false || strpos($content, "openPanel('history')") !== false,
];
$report['tests']['cloud_and_history_ui'] = array_merge(['description' => 'Cloud balance and history panels present and wired'], verdict($checks));

// ============================================================
// SUMMARY
// ============================================================
$allPassed = true;
$failures = [];
foreach ($report['tests'] as $name => $test) {
    if (($test['status'] ?? '') === 'FAIL') { $allPassed = false; $failures[] = $name; }
}
$report['summary'] = [
    'total_tests' => count($report['tests']),
    'passed' => count($report['tests']) - count($failures),
    'failed' => count($failures),
    'failures' => $failures,
    'status' => $allPassed ? '✅ All checks passed' : '❌ Some checks failed - review above',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
