<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Application/Admin/Auth/AdminAuth.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

use Application\Admin\Auth\AdminAuth;
use Core\Database\DBConnection;

$db = DBConnection::getConnection();

if (!AdminAuth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$limit = $_GET['limit'] ?? 10;

try {
    $stmt = $db->prepare("
        SELECT 
            debtor,
            creditor,
            amount,
            created_at
        FROM settlement_queue 
        WHERE status = 'PENDING'
        ORDER BY created_at ASC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $settlements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($settlements);
} catch (Exception $e) {
    echo json_encode([]);
}
