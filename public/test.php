<?php
/**
 * FNBB DIAGNOSTIC — run this directly on the vouchmorphn server console:
 *   php diagnose_fnbb.php
 *
 * Bypasses SwapService / PoolCoordinator / InstitutionAdapterFactory
 * entirely. Talks straight to GenericBankClient and CardAcquirerBankClient
 * with a manually-constructed FNBB_ACQUIRER config (merged from the real
 * participants.yaml + endpoints.yaml blocks pasted earlier), and prints
 * FULL, UNTRUNCATED request/response detail at every stage.
 *
 * ============================================================
 * BEFORE RUNNING — fix these two things for your actual environment:
 * ============================================================
 * 1. AUTOLOAD_PATH below — point it at whatever this app already uses
 *    to autoload classes (composer's vendor/autoload.php, or a custom
 *    bootstrap file). Needed so GenericBankClient/CardAcquirerBankClient/
 *    CertificateManagerFactory resolve correctly.
 *
 * 2. If GenericBankClient's constructor expects config shaped differently
 *    than the flat array below (e.g. it wants YAML-parsed nested keys
 *    exactly as loadYamlEndpoints() produces internally), adjust
 *    $participantConfig to match. This script does NOT re-parse
 *    endpoints.yaml — it hardcodes the FNBB block's real values directly,
 *    to eliminate "did the YAML parse correctly" as a variable entirely.
 * ============================================================
 */

declare(strict_types=1);

$AUTOLOAD_PATH = __DIR__ . '/vendor/autoload.php'; // <-- ADJUST IF NEEDED

// FIX: STDERR is undefined when this script runs under a web SAPI
// (confirmed: it was hit via GET /test.php on PHP's built-in dev
// server, not CLI). Use error_log()/echo instead, which work in both
// contexts.
if (php_sapi_name() === 'cli' && !defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'w'));
}
function diag_fail(string $msg): void
{
    error_log($msg);
    echo "!! " . $msg . "\n";
}

if (file_exists($AUTOLOAD_PATH)) {
    require_once $AUTOLOAD_PATH;
} else {
    diag_fail("Autoload not found at {$AUTOLOAD_PATH} — edit \$AUTOLOAD_PATH at the top of this script.");
    exit(1);
}

use Infrastructure\Banks\GenericBankClient;
use Infrastructure\Banks\CardAcquirerBankClient;

function section(string $title): void
{
    echo "\n" . str_repeat("=", 70) . "\n{$title}\n" . str_repeat("=", 70) . "\n";
}

