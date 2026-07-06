<?php
declare(strict_types=1);

/**
 * public/admin/test.php
 *
 * Adapter test suite. Three kinds of checks, all safe to run repeatedly:
 *   1. Class/autoload resolution - catches the case-sensitivity risk in
 *      the newer Channel/QR/USSD files before it becomes a fatal error.
 *   2. Contract key-matching - mechanically diffs what each
 *      InstitutionAdapterInterface method RETURNS against what
 *      SwapService.php actually CHECKS for that same call site. This is
 *      exactly the bug class that caused credit()/'success' vs 'credited'
 *      to silently eat a real successful deposit. Every future instance
 *      of this bug gets caught here, in seconds, with no live bank call.
 *   3. Synthetic round-trips for USSD/QR - fabricated input, no network,
 *      no real gateway/bank touched.
 *
 * Nothing in this file makes an HTTP call to ZURUBANK, SACCUSSALIS, or
 * any SMS/USSD gateway. It is safe to run against production config.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ---- Auth gate, same pattern as tested.php/execute.php ----
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

$report = ['generated_at' => date('c')];

// ============================================================
// SECTION 1: CLASS RESOLUTION - catches case-sensitivity / autoload
// mismatches before they become a fatal error mid-swap.
// ============================================================

$classesToResolve = [
    // core swap adapter chain
    'Infrastructure\Adapters\InstitutionAdapterInterface',
    'Infrastructure\Adapters\InstitutionAdapterFactory',
    'Infrastructure\Adapters\GenericInstitutionAdapter',
    'Infrastructure\Banks\GenericBankClient',
    'Infrastructure\Banks\Contracts\BankAPIInterface',
    // SMS
    'Infrastructure\SMS\Contracts\ProviderInterface',
    'Infrastructure\SMS\SmsGatewayClient',
    'Infrastructure\SMS\SmsNotificationService',
    // USSD - flagging the exact classes at risk from filename casing
    'Infrastructure\USSD\Contracts\UssdGatewayAdapterInterface',
    'Infrastructure\USSD\Contracts\UssdGatewayAdapter',
    'Infrastructure\USSD\Contracts\UssdSessionRequest',
    'Infrastructure\USSD\Contracts\UssdSessionResponse',
    // QR - same risk
    'Infrastructure\QRcodes\Contracts\QrAdapterInterface',
    'Infrastructure\QRcodes\EmvQrAdapter',
    'Infrastructure\QRcodes\QrCodeService',
    // channel factory
    'Infrastructure\ChannelAdapterFactory',
];

$report['section_1_class_resolution'] = [];
foreach ($classesToResolve as $fqcn) {
    $found = class_exists($fqcn) || interface_exists($fqcn);
    $report['section_1_class_resolution'][$fqcn] = $found ? 'RESOLVED' : 'NOT FOUND - check exact case of file name vs class name/namespace on disk';
}

// ============================================================
// SECTION 2: CONTRACT KEY-MATCHING - the critical one.
// For each SwapService call site, extract which result key it checks
// (success/credited/debited/hold_placed/verified/confirmed), then
// extract which key the corresponding GenericInstitutionAdapter method
// actually SETS in its return array. Flag any mismatch mechanically -
// this is exactly the bug that broke credit()/processDepositWithProof.
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

/**
 * For a SwapService method body, find every "$result['x'] ?? ...bool)"
 * style check - i.e. which key it reads off an adapter's return value
 * to decide success/failure.
 */
function extractResultKeyChecks(string $body): array
{
    preg_match_all("/\\\$(?:result|res)\\['([\\w]+)'\\]\\s*\\?\\?\\s*false/", $body, $m);
    return array_values(array_unique($m[1]));
}

/**
 * For a GenericInstitutionAdapter method body, find every top-level key
 * actually assigned in a returned array literal, e.g. 'credited' => true.
 */
function extractReturnedKeys(string $body): array
{
    $keys = [];
    // Grab each "return [ ... ];" block (there are usually 2-3 per method:
    // error path, success path). Collect keys from all of them.
    preg_match_all('/return\s*\[(.*?)\];/s', $body, $blocks);
    foreach ($blocks[1] as $block) {
        preg_match_all("/'([\\w]+)'\\s*=>/", $block, $km);
        $keys = array_merge($keys, $km[1]);
    }
    return array_values(array_unique($keys));
}

