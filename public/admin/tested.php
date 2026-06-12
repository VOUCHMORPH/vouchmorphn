<?php
// test_vouchmorph_signing.php
// This uses the EXACT private key from VouchMorph

// VouchMorph's actual private key (from your message)
$privateKeyContent = '-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCwXlaFAGVb1pi7
yW17EHn+IgQQefzeKQ/Bi7xCi8pFuA3gll9OMQ2R5qD/6ObMOW0hJxaw5+1I8w0q
m+011/GZ6iINElwaE0fCEZqbpBJZBMKukCdlwx0KeqgoANxjskRcTka7xszgB/Xi
o8IaYGsYEXB1w+j9A2R6qOoK+jK9p9HRcmQui6vSBJXYLm40/i/AsD39revfLgQA
ylii9C9sQo0G5yJvkLxA4ISYnRk1mN1J41qSmtH7R8WpdOvOO/IiteVzuN2p6zfl
SIGclQsMfD95kZ3EhMghaWb75XSvfEIVJLO7f4KWJn6GMqUV3dYGed7cYvPgYu+
IKkb3+eAVAgMBAAECggEAJNwWf3X5eQPkyExc6+0h3dW8nTfte/2/a/8ZAZxnEgKZ
LeCnevdAA4fcipdhkvmGf/kEIkVaf1ZCoG7VmNzwgq8e3jYB1zZD10CoHBKifgXD
bUm13iv0uBGx7qhdZx2k8VivqkNuYnzva+Y3JR2VDELqysX+vdA1cfg278PiEmY1
R5SOhrjIEpmGjoykC39EiAx4cBbxMurjWBJbUcbpnN6mQAIpFwh/i+9o3atzjmCQ
7AdWJE/Sn7PToNzhIBAT2/R3Md4qEyigJ4SmIeflA6kPaGWFUtjqqvqKeisedrov
fk9q3pNZoSh7GK2R7RraVkBeuWGOwxBc0Lu9HdkmkQKBgQDefsioeomeqRarbv17
BKXgtbAUJie5fCV4V+TJV8QG2UPkS/NAeI0TAKjH++nfFatsXLEVAyjBZ4ggPwos
OUTHRiRaqQHiKyljNCbwjaNKTd+w0ctu/ZN5jGG+8uOEx49p1by6w3a/cjXho0wE
4xFomVftMrmk4TJRSmseS2T2sQKBgQDK7Vz7q8wO5nuJK/1sSE4aaezhNF3+IXJR
5bZJ5da/40Lb1jDwMIE8pCb+ZZD8g7Rt5s3VtDEe9FLdlPwO8qJXfUhaDf1K7vBY
hewV31zCBOj469/jMed66KXIuJgi/96iwV9mSRO8Bhf60UXHCfC/vNNGnuUe0dNZ
jAH9Y8zgpQKBgFMIqcYGhRmLLQSplTu1zloANEgwvR6B8FHrK1zgvi14I9gtaAil
dLCkzFhl8S/qHGGCbivTVABprOmr3RYIAV0FFkgnTqajSPzW17lqgogWa+bHRM6V
H9Z6x3fFmZdSCnmK5LYmgEiOTQF6OcKRI0wP/jptdc7MpESmKzfRF0rhAoGAXLb8
b8Q7dGdb8/1UST/z51+UKgTaGP1BFSgGFFdduchkyLphG6ydr440frD7AFRQgJIe
Y1BzzPfGUJT8YPv8rkqAXxzbKHxo9Zkil4+4+rBxnSFv5obrgx1+eWnVoNAU8Xm2
U655xMNn+2HYJqtlAsWMJkz81Ar8LIKqehI6Dj0CgYARiHLJnJknkPVMJcS2YJba
Jue0ErALWSjbJI3UM505ZwMyCD0dntMylj/LBRxpc4Fsk+wlnz3E5sTIEdbNFs+C
Aqlc5VESXC96ig0NCQIUgUBkIVGXe1mcbsAOF7BCZYXybPChn2SOd4AIjrvsXc9A
yFSjzxCeoXZW/1pcRqOgyA==
-----END PRIVATE KEY-----';

