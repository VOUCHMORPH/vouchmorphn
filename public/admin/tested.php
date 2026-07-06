<?php
declare(strict_types=1);

/**
 * public/admin/tested.php
 *
 * Adapter test suite with case-sensitivity detection.
 * 
 * FIXED: Now handles file name case mismatches gracefully and provides
 * detailed diagnostics for autoloader issues.
 */

header('Content-Type: application/json; charset=UTF-8');

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/vendor/autoload.php';

// ---- Auth gate ----
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
// HELPER: Scan for actual files to detect case mismatches
// ============================================================

function scanForFile(string $baseDir, string $className): ?string
{
    $parts = explode('\\', $className);
    $fileName = end($parts) . '.php';
    
    // Build path relative to baseDir
    $pathParts = array_slice($parts, 0, -1);
    $dirPath = $baseDir . '/' . implode('/', $pathParts);
    
    if (!is_dir($dirPath)) {
        return null;
    }
    
    // Case-sensitive check first
    $exactPath = $dirPath . '/' . $fileName;
    if (file_exists($exactPath)) {
        return $exactPath;
    }
    
    // Case-insensitive scan
    $files = scandir($dirPath);
    foreach ($files as $file) {
        if (strtolower($file) === strtolower($fileName)) {
            return $dirPath . '/' . $file;
        }
    }
    
    return null;
}

// ============================================================
// SECTION 1: CLASS RESOLUTION with case detection
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

// First, scan the actual filesystem to see what files exist
$srcDir = ROOT_PATH . '/src';
$report['section_1_file_scan'] = [];