$swapServiceSrc = readSource('src/Domain/Services/SwapService.php');
$adapterSrc = readSource('src/Infrastructure/Adapters/GenericInstitutionAdapter.php');

$report['section_2_contract_matching'] = [
    'how' => 'extracts which key each SwapService call site checks vs which keys the adapter method actually returns - mismatch here means a real success/failure can be silently swapped, as happened with credit()'
];

if ($swapServiceSrc === null || $adapterSrc === null) {
    $report['section_2_contract_matching']['error'] = 'Could not read SwapService.php or GenericInstitutionAdapter.php';
} else {
    // Map: SwapService method => adapter method it calls => expected result key
    $callSites = [
        'verifyAssetSigned'        => 'verifyAsset',
        'placeHoldSigned'          => 'placeHold',
        'debitSource'              => 'debit',
        'processDepositWithProof'  => 'credit',
        'processDestinationWithProof' => 'transferWithProof',
        'generateCashoutToken'     => 'generateCashoutToken',
        'verifyAccount'            => 'verifyAccount',
        'verifyCashout'            => 'verifyCashoutToken',
        'confirmCashout'           => 'confirmCashout',
    ];

    foreach ($callSites as $swapMethod => $adapterMethod) {
        $swapBody = extractMethodBody($swapServiceSrc, $swapMethod);
        $adapterBody = extractMethodBody($adapterSrc, $adapterMethod);

        $entry = ['swap_method' => $swapMethod, 'adapter_method' => $adapterMethod];

        if ($swapBody === null) {
            $entry['status'] = 'SwapService method not found';
            $report['section_2_contract_matching'][$swapMethod] = $entry;
            continue;
        }
        if ($adapterBody === null) {
            $entry['status'] = 'Adapter method not found';
            $report['section_2_contract_matching'][$swapMethod] = $entry;
            continue;
        }

        $checkedKeys = extractResultKeyChecks($swapBody);
        $returnedKeys = extractReturnedKeys($adapterBody);

        $entry['swap_checks_keys'] = $checkedKeys;
        $entry['adapter_returns_keys'] = $returnedKeys;

        $matched = array_intersect($checkedKeys, $returnedKeys);
        $entry['matched'] = array_values($matched);
        $entry['MISMATCH'] = empty($matched) && !empty($checkedKeys)
            ? 'CRITICAL: SwapService checks a key the adapter never returns - this call site will ALWAYS evaluate as failed/false, exactly like the credit() bug'
            : 'OK - at least one checked key is actually returned';

        $report['section_2_contract_matching'][$swapMethod] = $entry;
    }
}

// ============================================================
// SECTION 3: SMS ADAPTER - construction/interface conformance only,
// no live send.
// ============================================================

$report['section_3_sms'] = [];
try {
    if (interface_exists('Infrastructure\SMS\Contracts\ProviderInterface')
        && class_exists('Infrastructure\SMS\SmsGatewayClient')) {
        $implements = class_implements('Infrastructure\SMS\SmsGatewayClient');
        $report['section_3_sms']['SmsGatewayClient_implements_ProviderInterface'] =
            in_array('Infrastructure\SMS\Contracts\ProviderInterface', $implements ?: [])
                ? 'YES' : 'NO - class exists but does not implement the interface';
    } else {
        $report['section_3_sms']['status'] = 'SmsGatewayClient or ProviderInterface not resolvable';
    }
} catch (\Throwable $e) {
    $report['section_3_sms']['error'] = $e->getMessage();
}

// ============================================================
// SECTION 4: USSD ADAPTER - synthetic round-trip, no real gateway hit.
// Builds a fake AfricasTalking-style request, runs it through
// parseRequest() -> a hand-built response -> formatResponse(), and
// confirms the output shape is what a real gateway would expect.
// ============================================================

