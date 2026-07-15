<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Define project root
define('PROJECT_ROOT', dirname(__DIR__, 2));

// Load configuration using the new system
$configPath = PROJECT_ROOT . '/src/Core/Config/LoadCountry.php';
if (!file_exists($configPath)) {
    die("Configuration system not found.");
}

require_once $configPath;

try {
    $config = \Core\Config\LoadCountry::getConfig();
    if (!is_array($config)) {
        die("Configuration failed to load.");
    }
} catch (Throwable $e) {
    die("Config error: " . $e->getMessage());
}

// Load required classes
require_once PROJECT_ROOT . '/src/Core/Database/DBConnection.php';
require_once PROJECT_ROOT . '/src/Application/Utils/SessionManager.php';
require_once PROJECT_ROOT . '/src/Application/Admin/Auth/AdminAuth.php';

use Core\Database\DBConnection;
use Application\Utils\SessionManager;
use Application\Admin\Auth\AdminAuth;

// Check if super admin is logged in (role_id = 999)
if (!SessionManager::isAdminLoggedIn() || SessionManager::getAdminRoleId() !== 999) {
    header('Location: admin_login.php');
    exit();
}

// ============================================================
// FIXED: Use DBConnection::getConnection() - the CORRECT method
// ============================================================
try {
    $db = DBConnection::getConnection();
    
    if (!$db || !($db instanceof PDO)) {
        throw new Exception("Database connection failed - no PDO object returned.");
    }
    
    // Test the connection
    $db->query("SELECT 1");
    error_log("[ADMIN MANAGEMENT] Database connected successfully via DBConnection::getConnection()");
    
} catch (Throwable $e) {
    error_log("[ADMIN MANAGEMENT] DB Error: " . $e->getMessage());
    error_log("[ADMIN MANAGEMENT] Trace: " . $e->getTraceAsString());
    die("Database connection failed: " . $e->getMessage());
}

