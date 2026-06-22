<?php
// public/health.php - Simple health check
try {
    // Check database connection with minimal query
    require_once __DIR__ . '/../src/Core/Database/DBConnection.php';
    use Core\Database\DBConnection;
    
    $db = DBConnection::getConnection();
    $stmt = $db->query("SELECT 1");
    $stmt->fetch();
    
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'healthy',
        'timestamp' => time(),
        'service' => 'vouchmorph'
    ]);
} catch (Exception $e) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'unhealthy',
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
