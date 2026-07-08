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
    <title>VOUCHMORPH · SIGN IN</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --ink-900:      #0F2138;
            --ink-700:      #1D3557;
            --ink-500:      #4A5A6E;
            --ink-300:      #8A96A3;
            --line:         #D3DAD6;
            --line-strong:  #AEB8B2;
            --brass:        #8A6D3B;
            --brass-tint:   #F4EFE3;
            --seal-red:     #7A2118;
            --amber:        #8A5A0B;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --danger:       #b3261e;
            --danger-bg:    #fbeceb;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--f-body);
            background:
                radial-gradient(1100px 500px at 15% -10%, rgba(138,109,59,.10), transparent 60%),
                linear-gradient(160deg, #060b16 0%, var(--ink-900) 55%, #10203a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
            color: var(--ink-900);
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

        .stage { width: 100%; max-width: 404px; }

        /* ============================================================
           BRAND - Clean, just "Sovereign Disbursement Network"
           ============================================================ */
        .brand {
            text-align: center;
            margin-bottom: 40px;
        }
        .brand h1 {
            color: #fff;
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 20px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-top: 2px solid var(--brass);
            border-bottom: 2px solid var(--brass);
            padding: 14px 0;
            display: inline-block;
        }
        .brand .sub {
            color: rgba(255,255,255,0.2);
            font-size: 8px;
            margin-top: 12px;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-weight: 600;
            font-family: var(--f-mono);
            display: block;
        }

        /* ============================================================
           CARD - MATCHES DASHBOARD STYLE
           ============================================================ */
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 38px 36px 32px;
            position: relative;
        }
        .card::before {
            content: "";
            position: absolute;
            top: -1px;
            left: -1px;
            width: 9px;
            height: 9px;
            border-top: 2px solid var(--brass);
            border-left: 2px solid var(--brass);
            pointer-events: none;
        }
        .card::after {
            content: "";
            position: absolute;
            bottom: -1px;
            right: -1px;
            width: 9px;
            height: 9px;
            border-bottom: 2px solid var(--brass);
            border-right: 2px solid var(--brass);
            pointer-events: none;
        }

        .card h2 {
            font-family: var(--f-cond);
            font-size: 18px;
            font-weight: 700;
            color: var(--ink-900);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .card .subtitle {
            color: var(--ink-300);
            font-size: 12px;
            margin-top: 4px;
            margin-bottom: 28px;
            font-family: var(--f-body);
        }

        /* ============================================================
           FORM FIELDS
           ============================================================ */
        .field { margin-bottom: 20px; }
        .field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            font-family: var(--f-cond);
        }
        .field-input {
            position: relative;
        }
        .field-input svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: var(--ink-300);
            pointer-events: none;
        }
        .field input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid var(--line);
            font-size: 14px;
            font-family: var(--f-body);
            background: #fdfcf9;
            transition: border-color .15s, background .15s;
            color: var(--ink-900);
        }
        .field input:focus {
            outline: none;
            border-color: var(--brass);
            background: #fff;
        }
        .field input::placeholder {
            color: var(--ink-300);
            opacity: 0.7;
        }

        /* ============================================================
           BUTTON
           ============================================================ */
        .btn {
            width: 100%;
            padding: 13px;
            background: var(--ink-900);
            color: #fff;
            border: 1.5px solid var(--ink-900);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: .15s;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-family: var(--f-cond);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
        }
        .btn svg {
            width: 15px;
            height: 15px;
            transition: transform .15s;
        }
        .btn:hover svg {
            transform: translateX(3px);
        }

        /* ============================================================
           ERROR
           ============================================================ */
        .error {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: var(--danger-bg);
            color: var(--danger);
            padding: 12px 14px;
            margin-bottom: 22px;
            font-size: 12px;
            border-left: 3px solid var(--danger);
            line-height: 1.5;
            font-weight: 500;
        }
        .error svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* ============================================================
           TRUST ROW
           ============================================================ */
        .trust-row {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--line);
            font-size: 9px;
            color: var(--ink-300);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            font-family: var(--f-cond);
        }
        .trust-row span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .trust-row svg {
            width: 13px;
            height: 13px;
            color: var(--brass);
        }

        /* ============================================================
           FOOTER - Updated to match dashboard style
           ============================================================ */
        .footer {
            text-align: center;
            margin-top: 30px;
            color: rgba(255,255,255,0.2);
            font-size: 8px;
            letter-spacing: 0.06em;
            font-family: var(--f-mono);
            text-transform: uppercase;
            line-height: 1.8;
        }
        .footer .line1 {
            color: rgba(255,255,255,0.3);
        }
        .footer .line2 {
            color: rgba(255,255,255,0.15);
            font-size: 7px;
            letter-spacing: 0.08em;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 480px) {
            .stage { max-width: 100%; }
            .card { padding: 28px 20px 24px; }
            .brand h1 { font-size: 16px; padding: 10px 0; }
            .trust-row { flex-wrap: wrap; gap: 8px; justify-content: center; }
        }

        @media (prefers-color-scheme: dark) {
            .card {
                background: #1B2733;
                border-color: #2C3A45;
            }
            .card h2 { color: #ECEFF2; }
            .card .subtitle { color: #6B7A85; }
            .field input {
                background: #1B2733;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .field input:focus {
                border-color: var(--brass);
                background: #22303A;
            }
            .field input::placeholder { color: #6B7A85; }
            .field label { color: #93A2AC; }
            .trust-row { border-color: #2C3A45; color: #6B7A85; }
            .btn {
                background: #2C3A45;
                border-color: #2C3A45;
                color: #ECEFF2;
            }
            .btn:hover {
                background: var(--brass);
                border-color: var(--brass);
                color: var(--ink-900);
            }
        }
    </style>
</head>
<body>
<div class="stage">
    <!-- Brand - Clean, just "Sovereign Disbursement Network" -->
    <div class="brand">
        <h1>Sovereign Disbursement Network</h1>
        <span class="sub">Secure · Multi-Asset · Identity-First</span>
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

    <!-- Updated Footer -->
    <div class="footer">
        <div class="line1">SECURE ENTERPRISE MULTI-ASSET PAYMENT · DISTRIBUTION RESTRICTED · ISO 27001 · © 2026 VOUCHMORPH</div>
        <div class="line2">OWNER · VM/2026/0708-000</div>
    </div>
</div>
</body>
</html>
