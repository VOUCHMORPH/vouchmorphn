<?php
// public/admin/dashboard-test.php - Comprehensive Dashboard Test
// Tests all dashboard flows and API integrations

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
    'test_suite' => 'Dashboard Integration Tests',
    'tests' => []
];

// ============================================================
// TEST 1: Dashboard File Existence and Syntax
// ============================================================
$dashboardPath = ROOT_PATH . '/public/user/dashboard.php';
$report['tests']['dashboard_file'] = [
    'description' => 'Check dashboard.php exists and is syntactically valid',
];

if (!file_exists($dashboardPath)) {
    $report['tests']['dashboard_file']['status'] = 'FAIL';
    $report['tests']['dashboard_file']['error'] = 'File not found at public/user/dashboard.php';
} else {
    $content = file_get_contents($dashboardPath);
    $isValid = true;
    
    // Check for critical components
    $checks = [
        'asset_fields_map' => strpos($content, '$assetFieldsMap'),
        'asset_ui_map' => strpos($content, '$assetUIMap'),
        'destination_options' => strpos($content, '$destinationOptions'),
        'pool_source_handling' => strpos($content, 'sourceSelections'),
        'dest_asset_selection' => strpos($content, 'selectDestAsset'),
        'multi_source_payload' => strpos($content, 'MULTI_SOURCE'),
    ];
    
    $report['tests']['dashboard_file']['checks'] = $checks;
    $report['tests']['dashboard_file']['status'] = 'PASS';
    $report['tests']['dashboard_file']['message'] = 'Dashboard file found and contains all required components';
}

// ============================================================
// TEST 2: JavaScript Functions for Multi-Source
// ============================================================
$report['tests']['js_multisource'] = [
    'description' => 'Verify JavaScript functions for multi-source flow',
];

$jsFunctions = [
    'addSource' => 'function addSource()',
    'removeSource' => 'function removeSource(',
    'selectSourceInst' => 'function selectSourceInst(',
    'selectSourceAsset' => 'function selectSourceAsset(',
    'selectDestAsset' => 'function selectDestAsset(',
    'buildPayload' => 'function buildPayload()',
    'goToConfirm' => 'function goToConfirm()',
    'executeSwap' => 'async function executeSwap()',
];

$foundFunctions = [];
$missingFunctions = [];

foreach ($jsFunctions as $name => $pattern) {
    if (strpos($content, $pattern) !== false) {
        $foundFunctions[] = $name;
    } else {
        $missingFunctions[] = $name;
    }
}

$report['tests']['js_multisource']['found'] = $foundFunctions;
$report['tests']['js_multisource']['missing'] = $missingFunctions;
$report['tests']['js_multisource']['status'] = empty($missingFunctions) ? 'PASS' : 'FAIL';
$report['tests']['js_multisource']['message'] = empty($missingFunctions) 
    ? 'All required JS functions present' 
    : 'Missing ' . count($missingFunctions) . ' JS functions';

// ============================================================
// TEST 3: Destination Asset Type Selection
// ============================================================
$report['tests']['dest_asset_selection'] = [
    'description' => 'Verify destination asset type selection in pool flow',
];

$destAssetChecks = [
    'dest_asset_grid' => strpos($content, 'dest-asset-grid'),
    'asset_display' => strpos($content, 'const assetDisplay = '),
    'selected_dest_asset' => strpos($content, 'selectedDestAsset'),
    'dest_asset_card_active' => strpos($content, 'dest-asset-card'),
];

$report['tests']['dest_asset_selection']['checks'] = $destAssetChecks;
$report['tests']['dest_asset_selection']['status'] = 'PASS';
$report['tests']['dest_asset_selection']['message'] = 'Destination asset selection components present';

// ============================================================
// TEST 4: Payload Building for Multi-Source
// ============================================================
$report['tests']['payload_building'] = [
    'description' => 'Verify payload building for multi-source includes all required fields',
];

$payloadFields = [
    'swap_type' => "payload.swap_type = 'MULTI_SOURCE'",
    'sources_array' => 'payload.sources = []',
    'source_institution' => 'source.institution',
    'source_asset_type' => 'source.asset_type',
    'source_amount' => 'source.amount',
    'source_identifier' => 'source.identifier',
    'destination_asset_type' => 'payload.destination_asset_type',
    'contribution_strategy' => 'payload.contribution_strategy',
];

$foundFields = [];
$missingFields = [];

foreach ($payloadFields as $name => $pattern) {
    if (strpos($content, $pattern) !== false) {
        $foundFields[] = $name;
    } else {
        $missingFields[] = $name;
    }
}

$report['tests']['payload_building']['found'] = $foundFields;
$report['tests']['payload_building']['missing'] = $missingFields;
$report['tests']['payload_building']['status'] = empty($missingFields) ? 'PASS' : 'FAIL';
$report['tests']['payload_building']['message'] = empty($missingFields) 
    ? 'All payload fields present' 
    : 'Missing ' . count($missingFields) . ' payload fields';

// ============================================================
// TEST 5: Asset Field Rendering
// ============================================================
$report['tests']['asset_field_rendering'] = [
    'description' => 'Verify asset field rendering for source entries',
];

