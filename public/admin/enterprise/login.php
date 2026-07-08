<?php
// login.php
require_once 'auth.php'; // auth.php now handles session hardening BEFORE session_start()

$pdo = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        $stmt = $pdo->prepare("
            SELECT
                ou.id as org_user_id,
                ou.organization_id,
                ou.user_id,
                ou.role,
                ou.department_id,
                ou.permissions,
                ou.is_active,
                o.id as org_id,
                o.name as org_name,
                o.logo_url,
                o.status as org_status,
                u.user_id as auth_user_id,
                u.email,
                u.username,
                u.password_hash,
                u.full_name,
                u.verified,
                u.kyc_verified,
                u.aml_score,
                u.wallet_uuid
            FROM organization_users ou
            INNER JOIN organizations o ON ou.organization_id = o.id
            INNER JOIN users u ON ou.user_id = u.user_id
            WHERE u.email = :email
                AND ou.is_active = true
                AND o.status = 'ACTIVE'
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['enterprise_user'] = [
                'id' => $user['user_id'],
                'org_user_id' => $user['org_user_id'],
                'user_id' => $user['user_id'],
                'organization_id' => $user['organization_id'],
                'organization_name' => $user['org_name'],
                'role' => $user['role'],
                'department_id' => $user['department_id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'permissions' => json_decode($user['permissions'] ?? '[]', true),
                'logo_url' => $user['logo_url'],
                'verified' => $user['verified'],
                'kyc_verified' => $user['kyc_verified'],
                'aml_score' => $user['aml_score'],
                'wallet_uuid' => $user['wallet_uuid']
            ];

            // Regenerate session ID on privilege change (login) to prevent
            // session fixation attacks.
            session_regenerate_id(true);

            try {
                $updateStmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE user_id = :user_id");
                $updateStmt->execute([':user_id' => $user['user_id']]);
            } catch (PDOException $e) {
                error_log("Failed to update last login: " . $e->getMessage());
            }

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
                error_log("Failed to create audit log: " . $e->getMessage());
            }

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password';

            try {
                $logStmt = $pdo->prepare("
                    INSERT INTO organization_audit_logs
                    (organization_id, user_id, action, entity_type, ip_address, user_agent, created_at)
                    VALUES (NULL, NULL, 'LOGIN_FAILED', 'user', :ip, :ua, NOW())
                ");
                $logStmt->execute([
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (PDOException $e) {
                error_log("Failed to create audit log: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        $error = 'Unable to sign in right now. Please try again shortly.';
        error_log("Login error: " . $e->getMessage());
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
        .login-container { max-width: 450px; width: 100%; }
        .logo { text-align: center; margin-bottom: 40px; }
        .logo h1 { color: white; font-size: 32px; font-weight: 700; }
        .logo span { color: #fbbf24; }
        .logo p { color: #94a3b8; margin-top: 8px; }
        .card { background: white; border-radius: 24px; padding: 40px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .card h2 { font-size: 24px; font-weight: 600; margin-bottom: 8px; }
        .card .subtitle { color: #64748b; margin-bottom: 32px; }
        .form-group { margin-bottom: 24px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 500; font-size: 14px; }
        .form-group input {
            width: 100%; padding: 12px 16px; border: 1px solid #e2e8f0;
            border-radius: 12px; font-size: 15px; transition: all 0.2s;
        }
        .form-group input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .btn {
            width: 100%; padding: 14px; background: #1e293b; color: white;
            border: none; border-radius: 40px; font-size: 16px; font-weight: 600;
            cursor: pointer; transition: background 0.2s;
        }
        .btn:hover { background: #0f172a; }
        .error { background: #fef2f2; color: #dc2626; padding: 12px 16px; border-radius: 12px; margin-bottom: 24px; font-size: 14px; }
        .footer { text-align: center; margin-top: 32px; color: #94a3b8; font-size: 13px; }
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

        <form method="POST">
            <div class="form-group">
                <label>Email address</label>
                <input type="email" name="email" required placeholder="admin@government.gov.bw" autocomplete="username">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required placeholder="••••••••" autocomplete="current-password">
            </div>
            <button type="submit" class="btn">Sign in →</button>
        </form>

        <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
            <div style="display: flex; justify-content: space-between; font-size: 12px; color: #64748b;">
                <span>🔒 Secure Login</span>
                <span>🛡️ 2FA Available</span>
            </div>
        </div>
    </div>
    <div class="footer">
        Secure enterprise payment platform • ISO 27001 Certified
    </div>
</div>
</body>
</html>
