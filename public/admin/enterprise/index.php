<?php
/**
 * enterprise/index.php - VouchMorph Enterprise Client Dashboard
 *
 * This is the main dashboard for organizations using VouchMorph.
 * Different roles see different views based on their permissions.
 */

// ============================================================
// FIX: Session settings MUST be set BEFORE any output (just in case)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../src/Domain/Services/SetupChecklistService.php';
use Domain\Services\DepartmentService;
use Domain\Services\SetupChecklistService;

$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';
$userId = $user['user_id'] ?? $user['id'] ?? null;
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$departmentId = $user['department_id'] ?? null;

$deptService = new DepartmentService($pdo);

// ============================================================
// GUIDED SETUP — before anything else loads. A fresh organization has
// no staff, no departments, and no confirmed source account, so every
// other part of this dashboard (batches, beneficiaries, rations) is
// either empty or actively misleading to show. The Owner — the only
// role that can actually complete every one of these steps — gets the
// full guided wizard instead of the normal dashboard until setup is
// done. Every other role just sees the normal dashboard with "New
// Disbursement" disabled and a short explanation, since they can't act
// on any of these steps themselves.
// ============================================================
$setupChecklist = new SetupChecklistService($pdo);
$setupStatus = $setupChecklist->getStatus((int)$orgId);
$setupReady = $setupStatus['ready_for_batches'];

if ($userRole === 'owner' && !$setupReady) {
    renderSetupWizard($orgName, $fullName, $setupStatus);
    exit;
}

