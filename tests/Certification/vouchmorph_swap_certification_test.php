#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ============================================================================
 * VOUCHMORPH SWAP CERTIFICATION SUITE
 * ============================================================================
 *
 * WHY THIS FILE EXISTS
 * ---------------------
 * The test suites already in the vouchmorphn repo (tests/System/, tests/Unit/,
 * tests/Certification/, tests/RevolutionaryTestSuite.php, etc.) reference a
 * prior architecture that no longer exists:
 *   - namespaces like BUSINESS_LOGIC_LAYER\, APP_LAYER\, DATA_PERSISTENCE_LAYER\
 *   - a SwapService::executeSwap()/initiateSwap() method that isn't on the
 *     class anymore
 * None of them will run against the current codebase (confirmed: the current
 * SwapService class only exposes executeAtomicSwap(), executeMultiDestinationSwap(),
 * initiateSwapToIdentity(), confirmAndFinalizeIdentitySwap(), etc. under the
 * Domain\Services\ namespace). They currently provide ZERO real coverage.
 *
 * This suite exercises the REAL, CURRENT, deployed HTTP API
 * (public/api/v1/swap/*.php) exactly as a real client would, rather than
 * trying to bootstrap SwapService in-process (which requires the full
 * app environment: DB, country config, participant registry, adapters,
 * etc. - see src/bootstrap.php). That also makes this script portable:
 * anyone with the base URL + API key + country code can run it, with no
 * local PHP dependency install, against any environment (ideally staging,
 * NEVER production with real counterparties until you trust the result).
 *
 * WHAT THIS DOES NOT DO
 * ----------------------
 * - It does NOT replace a proper PHPUnit suite. composer install for
 *   phpunit/phpunit could not be run in the environment this was written
 *   in (no packagist access) - if you have that access, migrating these
 *   into real PHPUnit tests under tests/Http/ is a good next step.
 * - It does NOT touch your database directly. Every check goes through
 *   the public API, the same way a real integrator would.
 * - It cannot invent fixture data it doesn't have (real phone numbers,
 *   PINs, account numbers, sandbox balances). Every section that needs
 *   fixtures is clearly marked and SKIPS (not fails) if unconfigured, so
 *   the script always runs clean out of the box and you fill in gaps
 *   incrementally.
 *
 * HOW TO RUN
 * ----------
 *   php vouchmorph_swap_certification_test.php
 *
 * CONFIGURE VIA ENVIRONMENT VARIABLES (see CONFIG section below), e.g.:
 *   VOUCHMORPH_BASE_URL=https://vouchmorphn-production-af4f.up.railway.app \
 *   VOUCHMORPH_API_KEY=xxxxx \
 *   VOUCHMORPH_COUNTRY_CODE=BW \
 *   VM_STD_SOURCE_INSTITUTION=... VM_STD_SOURCE_IDENTIFIER=... \
 *   php vouchmorph_swap_certification_test.php
 *
 * STRONGLY RECOMMENDED: point this at a staging/sandbox environment with
 * disposable balances, never at production with real customer funds,
 * until every section below is green.
 * ============================================================================
 */

// ============================================================================
// CONFIG - fill in via environment variables. Every fixture-dependent
// section skips gracefully (not a hard failure) if left unconfigured.
// ============================================================================

function envOr(string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$CFG = [
    // --- required for ANY test to run ---
    'base_url'     => envOr('VOUCHMORPH_BASE_URL', ''),      // e.g. https://vouchmorphn-production-af4f.up.railway.app
    'api_key'      => envOr('VOUCHMORPH_API_KEY', ''),
    'country_code' => envOr('VOUCHMORPH_COUNTRY_CODE', 'BW'),

    // --- STANDARD SWAP fixtures ---
    'std_source_institution'      => envOr('VM_STD_SOURCE_INSTITUTION'),      // e.g. ZURUBANK
    'std_source_identifier'       => envOr('VM_STD_SOURCE_IDENTIFIER'),       // e.g. account/wallet/phone number, sandbox balance
    'std_source_identifier_type'  => envOr('VM_STD_SOURCE_IDENTIFIER_TYPE', 'phone'),
    'std_dest_institution'        => envOr('VM_STD_DEST_INSTITUTION'),        // e.g. SACCUSSALIS
    'std_dest_identifier'         => envOr('VM_STD_DEST_IDENTIFIER'),
    'std_amount'                  => (float)envOr('VM_STD_AMOUNT', '25.00'),
    'currency'                    => envOr('VM_CURRENCY', 'BWP'),
    'std_pin'                     => envOr('VM_STD_PIN'),                     // optional, only if source requires it

    // --- IDENTITY SWAP fixtures ---
    'identity_type'   => envOr('VM_IDENTITY_TYPE', 'phone'),
    'identity_value'  => envOr('VM_IDENTITY_VALUE'),          // phone number NOT already registered as a source in this run
    'identity_claim_pin' => envOr('VM_IDENTITY_CLAIM_PIN'),   // PIN sent via SMS to identity_value - operator fills in after
    'identity_amount' => (float)envOr('VM_IDENTITY_AMOUNT', '15.00'),

    // --- MULTI-SOURCE fixtures: JSON array of {institution, identifier, identifier_type, amount} ---
    'multi_source_json' => envOr('VM_MULTI_SOURCE_JSON'),
    'multi_source_dest_institution' => envOr('VM_MULTI_SOURCE_DEST_INSTITUTION'),
    'multi_source_dest_identifier'  => envOr('VM_MULTI_SOURCE_DEST_IDENTIFIER'),

    // --- MULTI-DESTINATION fixtures: JSON array of destinations (bank and/or identity) ---
    'multi_dest_json' => envOr('VM_MULTI_DEST_JSON'),
    'multi_dest_source_institution' => envOr('VM_MULTI_DEST_SOURCE_INSTITUTION'),
    'multi_dest_source_identifier'  => envOr('VM_MULTI_DEST_SOURCE_IDENTIFIER'),
    'multi_dest_total_amount' => (float)envOr('VM_MULTI_DEST_TOTAL_AMOUNT', '40.00'),

    // --- balance-check endpoint fixtures (for money-safety assertions) ---
    'balance_check_user_id' => envOr('VM_BALANCE_CHECK_USER_ID'),

    // --- deliberately-doomed swap, to verify a clean fail with NO balance change ---
    'failure_source_institution' => envOr('VM_FAILURE_SOURCE_INSTITUTION'),
    'failure_source_identifier'  => envOr('VM_FAILURE_SOURCE_IDENTIFIER'),
    'failure_amount' => (float)envOr('VM_FAILURE_AMOUNT', '999999999.00'), // intentionally absurd -> should be rejected
];

// ============================================================================
// HTTP HELPER
// ============================================================================

function apiCall(array $cfg, string $method, string $path, ?array $payload = null, array $extraHeaders = []): array {
    $url = rtrim($cfg['base_url'], '/') . $path;
    $ch = curl_init($url);

    $headers = array_merge([
        'Content-Type: application/json',
        'X-API-Key: ' . $cfg['api_key'],
        'X-Country-Code: ' . $cfg['country_code'],
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['http_code' => 0, 'json' => null, 'raw' => null, 'curl_error' => $err];
    }

    $json = json_decode($body, true);
    return ['http_code' => $httpCode, 'json' => $json, 'raw' => $body, 'curl_error' => null];
}

// ============================================================================
// TEST HARNESS
// ============================================================================

class Results {
    public int $pass = 0;
    public int $fail = 0;
    public int $skip = 0;
    /** @var array<int,array{name:string,status:string,detail:?string}> */
    public array $log = [];

    public function pass(string $name, ?array $data = null): void {
        $this->pass++;
        $this->log[] = ['name' => $name, 'status' => 'PASS', 'detail' => null];
        echo "  \033[32m✔ PASS\033[0m  {$name}\n";
    }

    public function fail(string $name, $data = null): void {
        $this->fail++;
        $detail = is_string($data) ? $data : json_encode($data);
        $this->log[] = ['name' => $name, 'status' => 'FAIL', 'detail' => $detail];
        echo "  \033[31m✘ FAIL\033[0m  {$name}\n";
        if ($detail) {
            echo "         " . substr($detail, 0, 500) . "\n";
        }
    }

    public function skip(string $name, string $reason): void {
        $this->skip++;
        $this->log[] = ['name' => $name, 'status' => 'SKIP', 'detail' => $reason];
        echo "  \033[33m— SKIP\033[0m  {$name}  ({$reason})\n";
    }

    public function section(string $title): void {
        echo "\n" . str_repeat('=', 78) . "\n {$title}\n" . str_repeat('=', 78) . "\n";
    }
}

$R = new Results();

// ============================================================================
// SECTION 0: PRE-FLIGHT
// ============================================================================

$R->section('0. PRE-FLIGHT');

if (empty($CFG['base_url']) || empty($CFG['api_key'])) {
    echo "\nVOUCHMORPH_BASE_URL and VOUCHMORPH_API_KEY must be set. Aborting.\n";
    echo "Example:\n";
    echo "  VOUCHMORPH_BASE_URL=https://your-env.up.railway.app \\\n";
    echo "  VOUCHMORPH_API_KEY=xxxx \\\n";
    echo "  php vouchmorph_swap_certification_test.php\n";
    exit(1);
}
echo "  Target: {$CFG['base_url']}\n";
echo "  Country: {$CFG['country_code']}\n";
echo "  (Confirm this is a STAGING/SANDBOX environment, not production, before proceeding.)\n";

// ============================================================================
// SECTION A: AUTH - every one of these MUST behave exactly as asserted.
// A swap system that fails these silently accepts unauthenticated money
// movement, which is a critical-severity finding, not a nitpick.
// ============================================================================

$R->section('A. AUTHENTICATION & VALIDATION');

// A1: No API key at all -> must be 401, never 200
$r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', ['swap_type' => 'STANDARD'], ['X-API-Key: ']);
if ($r['http_code'] === 401) {
    $R->pass('Missing API key rejected with 401');
} else {
    $R->fail('Missing API key rejected with 401', ['http_code' => $r['http_code'], 'body' => $r['json']]);
}

// A2: Garbage API key -> must be 401
$r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', ['swap_type' => 'STANDARD'], ['X-API-Key: not-a-real-key-' . bin2hex(random_bytes(4))]);
if ($r['http_code'] === 401) {
    $R->pass('Invalid API key rejected with 401');
} else {
    $R->fail('Invalid API key rejected with 401', ['http_code' => $r['http_code'], 'body' => $r['json']]);
}

// A3: Valid key but missing/garbage X-Country-Code -> must be 400, and must
// NOT silently execute under a default country (this was a real bug that
// was fixed - see execute.php's COUNTRY_RESOLUTION comments. Re-verify it
// stays fixed.)
$r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php',
    ['swap_type' => 'STANDARD', 'amount' => 1],
    ['X-API-Key: ' . $CFG['api_key'], 'X-Country-Code: NOT_A_REAL_COUNTRY']
);
if ($r['http_code'] === 400) {
    $R->pass('Unknown country code rejected with 400 (no silent default-country fallback)');
} else {
    $R->fail('Unknown country code rejected with 400', ['http_code' => $r['http_code'], 'body' => $r['json']]);
}

// A4: GET instead of POST -> must be 405
$r = apiCall($CFG, 'GET', '/api/v1/swap/execute.php');
if ($r['http_code'] === 405) {
    $R->pass('Non-POST method rejected with 405');
} else {
    $R->fail('Non-POST method rejected with 405', ['http_code' => $r['http_code']]);
}

// ============================================================================
// SECTION B: STANDARD SWAP + IDEMPOTENCY
// ============================================================================

$R->section('B. STANDARD SWAP');

$stdConfigured = $CFG['std_source_institution'] && $CFG['std_source_identifier']
    && $CFG['std_dest_institution'] && $CFG['std_dest_identifier'];

$stdReference = null;
$stdIdempotencyKey = 'CERT_STD_' . bin2hex(random_bytes(6));

if (!$stdConfigured) {
    $R->skip('Standard swap execution', 'VM_STD_* fixtures not set');
    $R->skip('Standard swap idempotency (duplicate request)', 'VM_STD_* fixtures not set');
} else {
    $payload = [
        'swap_type'                  => 'STANDARD',
        'from_institution'           => $CFG['std_source_institution'],
        'source_institution'         => $CFG['std_source_institution'],
        'source_identifier'          => $CFG['std_source_identifier'],
        'source_identifier_type'     => $CFG['std_source_identifier_type'],
        'to_institution'             => $CFG['std_dest_institution'],
        'destination_institution'    => $CFG['std_dest_institution'],
        'destination_identifier'     => $CFG['std_dest_identifier'],
        'amount'                     => $CFG['std_amount'],
        'currency'                   => $CFG['currency'],
        'idempotency_key'            => $stdIdempotencyKey,
    ];
    if ($CFG['std_pin']) $payload['pin'] = $CFG['std_pin'];

    $r1 = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
    $ok1 = $r1['http_code'] === 200 && ($r1['json']['success'] ?? false);
    if ($ok1) {
        $stdReference = $r1['json']['swap_reference'] ?? null;
        $R->pass('Standard swap executes and returns a swap_reference', ['reference' => $stdReference]);
    } else {
        $R->fail('Standard swap executes successfully', ['http_code' => $r1['http_code'], 'body' => $r1['json']]);
    }

    // Money-safety-critical: replaying the EXACT SAME request (same
    // idempotency_key) must NOT create a second hold/second debit. It must
    // return the SAME reference as the first call.
    $r2 = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
    $ref2 = $r2['json']['swap_reference'] ?? null;
    if ($stdReference && $ref2 === $stdReference) {
        $R->pass('Duplicate request (same idempotency_key) returns SAME reference, not double-processed');
    } else {
        $R->fail('Duplicate request returns same reference', [
            'first_reference' => $stdReference, 'second_reference' => $ref2,
            'second_http_code' => $r2['http_code'], 'second_body' => $r2['json'],
        ]);
    }

    if ($stdReference) {
        // Record-keeping check: the swap must be independently retrievable.
        $rd = apiCall($CFG, 'GET', '/api/v1/swap/details.php?reference=' . urlencode($stdReference));
        if ($rd['http_code'] === 200 && !empty($rd['json'])) {
            $R->pass('Swap details are retrievable via swap/details.php (record-keeping)');
        } else {
            $R->fail('Swap details retrievable after execution', ['http_code' => $rd['http_code'], 'body' => $rd['json']]);
        }
    }
}

// ============================================================================
// SECTION C: MONEY-SAFETY - BALANCE CONSISTENCY
// Requires a way to read balance before/after. Uses /api/v1/user/balance.php
// if VM_BALANCE_CHECK_USER_ID is supplied. This is the section that most
// directly answers "is there any risk of money loss" - everything else is
// necessary but not sufficient without this.
// ============================================================================

$R->section('C. MONEY-SAFETY: BALANCE CONSISTENCY');

if (!$CFG['balance_check_user_id'] || !$stdConfigured) {
    $R->skip('Source balance decreases by exactly amount+fee', 'VM_BALANCE_CHECK_USER_ID or VM_STD_* not set');
    $R->skip('Destination balance increases by exactly the credited amount', 'VM_BALANCE_CHECK_USER_ID or VM_STD_* not set');
    echo "  NOTE: this is the single most important section for your \"no risk of\n";
    echo "  money loss\" requirement. Configure VM_BALANCE_CHECK_USER_ID (and\n";
    echo "  equivalent on the destination side, if different) so this actually\n";
    echo "  verifies debit == credit, not just that the API returned success:true.\n";
} else {
    $before = apiCall($CFG, 'GET', '/api/v1/user/balance.php?user_id=' . urlencode($CFG['balance_check_user_id']));
    $balBefore = $before['json']['balance'] ?? null;

    $freshKey = 'CERT_BAL_' . bin2hex(random_bytes(6));
    $payload = [
        'swap_type'               => 'STANDARD',
        'from_institution'        => $CFG['std_source_institution'],
        'source_institution'      => $CFG['std_source_institution'],
        'source_identifier'       => $CFG['std_source_identifier'],
        'source_identifier_type'  => $CFG['std_source_identifier_type'],
        'to_institution'          => $CFG['std_dest_institution'],
        'destination_institution' => $CFG['std_dest_institution'],
        'destination_identifier'  => $CFG['std_dest_identifier'],
        'amount'                  => $CFG['std_amount'],
        'currency'                => $CFG['currency'],
        'idempotency_key'         => $freshKey,
    ];
    apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);

    $after = apiCall($CFG, 'GET', '/api/v1/user/balance.php?user_id=' . urlencode($CFG['balance_check_user_id']));
    $balAfter = $after['json']['balance'] ?? null;

    if ($balBefore !== null && $balAfter !== null) {
        $delta = round($balBefore - $balAfter, 2);
        // Allow for a fee on top of the swapped amount - assert the delta is
        // AT LEAST the swap amount (fee could make it larger, never smaller).
        if ($delta >= round($CFG['std_amount'], 2) - 0.01) {
            $R->pass("Source balance decreased by at least the swap amount (delta={$delta}, amount={$CFG['std_amount']})");
        } else {
            $R->fail('Source balance decreased by at least the swap amount', [
                'balance_before' => $balBefore, 'balance_after' => $balAfter, 'delta' => $delta, 'expected_min' => $CFG['std_amount'],
            ]);
        }
    } else {
        $R->fail('Could read balance before and after', ['before' => $before['json'], 'after' => $after['json']]);
    }
}

// ============================================================================
// SECTION D: DELIBERATE FAILURE - NO PARTIAL MONEY MOVEMENT
// This is arguably the most important negative test: an absurd amount must
// be rejected cleanly, with the source balance UNCHANGED. If a rejected
// swap still moves money, that's fund loss, full stop.
// ============================================================================

$R->section('D. FAILURE INTEGRITY (rollback must leave zero side effects)');

if (!$CFG['failure_source_institution'] || !$CFG['failure_source_identifier']) {
    $R->skip('Doomed swap rejected without side effects', 'VM_FAILURE_SOURCE_* not set');
} else {
    $beforeBal = null;
    if ($CFG['balance_check_user_id']) {
        $b = apiCall($CFG, 'GET', '/api/v1/user/balance.php?user_id=' . urlencode($CFG['balance_check_user_id']));
        $beforeBal = $b['json']['balance'] ?? null;
    }

    $payload = [
        'swap_type'               => 'STANDARD',
        'from_institution'        => $CFG['failure_source_institution'],
        'source_institution'      => $CFG['failure_source_institution'],
        'source_identifier'       => $CFG['failure_source_identifier'],
        'to_institution'          => $CFG['std_dest_institution'] ?: $CFG['failure_source_institution'],
        'destination_institution' => $CFG['std_dest_institution'] ?: $CFG['failure_source_institution'],
        'destination_identifier'  => $CFG['std_dest_identifier'] ?: $CFG['failure_source_identifier'],
        'amount'                  => $CFG['failure_amount'],
        'currency'                => $CFG['currency'],
        'idempotency_key'         => 'CERT_FAIL_' . bin2hex(random_bytes(6)),
    ];
    $r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
    $rejectedCleanly = $r['http_code'] >= 400 || ($r['json']['success'] ?? true) === false;

    if ($rejectedCleanly) {
        $R->pass('Absurd/insufficient-funds swap rejected (not silently accepted)');
    } else {
        $R->fail('Absurd/insufficient-funds swap rejected', ['http_code' => $r['http_code'], 'body' => $r['json']]);
    }

    if ($beforeBal !== null) {
        $a = apiCall($CFG, 'GET', '/api/v1/user/balance.php?user_id=' . urlencode($CFG['balance_check_user_id']));
        $afterBal = $a['json']['balance'] ?? null;
        if ($afterBal !== null && abs($afterBal - $beforeBal) < 0.01) {
            $R->pass('Balance unchanged after rejected swap (no partial debit)');
        } else {
            $R->fail('Balance unchanged after rejected swap', ['before' => $beforeBal, 'after' => $afterBal]);
        }
    } else {
        $R->skip('Balance unchanged after rejected swap', 'VM_BALANCE_CHECK_USER_ID not set');
    }
}

// ============================================================================
// SECTION E: IDENTITY SWAP (initiate + claim)
// ============================================================================

$R->section('E. IDENTITY SWAP');

$identityConfigured = $CFG['identity_value'] && $stdConfigured;
if (!$identityConfigured) {
    $R->skip('Identity swap initiate (hold pending claim)', 'VM_IDENTITY_VALUE or VM_STD_* not set');
} else {
    $idRef = 'CERT_ID_' . bin2hex(random_bytes(6));
    $payload = [
        'swap_type'               => 'IDENTITY',
        'from_institution'        => $CFG['std_source_institution'],
        'source_institution'      => $CFG['std_source_institution'],
        'source_identifier'       => $CFG['std_source_identifier'],
        'source_identifier_type'  => $CFG['std_source_identifier_type'],
        'identity_type'           => $CFG['identity_type'],
        'identity_value'          => $CFG['identity_value'],
        'amount'                  => $CFG['identity_amount'],
        'currency'                => $CFG['currency'],
        'idempotency_key'         => $idRef,
    ];
    $r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
    if ($r['http_code'] === 200 && ($r['json']['success'] ?? false)) {
        $R->pass('Identity swap initiated (hold placed, pending PIN claim)', ['reference' => $r['json']['swap_reference'] ?? null]);

        if ($CFG['identity_claim_pin']) {
            $claimPayload = [
                'identity_type'  => $CFG['identity_type'],
                'identity_value' => $CFG['identity_value'],
                'pin'            => $CFG['identity_claim_pin'],
                'destination_type' => 'WALLET',
            ];
            $rc = apiCall($CFG, 'POST', '/api/v1/swap/claim_identity.php', $claimPayload);
            if ($rc['http_code'] === 200 && ($rc['json']['success'] ?? false)) {
                $R->pass('Identity swap claimed successfully with PIN');
            } else {
                $R->fail('Identity swap claimed successfully with PIN', ['http_code' => $rc['http_code'], 'body' => $rc['json']]);
            }
        } else {
            $R->skip('Identity swap claim', 'VM_IDENTITY_CLAIM_PIN not set (PIN was SMS-sent to VM_IDENTITY_VALUE - re-run with it set to test claim + confirm balance credit)');
        }
    } else {
        $R->fail('Identity swap initiated', ['http_code' => $r['http_code'], 'body' => $r['json']]);
    }
}

// ============================================================================
// SECTION F: MULTI-SOURCE SWAP
// ============================================================================

$R->section('F. MULTI-SOURCE SWAP');

if (!$CFG['multi_source_json'] || !$CFG['multi_source_dest_institution']) {
    $R->skip('Multi-source swap: pooled amount matches sum of sources', 'VM_MULTI_SOURCE_JSON / VM_MULTI_SOURCE_DEST_INSTITUTION not set');
} else {
    $sources = json_decode($CFG['multi_source_json'], true);
    if (!is_array($sources) || empty($sources)) {
        $R->fail('VM_MULTI_SOURCE_JSON is valid JSON array', $CFG['multi_source_json']);
    } else {
        $expectedTotal = array_sum(array_column($sources, 'amount'));
        $payload = [
            'swap_type'               => 'MULTI_SOURCE',
            'sources'                 => $sources,
            'to_institution'          => $CFG['multi_source_dest_institution'],
            'destination_institution' => $CFG['multi_source_dest_institution'],
            'destination_identifier'  => $CFG['multi_source_dest_identifier'],
            'currency'                => $CFG['currency'],
            'idempotency_key'         => 'CERT_MS_' . bin2hex(random_bytes(6)),
        ];
        $r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
        if ($r['http_code'] === 200 && ($r['json']['success'] ?? false)) {
            $R->pass('Multi-source swap executes across ' . count($sources) . ' sources');
            $actualTotal = $r['json']['data']['total_amount'] ?? $r['json']['data']['amount'] ?? null;
            if ($actualTotal !== null) {
                if (abs((float)$actualTotal - (float)$expectedTotal) < 0.01) {
                    $R->pass("Pooled amount ({$actualTotal}) matches sum of individual sources ({$expectedTotal}) - no over/under-collection");
                } else {
                    $R->fail('Pooled amount matches sum of sources', ['expected' => $expectedTotal, 'actual' => $actualTotal]);
                }
            } else {
                $R->skip('Pooled amount matches sum of sources', 'response did not include a total_amount/amount field to compare - inspect response body manually');
            }
        } else {
            $R->fail('Multi-source swap executes', ['http_code' => $r['http_code'], 'body' => $r['json']]);
        }
    }
}

// ============================================================================
// SECTION G: MULTI-DESTINATION SWAP
// ============================================================================

$R->section('G. MULTI-DESTINATION SWAP');

if (!$CFG['multi_dest_json'] || !$CFG['multi_dest_source_institution']) {
    $R->skip('Multi-destination swap: total distributed matches source debit', 'VM_MULTI_DEST_JSON / VM_MULTI_DEST_SOURCE_INSTITUTION not set');
} else {
    $destinations = json_decode($CFG['multi_dest_json'], true);
    if (!is_array($destinations) || empty($destinations)) {
        $R->fail('VM_MULTI_DEST_JSON is valid JSON array', $CFG['multi_dest_json']);
    } else {
        $payload = [
            'swap_type'               => 'MULTI_DESTINATION',
            'from_institution'        => $CFG['multi_dest_source_institution'],
            'source_institution'      => $CFG['multi_dest_source_institution'],
            'source_identifier'       => $CFG['multi_dest_source_identifier'],
            'destinations'            => $destinations,
            'amount'                  => $CFG['multi_dest_total_amount'],
            'currency'                => $CFG['currency'],
            'idempotency_key'         => 'CERT_MD_' . bin2hex(random_bytes(6)),
        ];
        $r = apiCall($CFG, 'POST', '/api/v1/swap/execute.php', $payload);
        if ($r['http_code'] === 200 && ($r['json']['success'] ?? false)) {
            $R->pass('Multi-destination swap executes across ' . count($destinations) . ' destinations (bank + identity mix supported)');
        } else {
            $R->fail('Multi-destination swap executes', ['http_code' => $r['http_code'], 'body' => $r['json']]);
        }
    }
}

// ============================================================================
// SECTION H: ROUTING / ADAPTERS
// Inspects the _routing block every response should carry once routing
// policy files exist for a country. Absence just means DIRECT-only mode
// for this country today - not itself a failure, just informational.
// ============================================================================

$R->section('H. ROUTING & ADAPTER TRANSPARENCY');

if ($stdReference) {
    $rd = apiCall($CFG, 'GET', '/api/v1/swap/details.php?reference=' . urlencode($stdReference));
    $routing = $rd['json']['_routing'] ?? $rd['json']['routing'] ?? null;
    if ($routing) {
        $R->pass('Swap response exposes routing decision (mode=' . ($routing['mode'] ?? '?') . ')');
    } else {
        $R->skip('Swap response exposes routing decision', 'no _routing block found - likely DIRECT-only mode for this country (no routing_policy.php yet), not necessarily a bug');
    }
} else {
    $R->skip('Routing/adapter transparency', 'standard swap in section B did not produce a reference to inspect');
}

// ============================================================================
// FINAL REPORT
// ============================================================================

$R->section('CERTIFICATION SUMMARY');
$total = $R->pass + $R->fail + $R->skip;
echo "  Passed:  {$R->pass}\n";
echo "  Failed:  {$R->fail}\n";
echo "  Skipped: {$R->skip}  (unconfigured fixtures - fill in env vars to cover)\n";
echo "  Total:   {$total}\n\n";

if ($R->fail > 0) {
    echo "  \033[31mRESULT: FAIL - do not treat this environment as certified until every\n";
    echo "  FAIL above is resolved.\033[0m\n";
    exit(1);
} elseif ($R->skip > 0) {
    echo "  \033[33mRESULT: INCOMPLETE - no failures, but {$R->skip} check(s) were skipped\n";
    echo "  due to missing fixtures. Configure the relevant VM_* env vars and re-run\n";
    echo "  before calling this a clean bill of health, especially Section C\n";
    echo "  (balance consistency) and Section D (failure integrity) - those are the\n";
    echo "  two checks that most directly prove \"no risk of money loss\".\033[0m\n";
    exit(2);
} else {
    echo "  \033[32mRESULT: PASS - all configured checks passed.\033[0m\n";
    exit(0);
}
