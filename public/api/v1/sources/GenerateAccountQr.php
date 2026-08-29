<?php
declare(strict_types=1);

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

$container = require_once ROOT_PATH . '/src/bootstrap.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/QrCodeService.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Contracts/QrPayload.php';
require_once ROOT_PATH . '/src/Infrastructure/QRcodes/Adapters/VouchMorphAccountQrAdapter.php';
require_once ROOT_PATH . '/src/Application/Utils/SessionManager.php';

use Infrastructure\QRcodes\QrCodeService;
use Infrastructure\QRcodes\Contracts\QrPayload;
use Infrastructure\QRcodes\Adapters\VouchMorphAccountQrAdapter;
use Application\Utils\SessionManager;

SessionManager::start();
if (!SessionManager::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$userId = (int)(SessionManager::getUser()['id'] ?? SessionManager::getUser()['user_id'] ?? 0);
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['source_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'source_id is required']);
    exit();
}

$db = $container->get(PDO::class);

try {
    // Ownership check — never let a user generate a QR for a source
    // that isn't theirs. Handles both namespacing schemes seen in this
    // codebase ("manual_123" vs raw numeric ids from user_source_accounts).
    $rawId = preg_replace('/^manual_/', '', (string)$input['source_id']);

    $stmt = $db->prepare("
        SELECT institution, asset_type, identifier, identifier_type, account_name
        FROM user_source_accounts
        WHERE id = :id AND user_id = :user_id AND deleted_at IS NULL
    ");
    $stmt->execute([':id' => (int)$rawId, ':user_id' => $userId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$source) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Source not found or not yours.']);
        exit();
    }

    $userStmt = $db->prepare("SELECT full_name FROM users WHERE user_id = :id");
    $userStmt->execute([':id' => $userId]);
    $ownerName = $userStmt->fetchColumn() ?: null;

    $qrService = new QrCodeService();
    $qrService->registerAdapter(new VouchMorphAccountQrAdapter());
    $qrPayload = $qrService->encode(
        new QrPayload('acct', [
            'institution' => $source['institution'],
            'asset_type' => $source['asset_type'],
            'identifier' => $source['identifier'],
            'identifier_type' => $source['identifier_type'],
            'display_name' => $ownerName,
        ]),
        'VOUCHMORPH_ACCOUNT_V1'
    );

    echo json_encode([
        'success' => true,
        'data' => [
            'qr_payload' => $qrPayload,
            'institution' => $source['institution'],
            'identifier' => $source['identifier'],
        ],
    ], JSON_PRETTY_PRINT);

} catch (\Throwable $e) {
    error_log("[GenerateAccountQr] Failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not generate QR right now.']);
}
