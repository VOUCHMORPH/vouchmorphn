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
            --ink-500: #4A5A6E; --ink-300: #8A96A3; --line: #000000; --line-strong: #000000;
            --brass: #0F2138; --brass-tint: #E4E8ED; --ledger-green: #24513A; --green-tint: #E5EEE7;
            --f-body: 'IBM Plex Sans', sans-serif; --f-cond: 'IBM Plex Sans Condensed', sans-serif; --f-mono: 'IBM Plex Mono', monospace;
            --space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px; --space-5: 24px; --space-6: 32px; --space-7: 48px;
            --h-control: 36px; --radius: 0; --border-w: 2px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: var(--f-body); background: var(--paper); color: var(--ink-900); min-height: 100vh; font-size: 14px; line-height: 1.5; }
        .header { background: var(--ink-900); color: #fff; border-bottom: 3px solid var(--brass); padding: var(--space-4) var(--space-6); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: var(--space-3); }
        .logo { font-family: var(--f-cond); font-weight: 700; font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; }
        .logo span { color: var(--brass); }
        .header-right { font-size: 12px; color: var(--ink-300); display: flex; align-items: center; gap: var(--space-4); }
        .header-right a { color: var(--brass); text-decoration: none; }
        .wrap { max-width: 760px; margin: 0 auto; padding: var(--space-7) var(--space-5); }
        .eyebrow { font-family: var(--f-cond); font-size: 11px; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--brass); margin-bottom: var(--space-2); }
        h1 { font-family: var(--f-cond); font-size: 28px; font-weight: 700; margin-bottom: var(--space-2); }
        .sub { color: var(--ink-500); font-size: 14.5px; margin-bottom: var(--space-6); max-width: 560px; }
        .progress-track { height: var(--space-2); background: var(--line); margin-bottom: 6px; }
        .progress-fill { height: 100%; background: var(--brass); transition: width 0.3s; }
        .progress-label { font-size: 11.5px; color: var(--ink-500); font-family: var(--f-mono); margin-bottom: var(--space-6); }
        .step {
            background: var(--panel); border: var(--border-w) solid var(--line); padding: var(--space-5); margin-bottom: var(--space-4);
            display: flex; gap: var(--space-4); align-items: flex-start;
        }
        .step.current { border-color: var(--brass); background: var(--brass-tint); }
        .step.done { border-color: var(--ledger-green); background: var(--green-tint); }
        .step-num {
            width: 34px; height: 34px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: var(--f-cond); font-weight: 700; font-size: 15px;
            background: var(--ink-900); color: #fff;
        }
        .step.current .step-num { background: var(--brass); }
        .step.done .step-num { background: var(--ledger-green); }
        .step-body { flex: 1; }
        .step-label { font-family: var(--f-cond); font-size: 16px; font-weight: 700; margin-bottom: var(--space-1); display: flex; align-items: center; gap: var(--space-3); flex-wrap: wrap; }
        .step-desc { color: var(--ink-500); font-size: 13px; margin-bottom: var(--space-3); }
        .step-count { font-family: var(--f-mono); font-size: 11px; color: var(--ink-300); }
        .btn {
            display: inline-flex; align-items: center; height: var(--h-control); padding: 0 var(--space-4);
            font-size: 12px; font-weight: 600; font-family: var(--f-cond); text-transform: uppercase;
            letter-spacing: 0.04em; text-decoration: none; border: var(--border-w) solid var(--ink-900);
            background: var(--ink-900); color: #fff; transition: all 0.15s;
        }
        .btn:hover { background: var(--brass); border-color: var(--brass); color: #fff; }
        .btn-done { background: var(--ledger-green); border-color: var(--ledger-green); color: #fff; cursor: default; }
        .btn-outline { background: transparent; border: var(--border-w) solid var(--line-strong); color: var(--ink-700); }
        .btn-outline:hover { border-color: var(--brass); color: var(--brass); }
        .badge-done { font-size: 10px; font-weight: 700; text-transform: uppercase; background: var(--ledger-green); color: #fff; padding: 2px var(--space-3); font-family: var(--f-cond); }
        .footnote { margin-top: var(--space-6); padding: var(--space-4) var(--space-5); border-left: 3px solid var(--brass); background: var(--brass-tint); font-size: 13px; color: var(--ink-700); }
        .wizard-nav { display: flex; gap: var(--space-4); padding: var(--space-3) var(--space-6); background: var(--panel); border-bottom: 1px solid var(--line); flex-wrap: wrap; }
        .wizard-nav a { color: var(--ink-700); text-decoration: none; font-size: 12.5px; font-weight: 600; }
        .wizard-nav a:hover { color: var(--brass); }
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
        <a href="departments/index.php">Departments</a>
        <a href="/admin/enterprise/settings/users.php">Manage Team</a>
        <a href="/admin/enterprise/imports/add_source.php">Source Accounts</a>
        <a href="settings.php">Settings</a>
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
                    <a href="<?php echo safeHtmlSetup($step['action_href']); ?>" class="btn btn-outline" style="margin-top:var(--space-2);">
                        Manage <?php echo safeHtmlSetup($step['label']); ?> →
                    </a>
                <?php else: ?>
                    <a href="<?php echo safeHtmlSetup($step['action_href']); ?>" class="btn"><?php echo safeHtmlSetup($step['action_label']); ?> →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="footnote">
            A department without a budget set isn't incomplete — it's a deliberate choice ("no vote"), meaning it's limited only by the real balance of whatever source account it draws from, checked at the moment funds actually move. Set one if you want a hard local cap; leave it blank if you don't.
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
        'draft' => 'Draft',
        'pending', 'pending_approval' => 'Pending',
        'approved' => 'Approved',
        'executing' => 'Executing',
        'completed' => 'Completed',
        'executed' => 'Executed',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
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
        'label' => 'Batches awaiting your approval',
        'count' => $metrics['pending_approvals'], 'href' => 'batches/index.php?status=pending_approval',
        'cta' => 'Review Now', 'tone' => 'amber',
    ];
}
if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0) {
    $actionItems[] = [
        'label' => 'Approved batches ready to disburse',
        'count' => $metrics['approved_for_disbursement'], 'href' => 'batches/index.php?status=approved',
        'cta' => 'Disburse Now', 'tone' => 'green',
    ];
}
if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) {
    $actionItems[] = [
        'label' => 'Source accounts awaiting confirmation',
        'count' => $metrics['pending_source_confirmations'], 'href' => 'imports/add_source.php',
        'cta' => 'Confirm Now', 'tone' => 'amber',
    ];
}
if (($metrics['rejected_batches'] ?? 0) > 0 && ($canCreate || $isSupervisor)) {
    $actionItems[] = [
        'label' => 'Rejected batches that may need correction',
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
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => 'index.php', 'show' => true],
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

$basePath = '';
$currentNavKey = 'dashboard';
$topbarSearchShow = $canTrace;
$topbarSearchAction = 'index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';
$topbarSearchValue = $traceQuery;
$attentionHref = '#attention';
$attentionActive = !empty($actionItems);

// ============================================================
// FIX: role-specific info panel — ONE slot, not a stack. Previously
// every eligible info-panel rendered independently (a role that
// matched several conditions — e.g. an Approver who is also mid-setup
// — could see 2-3 stacked, each adding its own margin/padding block to
// the page). Priority order below picks the single most relevant one.
// Colors now reference existing shell.css tokens throughout — the old
// Loader panel hardcoded #3b82f6, which was never a real token and
// didn't participate in the mono/dark-mode palette.
// ============================================================
$roleInfoPanel = null;
if ($isReadOnly) {
    $roleInfoPanel = [
        'label' => 'Read-Only Access',
        'desc'  => 'You have ' . ($userRole === 'auditor' ? 'auditor' : 'read-only') . ' access. You can view and export data but cannot create or modify any records.' . ($userRole === 'auditor' ? ' This is for compliance and audit purposes.' : ''),
        'accent' => 'var(--brass)', 'tint' => 'var(--brass-tint)',
    ];
} elseif ($isLoader) {
    $roleInfoPanel = [
        'label' => 'Loader Access',
        'desc'  => 'You can create and upload new disbursement batches (use Create Batch in the sidebar). Once created, they will be sent for approval.' . (!$setupReady ? ' Waiting on your Owner to finish setup first.' : ''),
        'accent' => 'var(--ink-500)', 'tint' => 'var(--blue-tint)',
    ];
} elseif ($isApprover) {
    $roleInfoPanel = [
        'label' => 'Approver Access',
        'desc'  => 'You can review and approve pending disbursement batches.' . (($metrics['pending_approvals'] ?? 0) > 0 ? ' ' . $metrics['pending_approvals'] . ' batches awaiting your review — see Needs Your Attention above.' : ''),
        'accent' => 'var(--amber)', 'tint' => 'var(--amber-bg)',
    ];
} elseif ($isSupervisor) {
    $roleInfoPanel = [
        'label' => 'Owner Disbursement Access',
        'desc'  => 'You can disburse funds for approved batches. This is the only role that can — it is the final, non-delegable step in the disbursement chain.' . (($metrics['approved_for_disbursement'] ?? 0) > 0 ? ' ' . $metrics['approved_for_disbursement'] . ' batches ready for disbursement — see Needs Your Attention above.' : ''),
        'accent' => 'var(--ledger-green)', 'tint' => 'var(--green-tint)',
    ];
} elseif ($userRole === 'beneficiary_registrar') {
    $roleInfoPanel = [
        'label' => 'Beneficiary Registrar',
        'desc'  => 'You can add and manage beneficiaries for disbursement batches.',
        'accent' => 'var(--ledger-green)', 'tint' => 'var(--green-tint)',
        'cta_href' => 'imports/add_destinations.php', 'cta_label' => 'Add Beneficiaries',
    ];
} elseif ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) {
    $roleInfoPanel = [
        'label' => 'Source Accounts Awaiting Confirmation',
        'desc'  => $metrics['pending_source_confirmations'] . ' source account(s) proposed by Finance are waiting for an Owner or IT Manager to confirm before they can be used in disbursements — see Needs Your Attention above, or Source Accounts in the sidebar.',
        'accent' => 'var(--amber)', 'tint' => 'var(--amber-bg)',
    ];
} elseif ($userRole === 'finance_officer') {
    $roleInfoPanel = [
        'label' => 'Finance Officer Access',
        'desc'  => 'You can propose new source accounts for disbursements (Source Accounts in the sidebar). An Owner or IT Manager (not you) must confirm each one before it becomes usable.',
        'accent' => 'var(--brass)', 'tint' => 'var(--brass-tint)',
    ];
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
    <link rel="stylesheet" href="partials/shell.css">
</head>
<body>
    <?php require __DIR__ . '/partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Operational Dashboard</h1>
                    <div class="meta-line">SYSTEM_TIME: <?php echo date('H:i:s'); ?> <?php echo date('T'); ?> · Welcome back, <?php echo safeHtml($fullName); ?></div>
                </div>
            </div>

            <?php if ($roleInfoPanel): ?>
            <div class="info-panel" style="border-left-color: <?php echo $roleInfoPanel['accent']; ?>;">
                <div class="label"><?php echo safeHtml($roleInfoPanel['label']); ?></div>
                <div class="desc">
                    <?php echo safeHtml($roleInfoPanel['desc']); ?>
                    <?php if (!empty($roleInfoPanel['cta_href'])): ?>
                    <a href="<?php echo safeHtml($roleInfoPanel['cta_href']); ?>" class="btn btn-primary btn-sm" style="margin-left:var(--space-3);"><?php echo safeHtml($roleInfoPanel['cta_label']); ?></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================================ -->
            <!-- STAT CARDS — now on the shared 12-col grid in shell.css, so
                 these edges line up with the panel-grid below them. -->
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
                <div class="panel col-12">
                    <div class="panel-head">
                        <span class="title"><?php echo svgIcon('warning'); ?> Needs Your Attention</span>
                        <?php if ($criticalActionCount > 0): ?>
                        <span class="critical-pill"><?php echo $criticalActionCount; ?> <span class="critical-pill-label">CRITICAL</span></span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body">
                        <?php if (empty($actionItems)): ?>
                        <div class="empty-row"><?php echo ($canApprove || $canDisburse || $canConfirmSource) ? 'All clear — nothing is waiting on you right now.' : 'Nothing needs your attention right now.'; ?></div>
                        <?php else: foreach ($actionItems as $item): ?>
                        <div class="task-row">
                            <span class="dot <?php echo $item['tone']; ?>"></span>
                            <div class="body">
                                <div class="top-line">
                                    <span class="label"><?php echo safeHtml($item['label']); ?></span>
                                </div>
                                <div class="cta"><a href="<?php echo safeHtml($item['href']); ?>" class="btn-mini"><?php echo safeHtml($item['cta']); ?> (<?php echo (int)$item['count']; ?>)</a></div>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- Recent Activity — demoted from its own peer-weight box to a
                         disclosure inside this panel. It's reference material, not
                         something requiring action, so it shouldn't compete visually
                         with the list above it. -->
                    <details class="disclosure" style="margin: 0; padding: var(--space-3) var(--space-4); border-top: 1px solid var(--ink-900);">
                        <summary><?php echo svgIcon('clock'); ?> Recent Activity</summary>
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
                    </details>

                    <div class="panel-foot"><a href="batches/index.php?status=all">View All Batches <?php echo svgIcon('arrow'); ?></a></div>
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

            <!-- Recent Batches — top 5 only, capped to an internal scroll
                 area so this section's height is predictable regardless of
                 row count; "View More" goes to the full, filterable list.
                 The header used to also carry its own "View All" and "New
                 Batch" buttons, both duplicating a sidebar/attention-panel
                 action already on this page. -->
            <?php $recentBatchesShown = array_slice($recentBatches, 0, 5); ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Batches</span>
                </div>
                <?php if (empty($recentBatchesShown)): ?>
                <div class="empty-state">
                    <p>No batches found. Create your first disbursement batch to get started.</p>
                    <?php if ($canCreate && !$setupReady): ?><p style="font-size:12px; color:var(--ink-300); margin-top:var(--space-2);">Waiting on your Owner to finish setup (team, department, source account).</p><?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
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
                <!-- FIX: was "margin: 16px -24px -20px" — a hardcoded hack tuned
                     to the OLD .card padding of 20px/24px (asymmetric). .card
                     padding is now var(--space-5) = 24px on ALL sides, so the
                     cancelling margin must be -24px on every side too. -->
                <div class="panel-foot" style="margin: var(--space-4) calc(var(--space-5) * -1) calc(var(--space-5) * -1);"><a href="batches/index.php?status=all">View More <?php echo svgIcon('arrow'); ?></a></div>
            </div>

            <!-- Payment Trace results (search lives in the topbar; results render here) -->
            <?php if ($canTrace && $traceQuery !== ''): ?>
            <div class="card" id="trace">
                <div class="card-header"><span class="card-title">Trace Results for "<?php echo safeHtml($traceQuery); ?>"</span></div>
                <?php if (empty($traceBatches) && empty($traceBeneficiaries)): ?>
                <div class="empty-state"><p>No matches for "<?php echo safeHtml($traceQuery); ?>".</p></div>
                <?php endif; ?>
                <?php if (!empty($traceBatches)): ?>
                <div class="table-responsive" style="margin-bottom:var(--space-4);">
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
        <?php
        $footerStatusLine = '';
        require __DIR__ . '/partials/shell-foot.php';
        ?>
</body>
</html>
