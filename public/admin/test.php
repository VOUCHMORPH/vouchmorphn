<?php
declare(strict_types=1);

/**
 * public/admin/tested_flows.php
 *
 * Mechanical introspection tool - Part 2 (companion to tested.php).
 *
 * Tests TWO orchestration directions not covered by tested.php:
 *
 *   A) ONE-SOURCE -> MULTI-DESTINATION ("fan-out" / disbursement)
 *      One funding source pays out simultaneously to N recipients across
 *      MIXED destination types: IDENTITY, ACCOUNT, WALLET, CASHOUT.
 *      This is the shape needed for government/business bulk disbursement.
 *
 *   B) MULTI-SOURCE -> ONE-DESTINATION ("pooling")
 *      N funding sources are combined to cover ONE destination amount,
 *      used when a single balance is insufficient to complete a payment.
 *
 * For both directions this checks: does the orchestrating code exist at
 * all, does fee/forex get applied per leg, does netting/invoicing exist,
 * and - where a real pool implementation exists - does rollback/state
 * handling behave correctly on partial failure.
 *
 * Same rules as tested.php: everything reported here comes from either
 * (a) regex-parsing real deployed method bodies, or (b) live directory
 * scans of files actually on disk. No live bank/forex/SMS call is ever
 * made. Safe to run repeatedly against production.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================================
// AUTH GATE (same pattern as tested.php)
// ============================================================
function getApiKeyFromRequest(): ?string
{
    $headers = getallheaders() ?: [];
    $headersLower = array_change_key_case($headers, CASE_LOWER);
    if (!empty($headersLower['x-api-key'])) return $headersLower['x-api-key'];
    if (!empty($headersLower['authorization'])) {
        $auth = $headersLower['authorization'];
        return str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : $auth;
    }
    return null;
}
function getAllApiKeysFromEnvironment(): array
{
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
    'source' => 'mechanically extracted from live files - see "how" field per section',
    'scope' => 'A: one-source->multi-destination disbursement (mixed types). B: multi-source->one-destination pooling.',
];

// ============================================================
// SHARED HELPERS
// ============================================================
function readSource(string $relativePath): ?string
{
    $path = ROOT_PATH . '/' . ltrim($relativePath, '/');
    return file_exists($path) ? file_get_contents($path) : null;
}

function extractMethodBody(string $source, string $methodName): ?string
{
    if (!preg_match('/(?:private|protected|public)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1; $i = $start; $len = strlen($source);
    while ($i < $len && $depth > 0) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') $depth--;
        $i++;
    }
    return substr($source, $start, $i - $start - 1);
}

function extractReturnedKeys(string $body): array
{
    $keys = [];
    preg_match_all('/return\s*\[(.*?)\];/s', $body, $blocks);
    foreach ($blocks[1] as $block) {
        preg_match_all("/'([\\w]+)'\\s*=>/", $block, $km);
        $keys = array_merge($keys, $km[1]);
    }
    return array_values(array_unique($keys));
}

function extractResultKeyChecks(string $body): array
{
    $allKeys = [];
    preg_match_all("/\\\$(?:result|res)\\['([\\w]+)'\\]\\s*\\?\\?\\s*false/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    preg_match_all("/if\\s*\\(\\s*!\\s*\\\$(?:result|res)\\['([\\w]+)'\\]\\s*\\)/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    preg_match_all("/if\\s*\\(\\s*\\\$(?:result|res)\\['([\\w]+)'\\]\\s*===\\s*true\\s*\\)/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    return array_values(array_unique($allKeys));
}

function checkContractPair(?string $callerBody, ?string $calleeBody, string $callerLabel, string $calleeLabel): array
{
    if ($callerBody === null) return ['status' => "{$callerLabel} method not found"];
    if ($calleeBody === null) return ['status' => "{$calleeLabel} method not found"];
    $checkedKeys = extractResultKeyChecks($callerBody);
    $returnedKeys = extractReturnedKeys($calleeBody);
    $matched = array_intersect($checkedKeys, $returnedKeys);
    return [
        'checks_keys' => $checkedKeys,
        'returns_keys' => $returnedKeys,
        'matched' => array_values($matched),
        'MISMATCH' => (empty($matched) && !empty($checkedKeys))
            ? 'CRITICAL: caller checks a key the callee never returns'
            : 'OK',
    ];
}

function listPublicMethods(string $source): array
{
    preg_match_all('/public function\s+(\w+)\s*\(/', $source, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Recursively scan a directory for files whose NAME or whose class/interface
 * declaration line matches a keyword regex. Used to answer "does X exist
 * anywhere" instead of guessing one specific expected path.
 */