function safeHtmlSetup($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function renderSetupWizard(string $orgName, string $fullName, array $setupStatus): void {
    $steps = $setupStatus['steps'];
    $doneCount = count(array_filter($steps, fn($s) => $s['done']));
    $totalCount = count($steps);
    // The first not-done step is the one to push the person toward right now.
    $nextStepKey = null;
    foreach ($steps as $s) {
        if (!$s['done']) { $nextStepKey = $s['key']; break; }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Set Up · <?php echo safeHtmlSetup($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF; --panel: #FFFFFF; --ink-900: #0F2138; --ink-700: #1D3557;
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #D3DAD6; --line-strong: #AEB8B2;
            --brass: #8A6D3B; --brass-tint: #F4EFE3; --ledger-green: #24513A; --green-tint: #E5EEE7;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height: 100vh; font-size: 14px; line-height: 1.5; }
        .header { background: var(--ink-900); color: #fff; border-bottom: 3px solid var(--brass); padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .logo { font-family: var(--f-cond); font-weight: 700; font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; }
        .logo span { color: var(--brass); }
        .header-right { font-size: 12px; color: var(--ink-300); display: flex; align-items: center; gap: 16px; }
        .header-right a { color: var(--brass); text-decoration: none; }
        .wrap { max-width: 760px; margin: 0 auto; padding: 48px 24px; }
        .eyebrow { font-family: var(--f-cond); font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--brass); margin-bottom: 8px; }
        h1 { font-family: var(--f-cond); font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .sub { color: var(--ink-500); font-size: 14.5px; margin-bottom: 28px; max-width: 560px; }
        .progress-track { height: 8px; background: var(--line); margin-bottom: 6px; }
        .progress-fill { height: 100%; background: var(--brass); transition: width 0.3s; }
        .progress-label { font-size: 11.5px; color: var(--ink-500); font-family: var(--f-mono); margin-bottom: 32px; }
        .step {
            background: var(--panel); border: 1.5px solid var(--line); padding: 22px 24px; margin-bottom: 14px;
            display: flex; gap: 18px; align-items: flex-start;
        }
        .step.current { border-color: var(--brass); background: var(--brass-tint); }
        .step.done { border-color: var(--ledger-green); background: var(--green-tint); }
        .step-num {
            width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--f-cond); font-weight: 700; font-size: 15px;
            background: var(--ink-900); color: #fff;
        }
        .step.current .step-num { background: var(--brass); }
        .step.done .step-num { background: var(--ledger-green); }
        .step-body { flex: 1; }
        .step-label { font-family: var(--f-cond); font-size: 16px; font-weight: 700; margin-bottom: 4px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .step-desc { color: var(--ink-500); font-size: 13px; margin-bottom: 12px; }
        .step-count { font-family: var(--f-mono); font-size: 11px; color: var(--ink-300); }
        .btn {
            display: inline-flex; align-items: center; height: 34px; padding: 0 18px;
            font-size: 12px; font-weight: 600; font-family: var(--f-cond); text-transform: uppercase;
            letter-spacing: 0.04em; text-decoration: none; border: 1px solid var(--ink-900);
            background: var(--ink-900); color: #fff; transition: all 0.15s;
        }
        .btn:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); }
        .btn-done { background: var(--ledger-green); border-color: var(--ledger-green); color: #fff; cursor: default; }
        .btn-outline { background: transparent; border: 1px solid var(--line-strong); color: var(--ink-700); }
        .btn-outline:hover { border-color: var(--brass); color: var(--brass); }
        .badge-done { font-size: 10px; font-weight: 700; text-transform: uppercase; background: var(--ledger-green); color: #fff; padding: 2px 10px; font-family: var(--f-cond); }
        .footnote { margin-top: 32px; padding: 16px 20px; border-left: 3px solid var(--brass); background: var(--brass-tint); font-size: 13px; color: var(--ink-700); }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">VOUCHMORPH <span>·</span> <?php echo safeHtmlSetup($orgName); ?></div>
        <div class="header-right">
            <?php echo safeHtmlSetup($fullName); ?> · Owner
            <a href="logout.php">Sign Out</a>
        </div>
    </div>
    <div class="wizard-nav">
        <a href="departments/index.php">🏢 Departments</a>
        <a href="/admin/enterprise/settings/users.php">👤 Manage Team</a>
        <a href="/admin/enterprise/imports/add_source.php">💰 Source Accounts</a>
        <a href="settings.php">⚙️ Settings</a>
    </div>
    <div class="wrap">
        <div class="eyebrow">Getting Started</div>
        <h1>Let's get <?php echo safeHtmlSetup($orgName); ?> ready</h1>
        <p class="sub">A few things need to be in place before disbursements can begin. Work through these in order — each one unlocks the next.</p>

        <div class="progress-track"><div class="progress-fill" style="width:<?php echo $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0; ?>%;"></div></div>
        <div class="progress-label"><?php echo $doneCount; ?> OF <?php echo $totalCount; ?> COMPLETE</div>

        <?php foreach ($steps as $i => $step):
            $stateClass = $step['done'] ? 'done' : ($step['key'] === $nextStepKey ? 'current' : '');
        ?>
        <div class="step <?php echo $stateClass; ?>">
            <div class="step-num"><?php echo $step['done'] ? '✓' : ($i + 1); ?></div>
            <div class="step-body">
                <div class="step-label">
                    <?php echo safeHtmlSetup($step['label']); ?>
                    <?php if ($step['done']): ?><span class="badge-done">Done</span><?php endif; ?>
                </div>
                <div class="step-desc"><?php echo safeHtmlSetup($step['description']); ?></div>
               <?php if ($step['done']): ?>
                    <div class="step-count"><?php echo (int)$step['count']; ?> on record</div>
                    <a href="<?php echo safeHtmlSetup($step['action_href']); ?>" class="btn btn-outline" style="margin-top:8px;">
                        Manage <?php echo safeHtmlSetup($step['label']); ?> →
                    </a>
                <?php else: ?>
                    <a href="<?php echo safeHtmlSetup($step['action_href']); ?>" class="btn"><?php echo safeHtmlSetup($step['action_label']); ?> →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="footnote">
            💡 A department without a budget set isn't incomplete — it's a deliberate choice ("no vote"), meaning it's limited only by the real balance of whatever source account it draws from, checked at the moment funds actually move. Set one if you want a hard local cap; leave it blank if you don't.
        </div>
    </div>
</body>
</html>
    <?php
}


// ============================================================
// DEPARTMENT SCOPE — for a ministry/government structure where oversight
// roles are devolved: an Owner or Approver/Senior Approver assigned to a
// specific department only sees and acts on that department's (and its
// sub-departments') batches. Leaving organization_users.department_id
// NULL for one of these accounts is the deliberate "sees everything"
// configuration (e.g. an HQ-level signatory), not a default — see
// DepartmentService::isDepartmentInScope for the full rule. This does
// NOT apply to creator roles (program_officer/department_head/
// beneficiary_registrar), which are scoped separately below by exact
// department match, and it doesn't apply to it_manager_enterprise, which
// stays an always-org-wide administrative role.
// ============================================================
$scopableOversightRoles = ['owner', 'approver', 'senior_approver'];
$userDeptScopeIds = in_array($userRole, $scopableOversightRoles, true)
    ? $deptService->getDepartmentScopeIds($departmentId)
    : null;

/**
 * Builds a " AND department_id IN (...)" fragment (with its own bound
 * params merged into &$params) for the given scope, or '' if the scope
 * is null (unrestricted). Centralizing this so the badge counts and the
 * batch list below can never disagree with each other.
 */
function departmentScopeSql(?array $scopeIds, array &$params, string $prefix = 'sdep'): string {
    if ($scopeIds === null) {
        return '';
    }
    if (empty($scopeIds)) {
        return ' AND 1=0';
    }
    $placeholders = [];
    foreach (array_values($scopeIds) as $i => $id) {
        $key = ":{$prefix}{$i}";
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    return ' AND department_id IN (' . implode(',', $placeholders) . ')';
}

// ============================================================
// ROLE PERMISSIONS
// ============================================================
$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);

$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head']);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise']);

// ============================================================
// FIX (P0): Disbursement execution is OWNER-ONLY, matching the hard
// enforcement in imports/review_batch.php ($canExecute = role === 'owner',
// "NO EXCEPTIONS"). This dashboard used to tell IT Managers they could
// disburse (nav link, badge counts, quick-action card, info panel) and
// then silently lock them out on the actual execute screen — the single
// worst failure mode to hit live in front of a client. The two files now
// agree: only 'owner' sees, is counted for, or is invited toward
// disbursement anywhere in the product.
// ============================================================
$canDisburse = ($userRole === 'owner');
$isSupervisor = ($userRole === 'owner');

$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise']);
$canViewAll = in_array($userRole, ['owner', 'auditor', 'it_manager_enterprise', 'it_officer_enterprise']);
$isReadOnly = in_array($userRole, ['auditor', 'viewer']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);

// Source account maker-checker: Finance Officers propose, Owner/IT Manager confirm.
$canProposeSource = in_array($userRole, ['finance_officer', 'owner']);
$canConfirmSource = in_array($userRole, ['owner', 'it_manager_enterprise']);
$canManageSourceAccounts = $canProposeSource || $canConfirmSource;

// ============================================================
// Separate from $canManageSourceAccounts (which gates propose/confirm
// ACTIONS): this gates whether the nav link / info panels appear at all.
// Batch-creating roles (program_officer, department_head) must never see
// this area — their job starts and ends at building a batch against
// their department's ration.
// ============================================================
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer']);

$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer']);

// Departments & rations
$canManageDepartments = $isTopRole;
$isDepartmentHead = ($userRole === 'department_head');
$canApproveBorrow = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer']);

// ============================================================
// HELPER: Check if user can edit a batch
// ============================================================
function canEditBatch($batchCreatedBy, $currentUserId, $userRole) {
    if ($userRole === 'owner') return true;
    if (in_array($userRole, ['program_officer', 'department_head'])) {
        return $batchCreatedBy == $currentUserId;
    }
    return false;
}

// ============================================================
// FETCH DASHBOARD DATA
// ============================================================

// Organization details
$orgData = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, tax_id, registration_number, country_code,
               default_currency, status, logo_url, created_at
        FROM organizations
        WHERE id = :org_id
    ");
    $stmt->execute([':org_id' => $orgId]);
    $orgData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Org fetch error: " . $e->getMessage());
}
$orgCurrency = $orgData['default_currency'] ?? 'BWP';

// Dashboard metrics
$metrics = [];
$recentBatches = [];

try {
    $params = [':org_id' => $orgId];

    // Total batches
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM disbursement_batches
        WHERE organization_id = :org_id
    ");
    $stmt->execute($params);
    $metrics['total_batches'] = (int)$stmt->fetchColumn();

    // Batches by status
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) as count
        FROM disbursement_batches
        WHERE organization_id = :org_id
        GROUP BY status
    ");
    $stmt->execute($params);
    $batchStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = strtolower($row['status']);
        $batchStatus[$status] = $row['count'];
    }

    // ============================================================
    // FIX: these were `??` (pick ONE synonym), not a sum. Every status
    // filter elsewhere in this file treats pending/pending_approval and
    // executed/completed as the same thing, so the metric cards must
    // add both variants or they silently undercount and disagree with
    // the action-queue numbers on the same page.
    // ============================================================
    $metrics['pending_batches'] = ($batchStatus['pending'] ?? 0) + ($batchStatus['pending_approval'] ?? 0);
    $metrics['approved_batches'] = $batchStatus['approved'] ?? 0;
    $metrics['executed_batches'] = ($batchStatus['executed'] ?? 0) + ($batchStatus['completed'] ?? 0);
    $metrics['rejected_batches'] = $batchStatus['rejected'] ?? 0;

    // Total disbursed amount
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as total
        FROM disbursement_batches
        WHERE organization_id = :org_id
        AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')
    ");
    $stmt->execute($params);
    $metrics['total_disbursed'] = (float)$stmt->fetchColumn();

    // Total beneficiaries
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM organization_beneficiaries
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_beneficiaries'] = (int)$stmt->fetchColumn();

    // Total users
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM organization_users
        WHERE organization_id = :org_id AND is_active = true
    ");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_users'] = (int)$stmt->fetchColumn();

    // For the (now owner-only) disburser - approved batches ready for disbursement,
    // scoped to their department + sub-departments if this Owner account is scoped.
    if ($canDisburse) {
        $adfParams = [':org_id' => $orgId];
        $adfScopeSql = departmentScopeSql($userDeptScopeIds, $adfParams, 'adf');
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM disbursement_batches
            WHERE organization_id = :org_id
            AND status = 'approved'
            $adfScopeSql
        ");
        $stmt->execute($adfParams);
        $metrics['approved_for_disbursement'] = (int)$stmt->fetchColumn();
    }

    // For approvers - pending approvals, scoped to their department + sub-departments
    // if this approver (or owner viewing this count) is a scoped account.
    $papParams = [':org_id' => $orgId];
    $papScopeSql = departmentScopeSql($userDeptScopeIds, $papParams, 'pap');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM disbursement_batches
        WHERE organization_id = :org_id
        AND status IN ('pending', 'pending_approval', 'PENDING', 'PENDING_APPROVAL')
        $papScopeSql
    ");
    $stmt->execute($papParams);
    $metrics['pending_approvals'] = (int)$stmt->fetchColumn();

    // For Owner/IT Manager - source accounts awaiting confirmation
    if ($canConfirmSource) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM source_accounts
                WHERE organization_id = :org_id
                AND status = 'pending_confirmation'
                AND deleted_at IS NULL
            ");
            $stmt->execute([':org_id' => $orgId]);
            $metrics['pending_source_confirmations'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("[ENTERPRISE DASHBOARD] Source metrics error: " . $e->getMessage());
            $metrics['pending_source_confirmations'] = 0;
        }
    }

    // Recent batches with status-based filtering
    $statusFilter = "";
    $statusParams = [':org_id' => $orgId];

    if ($isTopRole) {
        // it_manager_enterprise is always org-wide; a department-scoped
        // Owner (userDeptScopeIds non-null) is narrowed like an approver.
        if ($userRole === 'it_manager_enterprise' || $userDeptScopeIds === null) {
            $statusFilter = "AND 1=1";
        } else {
            $statusFilter = "AND 1=1" . departmentScopeSql($userDeptScopeIds, $statusParams, 'own');
        }
    } elseif ($isReadOnly) {
        $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
    } elseif ($isApprover) {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')"
            . departmentScopeSql($userDeptScopeIds, $statusParams, 'apr');
    } elseif ($userRole === 'finance_officer') {
        // Finance sees batches for ration/borrow visibility, but read-only,
        // not the unrestricted "everything" it used to fall through to.
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'completed', 'executed', 'PENDING', 'PENDING_APPROVAL', 'APPROVED', 'COMPLETED', 'EXECUTED')";
    } elseif ($isLoader) {
        // Batch staff: their own batches, OR any batch in their own department.
        $statusFilter = "AND (created_by = :user_id OR (department_id = :department_id AND status IN ('pending', 'pending_approval', 'approved', 'draft')))";
        $statusParams[':user_id'] = $userId;
        $statusParams[':department_id'] = $departmentId;
    } else {
        // ============================================================
        // FIX: default-deny. Any role not explicitly matched above used
        // to fall through with $statusFilter = "" — no filter at all,
        // meaning that role saw EVERY batch in the organization. Roles
        // like beneficiary_registrar hit this. Now they see nothing
        // until given an explicit scope, which is the safe direction to
        // fail in.
        // ============================================================
        $statusFilter = "AND 1=0";
    }

    $stmt = $pdo->prepare("
        SELECT
            id, batch_reference, batch_name, source_institution,
            total_amount, total_destinations, status, created_at,
            updated_at, created_by
        FROM disbursement_batches
        WHERE organization_id = :org_id $statusFilter
        ORDER BY
            CASE
                WHEN status IN ('pending', 'pending_approval') THEN 1
                WHEN status = 'approved' THEN 2
                WHEN status = 'draft' THEN 3
                ELSE 4
            END,
            created_at DESC
        LIMIT 15
    ");
    $stmt->execute($statusParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys([
        'total_batches', 'pending_batches', 'approved_batches',
        'executed_batches', 'total_disbursed', 'total_beneficiaries',
        'total_users', 'pending_approvals'
    ], 0);
    $recentBatches = [];
}

// ============================================================
// NEW METRICS — dashboard redesign (see design reference doc). Each
// block is its own try/catch so a failure here never takes down the
// metrics fetched above; all default to a safe "no data" shape.
// ============================================================

// Month-to-date disbursed + prior month, for a real (not fabricated)
// trend comparison on the "Total Disbursed" stat card.
$mtdDisbursed = 0.0;
$disbursedDeltaPct = null; // null = "not enough data", not "0%"
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(total_amount) FILTER (
                WHERE created_at >= date_trunc('month', CURRENT_DATE)
            ), 0) AS mtd,
            COALESCE(SUM(total_amount) FILTER (
                WHERE created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month')
                  AND created_at < date_trunc('month', CURRENT_DATE)
            ), 0) AS last_month
        FROM disbursement_batches
        WHERE organization_id = :org_id
        AND LOWER(status) IN ('completed', 'executed')
    ");
    $stmt->execute([':org_id' => $orgId]);
    $mtdRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['mtd' => 0, 'last_month' => 0];
    $mtdDisbursed = (float)$mtdRow['mtd'];
    $lastMonthDisbursed = (float)$mtdRow['last_month'];
    if ($lastMonthDisbursed > 0) {
        $disbursedDeltaPct = round((($mtdDisbursed - $lastMonthDisbursed) / $lastMonthDisbursed) * 100, 1);
    }
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] MTD metrics error: " . $e->getMessage());
}

