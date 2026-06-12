<?php
// test_vouchmorph_certificate.php
// Uses existing environment variables on VouchMorph

// Include the classes
require_once __DIR__ . '/../../src/Infrastructure/Crypto/CertificateManager.php';
require_once __DIR__ . '/../../../src/Infrastructure/Crypto/MessagerSigner.php';

use Infrastructure\Crypto\CertificateManager;
use Infrastructure\Crypto\MessagerSigner;

// ============================================================
// USE EXISTING ENVIRONMENT VARIABLES
// ============================================================

echo "========== VOUCHMORPH CERTIFICATE-BASED SIGNING ==========\n\n";

// Check what environment variables are available
echo "Checking environment variables:\n";
echo "  MEMBER_NAME: " . (getenv('MEMBER_NAME') ?: 'NOT SET') . "\n";
echo "  VOUCHMORPH_PRIVATE_KEY_CONTENT: " . (getenv('VOUCHMORPH_PRIVATE_KEY_CONTENT') ? "✓ SET" : "✗ NOT SET") . "\n";
echo "  VOUCHMORPH_CERT_CONTENT: " . (getenv('VOUCHMORPH_CERT_CONTENT') ? "✓ SET" : "✗ NOT SET") . "\n";
echo "  VOUCHMORPH_CA_CERT_CONTENT: " . (getenv('VOUCHMORPH_CA_CERT_CONTENT') ? "✓ SET" : "✗ NOT SET") . "\n\n";

// Initialize CertificateManager (it reads from environment automatically)
$certManager = new CertificateManager('VOUCHMORPH');

if (!$certManager->isConfigured()) {
    die("ERROR: CertificateManager not configured - environment variables missing\n");
}

echo "✓ CertificateManager configured successfully\n\n";

// Create payload
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

// Create signed request with certificate attached
$signedRequest = $certManager->createSignedRequest($payload, 'VOUCHMORPH');

echo "========== SIGNED REQUEST CREATED ==========\n";
echo "  - Certificate: " . (isset($signedRequest['certificate']) ? "✓ PRESENT" : "✗ MISSING") . "\n";
echo "  - Signature: " . (isset($signedRequest['signature']) ? "✓ PRESENT" : "✗ MISSING") . "\n";
echo "  - Timestamp: " . ($signedRequest['timestamp'] ?? 'MISSING') . "\n";
echo "  - Requester: " . ($signedRequest['requester'] ?? 'MISSING') . "\n\n";

// Save to file for curl
file_put_contents('hold_request_cert.json', json_encode($signedRequest));
echo "Saved to: hold_request_cert.json\n\n";

// ============================================================
// LOCAL VERIFICATION (simulating Saccussalis)
// ============================================================

echo "========== LOCAL VERIFICATION (simulating Saccussalis) ==========\n";
$verification = $certManager->verifySignedRequest($signedRequest);
echo "Result: " . ($verification['verified'] ? "✓ VALID" : "✗ INVALID") . "\n";
echo "Requester: " . ($verification['requester'] ?? 'UNKNOWN') . "\n";
echo "Message: " . ($verification['message'] ?? 'N/A') . "\n\n";

// ============================================================
// CURL COMMAND
// ============================================================

echo "========== CURL COMMAND TO TEST SACCUSSALIS ==========\n";
echo "curl -X POST https://saccussalis-production.up.railway.app/backend/api/v1/hold.php \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d @hold_request_cert.json\n\n";

// Show the request (first 500 chars)
echo "========== REQUEST PREVIEW ==========\n";
echo substr(json_encode($signedRequest, JSON_PRETTY_PRINT), 0, 500) . "...\n\n";