function findFilesByKeyword(string $relativeDir, string $keywordRegex): array
{
    $root = ROOT_PATH . '/' . ltrim($relativeDir, '/');
    if (!is_dir($root)) return [];
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;
        $rel = ltrim(str_replace(ROOT_PATH, '', $file->getPathname()), '/');
        if (preg_match($keywordRegex, $file->getFilename())) {
            $found[] = $rel;
            continue;
        }
        $head = @file_get_contents($file->getPathname(), false, null, 0, 2000);
        if ($head !== false && preg_match('/^(class|interface|trait)\s+\w*/im', $head)
            && preg_match($keywordRegex, $head)) {
            $found[] = $rel;
        }
    }
    return array_values(array_unique($found));
}

function bodyCallsAnyOf(string $body, array $needles): array
{
    $hits = [];
    foreach ($needles as $needle) {
        if (stripos($body, $needle) !== false) $hits[] = $needle;
    }
    return $hits;
}

$swapServiceSrc      = readSource('src/Domain/Services/SwapService.php');
$adapterSrc          = readSource('src/Infrastructure/Adapters/GenericInstitutionAdapter.php');
$poolCoordinatorSrc  = readSource('src/Domain/Services/MultiSource/PoolCoordinator.php');
$poolStateMachineSrc = readSource('src/Domain/ValueObjects/PoolStateMachine.php')
    ?? readSource('src/Domain/Services/MultiSource/PoolStateMachine.php');
$orchestratorSrc     = readSource('src/Domain/Services/MultiSource/MultiSourceSwapOrchestrator.php');
$executorSrc         = readSource('src/Domain/Services/MultiSource/MultiSourceSwapExecutor.php');
$multiFeeCalcSrc     = readSource('src/Domain/Services/MultiSourceFeeCalculator.php');
$contributionCalcSrc = readSource('src/Domain/Services/ContributionCalculator.php');
$settlementSrc       = readSource('src/Domain/Services/Settlement/HybridSettlementStrategy.php');

$dispatchBody = ($swapServiceSrc !== null) ? extractMethodBody($swapServiceSrc, 'executeAtomicSwap') : null;

// ============================================================
// SECTION A1: DOES A ONE-SOURCE -> MULTI-DESTINATION ORCHESTRATOR EXIST?
// ============================================================
$report['A1_disbursement_orchestrator_discovery'] = [
    'how' => 'recursive scan of src/ for classes/files matching disbursement/batch/fan-out naming, plus manual check of adjacent modules',
];

$disbursementKeywordRegex = '/disburs|broadcast|fan[_-]?out|batchpay|batchswap|multidest|bulkpay/i';
$foundDisbursementFiles = findFilesByKeyword('src', $disbursementKeywordRegex);
$report['A1_disbursement_orchestrator_discovery']['files_matching_disbursement_keywords'] = $foundDisbursementFiles;

