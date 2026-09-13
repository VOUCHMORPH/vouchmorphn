<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Admin\Auth\AdminAuth;
use Infrastructure\Banks\GenericBankClient;

header('Content-Type: application/json');

if (!AdminAuth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = DBConnection::getConnection();

$action = $_GET['action'] ?? 'status';
$participantId = $_GET['participant_id'] ?? null;

switch ($action) {
    case 'test_all':
    case 'test_one':
        // GenericBankClient has no generic health-check/connectivity-test
        // method - only request-shaped calls (checkStatus, etc.) tied to a
        // specific transaction. There's no real capability here to wire
        // this up to yet.
        http_response_code(501);
        $result = ['status' => 'error', 'message' => 'Connection testing is not implemented for this client'];
        break;
    case 'status':
    default:
        $result = getConnectionStatus($db);
        break;
}

echo json_encode($result, JSON_PRETTY_PRINT);

function getConnectionStatus($db) {
    $query = $db->query("
        SELECT 
            p.participant_id,
            p.name,
            p.type,
            p.base_url,
            p.status,
            COUNT(a.log_id) as total_calls,
            SUM(CASE WHEN a.success THEN 1 ELSE 0 END) as successful,
            MAX(a.created_at) as last_call,
            AVG(a.duration_ms) as avg_response
        FROM participants p
        LEFT JOIN api_message_logs a ON p.name = a.participant_name
        GROUP BY p.participant_id
        ORDER BY p.name
    ");
    
    $connections = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        $row['health'] = determineHealth($row);
        $connections[] = $row;
    }
    
    return [
        'status' => 'success',
        'timestamp' => date('c'),
        'total_partners' => count($connections),
        'connections' => $connections
    ];
}

function testAllConnections($db) {
    $query = $db->query("SELECT * FROM participants WHERE status = 'ACTIVE'");
    $results = [];
    
    while ($participant = $query->fetch(PDO::FETCH_ASSOC)) {
        $results[$participant['name']] = testEndpoint($participant);
    }
    
    return [
        'status' => 'success',
        'timestamp' => date('c'),
        'results' => $results
    ];
}

function testConnection($db, $participantId) {
    $query = $db->prepare("SELECT * FROM participants WHERE participant_id = ?");
    $query->execute([$participantId]);
    $participant = $query->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        return ['status' => 'error', 'message' => 'Participant not found'];
    }
    
    return testEndpoint($participant);
}

function testEndpoint($participant) {
    $start = microtime(true);
    
    try {
        $client = new GenericBankClient($participant);
        
        // Test health endpoint
        $result = $client->testConnection();
        
        $duration = (microtime(true) - $start) * 1000;
        
        // Log the test
        global $db;
        $stmt = $db->prepare("
            INSERT INTO api_message_logs 
            (message_id, message_type, direction, participant_id, participant_name, endpoint, success, duration_ms, created_at)
            VALUES (?, 'CONNECTION_TEST', 'outgoing', ?, ?, '/health', ?, ?, NOW())
        ");
        $stmt->execute([
            'TEST-' . uniqid(),
            $participant['participant_id'],
            $participant['name'],
            $result['success'] ? 1 : 0,
            $duration
        ]);
        
        return [
            'participant' => $participant['name'],
            'endpoint' => $participant['base_url'],
            'success' => $result['success'],
            'response_time_ms' => round($duration, 2),
            'http_code' => $result['http_code'] ?? null,
            'message' => $result['message'] ?? 'OK',
            'timestamp' => date('c')
        ];
        
    } catch (Exception $e) {
        $duration = (microtime(true) - $start) * 1000;
        
        return [
            'participant' => $participant['name'],
            'endpoint' => $participant['base_url'],
            'success' => false,
            'response_time_ms' => round($duration, 2),
            'error' => $e->getMessage(),
            'timestamp' => date('c')
        ];
    }
}

function determineHealth($row) {
    if ($row['total_calls'] == 0) return 'unknown';
    
    $successRate = $row['successful'] / $row['total_calls'];
    $avgResponse = $row['avg_response'] ?? 1000;
    
    if ($successRate > 0.99 && $avgResponse < 500) return 'excellent';
    if ($successRate > 0.95 && $avgResponse < 1000) return 'good';
    if ($successRate > 0.90) return 'degraded';
    return 'critical';
}
?>