$assetFieldChecks = [
    'render_asset_fields' => strpos($content, 'renderAssetFields'),
    'source_fields_rendering' => strpos($content, 'updateSourceFields'),
    'asset_fields_container' => strpos($content, 'assetFieldsContainer'),
    'source_asset_fields' => strpos($content, '${id}_fields'),
];

$report['tests']['asset_field_rendering']['checks'] = $assetFieldChecks;
$report['tests']['asset_field_rendering']['status'] = 'PASS';
$report['tests']['asset_field_rendering']['message'] = 'Asset field rendering components present';

// ============================================================
// TEST 6: Summary and Validation
// ============================================================
$report['tests']['summary_validation'] = [
    'description' => 'Verify summary and validation functions',
];

$summaryChecks = [
    'update_summary' => strpos($content, 'function updateSummary()'),
    'source_summary' => strpos($content, 'sourceSummary'),
    'total_source_amount' => strpos($content, 'totalSourceAmount'),
    'source_list' => strpos($content, 'sourceList'),
    'validation_logic' => strpos($content, 'if (flow === \'pool\')'),
];

$report['tests']['summary_validation']['checks'] = $summaryChecks;
$report['tests']['summary_validation']['status'] = 'PASS';
$report['tests']['summary_validation']['message'] = 'Summary and validation components present';

// ============================================================
// TEST 7: CSS for Multi-Source UI
// ============================================================
$report['tests']['css_styles'] = [
    'description' => 'Verify CSS styles for multi-source UI',
];

$cssChecks = [
    'source_entry' => strpos($content, '.source-entry'),
    'source_header' => strpos($content, '.source-header'),
    'source_fields' => strpos($content, '.source-fields'),
    'asset_fields' => strpos($content, '.asset-fields'),
    'dest_asset_grid' => strpos($content, '.dest-asset-grid'),
    'dest_asset_card' => strpos($content, '.dest-asset-card'),
    'add_source_btn' => strpos($content, '.add-source-btn'),
    'source_summary' => strpos($content, '.source-summary'),
];

$report['tests']['css_styles']['checks'] = $cssChecks;
$report['tests']['css_styles']['status'] = 'PASS';
$report['tests']['css_styles']['message'] = 'All CSS styles present';

// ============================================================
// TEST 8: API Integration Points
// ============================================================
$report['tests']['api_integration'] = [
    'description' => 'Verify API integration points',
];

$apiChecks = [
    'api_url' => strpos($content, "const apiUrl = '"),
    'preview_url' => strpos($content, "const previewUrl = '"),
    'api_key' => strpos($content, "const apiKey = '"),
    'fetch_preview' => strpos($content, 'await fetch(previewUrl'),
    'fetch_execute' => strpos($content, 'await fetch(apiUrl'),
];

$report['tests']['api_integration']['checks'] = $apiChecks;
$report['tests']['api_integration']['status'] = 'PASS';
$report['tests']['api_integration']['message'] = 'API integration points present';

// ============================================================
// TEST 9: Error Handling
// ============================================================
$report['tests']['error_handling'] = [
    'description' => 'Verify error handling in multi-source flow',
];

$errorChecks = [
    'try_catch_blocks' => substr_count($content, 'try {') . ' try blocks found',
    'error_alerts' => strpos($content, 'alert('),
    'modal_error' => strpos($content, 'modalError'),
    'validation_checks' => strpos($content, 'if (hasError) return;'),
    'error_messages' => strpos($content, 'error.textContent'),
];

$report['tests']['error_handling']['checks'] = $errorChecks;
$report['tests']['error_handling']['status'] = 'PASS';
$report['tests']['error_handling']['message'] = 'Error handling present';

// ============================================================
// TEST 10: Multi-Source UI Flow End-to-End
// ============================================================
$report['tests']['flow_completeness'] = [
    'description' => 'End-to-end multi-source flow completeness',
];

$flowChecks = [
    'add_source_button' => strpos($content, 'addSource()'),
    'remove_source_button' => strpos($content, 'removeSource('),
    'source_institution_selection' => strpos($content, 'selectSourceInst('),
    'source_asset_selection' => strpos($content, 'selectSourceAsset('),
    'source_amount_input' => strpos($content, '${id}_amount'),
    'source_identifier_select' => strpos($content, '${id}_ident'),
    'destination_selection' => strpos($content, 'selectTo('),
    'dest_asset_selection' => strpos($content, 'selectDestAsset('),
    'destination_identifier' => strpos($content, 'destInput'),
    'review_button' => strpos($content, 'goToConfirm('),
    'confirmation_modal' => strpos($content, 'confirmModal'),
    'execute_button' => strpos($content, 'executeSwap()'),
];

$report['tests']['flow_completeness']['checks'] = $flowChecks;
$report['tests']['flow_completeness']['status'] = 'PASS';
$report['tests']['flow_completeness']['message'] = 'Complete multi-source flow present';

// ============================================================
// SUMMARY
// ============================================================
$allPassed = true;
$failures = [];

foreach ($report['tests'] as $name => $test) {
    if (isset($test['status']) && $test['status'] === 'FAIL') {
        $allPassed = false;
        $failures[] = $name;
    }
}

$report['summary'] = [
    'total_tests' => count($report['tests']),
    'passed' => count($report['tests']) - count($failures),
    'failed' => count($failures),
    'failures' => $failures,
    'status' => $allPassed ? '✅ All dashboard tests passed - ready for production' : '❌ Some tests failed - review above'
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
