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
                AND o.status = 'ACTIVE'
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

                    // OWNER & IT MANAGERS - Full dashboard access
                    if (in_array($__role, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true)) {
                        header('Location: index.php');
                        
                    // PROGRAM OFFICERS & DEPARTMENT HEADS - Create new disbursements (LOADERS)
                    } elseif (in_array($__role, ['program_officer', 'department_head'], true)) {
                        header('Location: imports/source_input.php');
                        
                    // APPROVERS - See pending approvals
                    } elseif (in_array($__role, ['approver', 'senior_approver'], true)) {
                        header('Location: index.php');
                        
                    // SUPERVISORS - Can disburse funds after approval
                    } elseif (in_array($__role, ['supervisor'], true)) {
                        header('Location: imports/review_batch.php?status=approved');
                        
                    // AUDITORS - Read-only view all batches
                    } elseif ($__role === 'auditor') {
                        header('Location: imports/review_batch.php?status=all');
                        
                    // VIEWERS - Only completed batches
                    } elseif ($__role === 'viewer') {
                        header('Location: imports/review_batch.php?status=completed');
                        
                    // BENEFICIARY REGISTRARS - Add destinations
                    } elseif ($__role === 'beneficiary_registrar') {
                        header('Location: imports/add_destinations.php');
                        
                    } else {
                        // Fallback
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VOUCHMORPH · SIGN IN</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* ============================================================
       Vouchmorph‑INSPIRED VARIABLES – sharp, no rounded corners,
       centralized, same color palette (dark/brass/paper)
       ============================================================ */
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

    .stage {
      width: 100%;
      max-width: 520px;
      padding: 0 16px;
    }

    .brand {
      text-align: center;
      margin-bottom: 36px;
    }
    .brand h1 {
      color: #fff;
      font-family: var(--f-cond);
      font-weight: 700;
      font-size: 22px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      border-top: 2px solid var(--brass);
      border-bottom: 2px solid var(--brass);
      padding: 16px 0;
      display: inline-block;
    }
    .brand .sub {
      color: rgba(255,255,255,0.2);
      font-size: 8px;
      margin-top: 14px;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      font-weight: 600;
      font-family: var(--f-mono);
      display: block;
    }

    .card {
      background: var(--panel);
      border: 1px solid var(--line);
      padding: 48px 44px 40px;
      position: relative;
      border-radius: 0;
    }
    .card::before {
      content: "";
      position: absolute;
      top: -1px;
      left: -1px;
      width: 12px;
      height: 12px;
      border-top: 2px solid var(--brass);
      border-left: 2px solid var(--brass);
      pointer-events: none;
    }
    .card::after {
      content: "";
      position: absolute;
      bottom: -1px;
      right: -1px;
      width: 12px;
      height: 12px;
      border-bottom: 2px solid var(--brass);
      border-right: 2px solid var(--brass);
      pointer-events: none;
    }

    .card h2 {
      font-family: var(--f-cond);
      font-size: 20px;
      font-weight: 700;
      color: var(--ink-900);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .card .subtitle {
      color: var(--ink-300);
      font-size: 13px;
      margin-top: 4px;
      margin-bottom: 32px;
      font-family: var(--f-body);
    }

    .field { margin-bottom: 24px; }
    .field label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      font-size: 10px;
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
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      color: var(--ink-300);
      pointer-events: none;
    }
    .field input {
      width: 100%;
      padding: 14px 16px 14px 44px;
      border: 1.5px solid var(--line);
      font-size: 15px;
      font-family: var(--f-body);
      background: #fdfcf9;
      transition: border-color .15s, background .15s;
      color: var(--ink-900);
      border-radius: 0;
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

    .btn {
      width: 100%;
      padding: 16px;
      background: var(--ink-900);
      color: #fff;
      border: 1.5px solid var(--ink-900);
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: .15s;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-family: var(--f-cond);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      border-radius: 0;
      margin-top: 4px;
    }
    .btn:hover {
      background: var(--brass);
      border-color: var(--brass);
      color: var(--ink-900);
    }
    .btn svg {
      width: 16px;
      height: 16px;
      transition: transform .15s;
    }
    .btn:hover svg {
      transform: translateX(4px);
    }

    .error {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      background: var(--danger-bg);
      color: var(--danger);
      padding: 14px 16px;
      margin-bottom: 24px;
      font-size: 13px;
      border-left: 3px solid var(--danger);
      line-height: 1.5;
      font-weight: 500;
      border-radius: 0;
    }
    .error svg {
      width: 18px;
      height: 18px;
      flex-shrink: 0;
      margin-top: 1px;
    }

    .trust-row {
      display: flex;
      justify-content: space-between;
      margin-top: 28px;
      padding-top: 22px;
      border-top: 1px solid var(--line);
      font-size: 10px;
      color: var(--ink-300);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
      font-family: var(--f-cond);
    }
    .trust-row span {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .trust-row svg {
      width: 14px;
      height: 14px;
      color: var(--brass);
    }

    .footer {
      text-align: center;
      margin-top: 34px;
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

    @media (max-width: 480px) {
      .stage { max-width: 100%; padding: 0 12px; }
      .card { padding: 30px 20px 24px; }
      .brand h1 { font-size: 18px; padding: 12px 0; }
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

    <form method="POST" action="#">
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

  <div class="footer">
    <div class="line1">SECURE ENTERPRISE MULTI-ASSET PAYMENT · DISTRIBUTION RESTRICTED · ISO 27001 · © 2026 VOUCHMORPH</div>
    <div class="line2">OWNER · VM/2026/0708-000</div>
  </div>
</div>
</body>
</html>
