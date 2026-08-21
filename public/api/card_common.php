<?php
declare(strict_types=1);

const BASE_URL = 'https://vouchmorphn-production.up.railway.app';
const API_KEY  = 'vouchmorph_live_1aB2cD3eF4gH5iJ6'; // ROTATE — still plaintext in a shared script
const COUNTRY_CODE = 'BW';
const USER_ID = 1;

const EXECUTE_ENDPOINT      = '/api/v1/swap/execute.php';
const HOOK_ENDPOINT         = '/api/v1/cards/hook.php';
const UNHOOK_ENDPOINT       = '/api/v1/cards/unhook.php'; // CONFIRMED real path (lowercase) — deployed this session
const ACTIVATE_ENDPOINT     = '/api/v1/cards/Activate.php';
const MY_CARD_ENDPOINT      = '/api/v1/cards/My.php';
const GET_SOURCES_ENDPOINT  = '/api/v1/cards/GetCardSources.php';
const AUTHORIZE_ENDPOINT    = '/api/v1/cards/authorize.php';
const LOGIN_ENDPOINT        = null; // BLOCKED — need real path + test credentials

// Real account/phone data, from the participants.yaml-derived test
// scripts pasted earlier this session. Vouchers deliberately NOT used
// per instruction.
const ZURUBANK_ACCOUNT    = '10000001';
const SACCUSSALIS_ACCOUNT = '10000001';
const ABSA_ACCOUNT        = '10000002'; // balance 1,000,000
const ABSA_ACCOUNT_2      = '10000003'; // balance 500 — distinct account for same-institution-repeat tests
const ABSA_ACCOUNT_3      = '10000004'; // balance 500

// REAL agent/merchant account for POS settlement testing — confirmed
// by the person running these tests as an actual ZURUBANK agent/
// merchant account, not a placeholder. Used to test
// settleCardSwipeToMerchant() against a genuine destination account
// rather than the resolveMerchantAccountByMerchantId() placeholder.
const ZURUBANK_MERCHANT_ACCOUNT = '10000001';
const ZURUBANK_MERCHANT_ID      = 'ZURUBANK_MERCHANT_10000001'; // synthetic ISO 8583 field-42 value mapping to the above

const CAZACOM_PHONE = '+26770000000';
const MTN_PHONE_1   = '+26779000000'; // balance 5000
const MTN_PHONE_2   = '+26779000001'; // balance 5000
const MTN_PHONE_3   = '+26779000002'; // balance 5000

const IDENTITY_PHONE      = '+26770000000';
const LOGIN_NATIONAL_ID   = '657613013'; // CONFIRMED NOT FOUND on this deployment — see login test
const LOGIN_PHONE         = '+26770000000'; // CONFIRMED WORKING login identifier

const TEST_CARD_PRIMARY = ['pan' => '4111111111111111', 'cvv' => '123'];

// Test-mode ISO 8583 endpoint (wraps Iso8583AuthorizationBridge for
// HTTP testing) — not yet confirmed deployed. See test report for
// status.
const ISO8583_TEST_ENDPOINT = '/api/v1/cards/iso8583_test.php';

/**
 * Real login, via /user/login.php's SUPER TEST MODE (PIN is not
 * verified — any value works, only the identifier needs to match a
 * real user row). Success = a 302 redirect to user_dashboard.php;
 * the cookie jar captures the session cookie automatically for every
 * subsequent callApi(..., useSession: true) call.
 */
function login(string $identifierType, string $identifier, string $pin = '0000'): array
{
    global $__cookieJar;

    $ch = curl_init(BASE_URL . '/user/login.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'identifier_type' => $identifierType,
            'identifier' => $identifier,
            'pin' => $pin,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_COOKIEFILE => $__cookieJar,
        CURLOPT_COOKIEJAR => $__cookieJar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // FIX: curl_exec() returns false (not a string) on a transport-level
    // failure — e.g. this container calling its own public HTTPS domain
    // from inside itself, which can fail on DNS/loopback/TLS grounds even
    // when the app is otherwise healthy. Guard against that before ever
    // touching $raw as a string; the previous version crashed with an
    // uncaught TypeError here under strict_types when $raw was false.
    if ($raw === false) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'curl_error' => $curlError ?: 'curl_exec() returned false with no curl_error set — likely a self-loopback/DNS/TLS issue calling this host\'s own public domain from inside its own container',
            'raw_headers' => null,
        ];
    }

    $redirectedToLoggedIn = ($httpCode >= 300 && $httpCode < 400) && stripos($raw, 'user_dashboard.php') !== false;

    return [
        'ok' => $redirectedToLoggedIn,
        'http_code' => $httpCode,
        'curl_error' => $curlError ?: null,
        'raw_headers' => $redirectedToLoggedIn ? null : substr($raw, 0, 800), // only keep on failure, for diagnosis
    ];
}

