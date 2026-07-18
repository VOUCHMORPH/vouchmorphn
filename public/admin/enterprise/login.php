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
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600&family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* ============================================================
       VOUCHMORPH — SIGN IN
       Full-bleed 50/50 split. Left: white, functional, form.
       Right: ink-black, magazine-set brand statement inside a
       museum-mat frame with the wordmark run around its border.
       No max-width box floating mid-screen — each half fills
       exactly 50% of the viewport at any size, always.
       ============================================================ */
    :root {
      --paper:        #EEF1EF;
      --panel:        #FFFFFF;
      --ink-900:      #0B1B2B;
      --ink-700:      #1D3557;
      --ink-500:      #4A5A6E;
      --ink-300:      #8A96A3;
      --line:         #D3DAD6;
      --line-strong:  #AEB8B2;
      --brass:        #9C7A3C;
      --brass-deep:   #6E5326;
      --brass-tint:   #F4EFE3;
      --danger:       #b3261e;
      --danger-bg:    #fbeceb;

      --f-display: 'Source Serif 4', 'IBM Plex Sans', serif;
      --f-body: 'IBM Plex Sans', sans-serif;
      --f-cond: 'IBM Plex Sans Condensed', sans-serif;
      --f-mono: 'IBM Plex Mono', monospace;

      --sp-1: 4px;  --sp-2: 8px;  --sp-3: 12px; --sp-4: 16px;
      --sp-5: 20px; --sp-6: 24px; --sp-7: 32px; --sp-8: 40px;
      --sp-9: 48px; --sp-10: 64px;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }
    html, body { height: 100%; }

    body {
      font-family: var(--f-body);
      color: var(--ink-900);
      font-size: 15px;
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
    }

    :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

    /* ============================================================
       SPLIT — two flex children, each exactly half, full height.
       No fixed widths anywhere: this is what keeps it symmetric
       on any screen size instead of drifting to one side.
       ============================================================ */
    .split {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    .col {
      flex: 1 1 50%;
      min-width: 0;
      display: flex;
      flex-direction: column;
    }

    /* ============================================================
       LEFT — white, functional
       ============================================================ */
    .col-form {
      background: var(--panel);
      align-items: center;
      justify-content: center;
      padding: var(--sp-8) var(--sp-6);
    }
    .form-wrap {
      width: 100%;
      max-width: 400px;
    }

    .brand {
      margin-bottom: var(--sp-8);
    }
    .brand .mark {
      font-family: var(--f-display);
      font-weight: 600;
      font-size: 26px;
      letter-spacing: 0.005em;
      color: var(--ink-900);
    }
    .brand .mark sup { font-size: 11px; color: var(--brass-deep); font-weight: 600; }
    .brand .division {
      margin-top: var(--sp-2);
      font-family: var(--f-cond);
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--ink-300);
      padding-top: var(--sp-2);
      border-top: 2px solid var(--brass);
      display: inline-block;
    }

    .form-wrap h2 {
      font-family: var(--f-display);
      font-size: 24px;
      font-weight: 600;
      color: var(--ink-900);
    }
    .form-wrap .subtitle {
      color: var(--ink-500);
      font-size: 14px;
      margin-top: var(--sp-1);
      margin-bottom: var(--sp-7);
    }

    .field { margin-bottom: var(--sp-5); }
    .field label {
      display: block;
      margin-bottom: var(--sp-2);
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--ink-500);
      font-family: var(--f-cond);
    }
    .field-input { position: relative; }
    .field-input svg {
      position: absolute;
      left: var(--sp-4);
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      color: var(--ink-300);
      pointer-events: none;
    }
    .field input {
      width: 100%;
      padding: var(--sp-4) var(--sp-4) var(--sp-4) 44px;
      border: 1.5px solid var(--line);
      font-size: 15px;
      font-family: var(--f-body);
      background: var(--paper);
      transition: border-color .15s, background .15s;
      color: var(--ink-900);
      border-radius: 0;
    }
    .field input:focus {
      outline: none;
      border-color: var(--brass);
      background: #fff;
    }
    .field input::placeholder { color: var(--ink-300); opacity: 0.8; }

    .btn {
      width: 100%;
      padding: var(--sp-4);
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
      gap: var(--sp-3);
      border-radius: 0;
      margin-top: var(--sp-2);
    }
    .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
    .btn svg { width: 16px; height: 16px; transition: transform .15s; }
    .btn:hover svg { transform: translateX(4px); }

    .error {
      display: flex;
      align-items: flex-start;
      gap: var(--sp-3);
      background: var(--danger-bg);
      color: var(--danger);
      padding: var(--sp-4);
      margin-bottom: var(--sp-6);
      font-size: 13px;
      border-left: 3px solid var(--danger);
      line-height: 1.5;
      font-weight: 500;
    }
    .error svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

    .trust-row {
      display: flex;
      justify-content: space-between;
      margin-top: var(--sp-7);
      padding-top: var(--sp-5);
      border-top: 1px solid var(--line);
      font-size: 10.5px;
      color: var(--ink-300);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      font-weight: 600;
      font-family: var(--f-cond);
    }
    .trust-row span { display: flex; align-items: center; gap: var(--sp-2); }
    .trust-row svg { width: 14px; height: 14px; color: var(--brass); }

    .legal {
      margin-top: var(--sp-8);
      font-size: 9.5px;
      letter-spacing: 0.05em;
      font-family: var(--f-mono);
      text-transform: uppercase;
      line-height: 1.9;
      color: var(--ink-300);
    }
    .legal .line2 { color: var(--line-strong); font-size: 9px; }

    /* ============================================================
       RIGHT — black, magazine statement inside a mat frame
       ============================================================ */
    .col-brand {
      background:
        radial-gradient(1100px 600px at 85% 0%, rgba(156,122,60,.12), transparent 60%),
        #0A1420;
      position: relative;
      align-items: stretch;
      justify-content: stretch;
      overflow: hidden;
    }

    .frame-mat {
      position: relative;
      flex: 1;
      margin: var(--sp-9);
    }
    .frame-line {
      position: absolute;
      inset: var(--sp-8);
      border: 1px solid rgba(255,255,255,0.16);
      pointer-events: none;
    }

    /* Repeating wordmark strips that trace the frame — this is the
       "written around the frame" effect, like a mat board with the
       studio name printed on it. */
    .frame-strip {
      position: absolute;
      color: rgba(255,255,255,0.3);
      font-family: var(--f-mono);
      font-size: 10px;
      letter-spacing: 0.28em;
      text-transform: uppercase;
      white-space: nowrap;
      overflow: hidden;
      display: flex;
      align-items: center;
    }
    .frame-strip span { display: inline-block; }
    .frame-strip.top {
      top: var(--sp-3); left: var(--sp-8); right: var(--sp-8);
      height: 20px; justify-content: center;
    }
    .frame-strip.bottom {
      bottom: var(--sp-3); left: var(--sp-8); right: var(--sp-8);
      height: 20px; justify-content: center;
    }
    .frame-strip.right {
      right: var(--sp-3); top: var(--sp-8); bottom: var(--sp-8);
      width: 26px; writing-mode: vertical-rl; transform: rotate(180deg);
      justify-content: center;
      color: var(--brass);
      font-family: var(--f-cond);
      font-size: 15px;
      font-weight: 700;
      letter-spacing: 0.34em;
      opacity: 1;
    }

    .magazine {
      position: absolute;
      inset: var(--sp-8);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: var(--sp-9) var(--sp-8);
    }
    .magazine .eyebrow {
      font-family: var(--f-cond);
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: var(--brass);
      margin-bottom: var(--sp-6);
    }

    /* ============================================================
       BOTH PARAGRAPHS — Same font size, justified alignment
       First letter uses the same font as "V" in VOUCHMORPH
       ============================================================ */
    .magazine p {
      font-family: var(--f-display);
      font-size: 17px;
      font-weight: 400;
      line-height: 1.85;
      color: rgba(255,255,255,0.92);
      max-width: 520px;
      text-align: justify;
      text-justify: inter-word;
      hyphens: auto;
    }

    /* First letter styling — uses the same font as the "V" in VOUCHMORPH™ */
    .magazine p::first-letter {
      font-family: var(--f-cond);
      font-size: 58px;
      font-weight: 700;
      color: var(--brass);
      float: left;
      line-height: 0.8;
      padding-right: var(--sp-2);
      padding-top: 4px;
    }

    .magazine p.secondary {
      font-family: var(--f-body);
      font-size: 17px;
      font-weight: 400;
      line-height: 1.75;
      color: rgba(255,255,255,0.62);
      max-width: 480px;
      margin-top: var(--sp-5);
      letter-spacing: 0.005em;
      text-align: justify;
      text-justify: inter-word;
      hyphens: auto;
    }
    .magazine p.secondary strong {
      color: rgba(255,255,255,0.85);
      font-weight: 600;
    }
    .magazine .mark {
      margin-top: var(--sp-7);
      width: 40px;
      height: 1px;
      background: var(--brass);
    }

    /* ============================================================
       RESPONSIVE — stack on narrow screens, never lopsided
       ============================================================ */
    @media (max-width: 900px) {
      .split { flex-direction: column; }
      .col { flex: 1 1 auto; }
      .col-form { padding: var(--sp-7) var(--sp-5); }
      .col-brand { min-height: 380px; }
      .frame-mat { margin: var(--sp-6); }
      .frame-line { inset: var(--sp-6); }
      .frame-strip.top, .frame-strip.bottom { left: var(--sp-6); right: var(--sp-6); }
      .frame-strip.right { top: var(--sp-6); bottom: var(--sp-6); }
      .magazine { inset: var(--sp-6); padding: var(--sp-7) var(--sp-5); }
      .magazine p { font-size: 15px; }
      .magazine p::first-letter { font-size: 48px; }
      .magazine p.secondary { font-size: 15px; }
    }
    @media (max-width: 480px) {
      .frame-strip.right { display: none; }
      .trust-row { flex-wrap: wrap; gap: var(--sp-3); justify-content: center; }
      .magazine p { font-size: 14px; }
      .magazine p::first-letter { font-size: 40px; }
      .magazine p.secondary { font-size: 14px; }
    }

    @media (prefers-color-scheme: dark) {
      .col-form { background: #12202B; }
      .brand .mark { color: #ECEFF2; }
      .form-wrap h2 { color: #ECEFF2; }
      .form-wrap .subtitle { color: #93A2AC; }
      .field label { color: #93A2AC; }
      .field input { background: #0F1B24; border-color: #2C3A45; color: #ECEFF2; }
      .field input:focus { background: #16232E; border-color: var(--brass); }
      .field input::placeholder { color: #6B7A85; }
      .trust-row { border-color: #2C3A45; color: #6B7A85; }
      .legal { color: #6B7A85; }
      .legal .line2 { color: #3A4A56; }
      .btn { background: #2C3A45; border-color: #2C3A45; color: #ECEFF2; }
      .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
    }
  </style>
</head>
<body>
<div class="split">

  <!-- LEFT — white, functional -->
  <div class="col col-form">
    <div class="form-wrap">
      <div class="brand">
        <div class="mark">VOUCHMORPH<sup>™</sup></div>
        <div class="division">Enterprise Access</div>
      </div>

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

      <div class="legal">
        <div>Secure enterprise multi-asset payment · distribution restricted · ISO 27001 · © 2026 VouchMorph</div>
        <div class="line2">VM/2026/0708-000</div>
      </div>
    </div>
  </div>

  <!-- RIGHT — black, magazine statement in a mat frame -->
  <div class="col col-brand">
    <div class="frame-mat">
      <div class="frame-line"></div>

      <div class="frame-strip right"><span>VOUCHMORPH™</span></div>

      <div class="magazine">
        <div class="eyebrow">What is VouchMorph</div>
        <p>VouchMorph moves money between banks, wallets, and vouchers that were never built to talk to each other. An organization sends funds from an account, a card, or a mobile wallet — and the person on the other end can collect it however suits them: a bank deposit, an ATM withdrawal, or a printed voucher redeemed by an agent. One instruction in. Any form of money out.</p>
        <p class="secondary">A single batch isn't limited to one destination type. Bank accounts, mobile wallets, and beneficiaries identified only by phone number or national ID can sit <strong>in the same batch</strong>, funded from one or several sources, and settle together as one reconciled record — not dozens of separate transfers to track by hand.</p>
        <div class="mark"></div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