// Clean the private key (replace literal \n with actual newlines)
$privateKeyContent = str_replace(['\\n', '\n'], "\n", $privateKeyContent);

// Load private key
$privateKey = openssl_pkey_get_private($privateKeyContent);
if (!$privateKey) {
    die("ERROR: Cannot load private key: " . openssl_error_string() . "\n");
}
echo "✓ Private key loaded successfully\n\n";

// Create the EXACT payload that hold.php expects
$payload = [
    'action' => 'PLACE_HOLD',
    'reference' => 'SWAP_TEST_' . time(),
    'asset_type' => 'BANK-WALLET',
    'amount' => 100,
    'currency' => 'BWP',
    'hold_reason' => 'PENDING_SWAP',
    'destination_institution' => 'ZURUBANK',
    'expiry' => date('Y-m-d H:i:s', strtotime('+1 hour')),
    'source_identifier' => '+26770000000',
    'source_identifier_type' => 'phone',
    'asset_id' => 4,
    'wallet_phone' => '+26770000000',
    'phone' => '+26770000000',
    'national_id' => '+26770000000',
    'email' => '+26770000000'
];

// Add timestamp to payload (as VouchMorph does)
$timestamp = time();
$payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);

// SORT KEYS (CRITICAL for consistent JSON)
ksort($payloadWithTimestamp);

// Convert to JSON with consistent formatting
$jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "========== WHAT VOUCHMORPH SIGNS ==========\n";
echo "JSON being signed: " . $jsonToSign . "\n\n";

// Generate signature
$signature = '';
$signSuccess = openssl_sign($jsonToSign, $signature, $privateKey, OPENSSL_ALGO_SHA256);

if (!$signSuccess) {
    die("ERROR: Failed to sign: " . openssl_error_string() . "\n");
}

$signature_b64 = base64_encode($signature);
echo "Signature: " . $signature_b64 . "\n\n";

// Build the final request (what VouchMorph sends)
$finalRequest = $payloadWithTimestamp;
$finalRequest['signature'] = $signature_b64;
$finalRequest['requester'] = 'VOUCHMORPH';

echo "========== COMPLETE REQUEST TO SEND ==========\n";
echo json_encode($finalRequest, JSON_PRETTY_PRINT) . "\n\n";

// Save to file for curl
file_put_contents('hold_request.json', json_encode($finalRequest));

echo "========== CURL COMMAND TO TEST ==========\n";
echo "curl -X POST https://saccussalis-production.up.railway.app/backend/api/v1/hold.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d @hold_request.json\n\n";

// Also show the public key fingerprint for reference
$details = openssl_pkey_get_details($privateKey);
$fingerprint = hash('sha256', $details['key']);
echo "Public key fingerprint (should match Saccussalis): " . $fingerprint . "\n";

// Compare with what Saccussalis should have
echo "\n========== VERIFICATION CHECK ==========\n";

// Simulate what Saccussalis will do to verify
$receivedPayload = $finalRequest;
$receivedSignature = $receivedPayload['signature'];
unset($receivedPayload['signature']);
unset($receivedPayload['requester']);

// Saccussalis should sort the same way
ksort($receivedPayload);
$jsonToVerify = json_encode($receivedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

echo "Saccussalis will verify against: " . $jsonToVerify . "\n\n";

// Verify using the public key (simulating Saccussalis)
$publicKey = openssl_pkey_get_details($privateKey)['key'];
$verifyResult = openssl_verify($jsonToVerify, base64_decode($receivedSignature), $publicKey, OPENSSL_ALGO_SHA256);

echo "Local verification result: " . ($verifyResult === 1 ? "✓ VALID" : ($verifyResult === 0 ? "✗ INVALID" : "ERROR")) . "\n";

if ($verifyResult === 1) {
    echo "\n✓ SUCCESS! The signature is valid. The problem is likely on Saccussalis side.\n";
    echo "  Check that Saccussalis has the correct VOUCHMORPH_PUBLIC_KEY fingerprint: " . $fingerprint . "\n";
} else {
    echo "\n✗ FAILED! The signature failed even locally. Key or payload issue.\n";
}
