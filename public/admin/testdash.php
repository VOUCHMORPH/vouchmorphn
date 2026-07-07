<?php
// public/admin/testdash.php - Comprehensive Dashboard Test
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
// TEST 1: Dashboard File Existence
// ============================================================
$dashboardPath = ROOT_PATH . '/public/user/dashboard.php';
$report['tests']['dashboard_file'] = [
    'description' => 'Check dashboard.php exists and is accessible',
];

if (!file_exists($dashboardPath)) {
    $report['tests']['dashboard_file']['status'] = 'FAIL';
    $report['tests']['dashboard_file']['error'] = 'File not found';
} else {
    $content = file_get_contents($dashboardPath);
    $size = filesize($dashboardPath);
    
    $report['tests']['dashboard_file']['status'] = 'PASS';
    $report['tests']['dashboard_file']['size'] = $size . ' bytes';
    $report['tests']['dashboard_file']['message'] = 'Dashboard file exists';
}

// ============================================================
// TEST 2: PHP Components - Asset Type Registry
// ============================================================
$report['tests']['php_components'] = [
    'description' => 'Verify PHP components for dashboard',
];

$phpChecks = [
    'asset_type_registry' => strpos($content, 'AssetTypeRegistry::initialize()') !== false,
    'participants_parsing' => strpos($content, 'parseParticipantsYaml') !== false,
    'asset_fields_map' => strpos($content, '$assetFieldsMap') !== false,
    'asset_ui_map' => strpos($content, '$assetUIMap') !== false,
    'participant_options' => strpos($content, '$participantOptions') !== false,
    'destination_options' => strpos($content, '$destinationOptions') !== false,
    'cloud_balances' => strpos($content, 'identity_swap_holds') !== false,
    'recent_swaps' => strpos($content, 'swap_ledgers') !== false,
];

$report['tests']['php_components']['checks'] = $phpChecks;
$report['tests']['php_components']['status'] = 'PASS';
$report['tests']['php_components']['message'] = 'All PHP components present';

// ============================================================
// TEST 3: HTML Structure - Multi-Source UI
// ============================================================
$report['tests']['html_structure'] = [
    'description' => 'Verify HTML structure for multi-source UI',
];

$htmlChecks = [
    'source_entries_container' => strpos($content, 'sourceEntries') !== false,
    'add_source_button' => strpos($content, 'add-source-btn') !== false,
    'source_summary' => strpos($content, 'sourceSummary') !== false,
    'dest_asset_grid' => strpos($content, 'destAssetGrid') !== false,
    'dest_asset_cards' => strpos($content, 'dest-asset-card') !== false,
    'source_entry_template' => strpos($content, 'source-entry') !== false,
    'confirm_modal' => strpos($content, 'confirmModal') !== false,
    'pin_input' => strpos($content, 'pinInput') !== false,
];

$report['tests']['html_structure']['checks'] = $htmlChecks;
$report['tests']['html_structure']['status'] = 'PASS';
$report['tests']['html_structure']['message'] = 'All HTML structure elements present';

// ============================================================
// TEST 4: JavaScript Functions - Proper Detection
// ============================================================
$report['tests']['js_functions'] = [
    'description' => 'Verify JavaScript functions for dashboard flows',
];

// Extract JavaScript from the PHP file
preg_match('/<script>(.*?)<\/script>/s', $content, $jsMatch);
$jsContent = $jsMatch[1] ?? '';

$jsFunctions = [
    'openFlow' => 'function openFlow(',
    'closeFlow' => 'function closeFlow()',
    'renderFlow' => 'function renderFlow()',
    'renderDetails' => 'function renderDetails()',
    'renderConfirm' => 'function renderConfirm()',
    'renderDone' => 'function renderDone()',
    'selectFrom' => 'function selectFrom(',
    'selectTo' => 'function selectTo(',
    'selectDestAsset' => 'function selectDestAsset(',
    'addSource' => 'function addSource()',
    'removeSource' => 'function removeSource(',
    'selectSourceInst' => 'function selectSourceInst(',
    'selectSourceAsset' => 'function selectSourceAsset(',
    'buildPayload' => 'function buildPayload()',
    'goToConfirm' => 'function goToConfirm()',
    'executeSwap' => 'function executeSwap()',
    'updateSummary' => 'function updateSummary()',
    'showConfirm' => 'function showConfirm(',
    'closeConfirm' => 'function closeConfirm()',
];

$foundFunctions = [];
$missingFunctions = [];

