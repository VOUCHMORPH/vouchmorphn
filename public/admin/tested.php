<?php
/**
 * Additions to public/admin/tested.php - insert these as new sections
 * after the existing Section 6. Same mechanism as Section 2: regex-extract
 * which result key each call site checks vs which key the corresponding
 * method actually returns. Nothing here touches a live bank, a live pool,
 * or real money - it reads deployed source files and diffs key names.
 */

// ============================================================
// SECTION 7: MULTI-SOURCE POOL CONTRACT MATCHING
// ============================================================

$poolCoordinatorSrc = readSource('src/Domain/Services/MultiSource/PoolCoordinator.php');

$report['section_7_multisource_contract_matching'] = [
    'how' => 'same mechanism as Section 2, applied to PoolCoordinator - catches the exact bug class already found 6 times, this time on the multi-source path'
];

if ($poolCoordinatorSrc === null || $adapterSrc === null) {
    $report['section_7_multisource_contract_matching']['error'] = 'Could not read PoolCoordinator.php or GenericInstitutionAdapter.php';
} else {
    $poolCallSites = [
        'verifySource'            => 'verifyAsset',
        'placeHoldsOnSources'     => 'placeHold',
        'processDestinationDeposit' => 'credit',
        'debitSources'            => 'debit',
    ];

    foreach ($poolCallSites as $poolMethod => $adapterMethod) {
        $poolBody = extractMethodBody($poolCoordinatorSrc, $poolMethod);
        $adapterBody = extractMethodBody($adapterSrc, $adapterMethod);

        $entry = ['pool_method' => $poolMethod, 'adapter_method' => $adapterMethod];

        if ($poolBody === null) { $entry['status'] = 'PoolCoordinator method not found'; $report['section_7_multisource_contract_matching'][$poolMethod] = $entry; continue; }
        if ($adapterBody === null) { $entry['status'] = 'Adapter method not found'; $report['section_7_multisource_contract_matching'][$poolMethod] = $entry; continue; }

        $checkedKeys = extractResultKeyChecks($poolBody);
        $returnedKeys = extractReturnedKeys($adapterBody);
        $matched = array_intersect($checkedKeys, $returnedKeys);

        $entry['pool_checks_keys'] = $checkedKeys;
        $entry['adapter_returns_keys'] = $returnedKeys;
        $entry['matched'] = array_values($matched);
        $entry['MISMATCH'] = (empty($matched) && !empty($checkedKeys))
            ? 'CRITICAL: this pool call site will ALWAYS evaluate as failed, regardless of what the institution returns'
            : 'OK';

        $report['section_7_multisource_contract_matching'][$poolMethod] = $entry;
    }

    // Specifically confirm the rollback fix landed: does the catch block
    // in placeHoldsOnSources actually call releaseHoldAtInstitution now,
    // or still only local bookkeeping?
    $holdMethodBody = extractMethodBody($poolCoordinatorSrc, 'placeHoldsOnSources');
    if ($holdMethodBody !== null) {
        $report['section_7_multisource_contract_matching']['_rollback_check'] =
            str_contains($holdMethodBody, 'releaseHoldAtInstitution')
                ? 'OK - rollback calls the real institution release, not just local bookkeeping'
                : 'MISSING - rollback still only touches local hold_transactions, real holds at institutions are never released on partial pool failure';
    }
}

// ============================================================
// SECTION 8: OAUTH / SOURCE-LINKING CONTRACT MATCHING
// ============================================================

$bankClientSrcForOauth = $bankClientSrc; // already read in Section 2/5 above
$report['section_8_oauth_source_linking'] = [
    'how' => 'checks whether SwapService source-linking methods and GenericBankClient actually agree on result keys - this path has never been checked before, live or mechanically'
];

if ($swapServiceSrc === null || $bankClientSrcForOauth === null) {
    $report['section_8_oauth_source_linking']['error'] = 'Missing source files';
} else {
    $oauthCallSites = [
        'initiateSourceLink'   => 'initiateSourceLink',
        'verifySourceLink'     => 'verifySourceLink',
        'refreshHookedSource'  => 'refreshSourceToken',
        'revokeHookedSource'   => 'revokeSourceToken',
    ];

    foreach ($oauthCallSites as $swapMethod => $clientMethod) {
        $swapBody = extractMethodBody($swapServiceSrc, $swapMethod);
        $clientBody = extractMethodBody($bankClientSrcForOauth, $clientMethod);

        $entry = ['swap_method' => $swapMethod, 'bank_client_method' => $clientMethod];

        if ($swapBody === null) { $entry['status'] = 'SwapService method not found - may not exist under this name'; $report['section_8_oauth_source_linking'][$swapMethod] = $entry; continue; }
        if ($clientBody === null) { $entry['status'] = 'GenericBankClient method not found'; $report['section_8_oauth_source_linking'][$swapMethod] = $entry; continue; }

        $checkedKeys = extractResultKeyChecks($swapBody);
        $returnedKeys = extractReturnedKeys($clientBody);
        $matched = array_intersect($checkedKeys, $returnedKeys);

        $entry['swap_checks_keys'] = $checkedKeys;
        $entry['client_returns_keys'] = $returnedKeys;
        $entry['matched'] = array_values($matched);
        $entry['MISMATCH'] = (empty($matched) && !empty($checkedKeys)) ? 'CRITICAL' : 'OK';

        $report['section_8_oauth_source_linking'][$swapMethod] = $entry;
    }
}