if (empty($foundDisbursementFiles)) {
    $report['A1_disbursement_orchestrator_discovery']['VERDICT'] =
        'NOT FOUND - no class or file under src/ matches disbursement/batch/fan-out naming. ' .
        'There is currently no dedicated one-source-to-many-destinations orchestrator in this codebase. ' .
        'Government/business bulk disbursement would today have to be built as a loop calling ' .
        'SwapService::executeAtomicSwap() once per recipient, with no shared batch/session, no combined ' .
        'invoicing, and no atomicity across the batch (if recipient #4 of 10 fails, 1-3 already completed ' .
        'with no visible rollback/compensation path for those).';
} else {
    $report['A1_disbursement_orchestrator_discovery']['VERDICT'] = 'CANDIDATE FILES FOUND - inspect public methods below';
    foreach ($foundDisbursementFiles as $relPath) {
        $src = readSource($relPath);
        $report['A1_disbursement_orchestrator_discovery']['candidates'][$relPath] = $src ? listPublicMethods($src) : 'unreadable';
    }
}

foreach ([
    'src/Modules/TradePaymentModule.php',
    'src/Modules/CorridorModule.php',
    'src/Application/Admin/Modules/PartnerManager.php',
] as $candidatePath) {
    $src = readSource($candidatePath);
    $report['A1_disbursement_orchestrator_discovery']['manually_checked'][$candidatePath] = $src === null
        ? 'file not found'
        : listPublicMethods($src);
}

// ============================================================
// SECTION A2: PER-DESTINATION-TYPE SUPPORT (identity/account/wallet/cashout)
// ============================================================
$report['A2_destination_type_support'] = [
    'how' => 'regex-extracted valid swap_type values from SwapService::executeAtomicSwap match() block, same mechanism as tested.php Section 1',
];

$validTypes = [];
if ($swapServiceSrc === null) {
    $report['A2_destination_type_support']['error'] = 'SwapService.php not found';
} else {
    if ($dispatchBody !== null && preg_match("/match\\(\\\$swapType\\)\\s*\\{(.*?)\\};/s", $dispatchBody, $dm)) {
        preg_match_all("/'([A-Z_]+)'\\s*=>/", $dm[1], $cases);
        $validTypes = array_values(array_unique($cases[1]));
    }
    $report['A2_destination_type_support']['valid_swap_type_values_found'] = $validTypes;

    $requiredForDisbursement = ['IDENTITY', 'ACCOUNT', 'WALLET', 'CASHOUT'];
    $missing = array_values(array_diff($requiredForDisbursement, $validTypes));
    $report['A2_destination_type_support']['required_for_mixed_disbursement'] = $requiredForDisbursement;
    $report['A2_destination_type_support']['missing'] = $missing;
    $report['A2_destination_type_support']['VERDICT'] = empty($missing)
        ? 'OK - all four destination types are individually reachable through executeAtomicSwap(). NOTE: this only proves the single-swap engine can target each type one at a time - it does NOT prove they can be fanned out together as one disbursement batch (see A1).'
        : 'INCOMPLETE - the following destination types have no matching case in executeAtomicSwap() and cannot currently be used as a disbursement destination at all: ' . implode(', ', $missing);
}

// ============================================================
// SECTION A3: FEE/FOREX APPLIED PER DESTINATION-TYPE BRANCH
// ============================================================
$report['A3_fee_forex_per_destination_type'] = [
    'how' => 'for each destination-type case in executeAtomicSwap dispatch, checks whether the target method body calls a fee or forex calculation before completing',
];

