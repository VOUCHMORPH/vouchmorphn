<?php
/**
 * SMS Delivery Callback Handler
 * Receives delivery reports from virtual phone system
 */

require_once __DIR__ . '/../../../src/bootstrap.php';

use Core\Database\DBConnection;
use Infrastructure\SMS\SmsNotificationService;

header('Content-Type: application/json');

// Get callback data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid callback data']);
    exit();
}

// Log callback
error_log("SMS Delivery Callback: " . json_encode($input));

try {
    $db = DBConnection::getConnection();
    $smsService = new SmsNotificationService($db);
    
    // Process the callback
    $smsService->processDeliveryCallback($input);
    
    // Update message_outbox
    if (isset($input['message_id'])) {
        $stmt = $db->prepare("
            UPDATE message_outbox 
            SET status = :status, 
                delivered_at = :delivered_at,
                delivery_report = :report
            WHERE message_id = :message_id
        ");
        
        $stmt->execute([
            ':status' => $input['status'] ?? 'DELIVERED',
            ':delivered_at' => $input['timestamp'] ?? date('Y-m-d H:i:s'),
            ':report' => json_encode($input),
            ':message_id' => $input['message_id']
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Callback processed'
    ]);
    
} catch (\Throwable $e) {
    error_log("Callback processing error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