// Active batches — anything not yet in a terminal state — plus how many
// of those are specifically mid-execution right now.
$metrics['active_batches'] = 0;
$metrics['executing_batches'] = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) FILTER (WHERE LOWER(status) IN ('draft','pending','pending_approval','approved','executing')) AS active,
            COUNT(*) FILTER (WHERE LOWER(status) = 'executing') AS executing
        FROM disbursement_batches
        WHERE organization_id = :org_id
    ");
    $stmt->execute([':org_id' => $orgId]);
    $activeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['active' => 0, 'executing' => 0];
    $metrics['active_batches'] = (int)$activeRow['active'];
    $metrics['executing_batches'] = (int)$activeRow['executing'];
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Active batch metrics error: " . $e->getMessage());
}

// Average time-to-approval over the last 30 days, for approvers/owners —
// real average of (approved_at - submitted_at) on batches that actually
// have both timestamps set.
$avgClearanceHours = null;
if ($canApprove || $canDisburse) {
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (approved_at - submitted_at)) / 3600.0) AS avg_hours
            FROM disbursement_batches
            WHERE organization_id = :org_id
              AND approved_at IS NOT NULL AND submitted_at IS NOT NULL
              AND approved_at >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([':org_id' => $orgId]);
        $avgHoursRaw = $stmt->fetchColumn();
        $avgClearanceHours = ($avgHoursRaw !== null && $avgHoursRaw !== false) ? round((float)$avgHoursRaw, 1) : null;
    } catch (PDOException $e) {
        error_log("[ENTERPRISE DASHBOARD] Avg clearance metrics error: " . $e->getMessage());
    }
}

// Recent Activity — a real feed from the audit trail, not a mock event
// log. organization_audit_logs is already written to by the departments,
// batch-approval, and rejection flows elsewhere in the app.
$recentActivity = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.full_name AS actor_name
        FROM organization_audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.organization_id = :org_id
        ORDER BY al.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([':org_id' => $orgId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Recent activity error: " . $e->getMessage());
    $recentActivity = [];
}

function getActivityLabel(string $action): string {
    $labels = [
        'APPROVE_BATCH' => 'Batch approved',
        'REJECT_BATCH' => 'Batch rejected',
        'DEPARTMENT_EDITED' => 'Department updated',
        'DEPARTMENT_ACTIVE' => 'Department reactivated',
        'DEPARTMENT_INACTIVE' => 'Department deactivated',
        'BUDGET_OVERRUN_RACE_DETECTED' => 'Budget overrun flagged for review',
    ];
    return $labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
}

// ============================================================
// PAYMENT TRACE
// A single reference (batch reference, beneficiary phone/ID, or any
// free-text fragment) searched across the batch and beneficiary
// tables. Uses to_jsonb(table.*)::text ILIKE so it works without
// hardcoding every column name — safe against schema drift, though
// less indexed/performant than a targeted column search. If this is
// used heavily, consider adding indexed lookup columns later.
// ============================================================
$traceQuery = trim($_GET['trace'] ?? '');
$traceBatches = [];
$traceBeneficiaries = [];
if ($canTrace && $traceQuery !== '') {
    $likeQ = '%' . $traceQuery . '%';
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, batch_name, source_institution,
                   total_amount, total_destinations, status, created_at, updated_at
            FROM disbursement_batches
            WHERE organization_id = :org_id
              AND (batch_reference ILIKE :q OR to_jsonb(disbursement_batches.*)::text ILIKE :q)
            ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([':org_id' => $orgId, ':q' => $likeQ]);
        $traceBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("[ENTERPRISE DASHBOARD] Trace batch error: " . $e->getMessage());
    }
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM organization_beneficiaries
            WHERE organization_id = :org_id
              AND is_active = true
              AND to_jsonb(organization_beneficiaries.*)::text ILIKE :q
            ORDER BY id DESC LIMIT 10
        ");
        $stmt->execute([':org_id' => $orgId, ':q' => $likeQ]);
        $traceBeneficiaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("[ENTERPRISE DASHBOARD] Trace beneficiary error: " . $e->getMessage());
    }
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}

function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft',
        'pending', 'pending_approval' => 'pending',
        'approved' => 'approved',
        'executing' => 'pending',
        'completed', 'executed' => 'completed',
        'rejected' => 'rejected',
        'cancelled' => 'rejected',
        default => 'draft'
    };
}

function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => '📝 Draft',
        'pending', 'pending_approval' => '⏳ Pending',
        'approved' => '✅ Approved',
        'executing' => '⚙️ Executing',
        'completed' => '✔️ Completed',
        'executed' => '🚀 Executed',
        'rejected' => '❌ Rejected',
        'cancelled' => '🚫 Cancelled',
        default => ucfirst($status)
    };
}

