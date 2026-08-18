<?php
/**
 * FNBB AUTH DIAGNOSTIC (v2) — run directly on the vouchmorphn server console:
 *   php diagnose_fnbb_auth.php
 *
 * Fixes three bugs found in the previous copy of this script:
 *   1. $cardClient was referenced in Stage 5 before it was ever instantiated
 *      (instantiation line was pasted AFTER its first use) -> fatal
 *      TypeError: get_class(): Argument #1 ($object) must be of type
 *      object, null given.
 *   2. The entire Stage 5 section was duplicated verbatim, back to back.
 *   3. $basePayload was referenced in Stage 5 but never defined anywhere
 *      in the file -> would silently auto-vivify to an empty array and
 *      run verifyAssetSigned() against a payload missing card_token,
 *      cvv, amount, asset_type, etc.
 *
 * Everything else (Stages 1-4, the byte-for-byte comparison logic) is
 * unchanged from the version that already ran cleanly.
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

function rawPost(string $url, array $payload, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $extraHeaders
        ),
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
// Same hardcoded FNBB_ACQUIRER config used in the earlier diagnostics.
// Update if your real participants.yaml has since changed.
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

$preauthUrl = 'https://zurubank-production.up.railway.app/Backend/api/Preauth.php';
$goodPan = '4111111111111111'; // whatever your suite calls "known good"
$badPan  = '0000000000000000'; // deliberately garbage

// ============================================================
// FIX #1 + #3: instantiate $cardClient AND define $basePayload here,
// before ANY stage uses them — not after, not never. $basePayload
// mirrors Stage 2's payload shape (the one already confirmed to work
// via raw cURL) so Stage 5 is testing the same request through the
// client class, not a differently-shaped one.
// ============================================================
$cardClient = new CardAcquirerBankClient($participantConfig);

$basePayload = [
    'reference' => 'DIAG_CLIENT_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6),
    'user_id' => 1,
    'from_institution' => 'FNBB_ACQUIRER',
    'source_institution' => 'FNBB_ACQUIRER',
    'institution' => 'FNBB_ACQUIRER',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'source_identifier' => $goodPan,
    'card_token' => $goodPan,
    'cvv' => '123',
    'amount' => 25,
    'currency' => 'BWP',
    'to_institution' => 'ZURUBANK',
    'destination_institution' => 'ZURUBANK',
    'destination_asset_type' => 'ACCOUNT',
    'destination_identifier' => '10000001',
    'destination_identifier_type' => 'account_number',
];

// ============================================================
// STAGE 1 — Environment / key sanity check
// ============================================================
section('STAGE 1: Environment check');
$apiKey = getenv('FNBB_ACQUIRER_API_KEY');
dump('FNBB_ACQUIRER_API_KEY set?', $apiKey ? 'YES (length ' . strlen($apiKey) . ')' : 'NO / empty');
dump('VOUCHMORPH_PRIVATE_KEY_CONTENT set?', getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CERT_CONTENT set?', getenv('VOUCHMORPH_CERT_CONTENT') ? 'YES' : 'NO');
dump('VOUCHMORPH_CA_CERT / VOUCHMORPH_CA_CERT_CONTENT set?',
    (getenv('VOUCHMORPH_CA_CERT') || getenv('VOUCHMORPH_CA_CERT_CONTENT')) ? 'YES' : 'NO');
if (!$apiKey) {
    diag_fail('FNBB_ACQUIRER_API_KEY is empty — this alone could explain a blanket "Authentication failed" on every card, valid or not. Fix this and re-run before trusting Stages 2-4.');
}

// ============================================================
// STAGE 2 — Raw call, GOOD pan, WITH signature/cert
// ============================================================
section('STAGE 2: Raw Preauth.php — GOOD pan, properly signed');
$resp2 = null;
try {
    $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
    $payload2 = [
        'reference' => 'DIAG_AUTH_GOOD_' . time(),
        'institution' => 'FNBB_ACQUIRER',
        'from_institution' => 'FNBB_ACQUIRER',
        'source_institution' => 'FNBB_ACQUIRER',
        'action' => 'VERIFY_ASSET',
        'asset_type' => 'VISA_MASTERCARD_CARD',
        'source_identifier' => $goodPan,
        'card_token' => $goodPan,
        'cvv' => '123',
        'amount' => 25,
        'currency' => 'BWP',
        'timestamp' => time(),
    ];
    $signed2 = $certManager->createSignedRequest($payload2, 'VOUCHMORPH');
    dump('Payload sent', $signed2);
    $headers2 = $apiKey ? ["X-API-Key: {$apiKey}"] : [];
    [$resp2, $code2, $err2] = rawPost($preauthUrl, $signed2, $headers2);
    dump('HTTP status', $code2);
    dump('curl error', $err2 ?: '(none)');
    dump('RAW response body', $resp2 ?: '(empty)');
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 3 — Raw call, SAME good pan, deliberately UNSIGNED /
// no API key header.
// ============================================================
section('STAGE 3: Raw Preauth.php — SAME good pan, deliberately BROKEN auth (control)');
try {
    $payload3 = [
        'reference' => 'DIAG_AUTH_BROKEN_' . time(),
        'institution' => 'FNBB_ACQUIRER',
        'from_institution' => 'FNBB_ACQUIRER',
        'source_institution' => 'FNBB_ACQUIRER',
        'action' => 'VERIFY_ASSET',
        'asset_type' => 'VISA_MASTERCARD_CARD',
        'source_identifier' => $goodPan,
        'card_token' => $goodPan,
        'cvv' => '123',
        'amount' => 25,
        'currency' => 'BWP',
        'timestamp' => time(),
        // deliberately NO signature / certificate fields here
    ];
    dump('Payload sent (unsigned, no API key header)', $payload3);
    [$resp3, $code3, $err3] = rawPost($preauthUrl, $payload3, []); // no X-API-Key
    dump('HTTP status', $code3);
    dump('curl error', $err3 ?: '(none)');
    dump('RAW response body', $resp3 ?: '(empty)');

    if ($resp2 !== null && $resp3 === $resp2) {
        dump('DIAGNOSIS', 'Stage 3 (deliberately broken auth) returned an IDENTICAL body to Stage 2 (properly signed). This strongly suggests the mock is NOT differentiating requests by auth validity.');
    } elseif ($resp2 !== null) {
        dump('DIAGNOSIS', 'Stage 3 differs from Stage 2 — the mock DOES respond differently to broken auth. That means Stage 2 succeeding is meaningful, and any "Authentication failed" elsewhere is more likely a real credential/signing problem in app code (see Stage 5) rather than a mock limitation.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 4 — Raw call, GARBAGE pan, WITH correct signing.
// ============================================================
section('STAGE 4: Raw Preauth.php — GARBAGE pan, properly signed (control)');
try {
    $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
    $payload4 = [
        'reference' => 'DIAG_AUTH_BADPAN_' . time(),
        'institution' => 'FNBB_ACQUIRER',
        'from_institution' => 'FNBB_ACQUIRER',
        'source_institution' => 'FNBB_ACQUIRER',
        'action' => 'VERIFY_ASSET',
        'asset_type' => 'VISA_MASTERCARD_CARD',
        'source_identifier' => $badPan,
        'card_token' => $badPan,
        'cvv' => '000',
        'amount' => 25,
        'currency' => 'BWP',
        'timestamp' => time(),
    ];
    $signed4 = $certManager->createSignedRequest($payload4, 'VOUCHMORPH');
    dump('Payload sent', $signed4);
    $headers4 = $apiKey ? ["X-API-Key: {$apiKey}"] : [];
    [$resp4, $code4, $err4] = rawPost($preauthUrl, $signed4, $headers4);
    dump('HTTP status', $code4);
    dump('curl error', $err4 ?: '(none)');
    dump('RAW response body', $resp4 ?: '(empty)');

    if ($resp2 !== null) {
        dump('COMPARISON vs Stage 2', $resp4 === $resp2
            ? 'IDENTICAL to the good-PAN response — the mock is not distinguishing card validity at all.'
            : 'DIFFERENT from the good-PAN response — the mock does distinguish cards.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 5: the REAL entry point — verifyAssetSigned(), not
// verifyAsset(). $cardClient and $basePayload are both already
// defined above, before Stage 1 even ran, so neither is null/undefined
// here. This section appears EXACTLY ONCE in this file.
// ============================================================
section('STAGE 5: CardAcquirerBankClient::verifyAssetSigned() — the REAL entry point');

dump('Resolved bank client class', get_class($cardClient));
if (get_class($cardClient) !== CardAcquirerBankClient::class) {
    dump('WARNING', 'This is NOT CardAcquirerBankClient. Since this diagnostic instantiates '
        . '$cardClient directly via `new CardAcquirerBankClient($participantConfig)`, this check will '
        . 'always say CardAcquirerBankClient regardless of what production actually resolves. See '
        . 'Stage 5b below for the check against the REAL factory + REAL parsed config.');
}

$signedPayload = $basePayload;
$signedPayload['reference'] = 'DIAG_SIGNED_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);

try {
    $signedResult = $cardClient->verifyAssetSigned($signedPayload);
    dump('verifyAssetSigned() result', $signedResult);
    dump('raw_response (untruncated)', $signedResult['raw_response'] ?? '(none — see note below if pre_auth_check is false)');
    if (($signedResult['message'] ?? '') === 'Card token present — deferring real check to authorization') {
        dump('NOTE', 'No network call was made — this means pre_auth_check resolved to false for this '
            . 'client instance (matches $participantConfig above, which has pre_auth_check: false). This is '
            . 'EXPECTED given that config, not a bug — if you need Stage 5 to actually hit /Preauth.php, set '
            . 'pre_auth_check to true in $participantConfig above and re-run.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ============================================================
// STAGE 5b: Factory resolution check — does the REAL loader +
// factory actually wire up CardAcquirerBankClient for FNBB_ACQUIRER
// now, after the participants.yaml indentation fix?
// ============================================================
section('STAGE 5b: Real InstitutionAdapterFactory resolution for FNBB_ACQUIRER');

try {
    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $realParticipants = $countryConfig['participants'] ?? [];

    if (!isset($realParticipants['FNBB_ACQUIRER'])) {
        dump('FATAL', 'FNBB_ACQUIRER not present at all in the REAL parsed participants config. '
            . 'Check for "[LoadCountry] Failed to parse YAML participants file" in the logs.');
    } else {
        dump('Real parsed FNBB_ACQUIRER config', $realParticipants['FNBB_ACQUIRER']);

        $hasBankClientClass = isset($realParticipants['FNBB_ACQUIRER']['bank_client_class']);
        $hasCardAcquirerBlock = isset($realParticipants['FNBB_ACQUIRER']['card_acquirer']);
        dump('bank_client_class present?', $hasBankClientClass ? 'YES: ' . $realParticipants['FNBB_ACQUIRER']['bank_client_class'] : 'NO — indentation fix did not take effect, or has not been deployed yet');
        dump('card_acquirer block present?', $hasCardAcquirerBlock ? 'YES' : 'NO — nested config lost, consistent with a fallback to the flat manual parser');

        $realFactory = new \Infrastructure\Adapters\InstitutionAdapterFactory($realParticipants, null);
        $realAdapter = $realFactory->getAdapter('FNBB_ACQUIRER');

        $reflection = new \ReflectionClass($realAdapter);
        $bankClientProp = $reflection->getProperty('bankClient');
        $bankClientProp->setAccessible(true);
        $realBankClient = $bankClientProp->getValue($realAdapter);

        dump('Adapter class', get_class($realAdapter));
        dump('ACTUAL resolved bank client class (via real factory + real config)', get_class($realBankClient));

        if (get_class($realBankClient) === \Infrastructure\Banks\CardAcquirerBankClient::class) {
            dump('RESULT', 'FIX CONFIRMED — FNBB_ACQUIRER now resolves to CardAcquirerBankClient in the real code path.');
        } else {
            dump('RESULT', 'FIX NOT YET EFFECTIVE — FNBB_ACQUIRER is still resolving to ' . get_class($realBankClient)
                . '. Confirm the corrected participants.yaml has actually been deployed, and that the running '
                . 'container picked up the new file.');
        }
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

section('DIAGNOSTIC COMPLETE');
echo "Paste the FULL output back.\n";
