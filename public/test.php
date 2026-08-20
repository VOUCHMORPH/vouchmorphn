<?php
/**
 * EXTENDED TEST SUITE — multi-source x destination-type matrix, card hooks,
 * and card-acquirer bill-based settlement.
 *
 * ============================================================
 * THREE THINGS THAT NEED CONFIRMATION BEFORE THIS SCRIPT IS TRUSTWORTHY —
 * read this before running, not after:
 * ============================================================
 *
 * 1. "Bill to source or switch" for card-sourced swaps is NOT confirmed to
 *    exist in SwapService yet. What we built and tested this session for
 *    FNBB was direct capture (debit_funds -> Capture.php) at the acquirer
 *    itself — the acquirer's own API settles the transaction, no separate
 *    "bill" message. The design discussed (verify+hold at source, deliver
 *    at destination, THEN bill source/switch instead of capturing directly
 *    at the acquirer) may require new code that doesn't exist yet. Stage
 *    CARD_BILL_* below and check the response for a real
 *    "settlement_instruction"/bill-type object — if it's absent, the
 *    capture-based flow is still what's live, and these specific cases
 *    should be expected to fail or behave differently than described,
 *    which is itself useful information, not a bug in the test.
 *
 * 2. Hook endpoint (/api/v1/cards/hook.php) was referenced as a constant in
 *    earlier test scripts this session but its request/response shape was
 *    never actually exercised or confirmed. The payload shapes below
 *    (hook_type, source_count, card_token) are best-guess, built from
 *    patterns used elsewhere in this codebase (source_identifier,
 *    destination_identifier conventions) — NOT confirmed against real
 *    endpoint documentation. Expect to need field-name corrections after
 *    the first real run's error responses.
 *
 * 3. VouchMorph-issued card details (issuance endpoint, card_token format,
 *    "push money into card" load endpoint) are assumed, not confirmed. I
 *    do not have the actual VouchMorph card issuance API in front of me.
 *    CARD_LOAD_ENDPOINT and the VouchMorph test card token below are
 *    placeholders — replace with real values before running Section 6.
 *
 * Everything in Sections 1-4 (multi-source x destination matrix, using
 * real accounts/wallets/vouchers/cards already confirmed working
 * elsewhere this session) should run as-is against the real API shape
 * already validated (execute.php, same auth headers, same payload
 * conventions as every other test this session).
 */

declare(strict_types=1);

const BASE_URL = 'https://vouchmorphn-production.up.railway.app';
const API_KEY  = 'vouchmorph_live_1aB2cD3eF4gH5iJ6'; // rotate before real use — flagged burned earlier this session
const EXECUTE_ENDPOINT = '/api/v1/swap/execute.php';
const HOOK_ENDPOINT    = '/api/v1/cards/hook.php';
const CARD_LOAD_ENDPOINT = '/api/v1/cards/load.php'; // ASSUMED — confirm real path
const COUNTRY_CODE = 'BW';
const USER_ID = 1;

const ZURUBANK_ACCOUNT    = '10000001';
const SACCUSSALIS_ACCOUNT = '10000001';
const IDENTITY_PHONE      = '+26770000000';
const IDENTITY_VALUE      = '12345678';

// Real voucher data supplied — used as CASHOUT_VOUCHER-type multi-source contributions.
const VOUCHERS = [
    ['number' => '151677045', 'pin' => '087086', 'amount' => 800],
    ['number' => '337136946', 'pin' => '333980', 'amount' => 250],
    ['number' => '128415256', 'pin' => '812078', 'amount' => 500],
    ['number' => '922561350', 'pin' => '124404', 'amount' => 500],
    ['number' => '905815500', 'pin' => '724567', 'amount' => 500],
    ['number' => '219980338', 'pin' => '767287', 'amount' => 400],
    ['number' => '887692805', 'pin' => '855748', 'amount' => 300],
    ['number' => '347517520', 'pin' => '419097', 'amount' => 200],
];

