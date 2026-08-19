<?php
/**
 * FNBB REQUESTER-ORDER DIAGNOSTIC — run directly:
 *   php diagnose_fnbb_requester.php
 *
 * Every prior isolated test omitted 'requester' from the base payload
 * and let createSignedRequest() add it itself -- and all succeeded.
 * The REAL failing payload has 'requester' pre-set BEFORE signing
 * (SwapService::verifyAssetSigned() includes it in $verifyPayload's
 * original construction). This tests whether THAT specific difference
 * -- requester present before vs added during signing -- is what
 * breaks verification, by comparing two variants that are IDENTICAL
 * except for this one thing.
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
    $basePayload['reference'] = 'DIAG_REQ_' . preg_replace('/\W+/', '_', strtolower($label)) . '_' . time();

    dump('Base payload keys BEFORE signing (order matters)', array_keys($basePayload));

    $signed = $certManager->createSignedRequest($basePayload, 'VOUCHMORPH');
    $jsonBody = json_encode($signed, JSON_UNESCAPED_SLASHES);

    dump('Final key order AFTER signing', array_keys($signed));

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

$commonFields = [
    'action' => 'PRE_AUTH_CHECK',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'card_token' => '4111111111111111',
    'currency' => 'BWP',
    'cvv' => '123',
    'from_institution' => 'FNBB_ACQUIRER',
    'institution' => 'FNBB_ACQUIRER',
    'amount' => 1,
    'source_identifier' => '4111111111111111',
    'source_identifier_type' => 'auto',
    'source_institution' => 'FNBB_ACQUIRER',
    'swap_type' => 'DEPOSIT',
];

section('Testing requester PRE-SET vs ADDED-DURING-SIGNING — identical field values otherwise');

// H: requester NOT pre-set (matches every prior successful isolated test)
sendVariant('H - requester NOT pre-set (control, known-good pattern)', $commonFields);

// I: requester pre-set BEFORE signing, positioned right after action+asset fields
// but BEFORE source_identifier -- mimics SwapService's exact construction order
// (action, reference, asset_type, amount, currency, institution, timestamp,
// swap_type, requester, from_institution, source_institution -- THEN
// source_identifier gets added after via a separate if-block)
$withRequesterEarly = [
    'action' => 'PRE_AUTH_CHECK',
    'asset_type' => 'VISA_MASTERCARD_CARD',
    'amount' => 1,
    'currency' => 'BWP',
    'institution' => 'FNBB_ACQUIRER',
    'swap_type' => 'DEPOSIT',
    'requester' => 'VOUCHMORPH',
    'from_institution' => 'FNBB_ACQUIRER',
    'source_institution' => 'FNBB_ACQUIRER',
    'card_token' => '4111111111111111',
    'cvv' => '123',
    'source_identifier' => '4111111111111111',
    'source_identifier_type' => 'auto',
];
sendVariant('I - requester PRE-SET before signing (matches real SwapService order)', $withRequesterEarly);

section('DIAGNOSTIC COMPLETE');
echo "If H succeeds and I fails, pre-setting 'requester' before signing is\n";
echo "confirmed as the cause -- meaning createSignedRequest() signs over a\n";
echo "different byte representation than what actually gets transmitted\n";
echo "whenever the caller already provides 'requester' as an original key.\n";
echo "Paste the full output back.\n";
