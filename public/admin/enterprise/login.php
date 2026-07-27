<?php
// login.php - Enterprise Login with Multi-Destination Workflow Redirects
 
// ============================================================================
// FIX: Session settings MUST be set BEFORE any output
// ============================================================================  
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once 'auth.php';

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
                AND UPPER(o.status) = 'ACTIVE'
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {

            $roleCheck = $pdo->prepare("
                SELECT role_code, label, default_scope 
                FROM organization_role_catalog 
                WHERE role_code = :role
            ");
            $roleCheck->execute([':role' => $user['role']]);
            $roleInfo = $roleCheck->fetch(PDO::FETCH_ASSOC);

            if (!$roleInfo) {
                error_log("[SECURITY] Login attempt with invalid role: " . $user['role'] . " for user: " . $email);
                $error = 'Invalid account configuration. Please contact your system administrator.';
            } else {
                $scope = $roleInfo['default_scope'];

                if ($scope === 'department' && empty($user['department_id'])) {
                    error_log("[SECURITY] Department-scoped role " . $user['role'] . " has no department_id for user: " . $email);
                    $error = 'Your account is not fully configured. Please contact your system administrator.';
                } else {
                    $_SESSION['enterprise_user'] = [
                        'id' => $user['user_id'],
                        'org_user_id' => $user['org_user_id'],
                        'user_id' => $user['user_id'],
                        'organization_id' => $user['organization_id'],
                        'organization_name' => $user['org_name'],
                        'role' => $user['role'],
                        'role_label' => $roleInfo['label'],
                        'role_scope' => $roleInfo['default_scope'],
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
                            (organization_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent, created_at)
                            VALUES (:org_id, :user_id, 'LOGIN', 'user', :entity_id, NULL, :new_values, :ip, :ua, NOW())
                        ");
                        $logStmt->execute([
                            ':org_id' => $user['organization_id'],
                            ':user_id' => $user['user_id'],
                            ':entity_id' => $user['user_id'],
                            ':new_values' => json_encode([
                                'role' => $user['role'],
                                'role_label' => $roleInfo['label'],
                                'role_scope' => $roleInfo['default_scope'],
                                'department_id' => $user['department_id']
                            ]),
                            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                    } catch (PDOException $e) {
                        error_log("Failed to create audit log: " . $e->getMessage());
                    }

                    // ============================================================
                    // ROLE-BASED REDIRECTS - Each role sees what they should see
                    // ============================================================
                    $__role = $user['role'];

                    if (in_array($__role, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true)) {
                        header('Location: index.php');
                    } elseif (in_array($__role, ['program_officer', 'department_head'], true)) {
                        header('Location: imports/source_input.php');
                    } elseif (in_array($__role, ['approver', 'senior_approver'], true)) {
                        header('Location: index.php');
                    } elseif (in_array($__role, ['supervisor'], true)) {
                        header('Location: imports/review_batch.php?status=approved');
                    } elseif ($__role === 'auditor') {
                        header('Location: imports/review_batch.php?status=all');
                    } elseif ($__role === 'viewer') {
                        header('Location: imports/review_batch.php?status=completed');
                    } elseif ($__role === 'beneficiary_registrar') {
                        header('Location: imports/add_destinations.php');
                    } else {
                        header('Location: index.php');
                    }
                    exit;
                }
            }
        } else {
            $error = 'Invalid email or password';
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
  <title>VOUCHMORPH · SIGN IN</title>
</head>
<body>
<!-- Styling/markup unchanged from the original file — omitted here since
     nothing about the visual design needed to change, only the query
     above. See the version you pasted for the full page. -->
<form method="POST" action="#">
  <input type="email" name="email" required placeholder="you@government.gov.bw" autocomplete="username">
  <input type="password" name="password" required placeholder="********" autocomplete="current-password">
  <button type="submit">Sign in</button>
</form>
</body>
</html>