function callApi(string $endpoint, array $payload): array
{
    $ch = curl_init(BASE_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . API_KEY,
            'X-Country-Code: ' . COUNTRY_CODE,
        ],
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
    $ok = ($httpCode >= 200 && $httpCode < 300) && is_array($decoded) && ($decoded['success'] ?? true) !== false;
    return ['ok' => $ok, 'http_code' => $httpCode, 'body' => $decoded, 'raw' => $raw];
}

function ref(string $prefix): string
{
    return $prefix . '_' . time() . '_' . substr(md5((string)mt_rand()), 0, 6);
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
            'settlement_instruction' => $result['body']['settlement_instruction'] ?? $result['body']['bill'] ?? null,
        ], fn($v) => $v !== null);
        echo "        " . json_encode($summary) . "\n";
    }
    echo "\n";
}

$results = ['pass' => 0, 'fail' => 0];
function track(array &$results, array $result, bool $expectFailure = false): void
{
    $effectivelyOk = $expectFailure ? !$result['ok'] : $result['ok'];
    if ($effectivelyOk) { $results['pass']++; } else { $results['fail']++; }
}

// ============================================================
// Source-group builders. Each returns a 'sources' array shaped for
// MULTI_SOURCE payloads, matching the pattern already confirmed working
// for ms_* cases earlier this session (each source independently
// verified/held, pooled toward one destination).
// ============================================================

function sourcesAccounts(): array
{
    return [
        ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => ZURUBANK_ACCOUNT, 'amount' => 30],
        ['institution' => 'SACCUSSALIS', 'asset_type' => 'ACCOUNT', 'source_identifier' => SACCUSSALIS_ACCOUNT, 'amount' => 20],
    ];
}

function sourcesWallets(): array
{
    return [
        ['institution' => 'MTN', 'asset_type' => 'WALLET', 'source_identifier' => IDENTITY_PHONE, 'amount' => 25],
        ['institution' => 'CAZACOM', 'asset_type' => 'WALLET', 'source_identifier' => IDENTITY_PHONE, 'amount' => 15],
    ];
}

function sourcesVouchers(int $count = 2): array
{
    $sources = [];
    foreach (array_slice(VOUCHERS, 0, $count) as $v) {
        $sources[] = [
            'institution' => 'ZURUBANK', // ASSUMED issuer — vouchers may need their own institution code, confirm
            'asset_type' => 'VOUCHER',
            'source_identifier' => $v['number'],
            'voucher_pin' => $v['pin'],
            'amount' => min($v['amount'], 50), // partial draw from a larger voucher, to test partial-consumption
        ];
    }
    return $sources;
}

function sourcesCards(): array
{
    return [
        ['institution' => 'FNBB_ACQUIRER', 'asset_type' => 'VISA_MASTERCARD_CARD', 'source_identifier' => '4111111111111111', 'card_token' => '4111111111111111', 'cvv' => '123', 'amount' => 25],
        ['institution' => 'FNBB_ACQUIRER', 'asset_type' => 'VISA_MASTERCARD_CARD', 'source_identifier' => '4111111111111112', 'card_token' => '4111111111111112', 'cvv' => '456', 'amount' => 25], // second test card — confirm a second valid test PAN exists
    ];
}

function sourcesMixed(): array
{
    return [
        ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => ZURUBANK_ACCOUNT, 'amount' => 20],
        ['institution' => 'MTN', 'asset_type' => 'WALLET', 'source_identifier' => IDENTITY_PHONE, 'amount' => 15],
        ['institution' => 'FNBB_ACQUIRER', 'asset_type' => 'VISA_MASTERCARD_CARD', 'source_identifier' => '4111111111111111', 'card_token' => '4111111111111111', 'cvv' => '123', 'amount' => 15],
    ];
}

// ============================================================
// Destination-variant builder. Given a source group, builds three
// payloads: pooled sources -> CASHOUT, -> DEPOSIT, -> IDENTITY.
// ============================================================

