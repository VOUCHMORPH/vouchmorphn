<?php
/**
 * FNBB FLOAT-VS-INT DIAGNOSTIC — run directly:
 *   php diagnose_fnbb_float.php
 *
 * All 5 field-content variants in the previous diagnostic succeeded,
 * ruling out field names/set and plain literal values as the cause.
 * The one thing not yet tested: CardAcquirerBankClient's real code
 * does `(float)($config['pre_auth_amount'] ?? 1.00)` — an explicit
 * float cast — not a plain int. PHP's json_encode (default
 * serialize_precision=-1) renders a whole-number float like 1.0 as
 * "amount":1.0 (with decimal point), while a plain int renders as
 * "amount":1 (no decimal). This tests whether that byte-level
 * difference alone is what FNBB's mock rejects.
 */

declare(strict_types=1);

$AUTOLOAD_PATH = __DIR__ . '/../vendor/autoload.php';
require_once $AUTOLOAD_PATH;

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
    $basePayload['reference'] = 'DIAG_FLOAT_' . preg_replace('/\W+/', '_', strtolower($label)) . '_' . time();

    $signed = $certManager->createSignedRequest($basePayload, 'VOUCHMORPH');
    $jsonBody = json_encode($signed, JSON_UNESCAPED_SLASHES);

    dump('Raw JSON amount field (grep manually)', substr($jsonBody, (int)strpos($jsonBody, '"amount"'), 20));
    dump('Full payload byte length', strlen($jsonBody));

    $ch = curl_init('https://zurubank-production.up.railway.app/Backend/api/Preauth.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonBody,
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

$baseline = [
    'action' => 'PRE_AUTH_CHECK',
    'institution' => 'FNBB_ACQUIRER',
    'from_institution' => 'FNBB_ACQUIRER',
    'source_institution' => 'FNBB_ACQUIRER',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'source_identifier' => '4111111111111111',
    'source_identifier_type' => 'auto',
    'card_token' => '4111111111111111',
    'cvv' => '123',
    'currency' => 'BWP',
    'swap_type' => 'DEPOSIT',
];

section('Testing amount as PLAIN INT vs FLOAT CAST — everything else identical');

sendVariant('F - amount as int 1 (plain literal)', array_merge($baseline, [
    'amount' => 1,
]));

sendVariant('G - amount as (float)1.00 (exact real-code cast)', array_merge($baseline, [
    'amount' => (float)($_ENV['PRE_AUTH_AMOUNT'] ?? 1.00),
]));

section('DIAGNOSTIC COMPLETE');
echo "Compare variant F vs G's raw JSON amount field and RESULT. If F\n";
echo "succeeds and G fails, the float-cast amount (1.0 vs 1) is confirmed\n";
echo "as the cause. Paste the full output back.\n";
