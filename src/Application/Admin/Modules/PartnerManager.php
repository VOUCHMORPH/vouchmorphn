<?php
declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/../../../src/bootstrap.php';

// Include required files with correct paths
require_once __DIR__ . '/../../../src/Core/Database/DBConnection.php';

use Core\Database\DBConnection;
use Infrastructure\Banks\GenericBankClient;

// Get database connection
try {
    $config = require __DIR__ . '/../../../src/Core/Config/Countries/Botswana/config.php';
    $db = DBConnection::getInstance($config['db']['swap'] ?? $config['database']['swap'] ?? []);
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    die("Database connection failed. Please check configuration.");
}

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'test_partner' && isset($_POST['participant_id'])) {
        $result = testPartnerConnection($db, (int)$_POST['participant_id']);
        if ($result['success']) {
            $message = "Connection test successful! Response time: {$result['response_time']}ms";
        } else {
            $error = "Connection failed: {$result['error']}";
        }
    }
    
    if ($action === 'update_partner' && isset($_POST['participant_id'])) {
        $updateResult = updatePartner($db, $_POST);
        if ($updateResult) {
            $message = "Partner updated successfully";
        } else {
            $error = "Failed to update partner";
        }
    }
    
    if ($action === 'add_partner') {
        $addResult = addPartner($db, $_POST);
        if ($addResult) {
            $message = "Partner added successfully";
        } else {
            $error = "Failed to add partner";
        }
    }
}