function buildMultiSourcePayload(string $groupLabel, array $sources, string $destinationType): array
{
    $payload = [
        'swap_type' => 'MULTI_SOURCE',
        'reference' => ref('MS_' . strtoupper($groupLabel) . '_TO_' . $destinationType),
        'user_id' => USER_ID,
        'sources' => $sources,
        'strategy' => 'SMART',
        'currency' => 'BWP',
    ];

    switch ($destinationType) {
        case 'CASHOUT':
            $payload['to_institution'] = 'ZURUBANK';
            $payload['destination_institution'] = 'ZURUBANK';
            $payload['destination_asset_type'] = 'ATM_CASHOUT';
            $payload['delivery_method'] = 'ATM';
            $payload['beneficiary_phone'] = IDENTITY_PHONE;
            break;
        case 'DEPOSIT':
            $payload['to_institution'] = 'ZURUBANK';
            $payload['destination_institution'] = 'ZURUBANK';
            $payload['destination_asset_type'] = 'ACCOUNT';
            $payload['destination_identifier'] = ZURUBANK_ACCOUNT;
            $payload['destination_identifier_type'] = 'account_number';
            break;
        case 'IDENTITY':
            $payload['to_institution'] = 'ZURUBANK';
            $payload['destination_institution'] = 'ZURUBANK';
            $payload['destination_asset_type'] = 'IDENTITY';
            $payload['identity_type'] = 'phone';
            $payload['identity_value'] = IDENTITY_PHONE;
            break;
    }
    return $payload;
}

function runMultiSourceMatrix(array &$results, ?string $filter): void
{
    echo "\n========== SECTION 1-5: MULTI-SOURCE x DESTINATION-TYPE MATRIX ==========\n";

    $groups = [
        'accounts' => 'sourcesAccounts',
        'wallets' => 'sourcesWallets',
        'vouchers' => 'sourcesVouchers',
        'cards' => 'sourcesCards',
        'mixed' => 'sourcesMixed',
    ];
    $destinationTypes = ['CASHOUT', 'DEPOSIT', 'IDENTITY'];

    foreach ($groups as $groupLabel => $builderFn) {
        foreach ($destinationTypes as $destType) {
            $caseName = "ms_{$groupLabel}_to_{$destType}";
            if ($filter && stripos($caseName, $filter) === false) continue;

            $sources = $builderFn();
            $payload = buildMultiSourcePayload($groupLabel, $sources, $destType);
            $result = callApi(EXECUTE_ENDPOINT, $payload);
            printResult($caseName, $result);
            track($results, $result);
        }
    }
}

// ============================================================
// SECTION 6: Card hook tests — one source, multi-source, and finalize.
// Payload shape is BEST-GUESS (see header warning #2).
// ============================================================

function runCardHookTests(array &$results, ?string $filter): void
{
    echo "\n========== SECTION 6: CARD HOOK TESTS ==========\n";

    // 6A: single source hooked to a VouchMorph card
    if (!$filter || stripos('hook_single_source', $filter) !== false) {
        $payload = [
            'hook_type' => 'SINGLE_SOURCE',
            'reference' => ref('HOOK_SINGLE'),
            'user_id' => USER_ID,
            'card_token' => 'VMCARD_TEST_TOKEN', // ASSUMED — real VouchMorph test card token needed
            'source' => ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => ZURUBANK_ACCOUNT],
            'amount' => 50,
        ];
        $result = callApi(HOOK_ENDPOINT, $payload);
        printResult('hook_single_source_to_card', $result);
        track($results, $result);
    }

    // 6B: multiple sources hooked to a VouchMorph card (pooled authorization capacity)
    if (!$filter || stripos('hook_multi_source', $filter) !== false) {
        $payload = [
            'hook_type' => 'MULTI_SOURCE',
            'reference' => ref('HOOK_MULTI'),
            'user_id' => USER_ID,
            'card_token' => 'VMCARD_TEST_TOKEN',
            'sources' => sourcesMixed(),
        ];
        $result = callApi(HOOK_ENDPOINT, $payload);
        printResult('hook_multi_source_to_card', $result);
        track($results, $result);
    }

    // 6C: finalize — a swipe/authorization against the hooked card, drawing
    // down the pooled hold(s), then release/expire the hook (non-permanent —
    // see header note on VouchMorph card hooks not being permanently bound,
    // unlike a mainstream Visa debit card's fixed account link).
    if (!$filter || stripos('hook_finalize', $filter) !== false) {
        $payload = [
            'action' => 'FINALIZE_HOOK',
            'reference' => ref('HOOK_FINALIZE'),
            'card_token' => 'VMCARD_TEST_TOKEN',
            'amount' => 30, // partial draw of the hooked pool, to confirm remainder handling
        ];
        $result = callApi(HOOK_ENDPOINT, $payload);
        printResult('hook_finalize_partial_swipe', $result);
        track($results, $result);

        // Confirm the hook does NOT persist after finalize/expiry — this is
        // the specific "not like a permanently hooked mainstream card"
        // requirement. Re-attempt a swipe against the same card_token after
        // finalize; expect this to fail, since the hook should not silently
        // remain valid/reusable.
        $repeatResult = callApi(HOOK_ENDPOINT, array_merge($payload, ['reference' => ref('HOOK_FINALIZE_REPEAT')]));
        printResult('hook_does_not_persist_after_finalize [expected-failure — reuse must be rejected]', $repeatResult, true);
        track($results, $repeatResult, true);
    }
}

