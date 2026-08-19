<?php
/**
 * FNBB FIELD-ISOLATION DIAGNOSTIC — run directly:
 *   php diagnose_fnbb_fields.php
 *
 * We know:
 *   - A minimal, hand-crafted payload (action=VERIFY_ASSET, amount=25,
 *     no extra fields) succeeds against /Preauth.php.
 *   - The REAL payload SwapService builds (action=PRE_AUTH_CHECK,
 *     amount=1, PLUS source_identifier_type + swap_type present) fails
 *     with 401 "Authentication failed", even though card_token/cvv are
 *     now correctly present in both.
 *
 * This isolates which specific difference (if any single one) is
 * responsible, by testing four variants against the real minimal
 * payload as a base:
 *   A. Baseline minimal payload (known good) — control.
 *   B. Baseline + action changed to PRE_AUTH_CHECK.
 *   C. Baseline + amount changed to 1.
 *   D. Baseline + swap_type and source_identifier_type added.
 *   E. Baseline with ALL real-payload differences applied at once
 *      (should reproduce the failure if these are the only differences).
 */

declare(strict_types=1);

$AUTOLOAD_PATH = __DIR__ . '/../vendor/autoload.php';
if (file_exists($AUTOLOAD_PATH)) {
    require_once $AUTOLOAD_PATH;
} else {
    echo "Autoload not found at {$AUTOLOAD_PATH} — adjust path.\n";
    exit(1);
}

function section(string $title): void
{
    echo "\n" . str_repeat("=", 70) . "\n{$title}\n" . str_repeat("=", 70) . "\n";
}

function dump(string $label, $value): void
{
    echo "--- {$label} ---\n";
    echo is_string($value) ? $value . "\n" : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function sendVariant(string $label, array $basePayload): void
{
    section("VARIANT: {$label}");
    $certManager = \Infrastructure\Crypto\CertificateManagerFactory::get('VOUCHMORPH');
    $basePayload['timestamp'] = time();
    $basePayload['reference'] = 'DIAG_FIELD_' . preg_replace('/\W+/', '_', strtolower($label)) . '_' . time();

    $signed = $certManager->createSignedRequest($basePayload, 'VOUCHMORPH');
    dump('Payload fields sent (keys only, for quick diff)', array_keys($signed));

    $ch = curl_init('https://zurubank-production.up.railway.app/Backend/api/Preauth.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($signed, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    dump('HTTP status', $httpCode);
    dump('RAW response body', $response ?: '(empty)');
    dump('RESULT', $httpCode === 200 ? 'SUCCEEDED' : 'FAILED');
}

// Baseline: the known-good minimal shape from the earlier successful diagnostic.
$baseline = [
    'action' => 'VERIFY_ASSET',
    'institution' => 'FNBB_ACQUIRER',
    'from_institution' => 'FNBB_ACQUIRER',
    'source_institution' => 'FNBB_ACQUIRER',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'source_identifier' => '4111111111111111',
    'card_token' => '4111111111111111',
    'cvv' => '123',
    'amount' => 25,
    'currency' => 'BWP',
];

section('BASELINE CONFIRMATION');
sendVariant('A - baseline (known good, control)', $baseline);

sendVariant('B - action changed to PRE_AUTH_CHECK', array_merge($baseline, [
    'action' => 'PRE_AUTH_CHECK',
]));

sendVariant('C - amount changed to 1', array_merge($baseline, [
    'amount' => 1,
]));

sendVariant('D - extra swap_type + source_identifier_type fields added', array_merge($baseline, [
    'swap_type' => 'DEPOSIT',
    'source_identifier_type' => 'auto',
]));

sendVariant('E - all real-payload differences combined', array_merge($baseline, [
    'action' => 'PRE_AUTH_CHECK',
    'amount' => 1,
    'swap_type' => 'DEPOSIT',
    'source_identifier_type' => 'auto',
]));

section('DIAGNOSTIC COMPLETE');
echo "Compare the RESULT line for each variant. Whichever variant(s) FAIL\n";
echo "while A succeeds identifies the exact field(s) breaking FNBB's\n";
echo "signature verification. Paste the full output back.\n";
