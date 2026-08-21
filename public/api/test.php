<?php
declare(strict_types=1);
require __DIR__ . '/card_common.php';

$results = ['pass' => 0, 'fail' => 0];
function track(array &$results, array $result, bool $expectFailure = false): void
{
    $ok = $expectFailure ? !$result['ok'] : $result['ok'];
    if ($ok) $results['pass']++; else $results['fail']++;
}

echo "VouchMorph Card — REAL SWIPE COMPLETION TEST\n";
echo "==============================================\n\n";

$login = login('phone', LOGIN_PHONE);
echo str_pad($login['ok'] ? '[PASS]' : '[FAIL]', 8) . "login\n\n";
if (!$login['ok']) exit(1);

$cardSuffix = '2084';

// Fresh TOTP secret + live code
global $__cookieJar;
$totpResult = callApi('/api/v1/cards/RegenerateTotp.php', ['card_suffix' => $cardSuffix], true);
$secret = $totpResult['body']['secret'] ?? null;
if (!$secret) { echo "Could not get TOTP secret. Stopping.\n"; exit(1); }
echo "Got fresh TOTP secret, computing live code...\n\n";

function liveTotp(string $secret): string {
    require_once __DIR__ . '/totp.php';
    return totp($secret);
}

// ---- Hook a single real source ----
echo "---- Hook single source (ZURUBANK account, 25 BWP) ----\n";
$hook = callApi(HOOK_ENDPOINT, [
    'card_suffix' => $cardSuffix,
    'sources' => [
        ['institution' => 'ZURUBANK', 'asset_type' => 'ACCOUNT', 'identifier' => ZURUBANK_ACCOUNT, 'authorized_amount' => 25],
    ],
], true);
printResult('hook_single_source', $hook);
track($results, $hook);
$hookRef = $hook['body']['hook_reference'] ?? null;

if (!$hookRef) { echo "No hook — stopping.\n"; exit(1); }

// ---- Swipe with a REAL, currently-valid TOTP code ----
echo "---- Swipe with LIVE valid dynamic_code ----\n";
$dynamicCode = liveTotp($secret);
echo "Computed dynamic_code: {$dynamicCode}\n";

$swipe = callApi(AUTHORIZE_ENDPOINT, [
    'card_suffix' => $cardSuffix,
    'amount' => 15,
    'dynamic_code' => $dynamicCode,
    'merchant_name' => 'ZuruBank Merchant 10000001',
    'merchant_id' => ZURUBANK_MERCHANT_ID,
    'channel' => 'POS',
], false);
printResult('swipe_with_real_totp (15 BWP against 25 BWP hooked)', $swipe);
track($results, $swipe);

echo "\n";

// ---- Check finalize outcome (async — poll My.php / hook state) ----
sleep(2);
$ch = curl_init(BASE_URL . MY_CARD_ENDPOINT);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $__cookieJar, CURLOPT_COOKIEJAR => $__cookieJar, CURLOPT_TIMEOUT => 30]);
$raw = curl_exec($ch); curl_close($ch);
$afterState = json_decode($raw, true);
echo "---- Card state after swipe attempt ----\n";
echo json_encode($afterState['data'] ?? $afterState, JSON_PRETTY_PRINT) . "\n\n";

// Cleanup — unhook if anything remains
if ($hookRef) {
    $cleanup = callApi(UNHOOK_ENDPOINT, ['hook_reference' => $hookRef], true);
    echo "---- Cleanup unhook ----\n";
    printResult('cleanup_unhook', $cleanup);
}

echo "========================================================\n";
echo "PASS: {$results['pass']}   FAIL: {$results['fail']}\n";