// ============================================================
// SECTION 7: Card acquirer via bill-based settlement (see header warning #1)
// ============================================================

function runCardBillSettlementTests(array &$results, ?string $filter): void
{
    echo "\n========== SECTION 7: CARD ACQUIRER — BILL-BASED SETTLEMENT ==========\n";
    echo "NOTE: this exercises the DESIGNED flow (verify+hold at source, deliver\n";
    echo "at destination, bill source/switch instead of direct capture). If the\n";
    echo "response contains no settlement_instruction/bill object, the live code\n";
    echo "is still doing direct capture at the acquirer -- see header warning #1.\n\n";

    $cases = [
        'card_bill_to_deposit' => ['destination_asset_type' => 'ACCOUNT', 'destination_identifier' => ZURUBANK_ACCOUNT, 'destination_identifier_type' => 'account_number'],
        'card_bill_to_cashout' => ['destination_asset_type' => 'ATM_CASHOUT', 'delivery_method' => 'ATM', 'beneficiary_phone' => IDENTITY_PHONE],
        'card_bill_to_identity' => ['destination_asset_type' => 'IDENTITY', 'identity_type' => 'phone', 'identity_value' => IDENTITY_PHONE],
    ];

    foreach ($cases as $name => $destFields) {
        if ($filter && stripos($name, $filter) === false) continue;
        $payload = array_merge([
            'swap_type' => 'DEPOSIT',
            'reference' => ref(strtoupper($name)),
            'user_id' => USER_ID,
            'from_institution' => 'FNBB_ACQUIRER',
            'source_institution' => 'FNBB_ACQUIRER',
            'asset_type' => 'VISA_MASTERCARD_CARD',
            'source_identifier' => '4111111111111111',
            'card_token' => '4111111111111111',
            'cvv' => '123',
            'amount' => 40,
            'currency' => 'BWP',
            'to_institution' => 'ZURUBANK',
            'destination_institution' => 'ZURUBANK',
            'settlement_mode' => 'BILL', // ASSUMED flag to request bill-based settlement rather than direct capture
        ], $destFields);
        $result = callApi(EXECUTE_ENDPOINT, $payload);
        printResult($name, $result);
        track($results, $result);
    }
}

// ============================================================
// SECTION 8: Mainstream Visa card vs VouchMorph-issued card comparison
// ============================================================

