<?php 
/**
 * FNBB AUTH DIAGNOSTIC — run directly on the vouchmorphn server console:
 *   php diagnose_fnbb_auth.php
 *
 * PURPOSE
 * ============================================================
 * The test suite reported:
 *   [FAIL] card_fnbb_acquirer_to_account -> "Authentication failed"
 *   [PASS] card_fnbb_acquirer_declined_pan_should_fail -> "Authentication failed"
 *
 * Both cases produce the SAME error string. That's the bug to isolate.
 * Either:
 *   (A) Your API key / signature / certificate isn't reaching FNBB's
 *       mock correctly, so EVERY request gets auth-rejected before the
 *       mock ever looks at the card number — meaning the "declined PAN"
 *       test is passing for the wrong reason.
 *   (B) The FNBB mock genuinely treats a valid PAN differently from a
 *       declined one, and this specific "good" card just happens to
 *       also be invalid/expired/misconfigured in mock data.
 *
 * This script separates those by hitting Preauth.php directly (raw
 * cURL, no client-class interpretation) three times, with three
 * different payload shapes, and diffing the raw responses byte-for-byte:
 *
 *   Stage 1: env / key / cert sanity check
 *   Stage 2: raw call with a "known good" test PAN, WITH signing
 *   Stage 3: raw call with the SAME "known good" PAN, WITHOUT signing
 *            (deliberately broken auth) -> if response is IDENTICAL to
 *            Stage 2, the mock is ignoring your signature entirely, or
 *            always returning the same canned auth-failure regardless
 *            of card validity.
 *   Stage 4: raw call with an obviously-garbage PAN, WITH correct
 *            signing -> if this differs from Stage 2, the mock DOES
 *            distinguish cards, and the problem is your "good" test
 *            card specifically, not the auth pipeline.
 *   Stage 5: same as Stage 2 but through your actual client class
 *            (verifyAsset / placeHold) -> confirms whether your code
 *            is sending the same payload the raw call proved works,
 *            or whether something in GenericBankClient/CardAcquirer-
 *            BankClient is dropping/mangling the cert or signature
 *            before it goes out.
 *
 * Paste the FULL output back — the HTTP status + raw body for stages
 * 2/3/4 side by side is what actually answers "which bug is this."
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
// Same hardcoded FNBB_ACQUIRER config used in the earlier diagnostic.
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
// no API key header. If this matches Stage 2 byte-for-byte, the
// mock isn't actually checking auth per-request, or it's returning
// a generic failure regardless of what you send.
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

    if (isset($resp2) && $resp3 === $resp2) {
        dump('DIAGNOSIS', 'Stage 3 (deliberately broken auth) returned an IDENTICAL body to Stage 2 (properly signed). This strongly suggests the mock is NOT differentiating requests by auth validity — every card gets the same generic response, which explains why the "declined PAN" test passes for the wrong reason.');
    } elseif (isset($resp2)) {
        dump('DIAGNOSIS', 'Stage 3 differs from Stage 2 — the mock DOES respond differently to broken auth. That means Stage 2 succeeding (or not) is meaningful, and the earlier "Authentication failed" is more likely a real credential/signing problem in your app code (see Stage 5) rather than a mock limitation.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 4 — Raw call, GARBAGE pan, WITH correct signing. If this
// differs from Stage 2, the mock DOES distinguish card validity,
// and the earlier failing test's PAN is the actual problem, not
// the auth pipeline.
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

    if (isset($resp2)) {
        dump('COMPARISON vs Stage 2', $resp4 === $resp2
            ? 'IDENTICAL to the good-PAN response — the mock is not distinguishing card validity at all; every request (good, bad, unsigned) gets the same canned response.'
            : 'DIFFERENT from the good-PAN response — the mock does distinguish cards. Good news: your auth pipeline may be fine; look at whether your "good" test card in the suite is actually valid in mock data.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage());
}

// ============================================================
// STAGE 5 (CORRECTED): call the REAL entry point.
//
// CardAcquirerBankClient does NOT override verifyAsset() — it only
// overrides verifyAssetSigned(). A call to ->verifyAsset() falls
// through to GenericBankClient::verifyAsset(), which sends the
// payload completely unsigned. That's why every previous Stage 5
// reproduced Stage 3's "deliberately broken, unsigned" control
// byte-for-byte: it WAS unsigned, just not on purpose. This says
// nothing about whether verifyAssetSigned() — the method the real
// app actually calls via GenericInstitutionAdapter::verifyAsset() —
// works. Testing that requires calling verifyAssetSigned() directly.
//
// This stage also answers the OTHER open question: after the
// participants.yaml indentation fix, does FNBB_ACQUIRER actually
// resolve to CardAcquirerBankClient, or is it still silently falling
// back to plain GenericBankClient? get_class() tells us directly,
// independent of whether the call itself succeeds.
// ============================================================
section('STAGE 5 (CORRECTED): CardAcquirerBankClient::verifyAssetSigned() — the REAL entry point');

dump('Resolved bank client class', get_class($cardClient));
if (get_class($cardClient) !== CardAcquirerBankClient::class) {
    dump('WARNING', 'This is NOT CardAcquirerBankClient. If this diagnostic instantiates '
        . '$cardClient directly (as v1/v2 did, via `new CardAcquirerBankClient($participantConfig)`), '
        . 'this check is meaningless — it will always say CardAcquirerBankClient regardless of what '
        . 'production actually resolves. To test the REAL resolution, this must go through '
        . 'InstitutionAdapterFactory::getAdapter(\'FNBB_ACQUIRER\') using the REAL parsed participants.yaml, '
        . 'not a hardcoded $participantConfig array. See the factory-resolution check below.');
}

$signedPayload = $basePayload;
$signedPayload['reference'] = 'DIAG_SIGNED_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);

try {
    $signedResult = $cardClient->verifyAssetSigned($signedPayload);
    dump('verifyAssetSigned() result', $signedResult);
    dump('raw_response (untruncated)', $signedResult['raw_response'] ?? '(none — see note below if pre_auth_check is false)');
    if (($signedResult['message'] ?? '') === 'Card token present — deferring real check to authorization') {
        dump('NOTE', 'No network call was made — this means pre_auth_check resolved to false for this '
            . 'client instance. If you expected a real /Preauth.php call here, confirm card_acquirer.pre_auth_check '
            . 'in the config this client instance actually received.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ============================================================
// STAGE 5 (CORRECTED): call the REAL entry point.
//
// CardAcquirerBankClient does NOT override verifyAsset() — it only
// overrides verifyAssetSigned(). A call to ->verifyAsset() falls
// through to GenericBankClient::verifyAsset(), which sends the
// payload completely unsigned. That's why every previous Stage 5
// reproduced Stage 3's "deliberately broken, unsigned" control
// byte-for-byte: it WAS unsigned, just not on purpose. This says
// nothing about whether verifyAssetSigned() — the method the real
// app actually calls via GenericInstitutionAdapter::verifyAsset() —
// works. Testing that requires calling verifyAssetSigned() directly.
//
// This stage also answers the OTHER open question: after the
// participants.yaml indentation fix, does FNBB_ACQUIRER actually
// resolve to CardAcquirerBankClient, or is it still silently falling
// back to plain GenericBankClient? get_class() tells us directly,
// independent of whether the call itself succeeds.
// ============================================================
section('STAGE 5 (CORRECTED): CardAcquirerBankClient::verifyAssetSigned() — the REAL entry point');

dump('Resolved bank client class', get_class($cardClient));
if (get_class($cardClient) !== CardAcquirerBankClient::class) {
    dump('WARNING', 'This is NOT CardAcquirerBankClient. If this diagnostic instantiates '
        . '$cardClient directly (as v1/v2 did, via `new CardAcquirerBankClient($participantConfig)`), '
        . 'this check is meaningless — it will always say CardAcquirerBankClient regardless of what '
        . 'production actually resolves. To test the REAL resolution, this must go through '
        . 'InstitutionAdapterFactory::getAdapter(\'FNBB_ACQUIRER\') using the REAL parsed participants.yaml, '
        . 'not a hardcoded $participantConfig array. See the factory-resolution check below.');
}

$signedPayload = $basePayload;
$signedPayload['reference'] = 'DIAG_SIGNED_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);

try {
    $signedResult = $cardClient->verifyAssetSigned($signedPayload);
    dump('verifyAssetSigned() result', $signedResult);
    dump('raw_response (untruncated)', $signedResult['raw_response'] ?? '(none — see note below if pre_auth_check is false)');
    if (($signedResult['message'] ?? '') === 'Card token present — deferring real check to authorization') {
        dump('NOTE', 'No network call was made — this means pre_auth_check resolved to false for this '
            . 'client instance. If you expected a real /Preauth.php call here, confirm card_acquirer.pre_auth_check '
            . 'in the config this client instance actually received.');
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

// ============================================================
// STAGE 5b: Factory resolution check — does the REAL loader +
// factory actually wire up CardAcquirerBankClient for FNBB_ACQUIRER
// now, after the participants.yaml indentation fix? This is the only
// way to confirm the fix landed, as opposed to inspecting a
// hardcoded diagnostic config that was never affected by the bug
// in the first place.
// ============================================================
section('STAGE 5b: Real InstitutionAdapterFactory resolution for FNBB_ACQUIRER');

try {
    $countryConfig = \Core\Config\LoadCountry::getConfig();
    $realParticipants = $countryConfig['participants'] ?? [];

    if (!isset($realParticipants['FNBB_ACQUIRER'])) {
        dump('FATAL', 'FNBB_ACQUIRER not present at all in the REAL parsed participants config. '
            . 'This means the loader either failed to parse the file entirely, or fell back to the '
            . 'flat manual parser and something upstream of this filtered it out. Check for '
            . '"[LoadCountry] Failed to parse YAML participants file" in the logs.');
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
                . '. Confirm the corrected participants.yaml has actually been deployed (not just committed), '
                . 'and that the running container picked up the new file.');
        }
    }
} catch (\Throwable $e) {
    dump('EXCEPTION', get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
}

section('DIAGNOSTIC COMPLETE');
echo "Paste the FULL output back. What matters most:\n";
echo "  - Stage 3 vs Stage 2: same body? -> mock ignores auth validity entirely\n";
echo "  - Stage 4 vs Stage 2: same body? -> mock ignores card validity entirely\n";
echo "  - Stage 5: does your actual client code reproduce Stage 2's request/response,\n";
echo "    or does it fail differently (which would point at GenericBankClient /\n";
echo "    CardAcquirerBankClient dropping or mangling the signature/cert)?\n";