if ($swapServiceSrc !== null && $dispatchBody !== null) {
    $feeForexNeedles = ['calculateFeesWithDetails', 'calculateFees', 'ForexService', 'forex', 'FeeService'];
    preg_match_all("/'([A-Z_]+)'\\s*=>\\s*\\\$this->(\\w+)\\(/", $dispatchBody, $armMatches, PREG_SET_ORDER);
    if (empty($armMatches)) {
        $report['A3_fee_forex_per_destination_type']['status'] = 'Could not parse individual match arms - manual review needed';
    } else {
        foreach ($armMatches as $arm) {
            [, $swapType, $targetMethod] = $arm;
            $targetBody = extractMethodBody($swapServiceSrc, $targetMethod);
            if ($targetBody === null) {
                $report['A3_fee_forex_per_destination_type'][$swapType] = [
                    'dispatches_to' => $targetMethod,
                    'status' => 'target method not found - cannot verify fee/forex is applied',
                ];
                continue;
            }
            $hits = bodyCallsAnyOf($targetBody, $feeForexNeedles);
            $report['A3_fee_forex_per_destination_type'][$swapType] = [
                'dispatches_to' => $targetMethod,
                'fee_or_forex_calls_found' => $hits,
                'VERDICT' => empty($hits)
                    ? 'CRITICAL: no fee/forex calculation call found in this destination-type path - this swap type may currently execute fee-free/rate-free'
                    : 'OK',
            ];
        }
    }
} else {
    $report['A3_fee_forex_per_destination_type']['status'] = 'SwapService.php or dispatch body not found';
}

// ============================================================
// SECTION A4: NETTING & INVOICING EXISTENCE CHECK
// ============================================================
$report['A4_netting_invoicing_discovery'] = [
    'how' => 'recursive scan of src/ for classes/files matching netting/invoice/settlement-batch naming',
];

$nettingFiles = findFilesByKeyword('src', '/netting|invoic|settlementbatch|reconcilebatch/i');
$report['A4_netting_invoicing_discovery']['files_found'] = $nettingFiles;
$report['A4_netting_invoicing_discovery']['VERDICT'] = empty($nettingFiles)
    ? 'NOT FOUND - no netting or invoicing class exists anywhere under src/. A multi-destination disbursement batch today would produce N separate settlement events with no combined invoice and no netting against reverse flows. If a single consolidated invoice or net settlement figure is required, this has to be built from scratch.'
    : 'CANDIDATE FILES FOUND - inspect manually; contract-testing these requires their real method signatures, not guessed here to avoid false positives.';

// ============================================================
// SECTION B1: MULTI-SOURCE -> ONE-DESTINATION - COMPONENT DISCOVERY
// ============================================================
$report['B1_pooling_component_discovery'] = [
    'how' => 'confirms existence + public method surface of each known multi-source pooling component',
];

$poolingComponents = [
    'PoolCoordinator' => $poolCoordinatorSrc,
    'PoolStateMachine' => $poolStateMachineSrc,
    'MultiSourceSwapOrchestrator' => $orchestratorSrc,
    'MultiSourceSwapExecutor' => $executorSrc,
    'MultiSourceFeeCalculator' => $multiFeeCalcSrc,
    'ContributionCalculator' => $contributionCalcSrc,
    'HybridSettlementStrategy' => $settlementSrc,
];
foreach ($poolingComponents as $name => $src) {
    $report['B1_pooling_component_discovery'][$name] = $src === null
        ? 'NOT FOUND at expected path'
        : listPublicMethods($src);
}

// ============================================================
// SECTION B2: IS FEE/FOREX ACTUALLY INVOKED DURING POOL ASSEMBLY?
// ============================================================
$report['B2_pool_fee_forex_wiring'] = [
    'how' => 'checks whether PoolCoordinator/Orchestrator/Executor bodies actually call MultiSourceFeeCalculator, ContributionCalculator, or ForexService rather than assuming they are wired via DI without ever being invoked',
];

$needlesForFeeWiring = ['MultiSourceFeeCalculator', 'ContributionCalculator', 'ForexService', 'calculateContribution', 'calculateFees'];
foreach (['PoolCoordinator' => $poolCoordinatorSrc, 'MultiSourceSwapOrchestrator' => $orchestratorSrc, 'MultiSourceSwapExecutor' => $executorSrc] as $name => $src) {
    if ($src === null) {
        $report['B2_pool_fee_forex_wiring'][$name] = 'source not found';
        continue;
    }
    $hits = bodyCallsAnyOf($src, $needlesForFeeWiring);
    $report['B2_pool_fee_forex_wiring'][$name] = [
        'fee_forex_related_calls_found' => $hits,
        'VERDICT' => empty($hits)
            ? 'CRITICAL: this class never references fee/forex/contribution calculation anywhere in its source - if fee application happens elsewhere, confirm where; if nowhere, pooled swaps may execute without fees or forex conversion applied'
            : 'referenced - manually confirm the call is on the actual execution path, not an unused branch or comment',
    ];
}