function dump(string $label, $value): void
{
    echo "--- {$label} ---\n";
    if (is_string($value)) {
        echo $value . "\n";
    } else {
        echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

// ============================================================
// Hardcoded FNBB_ACQUIRER config — merged directly from the real
// participants.yaml + endpoints.yaml blocks. No YAML parsing involved.
// ============================================================
$participantConfig = [
    'provider_code' => 'FNBB_ACQUIRER',
    'name' => 'FNBB Card Acquiring (Visa/Mastercard)',
    'country_code' => 'Botswana',

    'base_url' => 'https://zurubank-production.up.railway.app/Backend/api',
    'timeout_ms' => 5000,

    'auth' => [
        'type' => 'API_KEY',
        'header_name' => 'X-API-Key',
        'secret_source' => [
            'type' => 'env_var',
            'name' => 'FNBB_ACQUIRER_API_KEY',
        ],
    ],

    'endpoints' => [
        'source' => [
            'verify_asset' => '/preauth.php',
            'place_hold' => '/authorize.php',
            'debit_funds' => '/capture.php',
            'release_hold' => '/void.php',
            'get_balance' => '/n-a',
        ],
        'destination_deposit' => [
            'process_deposit' => '/card-load.php',
        ],
    ],

    'card_acquirer' => [
        'source_enabled' => true,
        'pre_auth_check' => false,
        'pre_auth_amount' => 1.00,
        'max_single_auth_amount' => 5000.00,
        'authorization_window_seconds' => 604800,
        'destination_enabled' => false,
        'max_single_load_amount' => 5000.00,
    ],

    'retry_policy' => [
        'max_retries' => 3,
        'retry_delay_ms' => 1000,
        'retry_on' => ['timeout', '5xx', 'network_error'],
    ],
];

// ============================================================
// Test payload — mirrors test.php's card_fnbb_acquirer_to_account
// case exactly.
// ============================================================
$basePayload = [
    'reference' => 'DIAG_FNBB_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6),
    'user_id' => 1,
    'from_institution' => 'FNBB_ACQUIRER',
    'source_institution' => 'FNBB_ACQUIRER',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'source_identifier' => '4111111111111111',
    'card_token' => '4111111111111111',
    'cvv' => '123',
    'amount' => 50,
    'currency' => 'BWP',
    'to_institution' => 'ZURUBANK',
    'destination_institution' => 'ZURUBANK',
    'destination_asset_type' => 'ACCOUNT',
    'destination_identifier' => '10000001',
    'destination_identifier_type' => 'account_number',
];

// ============================================================
// STAGE 0 — Confirm FNBB_ACQUIRER_API_KEY presence (should be
// irrelevant per our analysis, since the mock only checks
// certificate/signature — but confirm this assumption stands)
// ============================================================
section('STAGE 0: Environment check');
$fnbbApiKey = getenv('FNBB_ACQUIRER_API_KEY');
dump('FNBB_ACQUIRER_API_KEY set?', $fnbbApiKey ? 'YES (length ' . strlen($fnbbApiKey) . ')' : 'NO / empty');
dump('VOUCHMORPH_PRIVATE_KEY_CONTENT set?', getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CERT_CONTENT set?', getenv('VOUCHMORPH_CERT_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CA_CERT / VOUCHMORPH_CA_CERT_CONTENT set?',
    (getenv('VOUCHMORPH_CA_CERT') || getenv('VOUCHMORPH_CA_CERT_CONTENT')) ? 'YES' : 'NO');

// ============================================================
// STAGE 1 — Plain GenericBankClient (what the factory used BEFORE
// the bank_client_class fix, and what it STILL uses if that fix
// hasn't deployed or the class-check silently fails)
// ============================================================
section('STAGE 1: GenericBankClient::verifyAssetSigned() — RAW, no card-specific logic');

try {
    $genericClient = new GenericBankClient($participantConfig);
    $result1 = $genericClient->verifyAssetSigned($basePayload);
    dump('Result', $result1);
    dump('raw_response (untruncated)', $result1['raw_response'] ?? '(none captured)');
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ============================================================
// STAGE 2 — CardAcquirerBankClient directly (what SHOULD be running
// in production if the factory fix deployed correctly)
// ============================================================
section('STAGE 2: CardAcquirerBankClient::verifyAssetSigned() — WITH card-specific logic');

if (!class_exists(CardAcquirerBankClient::class)) {
    dump('FATAL', 'CardAcquirerBankClient class not found/autoloadable. Check namespace/path: src/Infrastructure/Banks/CardAcquirerBankClient.php');
} else {
    try {
        $cardClient = new CardAcquirerBankClient($participantConfig);
        $result2 = $cardClient->verifyAssetSigned($basePayload);
        dump('Result', $result2);
        dump('raw_response (untruncated)', $result2['raw_response'] ?? '(none — pre_auth_check=false means no network call was made, this is expected)');
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 3 — placeHold() via CardAcquirerBankClient (this DOES hit
// the network regardless of pre_auth_check, since AUTHORIZE always
// calls FNBB's mock)
// ============================================================
section('STAGE 3: CardAcquirerBankClient::placeHold() — hits /authorize.php for real');

if (class_exists(CardAcquirerBankClient::class)) {
    try {
        $cardClient = $cardClient ?? new CardAcquirerBankClient($participantConfig);
        $holdPayload = array_merge($basePayload, [
            'hold_reason' => 'DIAGNOSTIC_TEST',
        ]);
        $result3 = $cardClient->placeHold($holdPayload);
        dump('Result', $result3);
        dump('raw_response (untruncated)', $result3['raw_response'] ?? '(none captured)');
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 4 — Direct raw cURL to /preauth.php, completely bypassing
// GenericBankClient/CardAcquirerBankClient. Signs manually using
// CertificateManagerFactory the same way GenericBankClient does
// internally, so we can see EXACTLY what goes over the wire and
// EXACTLY what comes back, with zero abstraction in between.
// ============================================================
section('STAGE 4: Raw direct cURL to /preauth.php (bypasses all client classes)');

try {
    $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
    if (!$certManager->isConfigured()) {
        dump('WARNING', 'CertificateManager reports NOT configured — signing will fail or produce an unsigned payload.');
    }

    $rawPayload = array_merge($basePayload, [
        'action' => 'VERIFY_ASSET',
        'institution' => 'FNBB_ACQUIRER',
        'timestamp' => time(),
    ]);

    $signed = $certManager->createSignedRequest($rawPayload, 'VOUCHMORPH');
    dump('Signed payload being sent', $signed);

    $url = 'https://zurubank-production.up.railway.app/Backend/api/preauth.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($signed, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    dump('URL', $url);
    dump('HTTP status', $httpCode);
    dump('curl error', $curlError ?: '(none)');
    dump('RAW response body (completely untruncated)', $response ?: '(empty response body)');

    $decoded = json_decode($response, true);
    if ($decoded === null && $response !== '') {
        dump('WARNING', 'Response body is NOT valid JSON — this alone would explain "Verification failed" further up the stack, since json_decode() failing silently produces an empty/null data array.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ============================================================
// STAGE 5 — Same raw cURL, but to the DECLINED pan, to confirm the
// decline path is reachable at all once auth is settled.
// ============================================================
section('STAGE 5: Raw direct cURL to /authorize.php with the DECLINED test PAN');

try {
    $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
    $declinedPayload = array_merge($basePayload, [
        'action' => 'AUTHORIZE',
        'institution' => 'FNBB_ACQUIRER',
        'card_token' => '4000000000000002',
        'source_identifier' => '4000000000000002',
        'timestamp' => time(),
        'reference' => 'DIAG_DECLINE_' . time(),
    ]);
    $signedDeclined = $certManager->createSignedRequest($declinedPayload, 'VOUCHMORPH');

    $url = 'https://zurubank-production.up.railway.app/Backend/api/authorize.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($signedDeclined, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    dump('URL', $url);
    dump('HTTP status', $httpCode);
    dump('curl error', $curlError ?: '(none)');
    dump('RAW response body (completely untruncated)', $response ?: '(empty response body)');
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

section('DIAGNOSTIC COMPLETE');
echo "Paste the FULL output of this script back — every stage matters,\n";
echo "even the ones that look redundant. Stage 4/5's raw response bodies\n";
echo "are the most important: they show EXACTLY what FnbbAcquirerMock\n";
echo "sent back with zero VouchMorph-side interpretation in the way.\n";