function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner',
        'it_manager_enterprise' => 'IT Manager',
        'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support',
        'department_head' => 'Department Head',
        'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer',
        'approver' => 'Approver',
        'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor',
        'beneficiary_registrar' => 'Beneficiary Registrar',
        'auditor' => 'Auditor',
        'viewer' => 'Viewer'
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Consolidated "needs your attention" queue — every role-specific
// pending item in one place, so nothing requires digging through
// nav to discover. Ordered by urgency.
$actionItems = [];
if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0) {
    $actionItems[] = [
        'icon' => '⏳', 'label' => 'Batches awaiting your approval',
        'count' => $metrics['pending_approvals'], 'href' => 'batches/index.php?status=pending_approval',
        'cta' => 'Review Now', 'tone' => 'amber',
    ];
}
if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0) {
    $actionItems[] = [
        'icon' => '💸', 'label' => 'Approved batches ready to disburse',
        'count' => $metrics['approved_for_disbursement'], 'href' => 'batches/index.php?status=approved',
        'cta' => 'Disburse Now', 'tone' => 'green',
    ];
}
if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) {
    $actionItems[] = [
        'icon' => '💰', 'label' => 'Source accounts awaiting confirmation',
        'count' => $metrics['pending_source_confirmations'], 'href' => 'imports/add_source.php',
        'cta' => 'Confirm Now', 'tone' => 'amber',
    ];
}
if (($metrics['rejected_batches'] ?? 0) > 0 && ($canCreate || $isSupervisor)) {
    $actionItems[] = [
        'icon' => '❌', 'label' => 'Rejected batches that may need correction',
        'count' => $metrics['rejected_batches'], 'href' => 'batches/index.php?status=rejected',
        'cta' => 'Review', 'tone' => 'danger',
    ];
}
$criticalActionCount = count(array_filter($actionItems, fn($item) => $item['tone'] === 'danger'));

// ============================================================
// SIDEBAR NAV MODEL — one array driving both the expanded and
// collapsed rendering of the sidebar, so the two states can never
// drift out of sync with each other (a direct fix for the "nav
// disagrees with itself between pages" problem this redesign started
// from — see the design reference doc). Each entry's `show` is the
// exact same boolean already governing that link in the rest of this
// file; nothing new is being gated here, only re-skinned.
// ============================================================
$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => 'index.php', 'show' => true, 'active' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => 'batches/index.php?status=all', 'show' => true, 'badge' => ($metrics['pending_approvals'] ?? 0) > 0 && $canApprove ? $metrics['pending_approvals'] : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => 'beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => 'index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => 'departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => 'imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) ? $metrics['pending_source_confirmations'] : null],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => 'settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => 'reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => 'settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => 'logout.php', 'show' => true],
];

/**
 * Small inline stroke-icon set, 20x20, currentColor — kept as one
 * function so the sidebar, topbar and card headers all draw from the
 * same set rather than each hand-rolling their own SVG.
 */