// ============================================================
// SECTION B3: CONTRACT MATCHING - POOL METHODS VS ADAPTER
// ============================================================
$report['B3_pool_adapter_contract_matching'] = ['how' => 'same mechanism as tested.php section 7, repeated here for completeness'];
if ($poolCoordinatorSrc === null || $adapterSrc === null) {
    $report['B3_pool_adapter_contract_matching']['error'] = 'Could not read PoolCoordinator.php or GenericInstitutionAdapter.php';
} else {
    $poolCallSites = [
        'verifySources'      => 'verifyAsset',
        'placeHolds'         => 'placeHold',
        'executeDestination' => 'credit',
        'debitSources'       => 'debit',
    ];
    foreach ($poolCallSites as $poolMethod => $adapterMethod) {
        $entry = checkContractPair(
            extractMethodBody($poolCoordinatorSrc, $poolMethod),
            extractMethodBody($adapterSrc, $adapterMethod),
            $poolMethod, $adapterMethod
        );
        $report['B3_pool_adapter_contract_matching'][$poolMethod] = array_merge(
            ['pool_method' => $poolMethod, 'adapter_method' => $adapterMethod], $entry
        );
    }
}

// ============================================================
// SECTION B4: POOL STATE MACHINE - FAILURE STATE REACHABILITY
// ============================================================
$report['B4_pool_state_machine_failure_handling'] = [
    'how' => 'checks PoolStateMachine for an explicit FAILED/ROLLED_BACK-style terminal state, and confirms PoolCoordinator actually transitions into it on a source failure rather than only logging locally',
];

if ($poolStateMachineSrc === null) {
    $report['B4_pool_state_machine_failure_handling']['status'] = 'PoolStateMachine.php not found at either expected path - state transitions cannot be verified';
} else {
    preg_match_all("/'([A-Z_]+)'/", $poolStateMachineSrc, $stateMatches);
    $states = array_values(array_unique($stateMatches[1]));
    $report['B4_pool_state_machine_failure_handling']['states_found'] = $states;

    $hasFailureState = (bool) array_filter($states, fn($s) => preg_match('/FAIL|ROLLBACK|ROLLED_BACK|CANCELLED|REVERSED/i', $s));
    $report['B4_pool_state_machine_failure_handling']['has_failure_terminal_state'] = $hasFailureState ? 'YES' : 'NO';

    if ($poolCoordinatorSrc !== null) {
        $rollbackBody = extractMethodBody($poolCoordinatorSrc, 'rollbackHolds');
        $transitionsOnFailure = $rollbackBody !== null && preg_match('/transition|setState|->state\s*=/i', $rollbackBody);
        $report['B4_pool_state_machine_failure_handling']['rollback_calls_state_transition'] = $transitionsOnFailure
            ? 'YES'
            : 'NO - rollbackHolds exists but does not appear to move the pool into a state-machine-tracked failure state; the pool record may remain stuck in an in-progress state after a real rollback occurs';
    }

    $report['B4_pool_state_machine_failure_handling']['VERDICT'] = $hasFailureState
        ? 'OK - a failure/terminal state exists in the state machine definition'
        : 'CRITICAL: no FAILED/ROLLED_BACK-style state found in PoolStateMachine - if a source fails mid-pool, there may be no valid state to represent that outcome, which can leave pool records permanently ambiguous between in-progress and failed';
}