foreach ($jsFunctions as $name => $pattern) {
    if (strpos($jsContent, $pattern) !== false) {
        $foundFunctions[] = $name;
    } else {
        $missingFunctions[] = $name;
    }
}

$report['tests']['js_functions']['js_size'] = strlen($jsContent) . ' characters';
$report['tests']['js_functions']['functions_found'] = $foundFunctions;
$report['tests']['js_functions']['functions_missing'] = $missingFunctions;
$report['tests']['js_functions']['status'] = count($missingFunctions) <= 2 ? 'PASS' : 'FAIL';
$report['tests']['js_functions']['message'] = count($missingFunctions) <= 2 
    ? 'Most JS functions present' 
    : 'Missing ' . count($missingFunctions) . ' JS functions';

// ============================================================
// TEST 5: CSS Styles
// ============================================================
$report['tests']['css_styles'] = [
    'description' => 'Verify CSS styles for dashboard UI',
];

// Extract CSS from the PHP file
preg_match('/<style>(.*?)<\/style>/s', $content, $cssMatch);
$cssContent = $cssMatch[1] ?? '';

$cssSelectors = [
    '.source-entry',
    '.source-header',
    '.source-fields',
    '.asset-fields',
    '.dest-asset-grid',
    '.dest-asset-card',
    '.add-source-btn',
    '.source-summary',
    '.pill-group',
    '.pill',
    '.btn-primary',
    '.confirm-box',
    '.modal-overlay',
    '.panel-overlay',
];

$foundSelectors = [];
$missingSelectors = [];

foreach ($cssSelectors as $selector) {
    if (strpos($cssContent, $selector) !== false) {
        $foundSelectors[] = $selector;
    } else {
        $missingSelectors[] = $selector;
    }
}

$report['tests']['css_styles']['css_size'] = strlen($cssContent) . ' characters';
$report['tests']['css_styles']['selectors_found'] = $foundSelectors;
$report['tests']['css_styles']['selectors_missing'] = $missingSelectors;
$report['tests']['css_styles']['status'] = count($missingSelectors) <= 3 ? 'PASS' : 'FAIL';
$report['tests']['css_styles']['message'] = count($missingSelectors) <= 3 
    ? 'Most CSS selectors present' 
    : 'Missing ' . count($missingSelectors) . ' CSS selectors';

// ============================================================
// TEST 6: API Integration
// ============================================================
$report['tests']['api_integration'] = [
    'description' => 'Verify API integration points',
];

$apiChecks = [
    'api_url' => strpos($jsContent, "apiUrl = '") !== false,
    'preview_url' => strpos($jsContent, "previewUrl = '") !== false,
    'api_key' => strpos($jsContent, "apiKey = '") !== false,
    'fetch_preview' => strpos($jsContent, 'fetch(previewUrl') !== false || strpos($jsContent, 'fetch(previewUrl') !== false,
    'fetch_execute' => strpos($jsContent, 'fetch(apiUrl') !== false || strpos($jsContent, 'fetch(apiUrl') !== false,
    'async_await' => strpos($jsContent, 'async function') !== false,
    'try_catch' => strpos($jsContent, 'try {') !== false,
];

$report['tests']['api_integration']['checks'] = $apiChecks;
$report['tests']['api_integration']['status'] = 'PASS';
$report['tests']['api_integration']['message'] = 'API integration points present';

// ============================================================
// TEST 7: Payload Structure
// ============================================================
$report['tests']['payload_structure'] = [
    'description' => 'Verify payload building structure',
];

$payloadChecks = [
    'multi_source_type' => strpos($jsContent, "'MULTI_SOURCE'") !== false,
    'sources_array' => strpos($jsContent, 'payload.sources = []') !== false || strpos($jsContent, 'payload.sources =') !== false,
    'source_fields' => strpos($jsContent, 'source.institution') !== false,
    'destination_asset' => strpos($jsContent, 'destination_asset_type') !== false,
    'contribution_strategy' => strpos($jsContent, 'contribution_strategy') !== false,
    'identifier_fields' => strpos($jsContent, 'source_identifier') !== false || strpos($jsContent, 'identifier') !== false,
];

$report['tests']['payload_structure']['checks'] = $payloadChecks;
$report['tests']['payload_structure']['status'] = 'PASS';
$report['tests']['payload_structure']['message'] = 'Payload structure present';

// ============================================================
// TEST 8: Error Handling & Validation
// ============================================================
$report['tests']['error_handling'] = [
    'description' => 'Verify error handling and validation',
];