foreach ($classesToResolve as $fqcn) {
    $found = class_exists($fqcn) || interface_exists($fqcn);
    $filePath = scanForFile($srcDir, $fqcn);
    
    $entry = [
        'class' => $fqcn,
        'resolved_by_autoloader' => $found ? 'YES' : 'NO',
        'file_on_disk' => $filePath ? 'FOUND' : 'NOT FOUND',
        'file_path' => $filePath ? str_replace($srcDir . '/', '', $filePath) : null,
    ];
    
    if (!$found && $filePath) {
        // File exists but autoloader can't find it - likely case mismatch
        $entry['diagnosis'] = 'FILE EXISTS BUT AUTOLOADER FAILED - Check namespace/class name matches filename exactly.';
        
        // Extract the class name from the file
        $content = file_get_contents($filePath);
        if (preg_match('/\b(?:class|interface|trait)\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $content, $m)) {
            $actualClassName = $m[1];
            $expectedClassName = substr($fqcn, strrpos($fqcn, '\\') + 1);
            if ($actualClassName !== $expectedClassName) {
                $entry['diagnosis'] .= " Class name in file is '{$actualClassName}' but expected '{$expectedClassName}'.";
                $entry['suggested_fix'] = "Rename class in file to '{$expectedClassName}' or rename file to match class name.";
            }
        }
    } elseif (!$found && !$filePath) {
        $entry['diagnosis'] = 'CLASS NOT FOUND - File does not exist in expected location.';
        $entry['suggested_fix'] = "Create file at: src/" . str_replace('\\', '/', $fqcn) . ".php";
    } else {
        $entry['diagnosis'] = 'OK';
    }
    
    $report['section_1_file_scan'][$fqcn] = $entry;
}

// Also do the standard class_exists check for comparison
$report['section_1_class_exists_check'] = [];
foreach ($classesToResolve as $fqcn) {
    $report['section_1_class_exists_check'][$fqcn] = class_exists($fqcn) || interface_exists($fqcn) ? 'RESOLVED' : 'NOT FOUND';
}

// ============================================================
// SECTION 2: CONTRACT KEY-MATCHING
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

function extractResultKeyChecks(string $body): array
{
    preg_match_all("/\\\$(?:result|res)\\['([\\w]+)'\\]\\s*\\?\\?\\s*false/", $body, $m);
    return array_values(array_unique($m[1]));
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

$swapServiceSrc = readSource('src/Domain/Services/SwapService.php');
$adapterSrc = readSource('src/Infrastructure/Adapters/GenericInstitutionAdapter.php');

$report['section_2_contract_matching'] = [
    'how' => 'extracts which key each SwapService call site checks vs which keys the adapter method actually returns - mismatch here means a real success/failure can be silently swapped, as happened with credit()'
];

if ($swapServiceSrc === null || $adapterSrc === null) {
    $report['section_2_contract_matching']['error'] = 'Could not read SwapService.php or GenericInstitutionAdapter.php';
    $report['section_2_contract_matching']['swap_service_exists'] = $swapServiceSrc !== null;
    $report['section_2_contract_matching']['adapter_exists'] = $adapterSrc !== null;
} else {
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
// SECTION 3: SMS ADAPTER
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
// SECTION 4: USSD ADAPTER - with better error handling
// ============================================================

$report['section_4_ussd_synthetic_roundtrip'] = [];
try {
    // Check if the interface exists with case-insensitive fallback
    $interfaceName = 'Infrastructure\USSD\Contracts\UssdGatewayAdapterInterface';
    $adapterName = 'Infrastructure\USSD\Contracts\UssdGatewayAdapter';
    $responseName = 'Infrastructure\USSD\Contracts\UssdSessionResponse';
    
    // Try to load with error suppression to handle case issues
    $interfaceExists = interface_exists($interfaceName, true);
    $adapterExists = class_exists($adapterName, true);
    $responseExists = class_exists($responseName, true);
    
    if ($interfaceExists && $adapterExists && $responseExists) {
        $fakeConfig = [
            'request_fields' => [
                'session_id' => ['sessionId'],
                'phone' => ['phoneNumber'],
                'text' => ['text'],
            ],
            'response_format' => 'prefix',
            'content_type' => 'text/plain; charset=UTF-8',
        ];

        $adapter = new $adapterName($fakeConfig, 'TEST_SYNTHETIC');

        $fakeRawRequest = [
            'sessionId' => 'TEST_SESSION_123',
            'phoneNumber' => '+26771234567',
            'text' => '',
        ];

        $parsed = $adapter->parseRequest($fakeRawRequest);

        $fakeResponse = $responseName::continue('Welcome to VouchMorph - TEST MENU');
        $formatted = $adapter->formatResponse($fakeResponse);

        $report['section_4_ussd_synthetic_roundtrip'] = [
            'status' => 'OK',
            'parsed_session_id' => $parsed->sessionId,
            'parsed_phone' => $parsed->phoneNumber,
            'formatted_output' => $formatted,
            'expected_prefix' => 'CON ',
            'prefix_correct' => str_starts_with($formatted, 'CON ') ? 'YES' : 'NO',
        ];
    } else {
        $report['section_4_ussd_synthetic_roundtrip']['status'] = 'USSD classes not resolvable';
        $report['section_4_ussd_synthetic_roundtrip']['interface_exists'] = $interfaceExists;
        $report['section_4_ussd_synthetic_roundtrip']['adapter_exists'] = $adapterExists;
        $report['section_4_ussd_synthetic_roundtrip']['response_exists'] = $responseExists;
        $report['section_4_ussd_synthetic_roundtrip']['suggestion'] = 'Check file name case in src/Infrastructure/USSD/Contracts/';
    }
} catch (\Throwable $e) {
    $report['section_4_ussd_synthetic_roundtrip']['error'] = $e->getMessage();
    $report['section_4_ussd_synthetic_roundtrip']['trace'] = $e->getTraceAsString();
}

// ============================================================
// SECTION 5: QR ADAPTER - with better error handling
// ============================================================

$report['section_5_qr_synthetic_roundtrip'] = [];
try {
    $adapterName = 'Infrastructure\QRcodes\EmvQrAdapter';
    $payloadName = 'Infrastructure\QRcodes\Contracts\QrPayload';
    
    $adapterExists = class_exists($adapterName, true);
    $payloadExists = class_exists($payloadName, true);
    
    if ($adapterExists && $payloadExists) {
        $fakeTagMap = ['26' => 'ZURUBANK', '27' => 'SACCUSSALIS'];
        $qrAdapter = new $adapterName($fakeTagMap);

        $fakePayload = new $payloadName(
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
            'matches_own_format' => $matches ? 'YES' : 'NO',
            'decoded_institution' => $decoded?->institution,
            'decoded_amount' => $decoded?->amount,
            'roundtrip_correct' => ($decoded && $decoded->institution === 'ZURUBANK' && (float)$decoded->amount === 50.00)
                ? 'YES' : 'NO',
        ];
    } else {
        $report['section_5_qr_synthetic_roundtrip']['status'] = 'QR classes not resolvable';
        $report['section_5_qr_synthetic_roundtrip']['adapter_exists'] = $adapterExists;
        $report['section_5_qr_synthetic_roundtrip']['payload_exists'] = $payloadExists;
        $report['section_5_qr_synthetic_roundtrip']['suggestion'] = 'Check file name case in src/Infrastructure/QRcodes/';
    }
} catch (\Throwable $e) {
    $report['section_5_qr_synthetic_roundtrip']['error'] = $e->getMessage();
    $report['section_5_qr_synthetic_roundtrip']['trace'] = $e->getTraceAsString();
}

// ============================================================
// SECTION 6: Case Sensitivity Diagnostic
// ============================================================

$report['section_6_case_sensitivity_diagnostic'] = [];

// Scan USSD directory
$ussdDir = ROOT_PATH . '/src/Infrastructure/USSD/Contracts';
if (is_dir($ussdDir)) {
    $files = scandir($ussdDir);
    $expectedFiles = [
        'UssdGatewayAdapterInterface.php',
        'UssdGatewayAdapter.php',
        'UssdSessionRequest.php',
        'UssdSessionResponse.php',
    ];
    
    foreach ($expectedFiles as $expected) {
        $found = in_array($expected, $files);
        $caseMatch = false;
        foreach ($files as $file) {
            if (strtolower($file) === strtolower($expected)) {
                $caseMatch = true;
                if ($file !== $expected) {
                    $report['section_6_case_sensitivity_diagnostic']['ussd_' . $expected] = [
                        'status' => 'CASE MISMATCH',
                        'expected' => $expected,
                        'actual' => $file,
                        'fix' => "Rename file to: {$expected}"
                    ];
                } else {
                    $report['section_6_case_sensitivity_diagnostic']['ussd_' . $expected] = 'OK';
                }
                break;
            }
        }
        if (!$found && !$caseMatch) {
            $report['section_6_case_sensitivity_diagnostic']['ussd_' . $expected] = 'MISSING';
        }
    }
}

// Scan QR directory
$qrDir = ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts';
if (is_dir($qrDir)) {
    $files = scandir($qrDir);
    $expectedFiles = [
        'QrAdapterInterface.php',
    ];
    
    foreach ($expectedFiles as $expected) {
        $found = in_array($expected, $files);
        $caseMatch = false;
        foreach ($files as $file) {
            if (strtolower($file) === strtolower($expected)) {
                $caseMatch = true;
                if ($file !== $expected) {
                    $report['section_6_case_sensitivity_diagnostic']['qr_' . $expected] = [
                        'status' => 'CASE MISMATCH',
                        'expected' => $expected,
                        'actual' => $file,
                        'fix' => "Rename file to: {$expected}"
                    ];
                } else {
                    $report['section_6_case_sensitivity_diagnostic']['qr_' . $expected] = 'OK';
                }
                break;
            }
        }
        if (!$found && !$caseMatch) {
            $report['section_6_case_sensitivity_diagnostic']['qr_' . $expected] = 'MISSING';
        }
    }
}

// Scan QR root directory
$qrRootDir = ROOT_PATH . '/src/Infrastructure/QRcodes';
if (is_dir($qrRootDir)) {
    $files = scandir($qrRootDir);
    $expectedFiles = [
        'EmvQrAdapter.php',
    ];
    
    foreach ($expectedFiles as $expected) {
        $found = in_array($expected, $files);
        $caseMatch = false;
        foreach ($files as $file) {
            if (strtolower($file) === strtolower($expected)) {
                $caseMatch = true;
                if ($file !== $expected) {
                    $report['section_6_case_sensitivity_diagnostic']['qr_root_' . $expected] = [
                        'status' => 'CASE MISMATCH',
                        'expected' => $expected,
                        'actual' => $file,
                        'fix' => "Rename file to: {$expected}"
                    ];
                } else {
                    $report['section_6_case_sensitivity_diagnostic']['qr_root_' . $expected] = 'OK';
                }
                break;
            }
        }
        if (!$found && !$caseMatch) {
            $report['section_6_case_sensitivity_diagnostic']['qr_root_' . $expected] = 'MISSING';
        }
    }
}

// ============================================================
// SUMMARY
// ============================================================

$criticalIssues = [];

// Check class resolution
foreach ($report['section_1_file_scan'] as $class => $entry) {
    if (is_array($entry) && isset($entry['diagnosis']) && $entry['diagnosis'] !== 'OK') {
        $criticalIssues[] = "Class issue: {$class} - {$entry['diagnosis']}";
        if (isset($entry['suggested_fix'])) {
            $criticalIssues[] = "  Fix: {$entry['suggested_fix']}";
        }
    }
}

// Check contract mismatches
foreach ($report['section_2_contract_matching'] as $method => $entry) {
    if (is_array($entry) && isset($entry['MISMATCH']) && str_starts_with($entry['MISMATCH'], 'CRITICAL')) {
        $criticalIssues[] = "Contract mismatch: {$method} -> " . ($entry['adapter_method'] ?? 'unknown');
    }
}

// Check case sensitivity
foreach ($report['section_6_case_sensitivity_diagnostic'] as $key => $value) {
    if (is_array($value) && isset($value['status']) && $value['status'] === 'CASE MISMATCH') {
        $criticalIssues[] = "Case mismatch: {$value['expected']} vs {$value['actual']}";
        $criticalIssues[] = "  Fix: {$value['fix']}";
    }
}

$report['summary'] = [
    'critical_issues_found' => count($criticalIssues),
    'issues' => $criticalIssues,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
