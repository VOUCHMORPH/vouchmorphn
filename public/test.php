<?php
/**
 * FNBB DIAGNOSTIC v2 — run this directly on the vouchmorphn server console:
 *   php diagnose_fnbb_v2.php
 *
 * Extends the original diagnostic with REAL capture and void calls, so the
 * unwrapNestedData() fix gets checked against Capture.php and Void.php
 * directly, instead of assuming they share Preauth.php/Authorize.php's
 * nested-data shape.
 *
 * Flow:
 *   Stage 1-5:  unchanged from v1 (pre-auth, placeHold via client class,
 *               raw cURL to Preauth.php, raw cURL to Authorize.php-decline)
 *   Stage 6:    placeHold() again — this hold is kept OPEN, specifically
 *               so Stage 8 (debitFunds/Capture) has a real reference to
 *               capture against.
 *   Stage 7:    placeHold() a SECOND time — this hold is kept OPEN too,
 *               specifically so Stage 10 (releaseHold/Void) has its own
 *               independent reference to void. (Capturing and voiding the
 *               SAME hold would make stage order matter and contaminate
 *               results — each gets its own.)
 *   Stage 8:    CardAcquirerBankClient::debitFunds() against Stage 6's
 *               hold_reference — hits Capture.php for real.
 *   Stage 9:    Raw direct cURL to Capture.php, same reference pattern,
 *               bypassing all client classes — shows the EXACT bytes
 *               FnbbAcquirerMock sends back for a capture.
 *   Stage 10:   CardAcquirerBankClient::releaseHold() against Stage 7's
 *               hold_reference — hits Void.php for real.
 *   Stage 11:   Raw direct cURL to Void.php, same pattern — shows the
 *               EXACT bytes for a void.
 *   Stage 12:   CardAcquirerBankClient::processDepositWithProof() — will
 *               hit the disabled-capability branch unless
 *               card_acquirer.destination_enabled is true in your real
 *               participants.yaml. This stage reports which one happened;
 *               it does NOT flip destination_enabled to true. If it's
 *               genuinely enabled for FNBB_ACQUIRER, edit
 *               $participantConfig below to match your real config before
 *               relying on this stage's result.
 *
 * ============================================================
 * BEFORE RUNNING — same two things as v1:
 * ============================================================
 * 1. AUTOLOAD_PATH below — point it at whatever this app already uses.
 * 2. $participantConfig below mirrors v1's hardcoded FNBB block. If your
 *    real participants.yaml/endpoints.yaml has since changed (e.g.
 *    destination_enabled flipped to true), update the block to match, or
 *    Stage 12 will report the wrong thing.
 * ============================================================
 */

declare(strict_types=1);

$AUTOLOAD_PATH = __DIR__ . '/../vendor/autoload.php'; // <-- ADJUST IF NEEDED

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

function rawSignedPost(string $url, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [$response, $httpCode, $curlError];
}

