<?php
// login.php - Enterprise Login
require_once 'auth.php'; // auth.php handles session hardening BEFORE session_start()

$pdo = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    try {
        // ============================================================
        // STEP 1: Authenticate the user
        // ============================================================
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

            // ============================================================
            // STEP 2: Validate the role exists in organization_role_catalog
            // ============================================================
            $roleCheck = $pdo->prepare("
                SELECT role_code, label, default_scope 
                FROM organization_role_catalog 
                WHERE role_code = :role
            ");
            $roleCheck->execute([':role' => $user['role']]);
            $roleInfo = $roleCheck->fetch(PDO::FETCH_ASSOC);

            if (!$roleInfo) {
                error_log("[SECURITY] Login attempt with invalid role: " . $user['role'] . " for user: " . $email);

                try {
                    $logStmt = $pdo->prepare("
                        INSERT INTO organization_audit_logs
                        (organization_id, user_id, action, entity_type, ip_address, user_agent, created_at)
                        VALUES (NULL, NULL, 'INVALID_ROLE_ATTEMPT', 'security', :ip, :ua, NOW())
                    ");
                    $logStmt->execute([
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
                    ]);
                } catch (PDOException $e) {
                    error_log("Failed to create audit log: " . $e->getMessage());
                }

                $error = 'Invalid account configuration. Please contact your system administrator.';
            } else {
                // ============================================================
                // STEP 3: Validate role scoping matches department assignment
                // ============================================================
                $scope = $roleInfo['default_scope'];

                if ($scope === 'department' && empty($user['department_id'])) {
                    error_log("[SECURITY] Department-scoped role " . $user['role'] . " has no department_id for user: " . $email);

                    $error = 'Your account is not fully configured. Please contact your system administrator.';

                    try {
                        $logStmt = $pdo->prepare("
                            INSERT INTO organization_audit_logs
                            (organization_id, user_id, action, entity_type, ip_address, user_agent, created_at)
                            VALUES (:org_id, :user_id, 'DEPARTMENT_MISSING', 'security', :ip, :ua, NOW())
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
                } else {
                    // ============================================================
                    // STEP 4: All valid - proceed with login
                    // ============================================================
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

                    header('Location: index.php');
                    exit;
                }
            }
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
    <title>Sign in — VouchMorph Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#0a1628; --ink-soft:#16243d;
            --gold:#c9972a; --gold-bright:#e0ad3d;
            --paper:#ffffff; --line:#e4e1d6; --line-soft:#eeece3;
            --text:#191712; --mist:#6b6a63;
            --danger:#b3261e; --danger-bg:#fbeceb;
            --font-display:'Fraunces',serif; --font-body:'Inter',sans-serif; --font-mono:'JetBrains Mono',monospace;
        }
        *{margin:0;padding:0;box-sizing:border-box;}
        body{
            font-family:var(--font-body);
            background:
                radial-gradient(1100px 500px at 15% -10%, rgba(201,151,42,.10), transparent 60%),
                linear-gradient(160deg, #060b16 0%, var(--ink) 55%, #10203a 100%);
            min-height:100vh; display:flex; align-items:center; justify-content:center; padding:32px;
        }
        :focus-visible{outline:2px solid var(--gold); outline-offset:2px;}

        .stage{width:100%; max-width:404px;}

        .brand{text-align:center; margin-bottom:36px;}
        .brand-mark{
            width:46px; height:46px; margin:0 auto 16px; background:var(--gold);
            display:flex; align-items:center; justify-content:center; font-family:var(--font-display);
            font-weight:600; font-size:19px; color:var(--ink);
        }
        .brand h1{color:#fff; font-family:var(--font-display); font-weight:500; font-size:26px; letter-spacing:-.3px;}
        .brand h1 em{font-style:normal; color:var(--gold);}
        .brand p{color:#8791a6; font-size:11px; margin-top:8px; letter-spacing:1.6px; text-transform:uppercase; font-weight:500;}

        .card{
            background:var(--paper); border:1px solid var(--ink);
            box-shadow:6px 6px 0 rgba(201,151,42,.9);
            padding:38px 36px 32px;
        }
        .card h2{font-family:var(--font-display); font-size:21px; font-weight:600; color:var(--text); letter-spacing:-.2px;}
        .card .subtitle{color:var(--mist); font-size:13px; margin-top:4px; margin-bottom:28px;}

        .field{margin-bottom:20px;}
        .field label{
            display:block; margin-bottom:7px; font-weight:600; font-size:11px;
            text-transform:uppercase; letter-spacing:.8px; color:var(--ink-soft);
        }
        .field-input{position:relative;}
        .field-input svg{
            position:absolute; left:13px; top:50%; transform:translateY(-50%);
            width:16px; height:16px; color:var(--mist); pointer-events:none;
        }
        .field input{
            width:100%; padding:12px 14px 12px 40px; border:1.5px solid var(--line);
            font-size:14.5px; font-family:var(--font-body); background:#fdfcf9; transition:border-color .15s, background .15s;
        }
        .field input:focus{outline:none; border-color:var(--gold); background:#fff;}
        .field input::placeholder{color:#b8b6a9;}

        .btn{
            width:100%; padding:13px; background:var(--ink); color:#fff; border:1.5px solid var(--ink);
            font-size:13.5px; font-weight:600; cursor:pointer; transition:.15s;
            text-transform:uppercase; letter-spacing:1px; font-family:var(--font-body);
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .btn:hover{background:var(--gold); border-color:var(--gold); color:var(--ink);}
        .btn svg{width:15px; height:15px; transition:transform .15s;}
        .btn:hover svg{transform:translateX(3px);}

        .error{
            display:flex; align-items:flex-start; gap:10px;
            background:var(--danger-bg); color:var(--danger); padding:12px 14px; margin-bottom:22px;
            font-size:13px; border-left:3px solid var(--danger); line-height:1.5;
        }
        .error svg{width:16px; height:16px; flex-shrink:0; margin-top:1px;}

        .trust-row{
            display:flex; justify-content:space-between; margin-top:24px; padding-top:20px;
            border-top:1px solid var(--line-soft); font-size:11px; color:var(--mist);
            text-transform:uppercase; letter-spacing:.5px; font-weight:600;
        }
        .trust-row span{display:flex; align-items:center; gap:6px;}
        .trust-row svg{width:13px; height:13px; color:var(--gold);}

        .footer{text-align:center; margin-top:30px; color:#5c6779; font-size:11.5px; letter-spacing:.3px;}
        .footer strong{color:#8791a6;}
    </style>
</head>
<body>
<div class="stage">
    <div class="brand">
        <div class="brand-mark">VM</div>
        <h1>VouchMorph <em>Enterprise</em></h1>
        <p>Sovereign Disbursement Network</p>
    </div>

    <div class="card">
        <h2>Sign in</h2>
        <p class="subtitle">Access your organization's command center</p>

        <?php if ($error): ?>
            <div class="error">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label>Email address</label>
                <div class="field-input">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 7l9 6 9-6"/></svg>
                    <input type="email" name="email" required placeholder="you@government.gov.bw" autocomplete="username">
                </div>
            </div>
            <div class="field">
                <label>Password</label>
                <div class="field-input">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="9" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                    <input type="password" name="password" required placeholder="••••••••" autocomplete="current-password">
                </div>
            </div>
            <button type="submit" class="btn">
                Sign in
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </button>
        </form>

        <div class="trust-row">
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-4Z"/></svg>Secure</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="10" width="16" height="10" rx="1"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>2FA Ready</span>
            <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 12 5 5L20 6"/></svg>ISO 27001</span>
        </div>
    </div>

    <div class="footer">Secure enterprise payment platform &middot; <strong>Government of Botswana</strong></div>
</div>
</body>
</html>