// Role ID to Name mapping
$roleNames = [
    999 => 'Super Admin',
    3 => 'Regulator (BOB)',
    4 => 'Compliance Officer',
    5 => 'Auditor'
];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $fullName = trim($_POST['full_name'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 5);
            $countryCode = trim($_POST['country_code'] ?? 'BW');
            $mfaEnabled = isset($_POST['mfa_enabled']) ? 't' : 'f';
            
            // Validate
            if (empty($username) || empty($email) || empty($password)) {
                throw new Exception("Username, email, and password are required.");
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address.");
            }
            
            if (strlen($password) < 6) {
                throw new Exception("Password must be at least 6 characters.");
            }
            
            // Check if username or email exists
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = :username OR email = :email");
            $checkStmt->execute([':username' => $username, ':email' => $email]);
            if ($checkStmt->fetchColumn() > 0) {
                throw new Exception("Username or email already exists.");
            }
            
            // Hash password
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            
            // Insert new admin
            $stmt = $db->prepare("
                INSERT INTO admins (username, email, password_hash, role_id, full_name, country_code, mfa_enabled, created_at, updated_at)
                VALUES (:username, :email, :hash, :role_id, :full_name, :country_code, :mfa_enabled, NOW(), NOW())
            ");
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':hash' => $passwordHash,
                ':role_id' => $roleId,
                ':full_name' => $fullName,
                ':country_code' => $countryCode,
                ':mfa_enabled' => $mfaEnabled
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Admin created successfully']);
            exit;
            
        } elseif ($action === 'update') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $roleId = (int)($_POST['role_id'] ?? 5);
            $countryCode = trim($_POST['country_code'] ?? 'BW');
            $mfaEnabled = isset($_POST['mfa_enabled']) ? 't' : 'f';
            
            // Don't allow changing super admin role
            $checkStmt = $db->prepare("SELECT role_id FROM admins WHERE admin_id = :id");
            $checkStmt->execute([':id' => $adminId]);
            $currentRole = $checkStmt->fetchColumn();
            
            if ($currentRole == 999 && $roleId != 999) {
                throw new Exception("Cannot change Super Admin role.");
            }
            
            $stmt = $db->prepare("
                UPDATE admins 
                SET username = :username, 
                    email = :email, 
                    full_name = :full_name, 
                    role_id = :role_id, 
                    country_code = :country_code,
                    mfa_enabled = :mfa_enabled, 
                    updated_at = NOW()
                WHERE admin_id = :admin_id
            ");
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':full_name' => $fullName,
                ':role_id' => $roleId,
                ':country_code' => $countryCode,
                ':mfa_enabled' => $mfaEnabled,
                ':admin_id' => $adminId
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Admin updated successfully']);
            exit;
            
        } elseif ($action === 'delete') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            
            // Don't allow deleting self or super admin
            if ($adminId == SessionManager::getAdminId()) {
                throw new Exception("Cannot delete your own account.");
            }
            
            $checkStmt = $db->prepare("SELECT role_id FROM admins WHERE admin_id = :id");
            $checkStmt->execute([':id' => $adminId]);
            if ($checkStmt->fetchColumn() == 999) {
                throw new Exception("Cannot delete Super Admin account.");
            }
            
            $stmt = $db->prepare("UPDATE admins SET deleted_at = NOW() WHERE admin_id = :admin_id");
            $stmt->execute([':admin_id' => $adminId]);
            
            echo json_encode(['success' => true, 'message' => 'Admin deleted successfully']);
            exit;
            
        } elseif ($action === 'reset_password') {
            $adminId = (int)($_POST['admin_id'] ?? 0);
            $newPassword = $_POST['new_password'] ?? '';
            
            if (strlen($newPassword) < 6) {
                throw new Exception("Password must be at least 6 characters.");
            }
            
            $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            
            $stmt = $db->prepare("UPDATE admins SET password_hash = :hash, updated_at = NOW() WHERE admin_id = :admin_id");
            $stmt->execute([':hash' => $passwordHash, ':admin_id' => $adminId]);
            
            echo json_encode(['success' => true, 'message' => 'Password reset successfully']);
            exit;
        }
        
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Get all admins (excluding soft-deleted)
try {
    $admins = $db->query("
        SELECT admin_id, username, email, phone, full_name, role_id, country_code, mfa_enabled, created_at, updated_at 
        FROM admins 
        WHERE deleted_at IS NULL 
        ORDER BY role_id DESC, created_at ASC
    ")->fetchAll();
} catch (Throwable $e) {
    error_log("[ADMIN MANAGEMENT] Query error: " . $e->getMessage());
    $admins = [];
}

// Get current admin info
$currentAdminId = SessionManager::getAdminId();
$currentAdminRole = SessionManager::getAdminRoleId();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Admin Management</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'IBM Plex Mono', monospace;
            background: #f7f9fc;
            color: #001B44;
        }

        .header {
            background: #001B44;
            padding: 20px 30px;
            border-bottom: 5px solid #FFDA63;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #FFDA63;
            font-size: 1.2rem;
        }

        .back-btn {
            color: #fff;
            text-decoration: none;
            padding: 8px 16px;
            border: 2px solid #FFDA63;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: #FFDA63;
            color: #001B44;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }

        .card {
            background: #fff;
            border: 2px solid #001B44;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 6px 6px 0 #A1B5D8;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            border-bottom: 2px solid #001B44;
            padding-bottom: 10px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #001B44;
            font-family: 'IBM Plex Mono', monospace;
            font-size: 0.9rem;
            background: #fff;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #FFDA63;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input {
            width: auto;
        }

        .btn {
            padding: 10px 20px;
            border: 2px solid #001B44;
            background: #fff;
            cursor: pointer;
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #001B44;
            color: #fff;
        }

        .btn-primary:hover {
            background: #FFDA63;
            color: #001B44;
            border-color: #FFDA63;
        }

        .btn-danger {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-danger:hover {
            background: #dc3545;
            color: #fff;
        }

        .btn-sm {
            padding: 5px 10px;
            font-size: 0.75rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #001B44;
            color: #fff;
            padding: 12px;
            text-align: left;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            font-size: 0.85rem;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .status {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background: #fff;
            border: 3px solid #001B44;
            padding: 30px;
            max-width: 500px;
            margin: 100px auto;
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border: 2px solid;
            display: none;
        }

        .message-success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .message-error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .role-badge {
            display: inline-block;
            padding: 3px 10px;
            font-size: 0.7rem;
            font-weight: 600;
            border-radius: 0;
        }

        .role-super { background: #001B44; color: #FFDA63; }
        .role-regulator { background: #28a745; color: #fff; }
        .role-compliance { background: #17a2b8; color: #fff; }
        .role-auditor { background: #6c757d; color: #fff; }

        @media (max-width: 768px) {
            .grid-2 {
                grid-template-columns: 1fr;
            }
            .container {
                padding: 20px;
            }
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔐 VOUCHMORPH · ADMIN MANAGEMENT</h1>
        <a href="admin_dashboard.php" class="back-btn">← BACK TO DASHBOARD</a>
    </div>
    
    <div class="container">
        <div id="message" class="message"></div>
        
        <div class="grid-2">
            <!-- Add New Admin Form -->
            <div class="card">
                <div class="card-title">➕ CREATE NEW ADMINISTRATOR</div>
                <form id="createAdminForm">
                    <div class="form-group">
                        <label>USERNAME *</label>
                        <input type="text" id="create_username" required>
                    </div>
                    <div class="form-group">
                        <label>EMAIL *</label>
                        <input type="email" id="create_email" required>
                    </div>
                    <div class="form-group">
                        <label>FULL NAME</label>
                        <input type="text" id="create_full_name">
                    </div>
                    <div class="form-group">
                        <label>PASSWORD *</label>
                        <input type="password" id="create_password" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label>ROLE</label>
                        <select id="create_role_id">
                            <option value="999">Super Admin (Global Access)</option>
                            <option value="3">Regulator (Bank of Botswana)</option>
                            <option value="4">Compliance Officer</option>
                            <option value="5">Auditor</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>COUNTRY</label>
                        <select id="create_country_code">
                            <option value="BW">Botswana (BW)</option>
                            <option value="NG">Nigeria (NG)</option>
                            <option value="KE">Kenya (KE)</option>
                            <option value="">Global (Super Admin only)</option>
                        </select>
                    </div>
                    <div class="form-group checkbox-group">
                        <input type="checkbox" id="create_mfa_enabled" value="1">
                        <label>Enable Two-Factor Authentication (MFA)</label>
                    </div>
                    <button type="submit" class="btn btn-primary">➕ CREATE ADMIN</button>
                </form>
            </div>
            
            <!-- Role Information -->
            <div class="card">
                <div class="card-title">📋 ROLE PERMISSIONS</div>
                <div style="margin-bottom: 15px;">
                    <span class="role-badge role-super">Super Admin (999)</span>
                    <p style="margin-top: 10px; font-size: 0.85rem;">Full system access, manage all admins, all countries</p>
                </div>
                <div style="margin-bottom: 15px;">
                    <span class="role-badge role-regulator">Regulator (3)</span>
                    <p style="margin-top: 10px; font-size: 0.85rem;">View reports, audit logs, compliance checks (Bank of Botswana)</p>
                </div>
                <div style="margin-bottom: 15px;">
                    <span class="role-badge role-compliance">Compliance Officer (4)</span>
                    <p style="margin-top: 10px; font-size: 0.85rem;">KYC verification, transaction review, AML monitoring</p>
                </div>
                <div style="margin-bottom: 15px;">
                    <span class="role-badge role-auditor">Auditor (5)</span>
                    <p style="margin-top: 10px; font-size: 0.85rem;">Read-only access, audit trails, transaction logs</p>
                </div>
            </div>
        </div>
        
        <!-- Admin List -->
        <div class="card">
            <div class="card-title">📋 ADMINISTRATORS</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Country</th>
                            <th>MFA</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="adminsTable">
                        <?php if (!empty($admins)): ?>
                        <?php foreach ($admins as $admin): 
                            $roleClass = '';
                            if ($admin['role_id'] == 999) $roleClass = 'role-super';
                            elseif ($admin['role_id'] == 3) $roleClass = 'role-regulator';
                            elseif ($admin['role_id'] == 4) $roleClass = 'role-compliance';
                            elseif ($admin['role_id'] == 5) $roleClass = 'role-auditor';
                        ?>
                        <tr data-id="<?php echo $admin['admin_id']; ?>">
                            <td><?php echo $admin['admin_id']; ?></td>
                            <td><?php echo htmlspecialchars($admin['username']); ?></td>
                            <td><?php echo htmlspecialchars($admin['email']); ?></td>
                            <td><?php echo htmlspecialchars($admin['full_name'] ?? '-'); ?></td>
                            <td><span class="role-badge <?php echo $roleClass; ?>"><?php echo $roleNames[$admin['role_id']] ?? 'Unknown'; ?></span></td>
                            <td><?php echo $admin['country_code'] ?: 'Global'; ?></td>
                            <td><span class="status <?php echo $admin['mfa_enabled'] === 't' ? 'status-active' : 'status-inactive'; ?>"><?php echo $admin['mfa_enabled'] === 't' ? 'ON' : 'OFF'; ?></span></td>
                            <td><?php echo date('Y-m-d', strtotime($admin['created_at'])); ?></td>
                            <td>
                                <?php if ($admin['admin_id'] != $currentAdminId && $admin['role_id'] != 999): ?>
                                <button class="btn btn-sm btn-danger" onclick="deleteAdmin(<?php echo $admin['admin_id']; ?>)">Delete</button>
                                <?php endif; ?>
                                <button class="btn btn-sm" onclick="resetPassword(<?php echo $admin['admin_id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')">Reset PW</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 30px; color: #999;">No administrators found.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Reset Password Modal -->
        <div id="resetModal" class="modal">
            <div class="modal-content">
                <h3>Reset Password</h3>
                <p id="resetUsername"></p>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" id="reset_password" style="width: 100%;" minlength="6">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button class="btn btn-primary" onclick="confirmReset()">Reset Password</button>
                    <button class="btn" onclick="closeModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let pendingAdminId = null;
        
        function showMessage(msg, type) {
            const msgDiv = document.getElementById('message');
            msgDiv.textContent = msg;
            msgDiv.className = 'message message-' + type;
            msgDiv.style.display = 'block';
            setTimeout(() => {
                msgDiv.style.display = 'none';
            }, 5000);
        }
        
        document.getElementById('createAdminForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const password = document.getElementById('create_password').value;
            if (password.length < 6) {
                showMessage('Password must be at least 6 characters.', 'error');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'create');
            formData.append('username', document.getElementById('create_username').value);
            formData.append('email', document.getElementById('create_email').value);
            formData.append('full_name', document.getElementById('create_full_name').value);
            formData.append('password', password);
            formData.append('role_id', document.getElementById('create_role_id').value);
            formData.append('country_code', document.getElementById('create_country_code').value);
            formData.append('mfa_enabled', document.getElementById('create_mfa_enabled').checked ? '1' : '0');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error creating admin: ' + error.message, 'error');
            }
        });
        
        async function deleteAdmin(adminId) {
            if (!confirm('Are you sure you want to delete this admin?')) return;
            
            const formData = new URLSearchParams();
            formData.append('action', 'delete');
            formData.append('admin_id', adminId);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error deleting admin: ' + error.message, 'error');
            }
        }
        
        function resetPassword(adminId, username) {
            pendingAdminId = adminId;
            document.getElementById('resetUsername').innerHTML = `Admin: <strong>${username}</strong>`;
            document.getElementById('reset_password').value = '';
            document.getElementById('resetModal').style.display = 'block';
        }
        
        async function confirmReset() {
            const newPassword = document.getElementById('reset_password').value;
            if (!newPassword || newPassword.length < 6) {
                showMessage('Password must be at least 6 characters.', 'error');
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'reset_password');
            formData.append('admin_id', pendingAdminId);
            formData.append('new_password', newPassword);
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message, 'success');
                    closeModal();
                } else {
                    showMessage(result.message, 'error');
                }
            } catch (error) {
                showMessage('Error resetting password: ' + error.message, 'error');
            }
        }
        
        function closeModal() {
            document.getElementById('resetModal').style.display = 'none';
            pendingAdminId = null;
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('resetModal');
            if (event.target == modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>
