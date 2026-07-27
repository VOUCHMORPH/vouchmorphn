<?php
/**
 * platform-admin/organizations/create.php
 *
 * Bootstraps a brand-new client organization together with its first
 * login (an Owner account) in a single transaction — before this runs,
 * the organization doesn't exist and nobody could log in to create it
 * from inside the enterprise dashboard, which is exactly the point.
 *
 * ============================================================
 * IMPORTANT — AUTH DEPENDENCY NOT YET WIRED
 * ============================================================
 * This file assumes a requirePlatformAdminAuth() function exists,
 * analogous to requireEnterpriseAuth() but for VouchMorph's own staff —
 * people who administer MULTIPLE client organizations, not a member of
 * any one of them. I have no visibility into whether that mechanism
 * exists yet in this codebase. Do NOT deploy this file reachable by the
 * public internet, or by any organization_users account, until that's
 * wired up — as written, anyone who can reach this URL can create a new
 * organization with a fresh Owner login. Treat requirePlatformAdminAuth()
 * below as a placeholder that must be replaced with your actual
 * platform-level admin authentication before this goes anywhere near
 * production.
 */
require_once '../auth.php'; // expected to define requirePlatformAdminAuth() and getDBConnection()
$platformAdmin = function_exists('requirePlatformAdminAuth') ? requirePlatformAdminAuth() : null;
if ($platformAdmin === null) {
    die("requirePlatformAdminAuth() is not wired up yet — see the comment block at the top of this file. Refusing to run unauthenticated.");
}

require_once '../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();