// ============================================================
// SECTION B5: REPOSITORY PERSISTENCE CHECK
// ============================================================
$report['B5_pool_persistence_wiring'] = [
    'how' => 'confirms PoolCoordinator actually calls FundingPoolRepository/PoolContributionRepository to persist state, rather than only holding state in memory for the duration of one request',
];

$fundingPoolRepoSrc = readSource('src/Domain/Repositories/FundingPoolRepository.php');
$poolContribRepoSrc = readSource('src/Domain/Repositories/PoolContributionRepository.php');

$report['B5_pool_persistence_wiring']['FundingPoolRepository_found'] = $fundingPoolRepoSrc !== null ? 'YES' : 'NO';
$report['B5_pool_persistence_wiring']['PoolContributionRepository_found'] = $poolContribRepoSrc !== null ? 'YES' : 'NO';

if ($poolCoordinatorSrc !== null) {
    $usesFundingRepo = stripos($poolCoordinatorSrc, 'FundingPoolRepository') !== false;
    $usesContribRepo = stripos($poolCoordinatorSrc, 'PoolContributionRepository') !== false;
    $report['B5_pool_persistence_wiring']['PoolCoordinator_references_FundingPoolRepository'] = $usesFundingRepo ? 'YES' : 'NO';
    $report['B5_pool_persistence_wiring']['PoolCoordinator_references_PoolContributionRepository'] = $usesContribRepo ? 'YES' : 'NO';
    $report['B5_pool_persistence_wiring']['VERDICT'] = ($usesFundingRepo && $usesContribRepo)
        ? 'OK - both repositories are referenced from PoolCoordinator'
        : 'CRITICAL: PoolCoordinator does not reference one or both persistence repositories - pool/contribution state may only exist in memory for the current request and be lost if the process crashes mid-pool (e.g. after some holds are placed but before all sources are debited)';
}

// ============================================================
// SUMMARY
// ============================================================
$criticalIssues = [];

if (empty($foundDisbursementFiles)) {
    $criticalIssues[] = 'A1: no one-source-to-multi-destination disbursement orchestrator exists';
}
if (!empty($report['A2_destination_type_support']['missing'] ?? [])) {
    $criticalIssues[] = 'A2: missing destination types: ' . implode(', ', $report['A2_destination_type_support']['missing']);
}
foreach (($report['A3_fee_forex_per_destination_type'] ?? []) as $k => $v) {
    if (is_array($v) && str_starts_with($v['VERDICT'] ?? '', 'CRITICAL')) {
        $criticalIssues[] = "A3: {$k} destination path has no fee/forex call";
    }
}
if (empty($nettingFiles)) {
    $criticalIssues[] = 'A4: no netting/invoicing implementation exists anywhere in src/';
}
foreach (($report['B2_pool_fee_forex_wiring'] ?? []) as $k => $v) {
    if (is_array($v) && str_starts_with($v['VERDICT'] ?? '', 'CRITICAL')) {
        $criticalIssues[] = "B2: {$k} never calls fee/forex/contribution calculation";
    }
}
foreach (($report['B3_pool_adapter_contract_matching'] ?? []) as $k => $v) {
    if (is_array($v) && isset($v['MISMATCH']) && str_starts_with($v['MISMATCH'], 'CRITICAL')) {
        $criticalIssues[] = "B3: {$k}";
    }
}
if (($report['B4_pool_state_machine_failure_handling']['has_failure_terminal_state'] ?? 'NO') === 'NO') {
    $criticalIssues[] = 'B4: no failure/rollback terminal state in PoolStateMachine';
}
if (str_starts_with($report['B5_pool_persistence_wiring']['VERDICT'] ?? '', 'CRITICAL')) {
    $criticalIssues[] = 'B5: PoolCoordinator missing repository persistence wiring';
}

$report['summary'] = [
    'critical_issues_found' => count($criticalIssues),
    'issues' => $criticalIssues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