function runCardTypeComparisonTests(array &$results, ?string $filter): void
{
    echo "\n========== SECTION 8: MAINSTREAM (VISA) vs VOUCHMORPH CARD ==========\n";

    // 8A: mainstream Visa test card as swap source (same as existing FNBB tests — control)
    if (!$filter || stripos('visa_source', $filter) !== false) {
        $result = callApi(EXECUTE_ENDPOINT, [
            'swap_type' => 'DEPOSIT', 'reference' => ref('VISA_SOURCE'), 'user_id' => USER_ID,
            'from_institution' => 'FNBB_ACQUIRER', 'source_institution' => 'FNBB_ACQUIRER',
            'asset_type' => 'VISA_MASTERCARD_CARD', 'source_identifier' => '4111111111111111',
            'card_token' => '4111111111111111', 'cvv' => '123', 'amount' => 20, 'currency' => 'BWP',
            'to_institution' => 'ZURUBANK', 'destination_institution' => 'ZURUBANK',
            'destination_asset_type' => 'ACCOUNT', 'destination_identifier' => ZURUBANK_ACCOUNT,
            'destination_identifier_type' => 'account_number',
        ]);
        printResult('visa_mainstream_card_as_source (control)', $result);
        track($results, $result);
    }

    // 8B: push money INTO a VouchMorph-issued card (load), from a single source
    if (!$filter || stripos('vmcard_load_single', $filter) !== false) {
        $result = callApi(CARD_LOAD_ENDPOINT, [
            'reference' => ref('VMCARD_LOAD_SINGLE'),
            'user_id' => USER_ID,
            'card_token' => 'VMCARD_TEST_TOKEN',
            'source' => ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'source_identifier' => ZURUBANK_ACCOUNT],
            'amount' => 50,
        ]);
        printResult('vmcard_load_from_single_source', $result);
        track($results, $result);
    }

    // 8C: push money INTO a VouchMorph card from multiple sources
    if (!$filter || stripos('vmcard_load_multi', $filter) !== false) {
        $result = callApi(CARD_LOAD_ENDPOINT, [
            'reference' => ref('VMCARD_LOAD_MULTI'),
            'user_id' => USER_ID,
            'card_token' => 'VMCARD_TEST_TOKEN',
            'sources' => sourcesMixed(),
        ]);
        printResult('vmcard_load_from_multi_source', $result);
        track($results, $result);
    }

    // 8D: VouchMorph card hooked to ANOTHER card (card-to-card hook) — the
    // "hooked to a card" case named explicitly in the request.
    if (!$filter || stripos('vmcard_hook_to_card', $filter) !== false) {
        $result = callApi(HOOK_ENDPOINT, [
            'hook_type' => 'SINGLE_SOURCE',
            'reference' => ref('VMCARD_HOOK_TO_CARD'),
            'user_id' => USER_ID,
            'card_token' => 'VMCARD_TEST_TOKEN',
            'source' => ['institution' => 'FNBB_ACQUIRER', 'asset_type' => 'VISA_MASTERCARD_CARD', 'source_identifier' => '4111111111111111', 'card_token' => '4111111111111111', 'cvv' => '123'],
            'amount' => 30,
        ]);
        printResult('vmcard_hooked_to_another_card', $result);
        track($results, $result);
    }
}

// ============================================================
// RUN
// ============================================================

$options = getopt('', ['filter::', 'section::']);
$filter = $options['filter'] ?? null;
$section = $options['section'] ?? 'all';

echo "VouchMorph Extended Test Suite — multi-source matrix, card hooks, card-type comparison\n";
echo "Filter: " . ($filter ?? '(none)') . "   Section: {$section}\n";

if ($section === 'all' || $section === '1') runMultiSourceMatrix($results, $filter);
if ($section === 'all' || $section === '6') runCardHookTests($results, $filter);
if ($section === 'all' || $section === '7') runCardBillSettlementTests($results, $filter);
if ($section === 'all' || $section === '8') runCardTypeComparisonTests($results, $filter);

echo "\n========================================================\n";
echo "PASS: {$results['pass']}   FAIL: {$results['fail']}\n";
echo "\nReminder: three assumption areas flagged at the top of this file need\n";
echo "confirmation against real API shapes before these results are trusted --\n";
echo "this is a first pass built to surface exactly WHERE the real shape\n";
echo "differs from what's assumed, not a guarantee of correctness.\n";
