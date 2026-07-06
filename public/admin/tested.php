<?php
declare(strict_types=1);

/**
 * public/admin/tested.php
 *
 * Mechanical introspection tool. Every fact reported here comes from
 * either (a) regex-parsing the actual deployed method bodies on this
 * server right now, or (b) Reflection into actually-instantiated real
 * objects. Nothing is asserted from memory. No live bank, gateway, or
 * forex call is ever made by this script - it is safe to run repeatedly
 * against production.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ============================================================
// AUTH GATE
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

function extractPayloadKeyChains(string $body): array
{
    preg_match_all('/\$\w+((?:\[[\'"][\w]+[\'"]\])+)/', $body, $matches);
    $chains = [];
    foreach ($matches[1] as $chainRaw) {
        preg_match_all('/\[[\'"]([\w]+)[\'"]\]/', $chainRaw, $parts);
        $chains[] = implode('.', $parts[1]);
    }
    return array_values(array_unique($chains));
}

function extractResultKeyChecks(string $body): array
{
    // IMPROVED: Multiple patterns for different success-check styles
    $allKeys = [];
    
    // Pattern 1: $result['key'] ?? false
    preg_match_all("/\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*\\?\\?\\s*false/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 2: if (!$result['key'])
    preg_match_all("/if\\s*\\(\\s*!\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*\\)/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 3: if ($result['key'] === true)
    preg_match_all("/if\\s*\\(\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*===\\s*true\\s*\\)/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 4: isset($result['key']) && $result['key']
    preg_match_all("/isset\\s*\\(\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*\\)\\s*&&\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]/", $body, $m);
    for ($i = 0; $i < count($m[1]); $i++) {
        if ($m[1][$i] === $m[2][$i]) {
            $allKeys[] = $m[1][$i];
        } else {
            $allKeys[] = $m[1][$i];
            $allKeys[] = $m[2][$i];
        }
    }
    
    // Pattern 5: empty($result['key'])
    preg_match_all("/empty\\s*\\(\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*\\)/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 6: $result['key'] == true
    preg_match_all("/\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*==\\s*true/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 7: $result['key'] ?: 
    preg_match_all("/\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\s*\\?\\s*:/", $body, $m);
    $allKeys = array_merge($allKeys, $m[1]);
    
    // Pattern 8: Nested keys - $result['data']['key'] ?? false
    preg_match_all("/\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\['([\\w]+)'\\]\\s*\\?\\?\\s*false/", $body, $m);
    for ($i = 0; $i < count($m[1]); $i++) {
        $allKeys[] = $m[1][$i] . '.' . $m[2][$i];
    }
    
    // Pattern 9: if (!$result['data']['key'])
    preg_match_all("/if\\s*\\(\\s*!\\s*\\\$(?:result|res|verifyResult|releaseResult)\\['([\\w]+)'\\]\\['([\\w]+)'\\]\\s*\\)/", $body, $m);
    for ($i = 0; $i < count($m[1]); $i++) {
        $allKeys[] = $m[1][$i] . '.' . $m[2][$i];
    }
    
    return array_values(array_unique($allKeys));
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
            ? 'CRITICAL: caller checks a key the callee never returns - this call site will ALWAYS evaluate as failed, regardless of what actually happened downstream'
            : 'OK',
    ];
}

function checkExactFilenameCase(string $relativeDir, string $expectedFilename): array
{
    $dir = ROOT_PATH . '/' . ltrim($relativeDir, '/');
    if (!is_dir($dir)) {
        return ['status' => "MISSING - directory not found: {$relativeDir}"];
    }
    $entries = scandir($dir) ?: [];
    if (in_array($expectedFilename, $entries, true)) {
        return ['status' => 'OK'];
    }
    foreach ($entries as $entry) {
        if (strcasecmp($entry, $expectedFilename) === 0) {
            return ['status' => "CASE MISMATCH - found '{$entry}' on disk, code expects '{$expectedFilename}'"];
        }
    }
    return ['status' => "MISSING - no file matching '{$expectedFilename}' (case-insensitive) found in {$relativeDir}"];
}

$swapServiceSrc = readSource('src/Domain/Services/SwapService.php');
$bankClientSrc = readSource('src/Infrastructure/Banks/GenericBankClient.php');
$adapterSrc = readSource('src/Infrastructure/Adapters/GenericInstitutionAdapter.php');
$poolCoordinatorSrc = readSource('src/Domain/Services/MultiSource/PoolCoordinator.php');

// ============================================================
// SECTION 1: FIELD ALIAS VOCABULARY
// ============================================================

$report['section_1_field_vocabulary'] = ['how' => 'regex-extracted from SwapService.php method bodies as deployed right now'];

if ($swapServiceSrc === null) {
    $report['section_1_field_vocabulary']['error'] = 'SwapService.php not found';
} else {
    foreach ([
        'extractSourceInstitution', 'extractDestinationInstitution', 'extractDestinationAssetType',
        'extractSourceIdentifier', 'extractDestinationIdentifier', 'extractBeneficiaryPhone', 'forwardPin',
    ] as $method) {
        $body = extractMethodBody($swapServiceSrc, $method);
        $report['section_1_field_vocabulary'][$method] = $body === null
            ? 'METHOD NOT FOUND - renamed or removed since expected'
            : extractPayloadKeyChains($body);
    }

    $dispatchBody = extractMethodBody($swapServiceSrc, 'executeAtomicSwap');
    if ($dispatchBody !== null && preg_match("/match\\(\\\$swapType\\)\\s*\\{(.*?)\\};/s", $dispatchBody, $dm)) {
        preg_match_all("/'([A-Z_]+)'\\s*=>/", $dm[1], $cases);
        $report['section_1_field_vocabulary']['_valid_swap_type_values'] = array_values(array_unique($cases[1]));
        $report['section_1_field_vocabulary']['_swap_type_note'] =
            'Any swap_type NOT in this list silently falls through to default() (executeSignedStandardSwap) - no error, just the wrong code path.';
    }
}

// ============================================================
// SECTION 2: ACTION/ENDPOINT MAP + SINGLE-SOURCE CONTRACT MATCHING
// ============================================================

$report['section_2_action_endpoint_map'] = ['how' => 'regex-extracted yamlPathMap from GenericBankClient.php, cross-checked against REAL instantiated GenericBankClient objects per institution'];

if ($bankClientSrc === null) {
    $report['section_2_action_endpoint_map']['error'] = 'GenericBankClient.php not found';
} else {
    $getEndpointBody = extractMethodBody($bankClientSrc, 'getEndpoint');
    if ($getEndpointBody !== null && preg_match('/\$yamlPathMap\s*=\s*\[(.*?)\];/s', $getEndpointBody, $ym)) {
        preg_match_all("/'([\\w]+)'\\s*=>\\s*\\['([\\w]+)',\\s*'([\\w]+)'\\]/", $ym[1], $rows, PREG_SET_ORDER);
        $actionMap = [];
        foreach ($rows as $row) { $actionMap[$row[1]] = ['section' => $row[2], 'key' => $row[3]]; }
        $report['section_2_action_endpoint_map']['method_name_to_yaml_path'] = $actionMap;
    }

    preg_match_all('/public function (\w+)\(/', $bankClientSrc, $publicMethods);
    $methodActions = [];
    foreach ($publicMethods[1] as $method) {
        $body = extractMethodBody($bankClientSrc, $method);
        if ($body === null) continue;
        $entry = [];
        if (preg_match("/\\\$this->send\\('([a-z_]+)'/", $body, $sm)) $entry['sends_action'] = $sm[1];
        if (preg_match('/\$this->(\w+)\(\$\w+\)/', $body, $delegate) && !isset($entry['sends_action'])) $entry['delegates_to'] = $delegate[1];
        $entry['builds_new_array_literal'] = (bool)preg_match('/\$\w+Payload\s*=\s*\[/', $body);
        if ($entry) $methodActions[$method] = $entry;
    }
    $report['section_2_action_endpoint_map']['public_method_behavior'] = $methodActions;
    $report['section_2_action_endpoint_map']['_note'] =
        'builds_new_array_literal=true means this method constructs a fresh array instead of passing the caller\'s payload through - verify no fields get silently dropped.';
}

try {
    $fullCountryConfig = \Core\Config\LoadCountry::getConfig();
    $participants = $fullCountryConfig['participants'] ?? [];
    $yamlResolution = [];
    foreach ($participants as $code => $participantConfig) {
        try {
            $client = new \Infrastructure\Banks\GenericBankClient($participantConfig);
            $ref = new ReflectionClass($client);
            $baseUrlProp = $ref->getProperty('yamlBaseUrl'); $baseUrlProp->setAccessible(true);
            $endpointsProp = $ref->getProperty('yamlEndpoints'); $endpointsProp->setAccessible(true);
            $yamlResolution[$code] = [
                'resolved_base_url' => $baseUrlProp->getValue($client),
                'resolved_endpoints' => $endpointsProp->getValue($client),
            ];
        } catch (\Throwable $e) { $yamlResolution[$code] = ['error' => $e->getMessage()]; }
    }
    $report['section_2_action_endpoint_map']['real_resolved_per_institution'] = $yamlResolution;
} catch (\Throwable $e) {
    $report['section_2_action_endpoint_map']['institution_resolution_error'] = $e->getMessage();
}

$report['section_2b_single_source_contract_matching'] = ['how' => 'diffs which result key SwapService checks vs which key the adapter method actually returns, per call site'];
if ($swapServiceSrc !== null && $adapterSrc !== null) {
    $callSites = [
        'verifyAssetSigned'           => 'verifyAsset',
        'placeHoldSigned'             => 'placeHold',
        'debitSource'                 => 'debit',
        'processDepositWithProof'     => 'credit',
        'processDestinationWithProof' => 'transferWithProof',
        'generateCashoutToken'        => 'generateCashoutToken',
        'verifyAccount'                => 'verifyAccount',
        'verifyCashout'                => 'verifyCashoutToken',
        'confirmCashout'               => 'confirmCashout',
    ];
    foreach ($callSites as $swapMethod => $adapterMethod) {
        $entry = checkContractPair(
            extractMethodBody($swapServiceSrc, $swapMethod),
            extractMethodBody($adapterSrc, $adapterMethod),
            $swapMethod, $adapterMethod
        );
        $entry = array_merge(['swap_method' => $swapMethod, 'adapter_method' => $adapterMethod], $entry);
        $report['section_2b_single_source_contract_matching'][$swapMethod] = $entry;
    }
} else {
    $report['section_2b_single_source_contract_matching']['error'] = 'Missing SwapService.php or GenericInstitutionAdapter.php';
}

// ============================================================
// SECTION 3: SMS ADAPTER CONFORMANCE
// ============================================================

$report['section_3_sms'] = [];
try {
    if (interface_exists('Infrastructure\SMS\Contracts\ProviderInterface') && class_exists('Infrastructure\SMS\SmsGatewayClient')) {
        $implements = class_implements('Infrastructure\SMS\SmsGatewayClient');
        $report['section_3_sms']['SmsGatewayClient_implements_ProviderInterface'] =
            in_array('Infrastructure\SMS\Contracts\ProviderInterface', $implements ?: []) ? 'YES' : 'NO';
    } else {
        $report['section_3_sms']['status'] = 'SmsGatewayClient or ProviderInterface not resolvable';
    }
} catch (\Throwable $e) { $report['section_3_sms']['error'] = $e->getMessage(); }

// ============================================================
// SECTION 4: USSD SYNTHETIC ROUND-TRIP
// ============================================================

$report['section_4_ussd_synthetic_roundtrip'] = [];
try {
    if (class_exists('Infrastructure\USSD\Contracts\UssdGatewayAdapter') && class_exists('Infrastructure\USSD\Contracts\UssdSessionResponse')) {
        $fakeConfig = [
            'request_fields' => ['session_id' => ['sessionId'], 'phone' => ['phoneNumber'], 'text' => ['text']],
            'response_format' => 'prefix',
            'content_type' => 'text/plain; charset=UTF-8',
        ];
        $adapter = new \Infrastructure\USSD\Contracts\UssdGatewayAdapter($fakeConfig, 'TEST_SYNTHETIC');
        $parsed = $adapter->parseRequest(['sessionId' => 'TEST_SESSION_123', 'phoneNumber' => '+26771234567', 'text' => '']);
        $fakeResponse = \Infrastructure\USSD\Contracts\UssdSessionResponse::continue('Welcome to VouchMorph - TEST MENU');
        $formatted = $adapter->formatResponse($fakeResponse);

        $report['section_4_ussd_synthetic_roundtrip'] = [
            'status' => 'OK',
            'parsed_session_id' => $parsed->sessionId,
            'parsed_phone' => $parsed->phoneNumber,
            'formatted_output' => $formatted,
            'prefix_correct' => str_starts_with($formatted, 'CON ') ? 'YES' : 'NO',
        ];
    } else {
        $report['section_4_ussd_synthetic_roundtrip']['status'] = 'UssdGatewayAdapter or UssdSessionResponse not resolvable - see Section 6';
    }
} catch (\Throwable $e) {
    $report['section_4_ussd_synthetic_roundtrip']['error'] = $e->getMessage();
}

// ============================================================
// SECTION 5: QR SYNTHETIC ROUND-TRIP
// ============================================================

$report['section_5_qr_synthetic_roundtrip'] = [];
try {
    if (class_exists('Infrastructure\QRcodes\EmvQrAdapter') && class_exists('Infrastructure\QRcodes\Contracts\QrPayload')) {
        $qrAdapter = new \Infrastructure\QRcodes\EmvQrAdapter(['26' => 'ZURUBANK', '27' => 'SACCUSSALIS']);
        $fakePayload = new \Infrastructure\QRcodes\Contracts\QrPayload(
            qrType: 'STATIC', merchantOrPayeeId: 'TEST-MERCHANT-001', amount: 50.00,
            currency: 'BWP', reference: 'TESTREF001', institution: 'ZURUBANK'
        );
        $encoded = $qrAdapter->encode($fakePayload);
        $matches = $qrAdapter->matches($encoded);
        $decoded = $matches ? $qrAdapter->decode($encoded) : null;

        $report['section_5_qr_synthetic_roundtrip'] = [
            'status' => 'OK',
            'matches_own_format' => $matches ? 'YES' : 'NO',
            'decoded_institution' => $decoded?->institution,
            'decoded_amount' => $decoded?->amount,
            'roundtrip_correct' => ($decoded && $decoded->institution === 'ZURUBANK' && (float)$decoded->amount === 50.00) ? 'YES' : 'NO',
        ];
    } else {
        $report['section_5_qr_synthetic_roundtrip']['status'] = 'EmvQrAdapter or QrPayload not resolvable - see Section 6';
    }
} catch (\Throwable $e) {
    $report['section_5_qr_synthetic_roundtrip']['error'] = $e->getMessage();
    $report['section_5_qr_synthetic_roundtrip']['trace'] = $e->getTraceAsString();
}

// ============================================================
// SECTION 6: FILE / CLASS RESOLUTION (case-sensitivity safe)
// ============================================================

$report['section_6_file_class_resolution'] = ['how' => 'scandir-based exact-case check on disk, independent of composer classmap caching, plus class_exists confirmation'];

$filesToCheck = [
    ['dir' => 'src/Infrastructure/Adapters', 'file' => 'InstitutionAdapterInterface.php'],
    ['dir' => 'src/Infrastructure/Adapters', 'file' => 'InstitutionAdapterFactory.php'],
    ['dir' => 'src/Infrastructure/Adapters', 'file' => 'GenericInstitutionAdapter.php'],
    ['dir' => 'src/Infrastructure/Banks', 'file' => 'GenericBankClient.php'],
    ['dir' => 'src/Infrastructure/SMS/Contracts', 'file' => 'ProviderInterface.php'],
    ['dir' => 'src/Infrastructure/SMS', 'file' => 'SmsGatewayClient.php'],
    ['dir' => 'src/Infrastructure/USSD/Contracts', 'file' => 'UssdGatewayAdapterInterface.php'],
    ['dir' => 'src/Infrastructure/USSD/Contracts', 'file' => 'UssdGatewayAdapter.php'],
    ['dir' => 'src/Infrastructure/USSD/Contracts', 'file' => 'UssdSessionRequest.php'],
    ['dir' => 'src/Infrastructure/USSD/Contracts', 'file' => 'UssdSessionResponse.php'],
    ['dir' => 'src/Infrastructure/QRcodes/Contracts', 'file' => 'QrAdapterInterface.php'],
    ['dir' => 'src/Infrastructure/QRcodes', 'file' => 'EmvQrAdapter.php'],
    ['dir' => 'src/Infrastructure/QRcodes', 'file' => 'QrCodeService.php'],
    ['dir' => 'src/Infrastructure', 'file' => 'ChannelAdapterFactory.php'],
    ['dir' => 'src/Domain/Services/MultiSource', 'file' => 'PoolCoordinator.php'],
    ['dir' => 'src/Domain/Services/MultiSource', 'file' => 'PoolStateMachine.php'],
    ['dir' => 'src/Domain/Services/MultiSource', 'file' => 'MultiSourceSwapOrchestrator.php'],
];

foreach ($filesToCheck as $f) {
    $key = $f['dir'] . '/' . $f['file'];
    $report['section_6_file_class_resolution'][$key] = checkExactFilenameCase($f['dir'], $f['file']);
}

$classesToResolve = [
    'Infrastructure\Adapters\InstitutionAdapterInterface',
    'Infrastructure\Adapters\InstitutionAdapterFactory',
    'Infrastructure\Adapters\GenericInstitutionAdapter',
    'Infrastructure\Banks\GenericBankClient',
    'Infrastructure\SMS\Contracts\ProviderInterface',
    'Infrastructure\SMS\SmsGatewayClient',
    'Infrastructure\USSD\Contracts\UssdGatewayAdapterInterface',
    'Infrastructure\USSD\Contracts\UssdGatewayAdapter',
    'Infrastructure\USSD\Contracts\UssdSessionRequest',
    'Infrastructure\USSD\Contracts\UssdSessionResponse',
    'Infrastructure\QRcodes\Contracts\QrAdapterInterface',
    'Infrastructure\QRcodes\EmvQrAdapter',
    'Infrastructure\QRcodes\QrCodeService',
    'Infrastructure\ChannelAdapterFactory',
    'Domain\Services\MultiSource\PoolCoordinator',
    'Domain\Services\MultiSource\PoolStateMachine',
    'Domain\Services\MultiSource\MultiSourceSwapOrchestrator',
];
foreach ($classesToResolve as $fqcn) {
    $key = 'class::' . $fqcn;
    try {
        $report['section_6_file_class_resolution'][$key] = (class_exists($fqcn) || interface_exists($fqcn)) ? 'RESOLVED' : 'NOT FOUND';
    } catch (\Throwable $e) {
        $report['section_6_file_class_resolution'][$key] = 'FATAL ON LOAD: ' . $e->getMessage();
    }
}

// ============================================================
// SECTION 7: MULTI-SOURCE POOL CONTRACT MATCHING
// ============================================================

$report['section_7_multisource_contract_matching'] = ['how' => 'same mechanism as 2b, applied to PoolCoordinator'];
if ($poolCoordinatorSrc === null || $adapterSrc === null) {
    $report['section_7_multisource_contract_matching']['error'] = 'Could not read PoolCoordinator.php or GenericInstitutionAdapter.php';
} else {
    $poolCallSites = [
        'verifySources'              => 'verifyAsset',
        'placeHolds'                 => 'placeHold',
        'executeDestination'         => 'credit',
        'debitSources'               => 'debit',
    ];
    foreach ($poolCallSites as $poolMethod => $adapterMethod) {
        $entry = checkContractPair(
            extractMethodBody($poolCoordinatorSrc, $poolMethod),
            extractMethodBody($adapterSrc, $adapterMethod),
            $poolMethod, $adapterMethod
        );
        $entry = array_merge(['pool_method' => $poolMethod, 'adapter_method' => $adapterMethod], $entry);
        $report['section_7_multisource_contract_matching'][$poolMethod] = $entry;
    }

    $rollbackBody = extractMethodBody($poolCoordinatorSrc, 'rollbackHolds');
    if ($rollbackBody !== null) {
        $hasRealRelease = str_contains($rollbackBody, '->releaseHold(') || str_contains($rollbackBody, 'swapService->releaseHold');
        $report['section_7_multisource_contract_matching']['_rollback_check'] = $hasRealRelease
            ? 'OK - rollback calls the real institution release via SwapService::releaseHold()'
            : 'MISSING - rollback still only touches local bookkeeping, real holds never released on partial pool failure';
    } else {
        $report['section_7_multisource_contract_matching']['_rollback_check'] = 'rollbackHolds method not found';
    }

    $placeHoldsBody = extractMethodBody($poolCoordinatorSrc, 'placeHolds');
    if ($placeHoldsBody !== null) {
        $capturesHeldSources = str_contains($placeHoldsBody, '&$heldSources') || str_contains($placeHoldsBody, 'heldSources[]');
        $report['section_7_multisource_contract_matching']['_held_sources_tracking'] = $capturesHeldSources
            ? 'OK - placeHolds tracks held sources for rollback'
            : 'MISSING - placeHolds does not track held sources for rollback';
    }
}

// ============================================================
// SECTION 8: OAUTH / SOURCE-LINKING CONTRACT MATCHING (IMPROVED)
// ============================================================

$report['section_8_oauth_source_linking'] = ['how' => 'extracts ALL success-check patterns from SwapService and matching return keys from GenericBankClient'];

if ($swapServiceSrc !== null && $bankClientSrc !== null) {
    $oauthCallSites = [
        'initiateSourceLink'  => 'initiateSourceLink',
        'verifySourceLink'    => 'verifySourceLink',
        'refreshHookedSource' => 'refreshSourceToken',
        'revokeHookedSource'  => 'revokeSourceToken',
    ];
    
    // Pass-through methods that might legitimately not check result keys
    $passThroughMethods = ['refreshHookedSource', 'revokeHookedSource'];
    
    foreach ($oauthCallSites as $swapMethod => $clientMethod) {
        $swapBody = extractMethodBody($swapServiceSrc, $swapMethod);
        $clientBody = extractMethodBody($bankClientSrc, $clientMethod);
        
        if ($swapBody === null || $clientBody === null) {
            $report['section_8_oauth_source_linking'][$swapMethod] = [
                'status' => 'METHOD_NOT_FOUND',
                'swap_method' => $swapMethod,
                'bank_client_method' => $clientMethod,
            ];
            continue;
        }
        
        $checkedKeys = extractResultKeyChecks($swapBody);
        $returnedKeys = extractReturnedKeys($clientBody);
        $matched = array_intersect($checkedKeys, $returnedKeys);
        
        // Determine status with improved logic
        if (empty($checkedKeys)) {
            // No success checks found
            if (in_array($swapMethod, $passThroughMethods, true)) {
                $status = 'PASS_THROUGH_OK';
                $recommendation = 'Pass-through method - no success check expected, but verify manually';
            } else {
                $status = 'REQUIRES_MANUAL_REVIEW';
                $recommendation = 'No success-check pattern found in ' . $swapMethod . ' - review manually';
            }
        } elseif (empty($matched)) {
            $status = 'CRITICAL_MISMATCH';
            $recommendation = 'Checks keys that callee never returns - will always fail';
        } else {
            $status = 'OK';
            $recommendation = 'Success checks match callee returns';
        }
        
        $report['section_8_oauth_source_linking'][$swapMethod] = [
            'status' => $status,
            'swap_method' => $swapMethod,
            'bank_client_method' => $clientMethod,
            'checks_keys' => $checkedKeys,
            'returns_keys' => $returnedKeys,
            'matched' => array_values($matched),
            'recommendation' => $recommendation,
        ];
    }
} else {
    $report['section_8_oauth_source_linking']['error'] = 'Missing source files';
}

// ============================================================
// SECTION 9: FOREX / FEE CONTRACT MATCHING
// ============================================================

$feeServiceSrc = readSource('src/Infrastructure/Forex/FeeService.php')
    ?? readSource('src/Domain/Services/FeeService.php')
    ?? readSource('src/Infrastructure/Forex/ForexService.php');

$report['section_9_forex_fee_contract'] = ['how' => 'diffs SwapService fee/forex reads against FeeService/ForexService actual returned keys'];
if ($swapServiceSrc === null || $feeServiceSrc === null) {
    $report['section_9_forex_fee_contract']['error'] = 'FeeService.php not found at any expected path - update path once located';
} else {
    $feeCheckBody = extractMethodBody($swapServiceSrc, 'calculateFeesWithDetails');
    $feeCalcBody = extractMethodBody($feeServiceSrc, 'calculateFees');
    if ($feeCheckBody === null || $feeCalcBody === null) {
        $report['section_9_forex_fee_contract']['status'] = 'One or both methods not found under expected names';
    } else {
        preg_match_all("/\\\$feeResult\\['([\\w]+)'\\](?:\\['([\\w]+)'\\])?/", $feeCheckBody, $fm, PREG_SET_ORDER);
        $checkedPaths = [];
        foreach ($fm as $match) {
            $checkedPaths[] = isset($match[2]) && $match[2] !== '' ? "{$match[1]}.{$match[2]}" : $match[1];
        }
        $report['section_9_forex_fee_contract']['swap_service_reads'] = array_values(array_unique($checkedPaths));
        $report['section_9_forex_fee_contract']['fee_service_top_level_returns'] = extractReturnedKeys($feeCalcBody);
        $report['section_9_forex_fee_contract']['_note'] = 'Nested paths (e.g. forex.rate) need manual eyeball beyond one level.';
    }
}

// ============================================================
// SUMMARY (IMPROVED)
// ============================================================

$criticalIssues = [];

// Check single-source contracts
foreach ($report['section_2b_single_source_contract_matching'] as $k => $v) {
    if (is_array($v) && isset($v['MISMATCH']) && str_starts_with($v['MISMATCH'], 'CRITICAL')) {
        $criticalIssues[] = "single-source: {$k}";
    }
}

// Check multi-source contracts
foreach ($report['section_7_multisource_contract_matching'] as $k => $v) {
    if (is_array($v) && isset($v['MISMATCH']) && str_starts_with($v['MISMATCH'], 'CRITICAL')) {
        $criticalIssues[] = "multi-source: {$k}";
    }
}

// Check OAuth contracts - now catches REQUIRES_MANUAL_REVIEW as well
foreach ($report['section_8_oauth_source_linking'] as $k => $v) {
    if (is_array($v)) {
        if (isset($v['status']) && ($v['status'] === 'CRITICAL_MISMATCH' || $v['status'] === 'REQUIRES_MANUAL_REVIEW')) {
            $criticalIssues[] = "oauth: {$k} -> {$v['status']}: {$v['recommendation']}";
        }
    }
}

// Check file/class resolution - FIXED: skip 'how' key
foreach ($report['section_6_file_class_resolution'] as $k => $v) {
    // Skip the 'how' description string
    if ($k === 'how') continue;
    
    $status = is_array($v) ? ($v['status'] ?? '') : $v;
    if ($status !== 'OK' && $status !== 'RESOLVED') {
        $criticalIssues[] = "resolution: {$k} -> {$status}";
    }
}

$report['summary'] = [
    'critical_issues_found' => count($criticalIssues),
    'issues' => $criticalIssues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