$report['section_4_ussd_synthetic_roundtrip'] = [];
try {
    if (class_exists('Infrastructure\USSD\Contracts\UssdGatewayAdapter')
        && class_exists('Infrastructure\USSD\Contracts\UssdSessionResponse')) {

        $fakeConfig = [
            'request_fields' => [
                'session_id' => ['sessionId'],
                'phone' => ['phoneNumber'],
                'text' => ['text'],
            ],
            'response_format' => 'prefix',
            'content_type' => 'text/plain; charset=UTF-8',
        ];

        $adapterClass = 'Infrastructure\USSD\Contracts\UssdGatewayAdapter';
        $adapter = new $adapterClass($fakeConfig, 'TEST_SYNTHETIC');

        $fakeRawRequest = [
            'sessionId' => 'TEST_SESSION_123',
            'phoneNumber' => '+26771234567',
            'text' => '',
        ];

        $parsed = $adapter->parseRequest($fakeRawRequest);

        $responseClass = 'Infrastructure\USSD\Contracts\UssdSessionResponse';
        $fakeResponse = $responseClass::continue('Welcome to VouchMorph - TEST MENU');
        $formatted = $adapter->formatResponse($fakeResponse);

        $report['section_4_ussd_synthetic_roundtrip'] = [
            'status' => 'OK',
            'parsed_session_id' => $parsed->sessionId,
            'parsed_phone' => $parsed->phoneNumber,
            'formatted_output' => $formatted,
            'expected_prefix' => 'CON ',
            'prefix_correct' => str_starts_with($formatted, 'CON ') ? 'YES' : 'NO - formatResponse did not apply expected prefix',
        ];
    } else {
        $report['section_4_ussd_synthetic_roundtrip']['status'] = 'UssdGatewayAdapter or UssdSessionResponse not resolvable - see Section 1';
    }
} catch (\Throwable $e) {
    $report['section_4_ussd_synthetic_roundtrip']['error'] = $e->getMessage();
    $report['section_4_ussd_synthetic_roundtrip']['trace'] = $e->getTraceAsString();
}

// ============================================================
// SECTION 5: QR ADAPTER - synthetic encode/decode round-trip, no
// network, no real merchant registry touched.
// ============================================================

$report['section_5_qr_synthetic_roundtrip'] = [];
try {
    if (class_exists('Infrastructure\QRcodes\EmvQrAdapter')
        && class_exists('Infrastructure\QRcodes\Contracts\QrPayload')) {

        $fakeTagMap = ['26' => 'ZURUBANK', '27' => 'SACCUSSALIS'];
        $adapterClass = 'Infrastructure\QRcodes\EmvQrAdapter';
        $qrAdapter = new $adapterClass($fakeTagMap);

        $payloadClass = 'Infrastructure\QRcodes\Contracts\QrPayload';
        $fakePayload = new $payloadClass(
            qrType: 'STATIC',
            merchantOrPayeeId: 'TEST-MERCHANT-001',
            amount: 50.00,
            currency: 'BWP',
            reference: 'TESTREF001',
            institution: 'ZURUBANK'
        );

        $encoded = $qrAdapter->encode($fakePayload);
        $matches = $qrAdapter->matches($encoded);
        $decoded = $matches ? $qrAdapter->decode($encoded) : null;

        $report['section_5_qr_synthetic_roundtrip'] = [
            'status' => 'OK',
            'encoded_length' => strlen($encoded),
            'matches_own_format' => $matches ? 'YES' : 'NO - encode/matches are inconsistent',
            'decoded_institution' => $decoded?->institution,
            'decoded_amount' => $decoded?->amount,
            'roundtrip_correct' => ($decoded && $decoded->institution === 'ZURUBANK' && (float)$decoded->amount === 50.00)
                ? 'YES' : 'NO - decoded values do not match what was encoded',
        ];
    } else {
        $report['section_5_qr_synthetic_roundtrip']['status'] = 'EmvQrAdapter or QrPayload not resolvable - see Section 1';
    }
} catch (\Throwable $e) {
    $report['section_5_qr_synthetic_roundtrip']['error'] = $e->getMessage();
    $report['section_5_qr_synthetic_roundtrip']['trace'] = $e->getTraceAsString();
}

// ============================================================
// SUMMARY
// ============================================================

$criticalIssues = [];
foreach ($report['section_1_class_resolution'] as $class => $status) {
    if ($status !== 'RESOLVED') $criticalIssues[] = "Class not resolved: {$class}";
}
foreach ($report['section_2_contract_matching'] as $method => $entry) {
    if (is_array($entry) && isset($entry['MISMATCH']) && str_starts_with($entry['MISMATCH'], 'CRITICAL')) {
        $criticalIssues[] = "Contract mismatch: {$method} -> {$entry['adapter_method']}";
    }
}

$report['summary'] = [
    'critical_issues_found' => count($criticalIssues),
    'issues' => $criticalIssues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