function safeHtmlP($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function generateTempPasswordP(): string {
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < 12; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw;
}

/**
 * Creates the organization row and its first Owner login atomically —
 * if either half fails, neither is left behind. Returns the new org id,
 * owner user id, and the owner's one-time temp password.
 */
function createOrganizationWithOwner(PDO $db, array $orgData, array $ownerData): array {
    $name = trim($orgData['name'] ?? '');
    $countryCode = strtoupper(trim($orgData['country_code'] ?? ''));
    $currency = strtoupper(trim($orgData['default_currency'] ?? ''));
    $taxId = trim($orgData['tax_id'] ?? '') ?: null;
    $regNumber = trim($orgData['registration_number'] ?? '') ?: null;

    $ownerName = trim($ownerData['full_name'] ?? '');
    $ownerEmail = trim(strtolower($ownerData['email'] ?? ''));

    if ($name === '') throw new RuntimeException("Organization name is required.");
    if (strlen($countryCode) !== 2) throw new RuntimeException("Country code must be a 2-letter ISO code (e.g. AO, GH, NG, ZA).");
    if (strlen($currency) !== 3) throw new RuntimeException("Default currency must be a 3-letter ISO code (e.g. AOA, GHS, NGN, ZAR).");
    if ($ownerName === '') throw new RuntimeException("The first Owner's full name is required.");
    if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("A valid email is required for the first Owner.");

    $tempPassword = generateTempPasswordP();
    $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("
            INSERT INTO organizations (
                name, tax_id, registration_number, country_code, default_currency,
                status, created_at, updated_at
            ) VALUES (
                :name, :tax_id, :reg_number, :country, :currency,
                'active', NOW(), NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':name' => $name, ':tax_id' => $taxId, ':reg_number' => $regNumber,
            ':country' => $countryCode, ':currency' => $currency,
        ]);
        $orgId = (int)$stmt->fetchColumn();

        // No department_id — this first Owner is deliberately org-wide
        // (unscoped). Per the department-scoping rule elsewhere in this
        // codebase, that's what a NULL department_id on an owner means:
        // full authority across every department this org will ever create.
        $stmt = $db->prepare("
            INSERT INTO organization_users (
                organization_id, department_id, full_name, email, password_hash,
                role, is_active, must_change_password, created_by, created_at, updated_at
            ) VALUES (
                :org_id, NULL, :name, :email, :hash,
                'owner', true, true, NULL, NOW(), NOW()
            ) RETURNING user_id
        ");
        $stmt->execute([
            ':org_id' => $orgId, ':name' => $ownerName, ':email' => $ownerEmail, ':hash' => $hash,
        ]);
        $ownerUserId = (int)$stmt->fetchColumn();

        $db->commit();
        return ['organization_id' => $orgId, 'owner_user_id' => $ownerUserId, 'temp_password' => $tempPassword];
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

$error = '';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    try {
        $result = createOrganizationWithOwner($db, [
            'name' => $_POST['org_name'] ?? '',
            'country_code' => $_POST['country_code'] ?? '',
            'default_currency' => $_POST['default_currency'] ?? '',
            'tax_id' => $_POST['tax_id'] ?? '',
            'registration_number' => $_POST['registration_number'] ?? '',
        ], [
            'full_name' => $_POST['owner_name'] ?? '',
            'email' => $_POST['owner_email'] ?? '',
        ]);
    } catch (\RuntimeException $e) {
        $error = $e->getMessage();
    } catch (\Throwable $e) {
        error_log("[platform-admin/organizations/create] " . $e->getMessage());
        $error = "Something went wrong creating the organization. Please try again.";
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Platform Admin · New Organization</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-500: #4A5A6E; --ink-300: #8A96A3;
            --line: #D3DAD6; --brass: #8A6D3B; --brass-tint: #F4EFE3; --seal-red: #7A2118;
            --ledger-green: #24513A; --green-tint: #E5EEE7; --danger: #b3261e; --danger-bg: #fbeceb;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height:100vh; font-size:14px; line-height:1.5; }
        .masthead { background: var(--seal-red); color:#fff; padding:14px 32px; border-bottom:3px solid var(--ink-900); }
        .masthead h1 { font-family:var(--f-cond); font-size:16px; font-weight:700; letter-spacing:.04em; }
        .masthead .sub { font-size:11px; color:rgba(255,255,255,0.75); margin-top:2px; }
        .stage { max-width:640px; margin:0 auto; padding:32px 20px; }
        .card { background:var(--panel); border:1px solid var(--line); padding:24px; margin-bottom:20px; }
        .card-title { font-size:16px; font-weight:700; font-family:var(--f-cond); margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid var(--line); }
        .form-group { margin-bottom:14px; }
        .form-group label { display:block; margin-bottom:6px; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-family:var(--f-cond); color:var(--ink-500); }
        .form-group input { width:100%; padding:9px 12px; border:1.5px solid var(--line); font-size:13.5px; background:var(--paper); }
        .form-group input:focus { outline:none; border-color:var(--brass); background:var(--panel); }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .btn { padding:10px 26px; border:none; font-weight:600; font-size:12px; cursor:pointer; font-family:var(--f-cond); text-transform:uppercase; letter-spacing:.04em; background:var(--ink-900); color:#fff; }
        .btn:hover { background:var(--brass); color:var(--ink-900); }
        .error-msg { background:var(--danger-bg); color:var(--danger); padding:14px 18px; margin-bottom:16px; border-left:3px solid var(--danger); }
        .creds-box { background:var(--ink-900); color:#fff; padding:20px 24px; border-left:4px solid var(--brass); }
        .creds-box .warn { color:#fbbf24; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; font-family:var(--f-cond); margin-bottom:10px; }
        .creds-row { display:flex; gap:16px; margin-bottom:6px; font-size:14px; }
        .creds-row .k { color:var(--ink-300); font-family:var(--f-cond); font-size:11px; text-transform:uppercase; min-width:110px; }
        .creds-row .v { font-family:var(--f-mono); font-weight:700; }
        .next-steps { background:var(--green-tint); color:var(--ledger-green); padding:14px 18px; margin-top:16px; font-size:13px; }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>🔒 PLATFORM ADMIN · New Organization</h1>
        <div class="sub">Not part of the enterprise dashboard — this creates a client organization from scratch, before it has any staff of its own.</div>
    </div>

    <div class="stage">
        <?php if ($error): ?>
        <div class="error-msg">⚠️ <?php echo safeHtmlP($error); ?></div>
        <?php endif; ?>

        <?php if ($result): ?>
        <div class="creds-box">
            <div class="warn">⚠ One-time display — copy this now, it will not be shown again</div>
            <div class="creds-row"><span class="k">Organization ID</span><span class="v"><?php echo (int)$result['organization_id']; ?></span></div>
            <div class="creds-row"><span class="k">Owner login</span><span class="v"><?php echo safeHtmlP($_POST['owner_email']); ?></span></div>
            <div class="creds-row"><span class="k">Temp password</span><span class="v"><?php echo safeHtmlP($result['temp_password']); ?></span></div>
        </div>
        <div class="next-steps">
            ✅ Organization created with its first Owner account (unscoped — full authority across every department it creates). 
            Relay these credentials to them through a secure channel. Once they log in and change their password, they can build 
            out their own department tree and add staff via <code>enterprise/settings/users.php</code> — you shouldn't need to 
            touch this organization from here again.
        </div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo safeHtmlP($csrfToken); ?>">
            <div class="card">
                <div class="card-title">Organization</div>
                <div class="form-group">
                    <label>Organization Name</label>
                    <input type="text" name="org_name" required placeholder="e.g. Fundo de Apoio Social (FAS) — Programa Kwenda">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Country Code (ISO 2)</label>
                        <input type="text" name="country_code" required maxlength="2" placeholder="AO">
                    </div>
                    <div class="form-group">
                        <label>Default Currency (ISO 3)</label>
                        <input type="text" name="default_currency" required maxlength="3" placeholder="AOA">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tax ID (optional)</label>
                        <input type="text" name="tax_id">
                    </div>
                    <div class="form-group">
                        <label>Registration Number (optional)</label>
                        <input type="text" name="registration_number">
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-title">First Owner Account</div>
                <p style="font-size:12.5px; color:var(--ink-500); margin-bottom:14px;">
                    Created unscoped (organization-wide) by default — the natural starting point. This person can later create 
                    departments, add staff, and optionally create additional scoped Owner accounts for devolved provinces/departments.
                </p>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="owner_name" required>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="owner_email" required>
                </div>
            </div>
            <button type="submit" class="btn">Create Organization</button>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>