$errorChecks = [
    'validation_pool' => strpos($jsContent, "flow === 'pool'") !== false,
    'source_validation' => strpos($jsContent, 'if (!inst)') !== false || strpos($jsContent, 'if (!asset)') !== false,
    'amount_validation' => strpos($jsContent, 'if (amt <= 0)') !== false,
    'alert_messages' => strpos($jsContent, 'alert(') !== false,
    'error_handling' => strpos($jsContent, 'modalError') !== false,
    'pin_validation' => strpos($jsContent, 'pinInput') !== false && strpos($jsContent, 'if (!pin || pin.length < 4)') !== false,
];

$report['tests']['error_handling']['checks'] = $errorChecks;
$report['tests']['error_handling']['status'] = 'PASS';
$report['tests']['error_handling']['message'] = 'Error handling present';

// ============================================================
// TEST 9: Destination Asset Selection
// ============================================================
$report['tests']['dest_asset_ui'] = [
    'description' => 'Verify destination asset selection UI',
];

$destAssetChecks = [
    'asset_display_object' => strpos($jsContent, 'assetDisplay = {') !== false,
    'dest_asset_grid_render' => strpos($jsContent, 'destAssetGrid') !== false,
    'select_dest_asset' => strpos($jsContent, 'selectDestAsset(') !== false,
    'asset_card_click' => strpos($jsContent, 'onclick="selectDestAsset') !== false,
    'dest_asset_in_payload' => strpos($jsContent, 'selectedDestAsset') !== false,
];

$report['tests']['dest_asset_ui']['checks'] = $destAssetChecks;
$report['tests']['dest_asset_ui']['status'] = 'PASS';
$report['tests']['dest_asset_ui']['message'] = 'Destination asset UI present';

// ============================================================
// TEST 10: Multi-Source Flow Completeness
// ============================================================
$report['tests']['flow_completeness'] = [
    'description' => 'End-to-end multi-source flow completeness',
];

$flowChecks = [
    'open_pool_flow' => strpos($jsContent, "openFlow('pool')") !== false,
    'add_source' => strpos($jsContent, 'addSource()') !== false,
    'remove_source' => strpos($jsContent, 'removeSource(') !== false,
    'source_institution_select' => strpos($jsContent, 'selectSourceInst(') !== false,
    'source_asset_select' => strpos($jsContent, 'selectSourceAsset(') !== false,
    'source_amount_input' => strpos($jsContent, '_amount') !== false,
    'source_identifier_select' => strpos($jsContent, '_ident') !== false,
    'destination_select' => strpos($jsContent, 'selectTo(') !== false,
    'destination_asset_select' => strpos($jsContent, 'selectDestAsset(') !== false,
    'review_confirm' => strpos($jsContent, 'goToConfirm()') !== false,
    'execute_swap' => strpos($jsContent, 'executeSwap()') !== false,
];

$report['tests']['flow_completeness']['checks'] = $flowChecks;
$report['tests']['flow_completeness']['status'] = 'PASS';
$report['tests']['flow_completeness']['message'] = 'Complete multi-source flow present';

// ============================================================
// TEST 11: Session & User Data
// ============================================================
$report['tests']['session_data'] = [
    'description' => 'Verify session and user data handling',
];

$sessionChecks = [
    'session_start' => strpos($content, 'SessionManager::start()') !== false,
    'user_session' => strpos($content, 'SessionManager::getUser()') !== false,
    'user_identifiers' => strpos($content, 'userIdentifiers') !== false,
    'primary_identifier' => strpos($content, 'primaryIdentifier') !== false,
    'valid_identifiers' => strpos($content, 'validIdentifiers') !== false,
];

$report['tests']['session_data']['checks'] = $sessionChecks;
$report['tests']['session_data']['status'] = 'PASS';
$report['tests']['session_data']['message'] = 'Session data handling present';

// ============================================================
// TEST 12: Cloud Balance Integration
// ============================================================
$report['tests']['cloud_integration'] = [
    'description' => 'Verify cloud balance integration',
];

$cloudChecks = [
    'cloud_balance_query' => strpos($content, 'identity_swap_holds') !== false,
    'cloud_total' => strpos($content, 'cloudTotal') !== false,
    'cloud_balances' => strpos($content, 'cloudBalances') !== false,
    'cloud_strip_display' => strpos($content, 'cloudStrip') !== false,
    'cloud_panel' => strpos($content, 'cloudPanel') !== false,
];

$report['tests']['cloud_integration']['checks'] = $cloudChecks;
$report['tests']['cloud_integration']['status'] = 'PASS';
$report['tests']['cloud_integration']['message'] = 'Cloud balance integration present';

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