// ============================================================
// Hardcoded FNBB_ACQUIRER config — same as v1. Update if your real
// participants.yaml has changed since (see note at top for Stage 12).
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
            'verify_asset' => '/Preauth.php',
            'place_hold' => '/Authorize.php',
            'debit_funds' => '/Capture.php',
            'release_hold' => '/Void.php',
            'get_balance' => '/n-a',
        ],
        'destination_deposit' => [
            'process_deposit' => '/Cardload.php',
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
// STAGE 0-5 — unchanged from v1, kept for continuity of the paste.
// ============================================================
section('STAGE 0: Environment check');
dump('FNBB_ACQUIRER_API_KEY set?', getenv('FNBB_ACQUIRER_API_KEY') ? 'YES' : 'NO / empty');
dump('VOUCHMORPH_PRIVATE_KEY_CONTENT set?', getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CERT_CONTENT set?', getenv('VOUCHMORPH_CERT_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CA_CERT / VOUCHMORPH_CA_CERT_CONTENT set?',
    (getenv('VOUCHMORPH_CA_CERT') || getenv('VOUCHMORPH_CA_CERT_CONTENT')) ? 'YES' : 'NO');

$cardClient = new CardAcquirerBankClient($participantConfig);

// ============================================================
// STAGE 6 — placeHold() for the CAPTURE test. Hold is left OPEN
// (not voided here) so Stage 8/9 have a live reference to capture.
// ============================================================
section('STAGE 6: placeHold() — hold #1, reserved for CAPTURE test');
$capturePayload = $basePayload;
$capturePayload['reference'] = 'DIAG_CAP_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);
$holdForCapture = null;
try {
    $holdForCapture = $cardClient->placeHold($capturePayload);
    dump('Result', $holdForCapture);
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 7 — placeHold() for the VOID test. Independent hold so
// capturing in Stage 8 can't affect what Stage 10 tries to void.
// ============================================================
section('STAGE 7: placeHold() — hold #2, reserved for VOID test');
$voidPayload = $basePayload;
$voidPayload['reference'] = 'DIAG_VOID_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);
$holdForVoid = null;
try {
    $holdForVoid = $cardClient->placeHold($voidPayload);
    dump('Result', $holdForVoid);
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 8 — CardAcquirerBankClient::debitFunds() — REAL capture
// against hold #1's authorization_reference. This is the stage that
// actually exercises unwrapNestedData() inside debitFunds().
// ============================================================
section('STAGE 8: CardAcquirerBankClient::debitFunds() — hits /Capture.php for real');
if (!$holdForCapture || empty($holdForCapture['hold_reference'])) {
    dump('SKIPPED', 'Stage 6 did not produce a hold_reference — cannot test capture. Check Stage 6 output above.');
} else {
    try {
        $captureResult = $cardClient->debitFunds([
            'hold_reference' => $holdForCapture['hold_reference'],
            'reference' => 'DIAG_CAP_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6),
            'amount' => 50,
        ]);
        dump('Result', $captureResult);
        dump('raw_response (untruncated)', $captureResult['raw_response'] ?? '(none captured)');
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 9 — Raw direct cURL to /Capture.php, bypassing all client
// classes. Signs manually the same way createSignedPayload() does
// internally, so the response can be inspected with zero
// VouchMorph-side interpretation, mirroring v1's Stage 4/5 pattern.
// ============================================================
section('STAGE 9: Raw direct cURL to /Capture.php (bypasses all client classes)');
if (!$holdForCapture || empty($holdForCapture['hold_reference'])) {
    dump('SKIPPED', 'No hold_reference available from Stage 6.');
} else {
    try {
        $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
        $rawCapturePayload = [
            'reference' => 'DIAG_RAWCAP_' . time(),
            'authorization_reference' => $holdForCapture['hold_reference'],
            'amount' => 50,
            'action' => 'CAPTURE',
            'institution' => 'FNBB_ACQUIRER',
            'timestamp' => time(),
        ];
        $signed = $certManager->createSignedRequest($rawCapturePayload, 'VOUCHMORPH');
        dump('Signed payload being sent', $signed);

        $url = 'https://zurubank-production.up.railway.app/Backend/api/Capture.php';
        [$response, $httpCode, $curlError] = rawSignedPost($url, $signed);

        dump('URL', $url);
        dump('HTTP status', $httpCode);
        dump('curl error', $curlError ?: '(none)');
        dump('RAW response body (completely untruncated)', $response ?: '(empty response body)');

        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== '') {
            dump('WARNING', 'Response body is NOT valid JSON.');
        } elseif (is_array($decoded)) {
            $hasNestedData = isset($decoded['data']) && is_array($decoded['data']);
            dump('NESTING CHECK', $hasNestedData
                ? 'Capture.php DOES nest under "data" — same shape as Authorize.php. unwrapNestedData() fix applies here too, confirmed.'
                : 'Capture.php response is FLAT (no nested "data" array) — different shape from Authorize.php. Confirm Stage 8 still reads the right fields.');
        }
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 10 — CardAcquirerBankClient::releaseHold() — REAL void
// against hold #2's authorization_reference.
// ============================================================
section('STAGE 10: CardAcquirerBankClient::releaseHold() — hits /Void.php for real');
if (!$holdForVoid || empty($holdForVoid['hold_reference'])) {
    dump('SKIPPED', 'Stage 7 did not produce a hold_reference — cannot test void. Check Stage 7 output above.');
} else {
    try {
        $voidResult = $cardClient->releaseHold([
            'hold_reference' => $holdForVoid['hold_reference'],
            'reference' => 'DIAG_VOID_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6),
            'reason' => 'DIAGNOSTIC_TEST',
        ]);
        dump('Result', $voidResult);
        dump('raw_response (untruncated)', $voidResult['raw_response'] ?? '(none captured)');
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 11 — Raw direct cURL to /Void.php, bypassing all client
// classes.
// ============================================================
section('STAGE 11: Raw direct cURL to /Void.php (bypasses all client classes)');
if (!$holdForVoid || empty($holdForVoid['hold_reference'])) {
    dump('SKIPPED', 'No hold_reference available from Stage 7.');
} else {
    try {
        $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
        $rawVoidPayload = [
            'reference' => 'DIAG_RAWVOID_' . time(),
            'authorization_reference' => $holdForVoid['hold_reference'],
            'action' => 'VOID',
            'reason' => 'DIAGNOSTIC_TEST',
            'institution' => 'FNBB_ACQUIRER',
            'timestamp' => time(),
        ];
        $signed = $certManager->createSignedRequest($rawVoidPayload, 'VOUCHMORPH');
        dump('Signed payload being sent', $signed);

        $url = 'https://zurubank-production.up.railway.app/Backend/api/Void.php';
        [$response, $httpCode, $curlError] = rawSignedPost($url, $signed);

        dump('URL', $url);
        dump('HTTP status', $httpCode);
        dump('curl error', $curlError ?: '(none)');
        dump('RAW response body (completely untruncated)', $response ?: '(empty response body)');

        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== '') {
            dump('WARNING', 'Response body is NOT valid JSON.');
        } elseif (is_array($decoded)) {
            $hasNestedData = isset($decoded['data']) && is_array($decoded['data']);
            dump('NESTING CHECK', $hasNestedData
                ? 'Void.php DOES nest under "data" — same shape as Authorize.php. unwrapNestedData() fix applies here too, confirmed.'
                : 'Void.php response is FLAT (no nested "data" array) — different shape from Authorize.php. Confirm Stage 10 still reads the right fields.');
        }
    } catch (\Throwable $e) {
        dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// ============================================================
// STAGE 12 — processDepositWithProof(). Expected to hit the
// disabled-capability branch given destination_enabled: false above.
// This stage reports which branch fired; it does not change config.
// ============================================================
section('STAGE 12: CardAcquirerBankClient::processDepositWithProof() — card LOAD test');
try {
    $loadResult = $cardClient->processDepositWithProof([
        'card_token' => '4111111111111111',
        'amount' => 10,
        'currency' => 'BWP',
        'reference' => 'DIAG_LOAD_' . time(),
    ]);
    dump('Result', $loadResult);
    if (($participantConfig['card_acquirer']['destination_enabled'] ?? false) === false) {
        dump('EXPECTED', 'destination_enabled=false in this script\'s config — the disabled-capability message above is expected, not a bug. If FNBB_ACQUIRER genuinely supports card load in production, update $participantConfig here to match before drawing conclusions from this stage.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

section('DIAGNOSTIC v2 COMPLETE');
echo "Paste the FULL output back. Stage 9/11's NESTING CHECK lines matter most —\n";
echo "they confirm (or refute) whether Capture.php/Void.php actually share\n";
echo "Authorize.php's nested-data shape, rather than that being assumed.\n";