// Get all participants
try {
    $stmt = $db->query("
        SELECT p.*, 
               COUNT(a.log_id) as api_calls,
               AVG(a.duration_ms) as avg_response,
               SUM(CASE WHEN a.success THEN 1 ELSE 0 END) as successful
        FROM participants p
        LEFT JOIN api_message_logs a ON p.name = a.participant_name
        GROUP BY p.participant_id
        ORDER BY p.name
    ");
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching participants: " . $e->getMessage());
    $participants = [];
}

// Helper functions
function testPartnerConnection($db, $participantId) {
    $stmt = $db->prepare("SELECT * FROM participants WHERE participant_id = ?");
    $stmt->execute([$participantId]);
    $participant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$participant) {
        return ['success' => false, 'error' => 'Participant not found'];
    }
    
    $start = microtime(true);
    try {
        $client = new GenericBankClient($participant);
        $result = $client->testConnection();
        $responseTime = round((microtime(true) - $start) * 1000, 2);
        
        // Log the test
        $logStmt = $db->prepare("
            INSERT INTO api_message_logs 
            (message_id, message_type, direction, participant_id, participant_name, endpoint, success, duration_ms, created_at)
            VALUES (?, 'CONNECTION_TEST', 'outgoing', ?, ?, '/test', ?, ?, NOW())
        ");
        $logStmt->execute([
            'TEST-' . uniqid(),
            $participantId,
            $participant['name'],
            $result['success'] ? 1 : 0,
            $responseTime
        ]);
        
        return [
            'success' => $result['success'],
            'response_time' => $responseTime,
            'data' => $result
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updatePartner($db, $data) {
    $stmt = $db->prepare("
        UPDATE participants SET
            name = ?,
            type = ?,
            category = ?,
            provider_code = ?,
            auth_type = ?,
            base_url = ?,
            status = ?,
            updated_at = NOW()
        WHERE participant_id = ?
    ");
    
    return $stmt->execute([
        $data['name'],
        $data['type'],
        $data['category'],
        $data['provider_code'],
        $data['auth_type'],
        $data['base_url'],
        $data['status'],
        $data['participant_id']
    ]);
}

function addPartner($db, $data) {
    $stmt = $db->prepare("
        INSERT INTO participants (
            name, type, category, provider_code, auth_type, base_url, status, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
    ");
    
    return $stmt->execute([
        $data['name'],
        $data['type'],
        $data['category'],
        $data['provider_code'],
        $data['auth_type'],
        $data['base_url'],
        $data['status'] ?? 'ACTIVE'
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Partner Manager</title>
    <link rel="stylesheet" href="../assets/css/control.css">
    <style>
        /* Enhanced styles */
        .control-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .control-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .logo h1 {
            margin: 0;
            font-size: 28px;
            color: #2c3e50;
        }
        
        .logo h1 span {
            color: #3498db;
            font-weight: 300;
        }
        
        .badge {
            background: #34495e;
            padding: 8px 16px;
            border-radius: 20px;
        }
        
        .badge a {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .badge a:hover {
            color: #FFDA63;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-primary {
            background: #3498db;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(39, 174, 96, 0.3);
        }
        
        .btn-small {
            display: inline-block;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            background: #6c757d;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0 2px;
        }
        
        .btn-small:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }
        
        .compliance-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 30px;
        }
        
        .card-header {
            padding: 20px 25px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .card-badge {
            background: #6c757d;
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid #ddd;
            border-radius: 6px;
            transition: border-color 0.3s ease;
            background: #fff;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table thead th {
            padding: 15px 20px;
            text-align: left;
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        table tbody td {
            padding: 15px 20px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: #fff;
            max-width: 600px;
            width: 90%;
            padding: 30px;
            border-radius: 12px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .modal-header h2 {
            margin: 0;
            color: #2c3e50;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #6c757d;
            transition: color 0.3s;
        }
        
        .modal-close:hover {
            color: #dc3545;
        }
        
        .form-actions {
            padding: 20px 25px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            .control-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }
            
            table {
                font-size: 13px;
            }
            
            table thead th,
            table tbody td {
                padding: 10px 12px;
            }
            
            .modal-content {
                padding: 20px;
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="control-container">
        <div class="control-header">
            <div class="logo">
                <h1>VOUCHMORPH <span>PARTNER MANAGER</span></h1>
            </div>
            <div class="badge">
                <a href="../admin_dashboard.php">← BACK TO DASHBOARD</a>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add New Partner Button -->
        <div style="margin-bottom: 20px;">
            <button onclick="showAddForm()" class="btn btn-primary">➕ Add New Partner</button>
        </div>

        <!-- Add Partner Form (hidden by default) -->
        <div id="addForm" style="display: none; margin-bottom: 20px;">
            <div class="compliance-card">
                <div class="card-header">
                    <span class="card-title">Add New Partner Institution</span>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_partner">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" name="name" required class="form-control" placeholder="e.g., ABC Bank">
                        </div>
                        <div class="form-group">
                            <label>Type *</label>
                            <select name="type" required class="form-control">
                                <option value="FINANCIAL_INSTITUTION">Financial Institution</option>
                                <option value="MOBILE_MONEY_OPERATOR">Mobile Money Operator</option>
                                <option value="CARD_DISTRIBUTOR">Card Distributor</option>
                                <option value="TECHNICAL_PROVIDER">Technical Provider</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category" required class="form-control">
                                <option value="BANK">Bank</option>
                                <option value="MNO">MNO</option>
                                <option value="EMI_CARD">EMI/Card</option>
                                <option value="PAYMENT_PROCESSOR">Payment Processor</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Provider Code</label>
                            <input type="text" name="provider_code" class="form-control" placeholder="e.g., ABC001">
                        </div>
                        <div class="form-group">
                            <label>Auth Type</label>
                            <input type="text" name="auth_type" class="form-control" placeholder="API Key / OAuth / Basic">
                        </div>
                        <div class="form-group">
                            <label>Base URL</label>
                            <input type="url" name="base_url" class="form-control" placeholder="https://api.partner.com">
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="ACTIVE">Active</option>
                                <option value="INACTIVE">Inactive</option>
                                <option value="PENDING">Pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-success">💾 Save Partner</button>
                        <button type="button" onclick="hideAddForm()" class="btn" style="background: #6c757d;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Partners List -->
        <div class="compliance-card">
            <div class="card-header">
                <span class="card-title">Partner Institutions</span>
                <span class="card-badge"><?php echo count($participants); ?> Total</span>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Provider Code</th>
                        <th>Status</th>
                        <th>API Calls</th>
                        <th>Success Rate</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($participants)): ?>
                        <?php foreach ($participants as $p): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($p['name']); ?></strong>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($p['type']); ?>
                                <br>
                                <small style="color: #6c757d;"><?php echo htmlspecialchars($p['category']); ?></small>
                            </td>
                            <td>
                                <?php echo $p['provider_code'] ? htmlspecialchars($p['provider_code']) : '—'; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($p['status'] ?? 'pending'); ?>">
                                    <?php echo htmlspecialchars($p['status'] ?? 'PENDING'); ?>
                                </span>
                            </td>
                            <td><?php echo $p['api_calls'] ?: 0; ?></td>
                            <td>
                                <?php 
                                if ($p['api_calls'] > 0) {
                                    $rate = round(($p['successful'] / $p['api_calls']) * 100, 1);
                                    echo $rate . '%';
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="test_partner">
                                    <input type="hidden" name="participant_id" value="<?php echo $p['participant_id']; ?>">
                                    <button type="submit" class="btn-small" title="Test Connection">🔌 Test</button>
                                </form>
                                <button onclick='editPartner(<?php echo htmlspecialchars(json_encode($p)); ?>)' class="btn-small" title="Edit Partner">✏️ Edit</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding: 40px; color: #6c757d;">
                                <div style="font-size: 48px; margin-bottom: 10px;">🏦</div>
                                <p style="margin: 0;">No partner institutions found</p>
                                <p style="font-size: 13px;">Click "Add New Partner" to get started</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Partner Modal -->
    <div id="editModal" class="modal-overlay">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ Edit Partner</h2>
                <button onclick="closeEditModal()" class="modal-close">&times;</button>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update_partner">
                <input type="hidden" name="participant_id" id="edit_id">
                
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="name" id="edit_name" required class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Type *</label>
                    <select name="type" id="edit_type" required class="form-control">
                        <option value="FINANCIAL_INSTITUTION">Financial Institution</option>
                        <option value="MOBILE_MONEY_OPERATOR">Mobile Money Operator</option>
                        <option value="CARD_DISTRIBUTOR">Card Distributor</option>
                        <option value="TECHNICAL_PROVIDER">Technical Provider</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category" id="edit_category" required class="form-control">
                        <option value="BANK">Bank</option>
                        <option value="MNO">MNO</option>
                        <option value="EMI_CARD">EMI/Card</option>
                        <option value="PAYMENT_PROCESSOR">Payment Processor</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Provider Code</label>
                    <input type="text" name="provider_code" id="edit_provider_code" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Auth Type</label>
                    <input type="text" name="auth_type" id="edit_auth_type" class="form-control" placeholder="API Key / OAuth / Basic">
                </div>
                
                <div class="form-group">
                    <label>Base URL</label>
                    <input type="url" name="base_url" id="edit_base_url" class="form-control" placeholder="https://api.partner.com">
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_status" class="form-control">
                        <option value="ACTIVE">Active</option>
                        <option value="INACTIVE">Inactive</option>
                        <option value="PENDING">Pending</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-success">💾 Update Partner</button>
                    <button type="button" onclick="closeEditModal()" class="btn" style="background: #6c757d;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddForm() {
            document.getElementById('addForm').style.display = 'block';
            document.getElementById('addForm').scrollIntoView({ behavior: 'smooth' });
        }
        
        function hideAddForm() {
            document.getElementById('addForm').style.display = 'none';
        }
        
        function editPartner(partner) {
            document.getElementById('edit_id').value = partner.participant_id;
            document.getElementById('edit_name').value = partner.name;
            document.getElementById('edit_type').value = partner.type;
            document.getElementById('edit_category').value = partner.category;
            document.getElementById('edit_provider_code').value = partner.provider_code || '';
            document.getElementById('edit_auth_type').value = partner.auth_type || '';
            document.getElementById('edit_base_url').value = partner.base_url || '';
            document.getElementById('edit_status').value = partner.status || 'PENDING';
            document.getElementById('editModal').classList.add('active');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // Handle keyboard shortcut
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });
    </script>
</body>
</html>
