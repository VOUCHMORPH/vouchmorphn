<?php
declare(strict_types=1);

/**
 * VouchMorph — Create a QR Payment Request
 *
 * Lets an agent/merchant request a specific amount into one of their
 * own destinations, and returns a signed QR payload for the payer to
 * scan via "Scan to pay". See cards/Resolveqr.php for how the QR is
 * decoded back into a request_id, and payments/execute.php for how
 * the request is actually paid.
 */

define('ROOT_PATH', dirname(__DIR__, 4));

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

// A require_once on a missing file is a PHP fatal error that happens
// before this script produces any output and cannot be caught by
// try/catch -- it silently turns into a blank response with no JSON
// body. Verify every required file exists first (see cards/My.php and
// cards/Resolveqr.php for the same pattern) so a missing/misplaced
// file fails with a clean JSON error instead of empty output.
$requiredFiles = [
    ROOT_PATH . '/src/bootstrap.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrPayload.php',
    ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphPaymentRequestQrAdapter.php',
    ROOT_PATH . '/src/Application/Utils/SessionManager.php',
];
$missing = array_values(array_filter($requiredFiles, fn($f) => !file_exists($f)));
if (!empty($missing)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server misconfiguration: required file(s) not deployed.',
        'missing_files' => array_map(fn($f) => str_replace(ROOT_PATH, '', $f), $missing),
    ]);
    exit();
}

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrPayload.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphPaymentRequestQrAdapter.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\Contracts\QrPayload;
use Infrastructure\QRcodes\Adapters\VouchMorphPaymentRequestQrAdapter;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}
$userData = SessionManager::getUser();
$agentUserId = (int)($userData['id'] ?? $userData['user_id'] ?? 0);
if (!$agentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Could not resolve user from session']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit();
}

foreach (['destination_institution', 'destination_asset_type', 'destination_identifier', 'net_amount', 'currency'] as $field) {
    if (empty($input[$field]) && $input[$field] !== 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "{$field} is required"]);
        exit();
    }
}

$netAmount = (float)$input['net_amount'];
if ($netAmount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'net_amount must be greater than zero']);
    exit();
}

$destinationType = $input['destination_type'] ?? 'DEPOSIT';
$expiresInSeconds = 900; // 15 min lifetime for the payer to scan and pay

try {
    $db = $container->get(PDO::class);

    $expiresAt = new DateTimeImmutable("+{$expiresInSeconds} seconds");

    $stmt = $db->prepare("
        INSERT INTO payment_requests (
            agent_user_id, net_amount, currency, status, destination_type,
            destination_institution, destination_asset_type, destination_identifier,
            expires_at, created_at
        ) VALUES (
            :agent_user_id, :net_amount, :currency, 'PENDING', :destination_type,
            :destination_institution, :destination_asset_type, :destination_identifier,
            :expires_at, NOW()
        )
    ");
    $stmt->execute([
        ':agent_user_id' => $agentUserId,
        ':net_amount' => $netAmount,
        ':currency' => $input['currency'],
        ':destination_type' => $destinationType,
        ':destination_institution' => $input['destination_institution'],
        ':destination_asset_type' => $input['destination_asset_type'],
        ':destination_identifier' => $input['destination_identifier'],
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);
    $requestId = (int)$db->lastInsertId();

    $qrService = new QrCodeService();
    $qrService->registerAdapter(new VouchMorphPaymentRequestQrAdapter());
    $qrPayload = $qrService->encode(
        new QrPayload('payreq', ['request_id' => $requestId, 'expires_at' => $expiresAt->getTimestamp()]),
        'VOUCHMORPH_PAYMENT_REQUEST_V1'
    );

    echo json_encode([
        'success' => true,
        'data' => [
            'request_id' => $requestId,
            'qr_payload' => $qrPayload,
            'net_amount' => $netAmount,
            'currency' => $input['currency'],
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[create_request.php] FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => ['file' => basename($e->getFile()), 'line' => $e->getLine()],
    ]);
}
