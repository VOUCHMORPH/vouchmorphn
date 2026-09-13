<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Admin/Auth/AdminAuth.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../src/Domain/Services/KYCDocumentService.php';

use Application\Admin\Auth\AdminAuth;
use Core\Database\DBConnection;
use Domain\Services\KYCDocumentService;

if (!AdminAuth::isLoggedIn() || !AdminAuth::hasPermission('kyc_verification')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$kycId = isset($input['kyc_id']) ? (int)$input['kyc_id'] : 0;
$decision = $input['decision'] ?? '';
$notes = $input['notes'] ?? null;

if (!$kycId || !in_array($decision, ['approved', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'kyc_id and decision (approved|rejected) are required']);
    exit;
}

try {
    $db = DBConnection::getConnection();
    $kycService = new KYCDocumentService($db);
    $result = $kycService->reviewDocument($kycId, $decision, $notes, (int)AdminAuth::getCurrentAdminId());
    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[kyc_review] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