function svgIcon(string $name): string {
    $icons = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="13" rx="1.5"/><path d="M3 10h18"/><circle cx="16.5" cy="14" r="1"/>',
        'people' => '<circle cx="8.5" cy="8" r="3.2"/><path d="M2.5 19c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5"/><circle cx="17" cy="8.5" r="2.6"/><path d="M15.2 13.6c2.6.3 4.3 2.3 4.3 5.4"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 8h1M8 12h1M8 16h1M15 8h1M15 12h1M15 16h1M9 21v-4h6v4"/>',
        'bank' => '<path d="M3 9l9-5 9 5"/><rect x="4" y="9" width="16" height="10" rx="0.5"/><path d="M2 21h20M6 9v10M11 9v10M16 9v10"/>',
        'idcard' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M6 17c0-2 1.4-3.2 3-3.2s3 1.2 3 3.2M14 9h5M14 13h5"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="14" width="3" height="4"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v2.2M12 18.8V21M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M3 12h2.2M18.8 12H21M4.9 19.1l1.6-1.6M17.5 6.5l1.6-1.6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>',
        'bell' => '<path d="M6 9a6 6 0 1 1 12 0c0 4.5 1.5 6 1.5 6h-15S6 13.5 6 9Z"/><path d="M10 19a2 2 0 0 0 4 0"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.3 9a2.7 2.7 0 1 1 3.9 2.4c-.9.5-1.2 1-1.2 2"/><path d="M12 17h.01"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 3v2M12 19v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M3 12h2M19 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'warning' => '<path d="M12 3l10 18H2Z"/><path d="M12 10v4M12 17h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    ];
    $path = $icons[$name] ?? $icons['grid'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · Enterprise · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper:        #EEF1EF;
            --panel:        #FFFFFF;
            --sidebar:      #F4EFE3;
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
            --amber-bg:     #FEF3C7;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --danger:       #b3261e;
            --danger-bg:    #fbeceb;

            --sidebar-w:    236px;
            --sidebar-w-collapsed: 68px;
            --max-width:    1400px;
            --btn-h:        36px;
            --btn-h-sm:     28px;
            --radius:       6px;
            --radius-sm:    4px;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                --paper: #141B22; --panel: #1B2733; --sidebar: #17222B; --ink-900: #ECEFF2; --ink-700: #D5DCE0;
                --ink-500: #93A2AC; --ink-300: #6B7A85; --line: #2C3A45; --line-strong: #3C4C58;
                --brass: #B08D5B; --brass-tint: #2A2418; --green-tint: #16261D; --blue-tint: #17242E; --danger-bg: #2A1615; --amber-bg: #2A2114;
            }
        }
        :root[data-theme="dark"] {
            --paper: #141B22; --panel: #1B2733; --sidebar: #17222B; --ink-900: #ECEFF2; --ink-700: #D5DCE0;
            --ink-500: #93A2AC; --ink-300: #6B7A85; --line: #2C3A45; --line-strong: #3C4C58;
            --brass: #B08D5B; --brass-tint: #2A2418; --green-tint: #16261D; --blue-tint: #17242E; --danger-bg: #2A1615; --amber-bg: #2A2114;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { background: var(--paper); }
        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            display: flex;
        }
        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }
        svg { width: 19px; height: 19px; flex-shrink: 0; }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }

        /* One place controlling "a bit more rounded" everywhere, instead
           of threading border-radius into two dozen individual rules. */
        .btn, .btn-mini, .btn-create, .card, .panel, .stat-card, .quick-action,
        .icon-btn, .collapse-btn, .mobile-menu-btn, .topbar-avatar, .user-avatar,
        .brand-mark, .topbar-search form, .status, .nav-badge, .card-badge,
        .empty-state, .info-panel, input[type="text"] {
            border-radius: var(--radius);
        }
        .nav-link { border-radius: var(--radius-sm); }
        .nav-badge, .status, .card-badge { border-radius: 999px; }

        /* ============================================================
           SIDEBAR — vertical, persistent, collapsible to icons-only.
           One component every enterprise page should include identically
           (see the design reference doc's §"no shared header" finding) —
           this file is the first to carry it.
           ============================================================ */
        .sidebar {
            width: var(--sidebar-w);
            flex-shrink: 0;
            background: var(--sidebar);
            border-right: 1px solid var(--line);
            display: flex;
            flex-direction: column;
            height: 100vh;
            position: sticky;
            top: 0;
            overflow: hidden;
            transition: width 0.18s ease;
        }
        body.sidebar-collapsed .sidebar { width: var(--sidebar-w-collapsed); }
        .mobile-menu-btn {
            display: none;
            width: 36px; height: 36px; align-items: center; justify-content: center;
            background: none; border: 1px solid var(--line); color: var(--ink-700); cursor: pointer; flex-shrink: 0;
        }
        .mobile-menu-btn:hover { border-color: var(--brass); color: var(--brass); }
        .sidebar-backdrop { display: none; border: none; }

        .sidebar-head {
            padding: 20px 18px 16px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brand-mark {
            width: 36px; height: 36px; flex-shrink: 0;
            background: var(--ink-900); color: #fff;
            display: flex; align-items: center; justify-content: center;
        }
        .brand-mark svg { width: 20px; height: 20px; }
        .brand-text { min-width: 0; overflow: hidden; }
        .brand-name { font-family: var(--f-cond); font-weight: 700; font-size: 15px; letter-spacing: 0.02em; white-space: nowrap; }
        .brand-org { font-family: var(--f-cond); font-size: 10.5px; color: var(--ink-500); text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        body.sidebar-collapsed .brand-text { display: none; }

        .collapse-btn {
            width: 26px; height: 26px; flex-shrink: 0;
            background: transparent; border: 1px solid var(--line-strong); color: var(--ink-500);
            display: flex; align-items: center; justify-content: center; cursor: pointer;
        }
        .collapse-btn svg { width: 14px; height: 14px; transition: transform 0.18s ease; }
        .collapse-btn:hover { border-color: var(--brass); color: var(--brass); }
        body.sidebar-collapsed .collapse-btn svg { transform: rotate(180deg); }

        .create-batch-wrap { padding: 0 18px 16px; }
        .btn-create {
            width: 100%; height: var(--btn-h);
            background: var(--brass); color: #fff; border: none;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            font-family: var(--f-cond); font-weight: 700; font-size: 12.5px; text-transform: uppercase; letter-spacing: 0.05em;
            text-decoration: none; cursor: pointer; white-space: nowrap; overflow: hidden;
        }
        .btn-create:hover { background: #755a2f; }
        .btn-create.locked { background: var(--line-strong); color: var(--ink-500); cursor: not-allowed; }
        body.sidebar-collapsed .btn-create span.label { display: none; }
        body.sidebar-collapsed .create-batch-wrap { padding: 0 14px 16px; }

        .sidebar-nav { flex: 1; overflow-y: auto; padding: 4px 10px; }
        .nav-link {
            display: flex; align-items: center; gap: 12px;
            height: 40px; padding: 0 10px;
            color: var(--ink-700); text-decoration: none;
            font-family: var(--f-cond); font-weight: 600; font-size: 13px; letter-spacing: 0.02em; text-transform: uppercase;
            white-space: nowrap; overflow: hidden;
            border-left: 3px solid transparent;
        }
        .nav-link:hover { background: rgba(138,109,59,0.1); color: var(--ink-900); }
        .nav-link.active { background: var(--brass); color: #fff; border-left-color: var(--ink-900); }
        .nav-link .nav-label { overflow: hidden; text-overflow: ellipsis; flex: 1; }
        .nav-link .nav-badge {
            background: var(--seal-red); color: #fff; font-family: var(--f-mono); font-size: 10px;
            padding: 1px 7px; flex-shrink: 0;
        }
        .nav-link.active .nav-badge { background: rgba(255,255,255,0.25); }
        body.sidebar-collapsed .nav-link .nav-label,
        body.sidebar-collapsed .nav-link .nav-badge { display: none; }
        body.sidebar-collapsed .nav-link { justify-content: center; padding: 0; }

        .sidebar-footer { border-top: 1px solid var(--line); padding: 10px; }
        .sidebar-toggle-row {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 10px 12px;
        }
        .theme-btn {
            display: flex; align-items: center; gap: 8px; background: none; border: none; cursor: pointer;
            color: var(--ink-500); font-family: var(--f-cond); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em;
        }
        .theme-btn:hover { color: var(--ink-900); }
        body.sidebar-collapsed .theme-btn span.label { display: none; }
        body.sidebar-collapsed .sidebar-toggle-row { justify-content: center; }

        .user-chip { display: flex; align-items: center; gap: 10px; padding: 10px 12px; min-width: 0; }
        .user-avatar {
            width: 30px; height: 30px; flex-shrink: 0; background: var(--ink-900); color: var(--brass);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--f-cond); font-weight: 700; font-size: 12px;
        }
        .user-meta { min-width: 0; overflow: hidden; }
        .user-meta .name { font-size: 12.5px; font-weight: 600; color: var(--ink-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-meta .role { font-size: 10.5px; color: var(--ink-300); text-transform: uppercase; font-family: var(--f-cond); letter-spacing: 0.04em; }
        body.sidebar-collapsed .user-meta { display: none; }

        /* ============================================================
           MOBILE — below this width the sidebar stops being a flex
           sibling of .main and becomes a full off-canvas drawer: hidden
           by default, opened only via the hamburger button, with a
           dimming backdrop behind it. This replaces an earlier version
           that made the sidebar `position: fixed` but left it visible
           by default with nothing narrowing .main underneath it — the
           sidebar rendered as a full-height panel sitting on top of the
           page content instead of beside it. The desktop collapse
           button/state above is untouched and still works above this
           width; below it, "collapsed vs not" no longer means anything,
           so those rules are simply inert here.
           ============================================================ */
        @media (max-width: 860px) {
            .sidebar {
                position: fixed;
                top: 0; left: 0; bottom: 0;
                width: var(--sidebar-w) !important;
                z-index: 50;
                transform: translateX(-100%);
            }
            body.sidebar-mobile-open .sidebar { transform: translateX(0); }
            body.sidebar-mobile-open .brand-text,
            body.sidebar-mobile-open .nav-link .nav-label,
            body.sidebar-mobile-open .nav-link .nav-badge,
            body.sidebar-mobile-open .theme-btn span.label,
            body.sidebar-mobile-open .user-meta { display: block; }

            .sidebar-backdrop {
                display: none;
                position: fixed; inset: 0; z-index: 45;
                background: rgba(15, 33, 56, 0.5);
            }
            body.sidebar-mobile-open .sidebar-backdrop { display: block; }

            .mobile-menu-btn { display: flex; }
            .collapse-btn { display: none; }
        }

        /* ============================================================
           MAIN COLUMN
           ============================================================ */
        .main { flex: 1; min-width: 0; display: flex; flex-direction: column; }

        .topbar {
            background: var(--panel); border-bottom: 1px solid var(--line);
            padding: 14px 28px; display: flex; align-items: center; gap: 18px;
        }
        .topbar-search { flex: 1; max-width: 480px; }
        .topbar-search form { display: flex; align-items: center; gap: 8px; background: var(--paper); border: 1px solid var(--line); height: 38px; padding: 0 12px; }
        .topbar-search svg { color: var(--ink-300); width: 17px; height: 17px; }
        .topbar-search input { flex: 1; border: none; background: none; font-family: var(--f-body); font-size: 13.5px; color: var(--ink-900); }
        .topbar-search input:focus { outline: none; }
        .topbar-icons { display: flex; align-items: center; gap: 6px; margin-left: auto; }
        .icon-btn {
            width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
            background: none; border: none; color: var(--ink-500); text-decoration: none; cursor: pointer; position: relative;
        }
        .icon-btn:hover { color: var(--ink-900); }
        .icon-btn .dot { position: absolute; top: 6px; right: 7px; width: 7px; height: 7px; background: var(--seal-red); border-radius: 50%; }
        .topbar-avatar {
            width: 34px; height: 34px; background: var(--ink-900); color: var(--brass);
            display: flex; align-items: center; justify-content: center;
            font-family: var(--f-cond); font-weight: 700; font-size: 12px; text-decoration: none;
        }

        .content { max-width: var(--max-width); width: 100%; margin: 0 auto; padding: 28px 32px 60px; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 12px; margin-bottom: 24px; }
        .page-header h1 { font-family: var(--f-cond); font-size: 26px; font-weight: 700; letter-spacing: 0.01em; text-transform: uppercase; }
        .page-header .meta-line { font-family: var(--f-mono); font-size: 11.5px; color: var(--ink-300); margin-top: 6px; }

        /* ============================================================
           STAT CARDS
           ============================================================ */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .stat-card { background: var(--panel); border: 1px solid var(--line); border-top: 3px solid var(--line-strong); padding: 18px 20px; text-decoration: none; color: inherit; display: block; }
        .stat-card.accent-amber { border-top-color: var(--amber); }
        .stat-card.accent-danger { border-top-color: var(--seal-red); }
        .stat-card.accent-green { border-top-color: var(--ledger-green); }
        .stat-card:hover { border-color: var(--brass); }
        .stat-label { font-family: var(--f-cond); font-size: 11.5px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--ink-500); }
        .stat-value { font-family: var(--f-cond); font-size: 32px; font-weight: 700; margin-top: 8px; font-variant-numeric: tabular-nums; }
        .stat-value .cur { font-size: 15px; color: var(--ink-300); font-weight: 400; }
        .stat-sub { font-size: 12px; color: var(--ink-500); margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--line); font-family: var(--f-mono); }
        .stat-sub.up { color: var(--ledger-green); }
        .stat-sub.down { color: var(--seal-red); }

        /* ============================================================
           TWO-UP PANELS: NEEDS ATTENTION / RECENT ACTIVITY
           ============================================================ */
        .panel-grid { display: grid; grid-template-columns: 1.1fr 1fr; gap: 14px; margin-bottom: 24px; }
        @media (max-width: 960px) { .panel-grid { grid-template-columns: 1fr; } }
        .panel { background: var(--panel); border: 1px solid var(--line); display: flex; flex-direction: column; }
        .panel-head {
            background: var(--ink-900); color: #fff; padding: 14px 20px;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
        }
        .panel-head .title { display: flex; align-items: center; gap: 10px; font-family: var(--f-cond); font-weight: 700; font-size: 14px; letter-spacing: 0.03em; text-transform: uppercase; }
        .panel-head .title svg { color: var(--brass); }
        .panel-head .critical-pill { background: var(--seal-red); color: #fff; font-family: var(--f-mono); font-size: 11px; padding: 3px 10px; display: flex; flex-direction: column; align-items: center; line-height: 1.15; }
        .panel-body { flex: 1; }
        .task-row { padding: 14px 20px; border-bottom: 1px solid var(--line); display: flex; gap: 12px; align-items: flex-start; }
        .task-row:last-child { border-bottom: none; }
        .task-row .dot { width: 8px; height: 8px; margin-top: 6px; flex-shrink: 0; }
        .dot.amber { background: var(--amber); }
        .dot.green { background: var(--ledger-green); }
        .dot.danger { background: var(--seal-red); }
        .task-row .body { flex: 1; min-width: 0; }
        .task-row .top-line { display: flex; justify-content: space-between; gap: 10px; }
        .task-row .label { font-weight: 600; font-size: 13.5px; }
        .task-row .when { font-family: var(--f-mono); font-size: 10.5px; color: var(--ink-300); white-space: nowrap; }
        .task-row .cta { margin-top: 8px; }
        .btn-mini {
            display: inline-flex; align-items: center; height: 28px; padding: 0 14px;
            background: var(--brass); color: #fff; border: none; font-family: var(--f-cond);
            font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; text-decoration: none;
        }
        .btn-mini:hover { background: #755a2f; }
        .empty-row { padding: 26px 20px; text-align: center; color: var(--ink-300); font-size: 13px; }
        .panel-foot { border-top: 1px solid var(--line); padding: 11px 20px; text-align: center; }
        .panel-foot a, .panel-foot span.disabled { font-family: var(--f-cond); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--brass); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .panel-foot span.disabled { color: var(--ink-300); cursor: default; }

        .activity-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .activity-table th { text-align: left; font-family: var(--f-cond); font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-300); padding: 10px 20px; border-bottom: 1px solid var(--line); background: var(--paper); }
        .activity-table td { padding: 10px 20px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .activity-table tr:last-child td { border-bottom: none; }
        .activity-table .ts { font-family: var(--f-mono); font-size: 11px; color: var(--ink-500); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .activity-table .who { font-family: var(--f-mono); font-size: 11px; color: var(--ink-300); }

        /* ============================================================
           SHARED: metrics / cards / tables / status / buttons — carried
           over from the previous version of this page.
           ============================================================ */
        .card { background: var(--panel); border: 1px solid var(--line); padding: 20px 24px; margin-bottom: 20px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 8px; }
        .card-title { font-size: 16px; font-weight: 700; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .card-badge { padding: 2px 12px; background: var(--ink-900); color: #fff; font-size: 10px; font-weight: 600; font-family: var(--f-cond); letter-spacing: 0.04em; }
        .card-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

        .table-responsive { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { background: var(--paper); color: var(--ink-500); padding: 10px 14px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; border-bottom: 2px solid var(--line); font-family: var(--f-cond); }
        td { padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 13px; }
        tr:hover { background: var(--brass-tint); }

        .status { display: inline-block; padding: 2px 12px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-family: var(--f-cond); }
        .status-draft { background: var(--paper); color: var(--ink-500); }
        .status-pending { background: var(--amber-bg); color: var(--amber); }
        .status-approved { background: var(--blue-tint); color: #1e40af; }
        .status-completed { background: var(--green-tint); color: var(--ledger-green); }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }

        .btn { height: var(--btn-h); padding: 0 18px; font-size: 12px; font-weight: 600; font-family: var(--f-cond); border: 1px solid transparent; cursor: pointer; transition: all 0.15s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; letter-spacing: 0.04em; text-transform: uppercase; box-sizing: border-box; line-height: 1; }
        .btn:hover { opacity: 0.85; }
        .btn-primary { background: var(--ink-900); color: #fff; border-color: var(--ink-900); }
        .btn-primary:hover { background: var(--brass); border-color: var(--brass); color: var(--ink-900); opacity: 1; }
        .btn-success { background: var(--ledger-green); color: #fff; border-color: var(--ledger-green); }
        .btn-warning { background: var(--amber); color: #fff; border-color: var(--amber); }
        .btn-outline { background: transparent; border: 1px solid var(--line); color: var(--ink-500); }
        .btn-outline:hover { border-color: var(--brass); color: var(--ink-900); background: var(--brass-tint); opacity: 1; }
        .btn-sm { height: var(--btn-h-sm); padding: 0 14px; font-size: 11px; }

        .empty-state { text-align: center; padding: 40px 20px; color: var(--ink-300); }
        .empty-state .icon { font-size: 40px; margin-bottom: 10px; }

        .info-panel { padding: 16px 20px; margin-bottom: 16px; border-left: 3px solid var(--brass); }
        .info-panel .label { font-weight: 600; font-size: 14px; font-family: var(--f-cond); letter-spacing: 0.02em; }
        .info-panel .desc { color: var(--ink-500); font-size: 13px; margin-top: 4px; }
        .info-panel .desc .highlight { font-weight: 600; color: var(--ink-900); }

        footer.footer { background: var(--ink-900); color: var(--ink-300); text-align: center; font-size: 11px; padding: 16px 32px; font-family: var(--f-mono); margin-top: 8px; }

        @media (max-width: 768px) {
            .content { padding: 16px; }
            .topbar { padding: 12px 16px; }
            .topbar-search { display: none; }
            .quick-actions { grid-template-columns: 1fr; }
            .page-header h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
    <script>
        // Runs before paint so the sidebar never "flashes" open then
        // collapses, and the theme never flashes light-then-dark.
        (function () {
            if (localStorage.getItem('vm_sidebar_collapsed') === '1') {
                document.body ? document.body.classList.add('sidebar-collapsed') : null;
            }
            var theme = localStorage.getItem('vm_theme');
            if (theme === 'dark' || theme === 'light') {
                document.documentElement.setAttribute('data-theme', theme);
            }
        })();
    </script>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- ============================================================ -->
    <!-- SIDEBAR -->
    <!-- ============================================================ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <div class="brand">
                <div class="brand-mark"><?php echo svgIcon('bank'); ?></div>
                <div class="brand-text">
                    <div class="brand-name">VOUCHMORPH</div>
                    <div class="brand-org"><?php echo safeHtml($orgName); ?></div>
                </div>
            </div>
            <button type="button" class="collapse-btn" id="collapseBtn" title="Collapse sidebar" aria-label="Collapse sidebar">
                <?php echo svgIcon('chevron'); ?>
            </button>
        </div>

        <div class="create-batch-wrap">
            <?php if ($canCreate && $setupReady): ?>
            <a href="imports/source_input.php" class="btn-create"><?php echo svgIcon('plus'); ?><span class="label">Create Batch</span></a>
            <?php elseif ($canCreate): ?>
            <span class="btn-create locked" title="Your Owner needs to finish setup first"><?php echo svgIcon('lock'); ?><span class="label">Create Batch</span></span>
            <?php endif; ?>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): if (!$item['show']) continue; ?>
            <a href="<?php echo safeHtml($item['href']); ?>" class="nav-link<?php echo !empty($item['active']) ? ' active' : ''; ?>" title="<?php echo safeHtml($item['label']); ?>">
                <?php echo svgIcon($item['icon']); ?>
                <span class="nav-label"><?php echo safeHtml($item['label']); ?></span>
                <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?php echo (int)$item['badge']; ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <?php foreach ($navUtility as $item): if (!$item['show']) continue; ?>
            <a href="<?php echo safeHtml($item['href']); ?>" class="nav-link" title="<?php echo safeHtml($item['label']); ?>">
                <?php echo svgIcon($item['icon']); ?>
                <span class="nav-label"><?php echo safeHtml($item['label']); ?></span>
            </a>
            <?php endforeach; ?>

            <div class="sidebar-toggle-row">
                <button type="button" class="theme-btn" id="themeBtn" title="Toggle dark mode">
                    <span class="theme-icon" id="themeIcon"><?php echo svgIcon('moon'); ?></span>
                    <span class="label">Dark Mode</span>
                </button>
            </div>

            <a href="settings.php" class="user-chip" style="text-decoration:none;" title="<?php echo safeHtml($fullName); ?>">
                <div class="user-avatar"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></div>
                <div class="user-meta">
                    <div class="name"><?php echo safeHtml($fullName); ?></div>
                    <div class="role"><?php echo safeHtml(getRoleLabel($userRole)); ?></div>
                </div>
            </a>
        </div>
    </aside>

    <!-- ============================================================ -->
    <!-- MAIN -->
    <!-- ============================================================ -->
    <div class="main">
        <div class="topbar">
            <button type="button" class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Open menu"><?php echo svgIcon('grid'); ?></button>
            <?php if ($canTrace): ?>
            <div class="topbar-search">
                <form method="get" action="index.php">
                    <?php echo svgIcon('search'); ?>
                    <input type="text" name="trace" placeholder="Search batch reference, phone, national ID…" value="<?php echo safeHtml($traceQuery); ?>">
                </form>
            </div>
            <?php endif; ?>
            <div class="topbar-icons">
                <a href="#attention" class="icon-btn" title="Needs your attention">
                    <?php echo svgIcon('bell'); ?>
                    <?php if (!empty($actionItems)): ?><span class="dot"></span><?php endif; ?>
                </a>
                <a href="settings.php" class="topbar-avatar" title="<?php echo safeHtml($fullName); ?>"><?php echo safeHtml(strtoupper(substr($fullName, 0, 1))); ?></a>
            </div>
        </div>

        <main class="content">
            <div class="page-header">
                <div>
                    <h1>Operational Dashboard</h1>
                    <div class="meta-line">SYSTEM_TIME: <?php echo date('H:i:s'); ?> <?php echo date('T'); ?> · Welcome back, <?php echo safeHtml($fullName); ?></div>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- STAT CARDS -->
            <!-- ============================================================ -->
            <div class="stat-grid">
                <a href="batches/index.php?status=completed" class="stat-card accent-green">
                    <div class="stat-label">Total Disbursed (MTD)</div>
                    <div class="stat-value"><span class="cur"><?php echo safeHtml($orgCurrency); ?></span> <?php echo number_format($mtdDisbursed, 2); ?></div>
                    <?php if ($disbursedDeltaPct !== null): ?>
                    <div class="stat-sub <?php echo $disbursedDeltaPct >= 0 ? 'up' : 'down'; ?>"><?php echo $disbursedDeltaPct >= 0 ? '↗' : '↘'; ?> <?php echo abs($disbursedDeltaPct); ?>% vs last month</div>
                    <?php else: ?>
                    <div class="stat-sub">No prior-month data yet</div>
                    <?php endif; ?>
                </a>

                <a href="batches/index.php?status=all" class="stat-card">
                    <div class="stat-label">Active Batches</div>
                    <div class="stat-value"><?php echo number_format($metrics['active_batches'] ?? 0); ?></div>
                    <div class="stat-sub"><?php echo (int)($metrics['executing_batches'] ?? 0); ?> executing right now</div>
                </a>

                <a href="batches/index.php?status=pending_approval" class="stat-card <?php echo ($metrics['pending_approvals'] ?? 0) > 0 ? 'accent-danger' : ''; ?>">
                    <div class="stat-label">Pending Approvals</div>
                    <div class="stat-value"><?php echo number_format($metrics['pending_approvals'] ?? 0); ?></div>
                    <div class="stat-sub"><?php echo $avgClearanceHours !== null ? 'Avg clearance: ' . $avgClearanceHours . ' hrs (30d)' : 'No approvals cleared in the last 30 days'; ?></div>
                </a>
            </div>

            <!-- ============================================================ -->
            <!-- NEEDS YOUR ATTENTION / RECENT ACTIVITY -->
            <!-- ============================================================ -->
            <div class="panel-grid" id="attention">
                <div class="panel">
                    <div class="panel-head">
                        <span class="title"><?php echo svgIcon('warning'); ?> Needs Your Attention</span>
                        <?php if ($criticalActionCount > 0): ?>
                        <span class="critical-pill"><?php echo $criticalActionCount; ?><small style="font-size:8px;">CRITICAL</small></span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($actionItems)): ?>
                        <div class="empty-row"><?php echo ($canApprove || $canDisburse || $canConfirmSource) ? '✅ All clear — nothing is waiting on you right now.' : 'Nothing needs your attention right now.'; ?></div>
                        <?php else: foreach ($actionItems as $item): ?>
                        <div class="task-row">
                            <span class="dot <?php echo $item['tone']; ?>"></span>
                            <div class="body">
                                <div class="top-line">
                                    <span class="label"><?php echo $item['icon']; ?> <?php echo safeHtml($item['label']); ?></span>
                                </div>
                                <div class="cta"><a href="<?php echo safeHtml($item['href']); ?>" class="btn-mini"><?php echo safeHtml($item['cta']); ?> (<?php echo (int)$item['count']; ?>)</a></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="panel-foot"><a href="batches/index.php?status=all">View All Batches <?php echo svgIcon('arrow'); ?></a></div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <span class="title"><?php echo svgIcon('clock'); ?> Recent Activity</span>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($recentActivity)): ?>
                        <div class="empty-row">No recorded activity yet.</div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="activity-table">
                                <thead><tr><th>Timestamp</th><th>Event</th><th>By</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentActivity as $ev): ?>
                                <tr>
                                    <td class="ts"><?php echo date('Y-m-d H:i', strtotime($ev['created_at'])); ?></td>
                                    <td><?php echo safeHtml(getActivityLabel($ev['action'])); ?></td>
                                    <td class="who"><?php echo $ev['actor_name'] ? safeHtml($ev['actor_name']) : 'System'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="panel-foot"><span class="disabled" title="A full audit-log page doesn't exist yet — this is the 8 most recent events only">Full log view not built yet</span></div>
                </div>
            </div>

            <!--
                No Quick Actions grid here on purpose: every tile it used
                to have duplicated something already one click away —
                "New Disbursement" duplicated the sidebar's Create Batch
                button, "Disburse Funds"/"Review & Approve" duplicated
                the Needs Your Attention panel's own per-item CTAs, and
                "View Beneficiaries"/"Manage Users" duplicated sidebar
                nav items. The stat cards above and the attention panel
                below are the real, non-duplicated entry points now.
            -->

            <!-- Secondary metrics -->
            <div class="stat-grid">
                <?php if (($metrics['pending_batches'] ?? 0) > 0): ?>
                <div class="stat-card accent-amber"><div class="stat-label">Pending Batches</div><div class="stat-value"><?php echo number_format($metrics['pending_batches']); ?></div><div class="stat-sub">Waiting for approval</div></div>
                <?php endif; ?>
                <?php if (($metrics['approved_batches'] ?? 0) > 0): ?>
                <div class="stat-card"><div class="stat-label">Approved</div><div class="stat-value"><?php echo number_format($metrics['approved_batches']); ?></div><div class="stat-sub">Ready for disbursement</div></div>
                <?php endif; ?>
                <?php if (($metrics['executed_batches'] ?? 0) > 0): ?>
                <div class="stat-card accent-green"><div class="stat-label">Completed</div><div class="stat-value"><?php echo number_format($metrics['executed_batches']); ?></div><div class="stat-sub">Successfully executed</div></div>
                <?php endif; ?>
                <div class="stat-card"><div class="stat-label">Beneficiaries</div><div class="stat-value"><?php echo number_format($metrics['total_beneficiaries'] ?? 0); ?></div><div class="stat-sub">Active recipients</div></div>
            </div>

            <!-- Recent Batches — top 5 only; "View More" goes to the full,
                 filterable list. The header used to also carry its own
                 "View All" and "New Batch" buttons, both duplicating a
                 sidebar/attention-panel action already on this page. -->
            <?php $recentBatchesShown = array_slice($recentBatches, 0, 5); ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">📋 Recent Batches</span>
                </div>
                <?php if (empty($recentBatchesShown)): ?>
                <div class="empty-state">
                    <div class="icon">📭</div>
                    <p>No batches found. Create your first disbursement batch to get started.</p>
                    <?php if ($canCreate && !$setupReady): ?><p style="font-size:12px; color:var(--ink-300); margin-top:8px;">🔒 Waiting on your Owner to finish setup (team, department, source account).</p><?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Reference</th><th>Name</th><th>Source</th><th>Amount</th><th>Destinations</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentBatchesShown as $batch): ?>
                        <tr>
                            <td><strong><?php echo safeHtml($batch['batch_reference']); ?></strong></td>
                            <td><?php echo safeHtml($batch['batch_name'] ?? '—'); ?></td>
                            <td><?php echo safeHtml($batch['source_institution'] ?? '—'); ?></td>
                            <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0, $orgCurrency); ?></strong></td>
                            <td><?php echo number_format($batch['total_destinations'] ?? 0); ?></td>
                            <td><span class="status status-<?php echo getStatusClass($batch['status']); ?>"><?php echo getStatusLabel($batch['status']); ?></span></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                            <td><a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-outline btn-sm">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <div class="panel-foot" style="margin: 16px -24px -20px;"><a href="batches/index.php?status=all">View More <?php echo svgIcon('arrow'); ?></a></div>
            </div>

            <!-- Payment Trace results (search lives in the topbar; results render here) -->
            <?php if ($canTrace && $traceQuery !== ''): ?>
            <div class="card" id="trace">
                <div class="card-header"><span class="card-title">🔍 Trace Results for "<?php echo safeHtml($traceQuery); ?>"</span></div>
                <?php if (empty($traceBatches) && empty($traceBeneficiaries)): ?>
                <div class="empty-state"><div class="icon">🔍</div><p>No matches for "<?php echo safeHtml($traceQuery); ?>".</p></div>
                <?php endif; ?>
                <?php if (!empty($traceBatches)): ?>
                <div class="table-responsive" style="margin-bottom:16px;">
                    <table>
                        <thead><tr><th>Reference</th><th>Name</th><th>Source</th><th>Amount</th><th>Status</th><th>Created</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($traceBatches as $b): ?>
                        <tr>
                            <td><strong><?php echo safeHtml($b['batch_reference']); ?></strong></td>
                            <td><?php echo safeHtml($b['batch_name'] ?? 'Unnamed'); ?></td>
                            <td><?php echo safeHtml($b['source_institution'] ?? 'N/A'); ?></td>
                            <td><?php echo formatCurrency($b['total_amount'] ?? 0, $orgCurrency); ?></td>
                            <td><span class="status status-<?php echo getStatusClass($b['status']); ?>"><?php echo getStatusLabel($b['status']); ?></span></td>
                            <td><?php echo date('Y-m-d H:i', strtotime($b['created_at'] ?? 'now')); ?></td>
                            <td><a href="imports/review_batch.php?batch_id=<?php echo $b['id']; ?>" class="btn btn-outline btn-sm">Open</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <?php if (!empty($traceBeneficiaries)): ?>
                <div class="table-responsive">
                    <table>
                        <thead><tr><?php foreach (array_keys($traceBeneficiaries[0]) as $col): if ($col === 'organization_id') continue; ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach ($traceBeneficiaries as $row): ?>
                        <tr>
                            <?php foreach ($row as $col => $val): if ($col === 'organization_id') continue; $s = is_array($val) ? json_encode($val) : (string)$val; ?>
                            <td><?php echo safeHtml(strlen($s) > 40 ? substr($s, 0, 40) . '…' : $s); ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Role-specific info panels -->
            <?php if ($isReadOnly): ?>
            <div class="info-panel" style="border-left-color: var(--brass); background: var(--brass-tint);"><div class="label">🔍 Read-Only Access</div><div class="desc">You have <span class="highlight"><?php echo $userRole === 'auditor' ? 'auditor' : 'read-only'; ?></span> access. You can view and export data but cannot create or modify any records.<?php if ($userRole === 'auditor'): ?> This is for compliance and audit purposes.<?php endif; ?></div></div>
            <?php endif; ?>

            <?php if ($isLoader): ?>
            <div class="info-panel" style="border-left-color: #3b82f6; background: var(--blue-tint);"><div class="label">📤 Loader Access</div><div class="desc">You can create and upload new disbursement batches (use Create Batch in the sidebar). Once created, they will be sent for approval.<?php if (!$setupReady): ?> <span style="margin-left:6px; color:var(--ink-500);">🔒 Waiting on your Owner to finish setup first.</span><?php endif; ?></div></div>
            <?php endif; ?>

            <?php if ($isApprover): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);"><div class="label">✅ Approver Access</div><div class="desc">You can review and approve pending disbursement batches.<?php if (($metrics['pending_approvals'] ?? 0) > 0): ?> <span class="highlight"><?php echo $metrics['pending_approvals']; ?> batches awaiting your review</span> — see Needs Your Attention above.<?php endif; ?></div></div>
            <?php endif; ?>

            <?php if ($isSupervisor): ?>
            <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);"><div class="label">💸 Owner Disbursement Access</div><div class="desc">You can disburse funds for approved batches. This is the only role that can — it is the final, non-delegable step in the disbursement chain.<?php if (($metrics['approved_for_disbursement'] ?? 0) > 0): ?> <span class="highlight"><?php echo $metrics['approved_for_disbursement']; ?> batches ready for disbursement</span> — see Needs Your Attention above.<?php endif; ?></div></div>
            <?php endif; ?>

            <?php if ($userRole === 'beneficiary_registrar'): ?>
            <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);"><div class="label">👤 Beneficiary Registrar</div><div class="desc">You can add and manage beneficiaries for disbursement batches. <a href="imports/add_destinations.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Add Beneficiaries</a></div></div>
            <?php endif; ?>

            <?php if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);"><div class="label">💰 Source Accounts Awaiting Confirmation</div><div class="desc"><span class="highlight"><?php echo $metrics['pending_source_confirmations']; ?> source account(s)</span> proposed by Finance are waiting for an Owner or IT Manager to confirm before they can be used in disbursements — see Needs Your Attention above, or Source Accounts in the sidebar.</div></div>
            <?php endif; ?>

            <?php if ($userRole === 'finance_officer'): ?>
            <div class="info-panel" style="border-left-color: var(--brass); background: var(--brass-tint);"><div class="label">💰 Finance Officer Access</div><div class="desc">You can propose new source accounts for disbursements (Source Accounts in the sidebar). An Owner or IT Manager (not you) must confirm each one before it becomes usable.</div></div>
            <?php endif; ?>
        </main>

        <footer class="footer">VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?> — <?php echo safeHtml($orgName); ?> · Role: <?php echo safeHtml(getRoleLabel($userRole)); ?></footer>
    </div>

    <script>
        (function () {
            var body = document.body;
            var collapseBtn = document.getElementById('collapseBtn');
            collapseBtn.addEventListener('click', function () {
                body.classList.toggle('sidebar-collapsed');
                localStorage.setItem('vm_sidebar_collapsed', body.classList.contains('sidebar-collapsed') ? '1' : '0');
            });

            // Mobile drawer: separate from the desktop collapsed/expanded
            // state above — below the 860px breakpoint the sidebar is an
            // off-canvas panel, closed by default, opened by the
            // hamburger button or closed by tapping the backdrop.
            var mobileMenuBtn = document.getElementById('mobileMenuBtn');
            var sidebarBackdrop = document.getElementById('sidebarBackdrop');
            function closeMobileMenu() { body.classList.remove('sidebar-mobile-open'); }
            mobileMenuBtn.addEventListener('click', function () { body.classList.toggle('sidebar-mobile-open'); });
            sidebarBackdrop.addEventListener('click', closeMobileMenu);
            document.querySelectorAll('.sidebar .nav-link, .sidebar .btn-create').forEach(function (el) {
                el.addEventListener('click', closeMobileMenu);
            });

            var themeBtn = document.getElementById('themeBtn');
            var themeIcon = document.getElementById('themeIcon');
            var sunSvg = <?php echo json_encode(svgIcon('sun')); ?>;
            var moonSvg = <?php echo json_encode(svgIcon('moon')); ?>;
            function syncThemeIcon() {
                var isDark = document.documentElement.getAttribute('data-theme') === 'dark'
                    || (!document.documentElement.hasAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                themeIcon.innerHTML = isDark ? sunSvg : moonSvg;
            }
            themeBtn.addEventListener('click', function () {
                var current = document.documentElement.getAttribute('data-theme');
                var isDark = current === 'dark' || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
                var next = isDark ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                localStorage.setItem('vm_theme', next);
                syncThemeIcon();
            });
            syncThemeIcon();
        })();
    </script>
</body>
</html>
