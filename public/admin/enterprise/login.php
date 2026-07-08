<?php
session_start();
require_once '../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// Database connection
$db = new DBConnection();
$pdo = $db->getConnection();

// Fallback if getConnection() doesn't exist
if (!method_exists($db, 'getConnection')) {
    $host = 'localhost';
    $port = '5432';
    $dbname = 'vouchmorph';
    $username = 'postgres';
    $password = 'postgres';
    
    try {
        $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    try {
        // First, check if user exists in your users table
        // Since we don't have the users table structure, let's check if we need to create it
        $stmt = $pdo->prepare("
            SELECT 
                ou.id,
                ou.organization_id,
                ou.user_id,
                ou.role,
                ou.permissions,
                ou.is_active,
                o.name as org_name,
                o.logo_url,
                o.status as org_status,
                u.email,
                u.password_hash
            FROM organization_users ou
            INNER JOIN organizations o ON ou.organization_id = o.id
            INNER JOIN users u ON ou.user_id = u.id
            WHERE u.email = :email 
                AND ou.is_active = true 
                AND o.status = 'ACTIVE'
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Store user session data
            $_SESSION['enterprise_user'] = [
                'id' => $user['id'],
                'user_id' => $user['user_id'],
                'organization_id' => $user['organization_id'],
                'organization_name' => $user['org_name'],
                'role' => $user['role'],
                'email' => $user['email'],
                'permissions' => json_decode($user['permissions'] ?? '[]', true),
                'logo_url' => $user['logo_url']
            ];
            
            // Log the login
            try {
                $logStmt = $pdo->prepare("
                    INSERT INTO organization_audit_logs 
                    (organization_id, user_id, action, entity_type, ip_address, user_agent, created_at)
                    VALUES (:org_id, :user_id, 'LOGIN', 'user', :ip, :ua, NOW())
                ");
                $logStmt->execute([
                    ':org_id' => $user['organization_id'],
                    ':user_id' => $user['user_id'],
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (PDOException $e) {
                // Log error but don't stop login
                error_log("Failed to create audit log: " . $e->getMessage());
            }
            
            // Redirect to dashboard
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password';
        }
    } catch (PDOException $e) {
        // Check if the error is because users table doesn't exist
        if (strpos($e->getMessage(), 'relation "users" does not exist') !== false) {
            $error = 'System setup incomplete: Users table not found. Please run database migrations.';
        } else {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Login - VouchMorph</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            max-width: 450px;
            width: 100%;
        }
        .logo {
            text-align: center;
            margin-bottom: 40px;
        }
        .logo h1 {
            color: white;
            font-size: 32px;
            font-weight: 700;
        }
        .logo span {
            color: #fbbf24;
        }
        .logo p {
            color: #94a3b8;
            margin-top: 8px;
        }
        .card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .card h2 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .card .subtitle {
            color: #64748b;
            margin-bottom: 32px;
        }
        .form-group {
            margin-bottom: 24px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 15px;
            transition: all 0.2s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: #1e293b;
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #0f172a;
        }
        .error {
            background: #fef2f2;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
        }
        .footer {
            text-align: center;
            margin-top: 32px;
            color: #94a3b8;
            font-size: 13px;
        }
        .demo-cred {
            margin-top: 24px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
            font-size: 13px;
        }
        .demo-cred strong {
            color: #1e293b;
        }
        .demo-cred span {
            display: block;
            margin-top: 4px;
        }
        .alert-info {
            background: #eff6ff;
            color: #2563eb;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            border: 1px solid #bfdbfe;
        }
        .alert-warning {
            background: #fffbeb;
            color: #d97706;
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 13px;
            border: 1px solid #fde68a;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="logo">
        <h1>VouchMorph <span>Enterprise</span></h1>
        <p>Bulk Payment Orchestration Layer</p>
    </div>
    <div class="card">
        <h2>Welcome back</h2>
        <p class="subtitle">Sign in to your organization dashboard</p>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="alert-info">
            ℹ️ Use demo credentials below to test the system
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Email address</label>
                <input type="email" name="email" required placeholder="admin@government.gov.bw" value="demo@vouchmorph.com">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="••••••••" value="demo123">
            </div>
            <button type="submit" class="btn">Sign in →</button>
        </form>
        
        <div class="demo-cred">
            <strong>🔐 Demo Credentials</strong>
            <span>Email: demo@vouchmorph.com</span>
            <span>Password: demo123</span>
            <span style="font-size: 11px; color: #94a3b8;">(For testing only)</span>
        </div>
        
        <div class="alert-warning" style="margin-top: 16px;">
            ⚠️ <strong>Setup Required:</strong> Make sure the <code>users</code> table exists with <code>id</code>, <code>email</code>, and <code>password_hash</code> columns.
        </div>
    </div>
    <div class="footer">
        Secure enterprise payment platform • ISO 27001 Certified
    </div>
</div>
</body>
</html>