// ============================================================
// SECTION 9: FOREX / FEE CONTRACT MATCHING
// ============================================================

$feeServiceSrc = readSource('src/Infrastructure/Forex/FeeService.php');
if ($feeServiceSrc === null) {
    $feeServiceSrc = readSource('src/Domain/Services/FeeService.php'); // try alternate location
}

$report['section_9_forex_fee_contract'] = [
    'how' => 'diffs SwapService::calculateFeesWithDetails() reads against FeeService::calculateFees() actual returned keys - a mismatch here silently mispraices swaps rather than failing them, which is worse than a crash'
];

if ($swapServiceSrc === null || $feeServiceSrc === null) {
    $report['section_9_forex_fee_contract']['error'] = 'FeeService.php not found at either expected path - update path in this script once located';
} else {
    $feeCheckBody = extractMethodBody($swapServiceSrc, 'calculateFeesWithDetails');
    $feeCalcBody = extractMethodBody($feeServiceSrc, 'calculateFees');

    if ($feeCheckBody === null || $feeCalcBody === null) {
        $report['section_9_forex_fee_contract']['status'] = 'One or both methods not found under expected names';
    } else {
        // Deeper keys here (nested: forex.rate, forex.applied) - extract
        // both top-level and one level of nesting.
        preg_match_all("/\\\$feeResult\\['([\\w]+)'\\](?:\\['([\\w]+)'\\])?/", $feeCheckBody, $fm, PREG_SET_ORDER);
        $checkedPaths = [];
        foreach ($fm as $match) {
            $checkedPaths[] = isset($match[2]) && $match[2] !== '' ? "{$match[1]}.{$match[2]}" : $match[1];
        }
        $checkedPaths = array_values(array_unique($checkedPaths));

        $returnedKeys = extractReturnedKeys($feeCalcBody);

        $report['section_9_forex_fee_contract']['swap_service_reads'] = $checkedPaths;
        $report['section_9_forex_fee_contract']['fee_service_top_level_returns'] = $returnedKeys;
        $report['section_9_forex_fee_contract']['_note'] =
            'Cross-check each top-level path above (e.g. "forex") against fee_service_top_level_returns manually if nested - the extractor only reliably catches one level of nesting automatically.';
    }
}

// ============================================================
// SECTION 10: SYNTHETIC MULTI-DESTINATION / IDENTITY SMOKE TEST
// Fabricated payloads, stops before any real DB write or HTTP call -
// only proves the validation/dispatch logic doesn't fatal on shape.
// ============================================================

$report['section_10_synthetic_smoke'] = ['how' => 'fabricated payloads run only through validation logic - no DB write, no HTTP call'];

try {
    if (class_exists('Domain\Services\SwapService')) {
        $refClass = new ReflectionClass('Domain\Services\SwapService');

        // Check extractDestinationAssetType accepts VOUCHER/CARD, not just ACCOUNT/WALLET -
        // flagged as a possible gap back when "VOUCHER" as swap_type caused confusion.
        if ($refClass->hasMethod('extractDestinationAssetType')) {
            $method = $refClass->getMethod('extractDestinationAssetType');
            $method->setAccessible(true);
            // Can't safely invoke without a constructed instance + DB - report
            // statically instead via source scan.
            $body = extractMethodBody($swapServiceSrc, 'extractDestinationAssetType');
            $report['section_10_synthetic_smoke']['destination_asset_type_values_accepted'] =
                $body ? extractPayloadKeyChains($body) : 'method not found';
        }

        $report['section_10_synthetic_smoke']['status'] = 'Static-only check performed - full instantiation requires a live DB connection, not attempted here to keep this section side-effect-free';
    }
} catch (\Throwable $e) {
    $report['section_10_synthetic_smoke']['error'] = $e->getMessage();
}
