<?php
// enterprise/imports/add_source.php - Manage source accounts (maker-checker controlled)
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$role = $user['role'] ?? 'viewer';

// ============================================================
// ROLE GATE - Only Finance Officers may propose sources.
// Only Owner / IT Manager may confirm, reject, or deactivate.
// Nobody else may even view this page.
// ============================================================
$canPropose = in_array($role, ['finance_officer', 'owner']);
$canConfirm = in_array($role, ['owner', 'it_manager_enterprise']);
$canManageSourceAccounts = $canPropose || $canConfirm;

if (!$canManageSourceAccounts) {
    http_response_code(403);
    die("Access denied. Source account management is restricted to Finance Officers (propose) and Owner / IT Manager (confirm).");
}

$error = '';
$success = '';
$sourceId = null;

// ============================================================
// AUDIT LOG HELPER
// ============================================================
function logSourceAudit($db, $orgId, $userId, $action, $entityId, $values) {
    try {
        $stmt = $db->prepare("
            INSERT INTO organization_audit_logs (
                organization_id, user_id, action, entity_type,
                entity_id, new_values, ip_address, user_agent,
                created_at
            ) VALUES (
                :org_id, :user_id, :action, 'source_account',
                :entity_id, :new_values, :ip, :ua, NOW()
            )
        ");
        $stmt->execute([
            ':org_id' => $orgId,
            ':user_id' => $userId,
            ':action' => $action,
            ':entity_id' => $entityId,
            ':new_values' => json_encode($values),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("[add_source] Audit log failed: " . $e->getMessage());
    }
}

// ============================================================
// TOKEN ENCRYPTION HELPERS
// Requires VOUCHMORPH_TOKEN_ENC_KEY (32-byte key) set in environment.
// This is a stopgap so tokens are never stored in plaintext; proper
// key management (rotation, vault/HSM storage) should replace this
// before production use with real banking credentials. Also worth
// noting: AES-256-CBC here has no integrity check (no HMAC/auth tag) —
// fine as a stopgap against casual DB inspection, not a substitute for
// authenticated encryption if this ever handles real production tokens.
// ============================================================
function encryptSecret(string $plain): ?string {
    if ($plain === '') return null;
    $key = getenv('VOUCHMORPH_TOKEN_ENC_KEY');
    if (!$key) {
        error_log("[add_source] WARNING: VOUCHMORPH_TOKEN_ENC_KEY not set - refusing to store secret in plaintext");
        return null;
    }
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, 0, $iv);
    if ($cipher === false) return null;
    return base64_encode($iv . $cipher);
}

// ============================================================
// Fetches the full source list with proposer/confirmer names, joined
// against `users` — the real global identity table (confirmed against
// login.php, which populates the session the same way: proposed_by/
// confirmed_by store users.user_id, not organization_users.id).
// ============================================================
function loadSourceAccounts(PDO $db, int $orgId): array {
    $stmt = $db->prepare("
        SELECT s.*, u1.full_name as proposed_by_name, u2.full_name as confirmed_by_name
        FROM source_accounts s
        LEFT JOIN users u1 ON s.proposed_by = u1.user_id
        LEFT JOIN users u2 ON s.confirmed_by = u2.user_id
        WHERE s.organization_id = :org_id AND s.deleted_at IS NULL
        ORDER BY
            CASE WHEN s.status = 'pending_confirmation' THEN 1
                 WHEN s.status = 'active' THEN 2
                 ELSE 3 END,
            s.institution, s.source_identifier
    ");
    $stmt->execute([':org_id' => $orgId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sources = loadSourceAccounts($db, $orgId);

// Get participants from config
// NOTE: this reads Botswana's participants.yaml specifically (hardcoded
// path) and falls back to ZURUBANK/SACCUSSALIS/CAZACOM/VOUCHMORPH if
// that file is missing or the regex parse finds nothing — the same
// placeholder institution names seen in add_destinations.php. Real
// participant configuration per country is exactly what
// tools/market_readiness_check.php's participant check exists to catch
// before go-live; this fallback list should never be what a live
// disbursement actually uses.
$participants = [];
try {
    $configPath = __DIR__ . '/../../../../src/Core/Config/Countries/Botswana/participants.yaml';
    if (file_exists($configPath)) {
        $content = file_get_contents($configPath);
        preg_match_all('/^  ([A-Z_]+):$/m', $content, $matches);
        $participants = $matches[1] ?? [];
    }
} catch (Exception $e) {
    error_log("[add_source] Error loading participants: " . $e->getMessage());
}
if (empty($participants)) {
    $participants = ['ZURUBANK', 'SACCUSSALIS', 'CAZACOM', 'VOUCHMORPH'];
}

// ============================================================
// HANDLE FORM SUBMISSION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    // ------------------------------------------------------------
    // PROPOSE a new source account (Finance Officer / Owner only)
    // Goes in as pending_confirmation, NOT active.
    // ------------------------------------------------------------
    if ($action === 'add_source') {
        if (!$canPropose) {
            $error = "You do not have permission to propose a source account.";
        } else {
            $institution = trim($_POST['institution'] ?? '');
            $assetType = trim($_POST['asset_type'] ?? 'ACCOUNT');
            $sourceIdentifier = trim($_POST['source_identifier'] ?? '');
            $identifierType = trim($_POST['identifier_type'] ?? 'account_number');
            $accountName = trim($_POST['account_name'] ?? '');
            $currency = trim($_POST['currency'] ?? 'BWP');
            $balance = floatval($_POST['balance'] ?? 0);
            $isHooked = isset($_POST['is_hooked']) ? 1 : 0;
            $accessTokenRaw = trim($_POST['access_token'] ?? '');
            $refreshTokenRaw = trim($_POST['refresh_token'] ?? '');
            $tokenExpiry = !empty($_POST['token_expiry']) ? $_POST['token_expiry'] : null;

            if (empty($institution)) {
                $error = 'Institution is required.';
            } elseif (empty($sourceIdentifier)) {
                $error = 'Source identifier (account number/phone) is required.';
            } elseif ($balance < 0) {
                $error = 'Balance cannot be negative.';
            } elseif ($isHooked && $accessTokenRaw !== '' && !getenv('VOUCHMORPH_TOKEN_ENC_KEY')) {
                $error = 'Cannot store OAuth tokens: encryption key is not configured on this server. Contact IT.';
            } else {
                try {
                    $stmt = $db->prepare("
                        SELECT id FROM source_accounts
                        WHERE organization_id = :org_id
                        AND institution = :institution
                        AND source_identifier = :identifier
                        AND deleted_at IS NULL
                    ");
                    $stmt->execute([
                        ':org_id' => $orgId,
                        ':institution' => $institution,
                        ':identifier' => $sourceIdentifier
                    ]);

                    if ($stmt->fetch()) {
                        $error = 'This source already exists for your organization.';
                    } else {
                        $accessToken = encryptSecret($accessTokenRaw);
                        $refreshToken = encryptSecret($refreshTokenRaw);

                        // ============================================================
                        // FIX: source_accounts.source_reference is NOT NULL, but this
                        // INSERT never populated it — every submission failed with a
                        // raw constraint violation. Generating one here the same way
                        // SwapService generates user_source_accounts.source_reference
                        // (a prefixed, random, effectively-unique string), since
                        // nothing about this value needs to be predictable or
                        // sequential, only unique.
                        // ============================================================
                        $sourceReference = 'ORGSRC_' . $orgId . '_' . bin2hex(random_bytes(6));

                        $stmt = $db->prepare("
                            INSERT INTO source_accounts (
                                organization_id, institution, asset_type,
                                source_identifier, source_identifier_type,
                                account_name, currency, balance,
                                is_hooked, access_token, refresh_token,
                                token_expires_at, is_active, status,
                                source_reference,
                                proposed_by, proposed_at, created_by,
                                created_at, updated_at
                            ) VALUES (
                                :org_id, :institution, :asset_type,
                                :identifier, :identifier_type,
                                :account_name, :currency, :balance,
                                :is_hooked, :access_token, :refresh_token,
                                :token_expiry, false, 'pending_confirmation',
                                :source_reference,
                                :user_id, NOW(), :user_id,
                                NOW(), NOW()
                            ) RETURNING id
                        ");
                        $stmt->execute([
                            ':org_id' => $orgId,
                            ':institution' => $institution,
                            ':asset_type' => $assetType,
                            ':identifier' => $sourceIdentifier,
                            ':identifier_type' => $identifierType,
                            ':account_name' => $accountName,
                            ':currency' => $currency,
                            ':balance' => $balance,
                            ':is_hooked' => $isHooked,
                            ':access_token' => $accessToken,
                            ':refresh_token' => $refreshToken,
                            ':token_expiry' => $tokenExpiry,
                            ':source_reference' => $sourceReference,
                            ':user_id' => $userId
                        ]);

                        // ============================================================
                        // FIX: lastInsertId() is not reliable for a RETURNING clause
                        // under PDO's pgsql driver — it needs an explicit sequence
                        // name to be trustworthy there, which wasn't provided. The
                        // value actually returned by the query is read straight off
                        // the statement instead, which is always correct regardless
                        // of driver-specific lastInsertId() behavior.
                        // ============================================================
                        $sourceId = (int)$stmt->fetchColumn();
                        $success = "Source account proposed. It will not be available for disbursements until an Owner or IT Manager confirms it.";

                        logSourceAudit($db, $orgId, $userId, 'SOURCE_PROPOSED', $sourceId, [
                            'institution' => $institution,
                            'asset_type' => $assetType,
                            'source_identifier' => $sourceIdentifier,
                            'account_name' => $accountName,
                            'currency' => $currency,
                            'balance' => $balance,
                            'is_hooked' => $isHooked
                        ]);
                    }
                } catch (PDOException $e) {
                    error_log("[add_source] Error: " . $e->getMessage());
                    $error = "Database error while proposing source account.";
                }
            }
        }

    // ------------------------------------------------------------
    // CONFIRM a pending source account (Owner / IT Manager only,
    // and must NOT be the same person who proposed it)
    // ------------------------------------------------------------
    } elseif ($action === 'confirm_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);

        if (!$canConfirm) {
            $error = "You do not have permission to confirm source accounts.";
        } elseif (!$sourceId) {
            $error = "No source account specified.";
        } else {
            $stmt = $db->prepare("
                SELECT * FROM source_accounts
                WHERE id = :id AND organization_id = :org_id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$source) {
                $error = "Source account not found.";
            } elseif ($source['status'] !== 'pending_confirmation') {
                $error = "This source account is not awaiting confirmation.";
            } elseif ($source['proposed_by'] == $userId) {
                $error = "🚫 You proposed this source account. A different Owner or IT Manager must confirm it.";
                error_log("[SECURITY] User $userId attempted to self-confirm source account $sourceId");
            } else {
                $stmt = $db->prepare("
                    UPDATE source_accounts
                    SET is_active = true,
                        status = 'active',
                        confirmed_by = :user_id,
                        confirmed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id AND organization_id = :org_id
                ");
                $stmt->execute([':user_id' => $userId, ':id' => $sourceId, ':org_id' => $orgId]);
                $success = "Source account confirmed and is now active for disbursements.";
                logSourceAudit($db, $orgId, $userId, 'SOURCE_CONFIRMED', $sourceId, [
                    'proposed_by' => $source['proposed_by']
                ]);
            }
        }

    // ------------------------------------------------------------
    // REJECT a pending source account (Owner / IT Manager only)
    // ------------------------------------------------------------
    } elseif ($action === 'reject_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $reason = trim($_POST['rejection_reason'] ?? '');

        if (!$canConfirm) {
            $error = "You do not have permission to reject source accounts.";
        } elseif (!$sourceId) {
            $error = "No source account specified.";
        } elseif ($reason === '') {
            $error = "A rejection reason is required.";
        } else {
            $stmt = $db->prepare("
                UPDATE source_accounts
                SET status = 'rejected',
                    rejection_reason = :reason,
                    confirmed_by = :user_id,
                    confirmed_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id AND organization_id = :org_id AND status = 'pending_confirmation'
            ");
            $stmt->execute([':reason' => $reason, ':user_id' => $userId, ':id' => $sourceId, ':org_id' => $orgId]);
            $success = "Source account rejected.";
            logSourceAudit($db, $orgId, $userId, 'SOURCE_REJECTED', $sourceId, ['reason' => $reason]);
        }

    // ------------------------------------------------------------
    // DEACTIVATE an active source account (Owner / IT Manager only)
    // ------------------------------------------------------------
    } elseif ($action === 'delete_source') {
        $sourceId = (int)($_POST['source_id'] ?? 0);

        if (!$canConfirm) {
            $error = "You do not have permission to deactivate source accounts.";
        } elseif ($sourceId) {
            $stmt = $db->prepare("
                UPDATE source_accounts
                SET is_active = false, status = 'deactivated', deleted_at = NOW(), updated_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
            $success = "Source account deactivated.";
            logSourceAudit($db, $orgId, $userId, 'SOURCE_DEACTIVATED', $sourceId, []);
        }

    // ------------------------------------------------------------
    // REFRESH TOKEN (Owner / IT Manager only)
    // ------------------------------------------------------------
    } elseif ($action === 'refresh_token') {
        $sourceId = (int)($_POST['source_id'] ?? 0);

        if (!$canConfirm) {
            $error = "You do not have permission to refresh tokens.";
        } elseif ($sourceId) {
            $stmt = $db->prepare("
                SELECT * FROM source_accounts
                WHERE id = :id AND organization_id = :org_id AND deleted_at IS NULL
            ");
            $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
            $source = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($source && $source['is_hooked']) {
                // ============================================================
                // NOTE: this is still a stub — it extends token_expires_at by
                // an hour without calling the institution's real OAuth
                // refresh endpoint. Flagging prominently rather than quietly:
                // this means neither the token NOR the account's `balance`
                // column reflect anything live from the institution today.
                // That matters beyond just this feature — it directly bears
                // on the "vote optional, limited by source account balance"
                // mode built earlier this session. That mode is only a real
                // safety check if something in this system verifies the
                // actual live balance at execute time (SwapService's adapter
                // layer, not this `balance` column, which is user-typed
                // reference data). If SwapService's execute-time check also
                // just reads this same stale column instead of calling the
                // institution live, "vote optional" currently has no real
                // check behind it at all — worth confirming before relying
                // on that mode for anything beyond practice.
                // ============================================================
                $stmt = $db->prepare("
                    UPDATE source_accounts
                    SET token_expires_at = NOW() + INTERVAL '1 hour',
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $sourceId]);
                $success = "Token expiry extended (simulated — real OAuth refresh against the institution is not implemented yet).";
                logSourceAudit($db, $orgId, $userId, 'SOURCE_TOKEN_REFRESHED', $sourceId, []);
            } else {
                $error = "Source is not hooked or not found.";
            }
        }
    }

    // Re-fetch list after any action so the page reflects the new state
    $sources = loadSourceAccounts($db, $orgId);
}

$csrfToken = generateCsrfToken();
$roleDisplay = strtoupper($role);

function sourceStatusBadge($status) {
    return match($status) {
        'active' => '<span class="status status-approved">Active</span>',
        'pending_confirmation' => '<span class="status status-pending">⏳ Pending Confirmation</span>',
        'rejected' => '<span class="status status-rejected">Rejected</span>',
        'deactivated' => '<span class="status status-draft">Deactivated</span>',
        default => '<span class="status status-draft">' . safeHtml($status) . '</span>'
    };
}

// ============================================================
// SHARED SHELL SETUP — same contract as index.php/departments/index.php/
// source_input.php, so this page's nav is generated by the exact same
// code, not a hand-copied lookalike. Nothing below this point changes
// any query, permission check, or status-transition rule above it.
// ============================================================
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support', 'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor', 'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor', 'viewer' => 'Viewer',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization'; // RAW — do not htmlspecialchars() this; shell-head.php escapes it itself
$userRole = $role;
$basePath = '../';
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isDepartmentHead = ($userRole === 'department_head');
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;
$setupReady = true; // this page is unreachable pre-setup (owner would still be on the wizard)

// Same live badge numbers the dashboard/Departments/Create-Batch pages
// show, so a count on "Disbursements" or "Source Accounts" never
// disagrees depending on which page you're on.
$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($canApprove) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending','pending_approval','PENDING','PENDING_APPROVAL')");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingApprovals = (int)$stmt->fetchColumn();
    }
    if ($canSeeSourceAccountsArea) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingSourceConfirmations = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("[add_source] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => '../batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => '../index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => '../departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => 'add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null, 'active' => true],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => '../settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => '../reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => '../settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => '../logout.php', 'show' => true],
];
$topbarSearchShow = $canTrace;
$topbarSearchAction = '../index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Source Accounts · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <style>
        .hooked-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-family: var(--f-cond); background: var(--blue-tint); color: #1e40af; }
        .meta-line { font-size: 11px; color: var(--ink-300); margin-top: 4px; }
        .rejection-inline { display: flex; gap: 6px; align-items: center; margin-top: 6px; }
        .rejection-inline input { padding: 6px 10px; border: 1px solid var(--line); border-radius: 6px; font-size: 12px; flex: 1; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Source Accounts</h1>
                    <div class="sub">Propose, confirm, reject, and deactivate the bank and institution accounts disbursement batches draw funds from.</div>
                </div>
                <div class="page-header-actions">
                    <a href="source_input.php" class="btn btn-outline">← Back to Source Selection</a>
                </div>
            </div>

            <div class="info-panel">
                <?php if ($canPropose && !$canConfirm): ?>
                <div class="desc">💰 <strong>Finance Officer access:</strong> You can propose new source accounts. An Owner or IT Manager must confirm before they become active.</div>
                <?php elseif ($canConfirm && !$canPropose): ?>
                <div class="desc">🔑 <strong><?php echo $role === 'owner' ? 'Owner' : 'IT Manager'; ?> access:</strong> You can confirm, reject, or deactivate source accounts proposed by Finance.</div>
                <?php else: ?>
                <div class="desc">🔑 <strong>Owner access:</strong> You can propose, confirm, reject, or deactivate source accounts. For proper separation of duties, consider having a Finance Officer propose and a different Owner/IT Manager confirm.</div>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
            <div class="info-panel" style="border-left-color:var(--seal-red); background:var(--danger-bg);">
                <div class="desc">⚠️ <?php echo safeHtml($error); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($success): ?>
            <div class="info-panel" style="border-left-color:var(--ledger-green); background:var(--green-tint);">
                <div class="desc">✅ <?php echo safeHtml($success); ?></div>
            </div>
            <?php endif; ?>

            <!-- Propose Source Form - Finance Officer / Owner only -->
            <?php if ($canPropose): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">💰 Propose Source Account</span>
                    <span style="font-size:11px; color:var(--ink-300);">Requires confirmation before use</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="add_source">

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Institution *</label>
                            <select name="institution" required>
                                <option value="">Select institution</option>
                                <?php foreach ($participants as $p): ?>
                                <option value="<?php echo safeHtml($p); ?>"><?php echo safeHtml($p); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">The financial institution hosting the source account</div>
                        </div>

                        <div class="form-group">
                            <label>Asset Type</label>
                            <select name="asset_type">
                                <option value="ACCOUNT">Bank Account</option>
                                <option value="WALLET">Wallet</option>
                                <option value="BANK-WALLET">Bank Wallet</option>
                                <option value="CARD">Payment Card</option>
                                <option value="VOUCHER">Voucher</option>
                            </select>
                            <div class="hint">Type of asset to use as source</div>
                        </div>

                        <div class="form-group">
                            <label>Source Identifier *</label>
                            <input type="text" name="source_identifier" required
                                   placeholder="e.g., 10000001, +26770000000">
                            <div class="hint">Account number, phone number, or wallet ID</div>
                        </div>

                        <div class="form-group">
                            <label>Identifier Type</label>
                            <select name="identifier_type">
                                <option value="account_number">Account Number</option>
                                <option value="phone">Phone Number</option>
                                <option value="email">Email</option>
                                <option value="national_id">National ID</option>
                                <option value="wallet_id">Wallet ID</option>
                            </select>
                            <div class="hint">Type of identifier used</div>
                        </div>

                        <div class="form-group">
                            <label>Account Name</label>
                            <input type="text" name="account_name" placeholder="e.g., Saccussalis Main Account">
                            <div class="hint">Display name for the source account</div>
                        </div>

                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <option value="BWP">BWP - Botswana Pula</option>
                                <option value="ZAR">ZAR - South African Rand</option>
                                <option value="USD">USD - US Dollar</option>
                                <option value="EUR">EUR - Euro</option>
                            </select>
                            <div class="hint">Currency of the source account</div>
                        </div>

                        <div class="form-group">
                            <label>Balance</label>
                            <input type="number" name="balance" step="0.01" min="0"
                                   placeholder="0.00" value="0">
                            <div class="hint">Reference value only — not a live balance from the institution. See note on Refresh Token below.</div>
                        </div>

                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 8px; text-transform: none; font-weight: 400;">
                                <input type="checkbox" name="is_hooked" value="1">
                                🔗 Hooked Source (OAuth/Token based)
                            </label>
                            <div class="hint">Check if this source uses OAuth or token-based authentication</div>
                        </div>
                    </div>

                    <div class="form-grid" id="tokenFields" style="display: none;">
                        <div class="form-group">
                            <label>Access Token</label>
                            <input type="text" name="access_token" placeholder="eyJhbGciOiJIUzI1NiIs...">
                            <div class="hint">Stored encrypted at rest (if server key is configured)</div>
                        </div>
                        <div class="form-group">
                            <label>Refresh Token</label>
                            <input type="text" name="refresh_token" placeholder="Refresh token (if hooked)">
                            <div class="hint">Stored encrypted at rest (if server key is configured)</div>
                        </div>
                        <div class="form-group">
                            <label>Token Expiry</label>
                            <input type="datetime-local" name="token_expiry">
                            <div class="hint">When the token expires</div>
                        </div>
                    </div>

                    <div class="card-actions">
                        <button type="submit" class="btn btn-primary">➕ Propose Source Account</button>
                        <a href="source_input.php" class="btn btn-outline">← Back to Selection</a>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="info-panel" style="border-left-color:var(--amber); background:var(--amber-bg);">
                <div class="label" style="color:var(--amber);">🔒 View-Only for Proposals</div>
                <div class="desc">Only Finance Officers (or the Owner) can propose new source accounts. You can review and confirm below.</div>
            </div>
            <?php endif; ?>

            <!-- Source List -->
            <div class="card source-list">
                <div class="card-header">
                    <span class="card-title">📋 Source Accounts</span>
                </div>
                <?php if (empty($sources)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <p>No source accounts configured yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Institution</th>
                                <th>Identifier</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sources as $source): ?>
                            <tr>
                                <td>
                                    <strong><?php echo safeHtml($source['institution']); ?></strong>
                                    <?php if ($source['is_hooked']): ?>
                                    <span class="hooked-badge">🔗 Hooked</span>
                                    <?php endif; ?>
                                    <div class="meta-line">
                                        Proposed by <?php echo safeHtml($source['proposed_by_name'] ?? 'Unknown'); ?>
                                        <?php if ($source['confirmed_by_name']): ?>
                                        · Confirmed by <?php echo safeHtml($source['confirmed_by_name']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo safeHtml($source['source_identifier']); ?></td>
                                <td>
                                    <?php echo number_format($source['balance'] ?? 0, 2); ?>
                                    <?php echo safeHtml($source['currency'] ?? 'BWP'); ?>
                                </td>
                                <td>
                                    <?php echo sourceStatusBadge($source['status'] ?? ($source['is_active'] ? 'active' : 'deactivated')); ?>
                                    <?php if ($source['status'] === 'rejected' && !empty($source['rejection_reason'])): ?>
                                    <div class="meta-line" style="color:var(--seal-red);">
                                        Reason: <?php echo safeHtml($source['rejection_reason']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <?php if ($source['status'] === 'pending_confirmation' && $canConfirm): ?>
                                            <?php if ($source['proposed_by'] == $userId): ?>
                                            <span style="font-size:11px; color:var(--ink-300);">Awaiting a different confirmer</span>
                                            <?php else: ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                                                <input type="hidden" name="action" value="confirm_source">
                                                <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Confirm this source account for use in disbursements?')">✅ Confirm</button>
                                            </form>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('reject-<?php echo $source['id']; ?>').style.display='flex'">❌ Reject</button>
                                            <?php endif; ?>
                                        <?php elseif ($source['status'] === 'active' && $canConfirm): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                                                <input type="hidden" name="action" value="delete_source">
                                                <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this source account?')">Deactivate</button>
                                            </form>
                                            <?php if ($source['is_hooked']): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                                                <input type="hidden" name="action" value="refresh_token">
                                                <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                                <button type="submit" class="btn btn-outline btn-sm">🔄 Refresh Token</button>
                                            </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($source['status'] === 'pending_confirmation' && $canConfirm && $source['proposed_by'] != $userId): ?>
                                    <form method="POST" id="reject-<?php echo $source['id']; ?>" class="rejection-inline" style="display:none;">
                                        <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                                        <input type="hidden" name="action" value="reject_source">
                                        <input type="hidden" name="source_id" value="<?php echo $source['id']; ?>">
                                        <input type="text" name="rejection_reason" placeholder="Reason for rejection" required>
                                        <button type="submit" class="btn btn-danger btn-sm">Submit</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

    <script>
        document.querySelector('input[name="is_hooked"]')?.addEventListener('change', function() {
            document.getElementById('tokenFields').style.display = this.checked ? 'grid' : 'none';
        });

        document.querySelector('select[name="institution"]')?.addEventListener('change', function() {
            if (this.value === 'SACCUSSALIS') {
                const identifier = document.querySelector('input[name="source_identifier"]');
                const accountName = document.querySelector('input[name="account_name"]');
                if (identifier && !identifier.value) identifier.value = '10000001';
                if (accountName && !accountName.value) accountName.value = 'Saccussalis Main Account';
            }
        });
    </script>
<?php
$dbHealthy = DBConnection::isConnected();
$footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>
