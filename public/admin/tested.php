<?php
declare(strict_types=1);

/**
 * public/admin/tested.php
 *
 * Mechanical introspection tool. Every fact shown here is extracted from
 * the actual deployed source files on this server - by regex-parsing real
 * method bodies and by Reflection into actually-instantiated real objects.
 * Nothing here is typed from memory or assumption. If this page is wrong,
 * it's because the source files themselves say something different than
 * expected - which is itself the useful signal.
 *
 * Gate: same X-API-Key check as execute.php. Tighten to real admin session
 * auth (AdminAuth.php) before leaving this deployed long-term - this is a
 * diagnostic tool, not meant to sit open indefinitely.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ---- Auth gate (same pattern as execute.php) ----
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
// SECTION 1: FIELD ALIAS VOCABULARY - extracted from SwapService.php source text
// ============================================================

function readSource(string $relativePath): ?string
{
    $path = ROOT_PATH . '/' . ltrim($relativePath, '/');
    return file_exists($path) ? file_get_contents($path) : null;
}

/**
 * Extract a method's body by brace-counting from its declaration.
 * Returns null if the method isn't found - itself useful information
 * (means the method was renamed/removed since this tool was written).
 */
function extractMethodBody(string $source, string $methodName): ?string
{
    if (!preg_match('/(?:private|protected|public)\s+function\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $i = $start;
    $len = strlen($source);
    while ($i < $len && $depth > 0) {
        if ($source[$i] === '{') $depth++;
        elseif ($source[$i] === '}') $depth--;
        $i++;
    }
    return substr($source, $start, $i - $start - 1);
}

/**
 * Pull every $var['key'] / $var['key']['nested'] access path out of a
 * method body - this IS the actual alias vocabulary the code accepts,
 * mechanically, not from memory.
 */
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

$swapServiceSrc = readSource('src/Domain/Services/SwapService.php');
$report['section_1_field_vocabulary'] = ['how' => 'regex-extracted from the actual method bodies in SwapService.php on this deployment right now'];

if ($swapServiceSrc === null) {
    $report['section_1_field_vocabulary']['error'] = 'SwapService.php not found at expected path';
} else {
    $methodsToInspect = [
        'extractSourceInstitution',
        'extractDestinationInstitution',
        'extractDestinationAssetType',
        'extractSourceIdentifier',
        'extractDestinationIdentifier',
        'extractBeneficiaryPhone',
        'forwardPin',
        'validateInstitutions',
    ];
    foreach ($methodsToInspect as $method) {
        $body = extractMethodBody($swapServiceSrc, $method);
        if ($body === null) {
            $report['section_1_field_vocabulary'][$method] = 'METHOD NOT FOUND - renamed or removed since expected';
            continue;
        }
        $report['section_1_field_vocabulary'][$method] = extractPayloadKeyChains($body);
    }

    // Also extract which swap_type values the dispatch match() actually handles -
    // catches things like "VOUCHER" silently falling into default().
    $dispatchBody = extractMethodBody($swapServiceSrc, 'executeAtomicSwap');
    if ($dispatchBody !== null) {
        preg_match("/match\\(\\\$swapType\\)\\s*\\{(.*?)\\};/s", $dispatchBody, $dm);
        if (isset($dm[1])) {
            preg_match_all("/'([A-Z_]+)'\\s*=>/", $dm[1], $cases);
            $report['section_1_field_vocabulary']['_valid_swap_type_values'] = array_values(array_unique($cases[1]));
            $report['section_1_field_vocabulary']['_swap_type_note'] =
                'Any swap_type NOT in this list silently falls through to the default() branch (executeSignedStandardSwap) - it will NOT error, it will just run a different code path than you intended.';
        }
    }
}

// ============================================================
// SECTION 2: ACTION -> ENDPOINT MAP - extracted from GenericBankClient.php + real instantiated objects
// ============================================================

$bankClientSrc = readSource('src/Infrastructure/Banks/GenericBankClient.php');
$report['section_2_action_endpoint_map'] = ['how' => 'regex-extracted yamlPathMap from GenericBankClient.php, cross-checked against a REAL instantiated GenericBankClient object per institution via Reflection into its actual loaded YAML properties'];

if ($bankClientSrc === null) {
    $report['section_2_action_endpoint_map']['error'] = 'GenericBankClient.php not found';
} else {
    $getEndpointBody = extractMethodBody($bankClientSrc, 'getEndpoint');
    if ($getEndpointBody !== null && preg_match('/\$yamlPathMap\s*=\s*\[(.*?)\];/s', $getEndpointBody, $ym)) {
        preg_match_all("/'([\\w]+)'\\s*=>\\s*\\['([\\w]+)',\\s*'([\\w]+)'\\]/", $ym[1], $rows, PREG_SET_ORDER);
        $actionMap = [];
        foreach ($rows as $row) {
            $actionMap[$row[1]] = ['section' => $row[2], 'key' => $row[3]];
        }
        $report['section_2_action_endpoint_map']['method_name_to_yaml_path'] = $actionMap;
    }

    // Extract every "$this->send('action', ...)" call across all public methods,
    // so we know which action string each PUBLIC method actually sends -
    // this catches cases like debitHold() calling debitFunds() internally.
    preg_match_all('/public function (\w+)\(/', $bankClientSrc, $publicMethods);
    $methodActions = [];
    foreach ($publicMethods[1] as $method) {
        $body = extractMethodBody($bankClientSrc, $method);
        if ($body === null) continue;
        $entry = [];
        if (preg_match("/\\\$this->send\\('([a-z_]+)'/", $body, $sm)) {
            $entry['sends_action'] = $sm[1];
        }
        if (preg_match('/\$this->(\w+)\(\$\w+\)/', $body, $delegate) && !isset($entry['sends_action'])) {
            $entry['delegates_to'] = $delegate[1];
        }
        // Detect payload reconstruction: does this method build a NEW array
        // literal before calling send/another method, vs pass the incoming
        // $payload straight through? This is exactly the bug class we hit
        // twice already (debitHold, MessageAwareBankClient).
        $entry['builds_new_array_literal'] = (bool)preg_match('/\$\w+Payload\s*=\s*\[/', $body);
        if ($entry) $methodActions[$method] = $entry;
    }
    $report['section_2_action_endpoint_map']['public_method_behavior'] = $methodActions;
    $report['section_2_action_endpoint_map']['_note'] =
        'builds_new_array_literal=true means this method constructs a fresh array instead of passing the caller\'s payload through - check whether the fields it constructs match what the caller actually sent, or fields get silently dropped (as debitHold did).';
}

// Real YAML resolution per institution - via Reflection into an actually-constructed object
try {
    $fullCountryConfig = \Core\Config\LoadCountry::getConfig();
    $participants = $fullCountryConfig['participants'] ?? [];
    $yamlResolution = [];

    foreach ($participants as $code => $participantConfig) {
        try {
            $client = new \Infrastructure\Banks\GenericBankClient($participantConfig);
            $ref = new ReflectionClass($client);

            $baseUrlProp = $ref->getProperty('yamlBaseUrl');
            $baseUrlProp->setAccessible(true);
            $endpointsProp = $ref->getProperty('yamlEndpoints');
            $endpointsProp->setAccessible(true);

            $yamlResolution[$code] = [
                'resolved_base_url' => $baseUrlProp->getValue($client),
                'resolved_endpoints' => $endpointsProp->getValue($client),
            ];
        } catch (\Throwable $e) {
            $yamlResolution[$code] = ['error' => $e->getMessage()];
        }
    }
    $report['section_2_action_endpoint_map']['real_resolved_per_institution'] = $yamlResolution;
} catch (\Throwable $e) {
    $report['section_2_action_endpoint_map']['institution_resolution_error'] = $e->getMessage();
}

// ============================================================
// SECTION 3: CLASS/METHOD WIRING - Reflection-based, catches type mismatches
// before a real request does
// ============================================================

$report['section_3_wiring_check'] = ['how' => 'ReflectionClass/ReflectionMethod against the actually-loaded classes via autoload'];

$classesToCheck = [
    'Domain\Services\SwapService',
    'Infrastructure\Adapters\InstitutionAdapterFactory',
    'Infrastructure\Adapters\InstitutionAdapterInterface',
    'Infrastructure\Adapters\GenericInstitutionAdapter',
    'Infrastructure\Banks\GenericBankClient',
    'Infrastructure\Banks\Contracts\BankAPIInterface',
];

foreach ($classesToCheck as $className) {
    $report['section_3_wiring_check'][$className] = class_exists($className) || interface_exists($className)
        ? 'FOUND'
        : 'MISSING - autoload cannot resolve this class. Anything depending on it will fatal at runtime.';
}

// Specifically re-check the exact bug we already hit once: does
// GenericInstitutionAdapter's constructor accept whatever logger
// SwapService actually constructs by default?
if (class_exists('Infrastructure\Adapters\GenericInstitutionAdapter')) {
    $ctor = new ReflectionMethod('Infrastructure\Adapters\GenericInstitutionAdapter', '__construct');
    $params = [];
    foreach ($ctor->getParameters() as $p) {
        $type = $p->getType();
        $params[$p->getName()] = $type ? ($type->allowsNull() ? '?' : '') . $type->getName() : 'untyped';
    }
    $report['section_3_wiring_check']['GenericInstitutionAdapter::__construct_params'] = $params;
    $loggerType = $params['logger'] ?? null;
    $report['section_3_wiring_check']['logger_param_check'] =
        ($loggerType === 'untyped')
            ? 'OK - untyped, will accept any object SwapService passes (including its anonymous default logger)'
            : "WARNING - typed as {$loggerType}. If SwapService's default logger doesn't implement this, every call will fatal exactly like the previous psr/log bug.";
}

// Interface vs implementation signature compatibility
if (interface_exists('Infrastructure\Adapters\InstitutionAdapterInterface') && class_exists('Infrastructure\Adapters\GenericInstitutionAdapter')) {
    $interface = new ReflectionClass('Infrastructure\Adapters\InstitutionAdapterInterface');
    $impl = new ReflectionClass('Infrastructure\Adapters\GenericInstitutionAdapter');
    $missing = [];
    foreach ($interface->getMethods() as $ifaceMethod) {
        if (!$impl->hasMethod($ifaceMethod->getName())) {
            $missing[] = $ifaceMethod->getName();
        }
    }
    $report['section_3_wiring_check']['interface_methods_not_implemented'] = $missing ?: 'none - all interface methods implemented';
}

// ============================================================
// SECTION 4: LITERAL PAYLOAD SHAPES - extracted directly from each station's
// own array-building code in SwapService.php, so you see exactly what gets sent
// ============================================================

$report['section_4_actual_payload_literals'] = ['how' => 'regex-extracted array literals from the actual private station methods in SwapService.php'];

if ($swapServiceSrc !== null) {
    $stationMethods = [
        'verifyAssetSigned', 'placeHoldSigned', 'debitSource', 'generateCashoutToken',
        'processDepositWithProof', 'verifyAccount',
    ];
    foreach ($stationMethods as $method) {
        $body = extractMethodBody($swapServiceSrc, $method);
        if ($body === null) {
            $report['section_4_actual_payload_literals'][$method] = 'METHOD NOT FOUND';
            continue;
        }
        // Grab the first array literal assigned to a *Payload variable
        if (preg_match('/\$\w*[Pp]ayload\s*=\s*\[(.*?)\n(?:\s*)\];/s', $body, $am)) {
            preg_match_all("/'([\\w]+)'\\s*=>/", $am[1], $keys);
            $report['section_4_actual_payload_literals'][$method] = array_values(array_unique($keys[1]));
        } else {
            $report['section_4_actual_payload_literals'][$method] = 'No array literal pattern matched - method may build payload differently than expected';
        }
    }
}

// ============================================================
// SECTION 5: EXPECTED HEADERS - extracted from GenericBankClient::buildHeaders + execute.php's own auth gate
// ============================================================

$report['section_5_headers'] = ['how' => 'regex-extracted from GenericBankClient::buildHeaders() and execute.php auth logic'];

if ($bankClientSrc !== null) {
    $headersBody = extractMethodBody($bankClientSrc, 'buildHeaders');
    if ($headersBody !== null) {
        preg_match_all("/'([\\w-]+):\\s*'/", $headersBody, $hm);
        $report['section_5_headers']['outbound_to_institutions'] = array_values(array_unique($hm[1]));
    }
}

$executeSrc = readSource('public/api/v1/swap/execute.php');
if ($executeSrc !== null) {
    $inboundHeaders = [];
    if (str_contains($executeSrc, 'x-api-key')) $inboundHeaders[] = 'X-API-Key';
    if (str_contains($executeSrc, 'x-country-code')) $inboundHeaders[] = 'X-Country-Code';
    if (str_contains($executeSrc, 'x-country')) $inboundHeaders[] = 'X-Country';
    if (str_contains($executeSrc, 'HTTP_AUTHORIZATION') || str_contains($executeSrc, 'authorization')) $inboundHeaders[] = 'Authorization: Bearer <key>';
    $report['section_5_headers']['inbound_required_by_execute_php'] = $inboundHeaders;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