$__cookieJar = '/tmp/vouchmorph_test_cookies.txt';

/**
 * @param bool $useSession true = send stored session cookies (for
 *   card endpoints), false = send X-API-Key (for execute.php/authorize.php)
 */
function callApi(string $endpoint, array $payload, bool $useSession = false): array
{
    global $__cookieJar;

    $headers = ['Content-Type: application/json'];
    if ($useSession) {
        // Session-cookie auth — requires a prior successful login that
        // populated $__cookieJar. See loginBlocked() below.
    } else {
        $headers[] = 'X-API-Key: ' . API_KEY;
        $headers[] = 'X-Country-Code: ' . COUNTRY_CODE;
    }

    $ch = curl_init(BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $__cookieJar,
        CURLOPT_COOKIEJAR => $__cookieJar,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'http_code' => 0, 'body' => null, 'curl_error' => $curlError, 'raw' => null];
    }
    $decoded = json_decode($raw, true);
    $httpOk = ($httpCode >= 200 && $httpCode < 300);
    $bodyOk = is_array($decoded) && ($decoded['success'] ?? $decoded['processed'] ?? true) !== false;
    return ['ok' => $httpOk && $bodyOk, 'http_code' => $httpCode, 'body' => $decoded, 'raw' => $raw];
}

function ref(string $prefix): string
{
    return $prefix . '_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);
}

/**
 * Pulls out and prints exactly the fee/forex fields we care about for
 * this test round, wherever they show up in the response shape
 * (top-level, data.*, or fee_calculation_details.*).
 */
function printFeeBreakdown(array $body): void
{
    $data = $body['data'] ?? $body;
    $fee = $data['fee_calculation_details'] ?? $data['fee_breakdown'] ?? null;
    $fields = array_filter([
        'fee' => $data['fee'] ?? null,
        'total_fee' => $fee['total_fee'] ?? null,
        'swap_levy_total' => $fee['swap_levy_total'] ?? $fee['swap_levy'] ?? null,
        'per_source_cut' => $fee['per_source_cut'] ?? null,
        'total_source_cut' => $fee['total_source_cut'] ?? null,
        'destination_cut' => $fee['destination_cut'] ?? null,
        'platform_cut' => $fee['platform_cut'] ?? null,
        'immediate_charge' => $fee['immediate_charge'] ?? null,
        'deferred_charge' => $fee['deferred_charge'] ?? null,
        'forex_applied' => $data['forex_applied'] ?? $fee['forex_applied'] ?? null,
        'exchange_rate' => $data['exchange_rate'] ?? $fee['exchange_rate'] ?? null,
    ], fn($v) => $v !== null);

    if (!empty($fields)) {
        echo "        fees/forex: " . json_encode($fields) . "\n";
    } else {
        echo "        fees/forex: (none found in response — nothing to report)\n";
    }
}

function printResult(string $label, array $result, bool $expectFailure = false): void
{
    $effectivelyOk = $expectFailure ? !$result['ok'] : $result['ok'];
    $status = $effectivelyOk ? 'PASS' : 'FAIL';
    echo str_pad("[$status]", 8) . $label . " (HTTP {$result['http_code']})\n";
    if (isset($result['curl_error']) && $result['curl_error']) {
        echo "        curl error: {$result['curl_error']}\n";
    }
    if ($result['body'] ?? null) {
        $summary = array_filter([
            'success' => $result['body']['success'] ?? null,
            'status'  => $result['body']['status'] ?? ($result['body']['data']['status'] ?? null),
            'message' => $result['body']['message'] ?? ($result['body']['data']['message'] ?? null),
            'error'   => $result['body']['error'] ?? null,
        ], fn($v) => $v !== null);
        echo "        " . json_encode($summary) . "\n";
        printFeeBreakdown($result['body']);
    }
    echo "\n";
}

function loginBlocked(string $whatItWouldHaveTested): void
{
    echo "[BLOCKED] {$whatItWouldHaveTested}\n";
    echo "          Needs a session cookie — no login endpoint/credentials supplied yet.\n";
    echo "          Ready to run the instant a login path is provided.\n\n";
}
