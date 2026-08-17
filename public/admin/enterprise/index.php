<?php
/**
 * enterprise/index.php - VouchMorph Enterprise Client Dashboard
 * Professional 2-column grid layout with perfect alignment
 */

// ============================================================
// SESSION SETUP
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
// SETUP CHECK
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
            display: inline-flex; align-items: center; justify-content: center;
            height: var(--h-control); min-width: 96px; padding: 0 var(--space-4);
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
        @media (max-width: 600px) {
            .wrap { padding: var(--space-4); }
            .step { flex-direction: column; }
            .btn { min-width: 100%; justify-content: center; }
        }
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
        <p class="sub">A few things need to be in place before disbursements can begin.</p>

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
            A department without a budget set means it's limited only by the real balance of its source account at the moment funds move.
        </div>
    </div>
</body>
</html>
    <?php
}

// ============================================================
// ROLE PERMISSIONS
// ============================================================
$scopableOversightRoles = ['owner', 'approver', 'senior_approver'];
$userDeptScopeIds = in_array($userRole, $scopableOversightRoles, true)
    ? $deptService->getDepartmentScopeIds($departmentId)
    : null;

function departmentScopeSql(?array $scopeIds, array &$params, string $prefix = 'sdep'): string {
    if ($scopeIds === null) return '';
    if (empty($scopeIds)) return ' AND 1=0';
    $placeholders = [];
    foreach (array_values($scopeIds) as $i => $id) {
        $key = ":{$prefix}{$i}";
        $placeholders[] = $key;
        $params[$key] = $id;
    }
    return ' AND department_id IN (' . implode(',', $placeholders) . ')';
}

$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head']);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise']);
$canDisburse = ($userRole === 'owner');
$isSupervisor = ($userRole === 'owner');
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise']);
$canViewAll = in_array($userRole, ['owner', 'auditor', 'it_manager_enterprise', 'it_officer_enterprise']);
$isReadOnly = in_array($userRole, ['auditor', 'viewer']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);
$canProposeSource = in_array($userRole, ['finance_officer', 'owner']);
$canConfirmSource = in_array($userRole, ['owner', 'it_manager_enterprise']);
$canManageSourceAccounts = $canProposeSource || $canConfirmSource;
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer']);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer']);
$canManageDepartments = $isTopRole;
$isDepartmentHead = ($userRole === 'department_head');

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
$orgData = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, tax_id, registration_number, country_code, default_currency, status, logo_url, created_at FROM organizations WHERE id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $orgData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Org fetch error: " . $e->getMessage());
}
$orgCurrency = $orgData['default_currency'] ?? 'BWP';

$metrics = [];
$recentBatches = [];

try {
    $params = [':org_id' => $orgId];

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id");
    $stmt->execute($params);
    $metrics['total_batches'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM disbursement_batches WHERE organization_id = :org_id GROUP BY status");
    $stmt->execute($params);
    $batchStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $status = strtolower($row['status']);
        $batchStatus[$status] = $row['count'];
    }

    $metrics['pending_batches'] = ($batchStatus['pending'] ?? 0) + ($batchStatus['pending_approval'] ?? 0);
    $metrics['approved_batches'] = $batchStatus['approved'] ?? 0;
    $metrics['executed_batches'] = ($batchStatus['executed'] ?? 0) + ($batchStatus['completed'] ?? 0);
    $metrics['rejected_batches'] = $batchStatus['rejected'] ?? 0;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) as total FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')");
    $stmt->execute($params);
    $metrics['total_disbursed'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM organization_beneficiaries WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_beneficiaries'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM organization_users WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_users'] = (int)$stmt->fetchColumn();

    if ($canDisburse) {
        $adfParams = [':org_id' => $orgId];
        $adfScopeSql = departmentScopeSql($userDeptScopeIds, $adfParams, 'adf');
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id AND status = 'approved' $adfScopeSql");
        $stmt->execute($adfParams);
        $metrics['approved_for_disbursement'] = (int)$stmt->fetchColumn();
    }

    $papParams = [':org_id' => $orgId];
    $papScopeSql = departmentScopeSql($userDeptScopeIds, $papParams, 'pap');
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending', 'pending_approval', 'PENDING', 'PENDING_APPROVAL') $papScopeSql");
    $stmt->execute($papParams);
    $metrics['pending_approvals'] = (int)$stmt->fetchColumn();

    if ($canConfirmSource) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
            $stmt->execute([':org_id' => $orgId]);
            $metrics['pending_source_confirmations'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("[ENTERPRISE DASHBOARD] Source metrics error: " . $e->getMessage());
            $metrics['pending_source_confirmations'] = 0;
        }
    }

    $statusFilter = "";
    $statusParams = [':org_id' => $orgId];

    if ($isTopRole) {
        if ($userRole === 'it_manager_enterprise' || $userDeptScopeIds === null) {
            $statusFilter = "AND 1=1";
        } else {
            $statusFilter = "AND 1=1" . departmentScopeSql($userDeptScopeIds, $statusParams, 'own');
        }
    } elseif ($isReadOnly) {
        $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
    } elseif ($isApprover) {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')" . departmentScopeSql($userDeptScopeIds, $statusParams, 'apr');
    } elseif ($userRole === 'finance_officer') {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'completed', 'executed', 'PENDING', 'PENDING_APPROVAL', 'APPROVED', 'COMPLETED', 'EXECUTED')";
    } elseif ($isLoader) {
        $statusFilter = "AND (created_by = :user_id OR (department_id = :department_id AND status IN ('pending', 'pending_approval', 'approved', 'draft')))";
        $statusParams[':user_id'] = $userId;
        $statusParams[':department_id'] = $departmentId;
    } else {
        $statusFilter = "AND 1=0";
    }

    $stmt = $pdo->prepare("
        SELECT id, batch_reference, batch_name, source_institution, total_amount, total_destinations, status, created_at, updated_at, created_by
        FROM disbursement_batches
        WHERE organization_id = :org_id $statusFilter
        ORDER BY CASE
            WHEN status IN ('pending', 'pending_approval') THEN 1
            WHEN status = 'approved' THEN 2
            WHEN status = 'draft' THEN 3
            ELSE 4
        END, created_at DESC
        LIMIT 15
    ");
    $stmt->execute($statusParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys(['total_batches', 'pending_batches', 'approved_batches', 'executed_batches', 'total_disbursed', 'total_beneficiaries', 'total_users', 'pending_approvals'], 0);
    $recentBatches = [];
}

// MTD Disbursed
$mtdDisbursed = 0.0;
$disbursedDeltaPct = null;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount) FILTER (WHERE created_at >= date_trunc('month', CURRENT_DATE)), 0) AS mtd,
               COALESCE(SUM(total_amount) FILTER (WHERE created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month') AND created_at < date_trunc('month', CURRENT_DATE)), 0) AS last_month
        FROM disbursement_batches
        WHERE organization_id = :org_id AND LOWER(status) IN ('completed', 'executed')
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

// Active batches
$metrics['active_batches'] = 0;
$metrics['executing_batches'] = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE LOWER(status) IN ('draft','pending','pending_approval','approved','executing')) AS active,
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

// Average clearance time
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

// Recent Activity
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
        'BUDGET_OVERRUN_RACE_DETECTED' => 'Budget overrun flagged',
    ];
    return $labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
}

// Trace
$traceQuery = trim($_GET['trace'] ?? '');
$traceBatches = [];
$traceBeneficiaries = [];
if ($canTrace && $traceQuery !== '') {
    $likeQ = '%' . $traceQuery . '%';
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, batch_name, source_institution, total_amount, total_destinations, status, created_at, updated_at
            FROM disbursement_batches
            WHERE organization_id = :org_id AND (batch_reference ILIKE :q OR to_jsonb(disbursement_batches.*)::text ILIKE :q)
            ORDER BY created_at DESC LIMIT 10
        ");
        $stmt->execute([':org_id' => $orgId, ':q' => $likeQ]);
        $traceBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("[ENTERPRISE DASHBOARD] Trace batch error: " . $e->getMessage());
    }
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM organization_beneficiaries
            WHERE organization_id = :org_id AND is_active = true AND to_jsonb(organization_beneficiaries.*)::text ILIKE :q
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
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager',
        'it_officer_enterprise' => 'IT Officer', 'it_support' => 'IT Support',
        'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver',
        'senior_approver' => 'Senior Approver', 'supervisor' => 'Supervisor',
        'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor',
        'viewer' => 'Viewer'
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Action Items
$actionItems = [];
if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0) {
    $actionItems[] = [
        'label' => 'Batches awaiting your approval',
        'count' => $metrics['pending_approvals'],
        'href' => 'batches/index.php?status=pending_approval',
        'cta' => 'Review Now', 'tone' => 'amber',
    ];
}
if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0) {
    $actionItems[] = [
        'label' => 'Approved batches ready to disburse',
        'count' => $metrics['approved_for_disbursement'],
        'href' => 'batches/index.php?status=approved',
        'cta' => 'Disburse Now', 'tone' => 'green',
    ];
}
if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) {
    $actionItems[] = [
        'label' => 'Source accounts awaiting confirmation',
        'count' => $metrics['pending_source_confirmations'],
        'href' => 'imports/add_source.php',
        'cta' => 'Confirm Now', 'tone' => 'amber',
    ];
}
if (($metrics['rejected_batches'] ?? 0) > 0 && ($canCreate || $isSupervisor)) {
    $actionItems[] = [
        'label' => 'Rejected batches needing correction',
        'count' => $metrics['rejected_batches'],
        'href' => 'batches/index.php?status=rejected',
        'cta' => 'Review', 'tone' => 'danger',
    ];
}
$criticalActionCount = count(array_filter($actionItems, fn($item) => $item['tone'] === 'danger'));

// ============================================================
// SIDEBAR NAV
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
$topbarSearchPlaceholder = 'Search batch, phone, ID…';
$topbarSearchValue = $traceQuery;
$attentionHref = '#attention';
$attentionActive = !empty($actionItems);

// Role info panel
$roleInfoPanel = null;
if ($isReadOnly) {
    $roleInfoPanel = [
        'label' => 'Read-Only Access',
        'desc' => 'You have ' . ($userRole === 'auditor' ? 'auditor' : 'read-only') . ' access. View and export data only.',
        'accent' => 'var(--brass)',
    ];
} elseif ($isLoader) {
    $roleInfoPanel = [
        'label' => 'Loader Access',
        'desc' => 'Create and upload disbursement batches for approval.' . (!$setupReady ? ' Waiting on setup.' : ''),
        'accent' => 'var(--ink-500)',
    ];
} elseif ($isApprover) {
    $roleInfoPanel = [
        'label' => 'Approver Access',
        'desc' => 'Review and approve pending batches.' . (($metrics['pending_approvals'] ?? 0) > 0 ? ' ' . $metrics['pending_approvals'] . ' awaiting review.' : ''),
        'accent' => 'var(--amber)',
    ];
} elseif ($isSupervisor) {
    $roleInfoPanel = [
        'label' => 'Owner Access',
        'desc' => 'Disburse funds for approved batches.' . (($metrics['approved_for_disbursement'] ?? 0) > 0 ? ' ' . $metrics['approved_for_disbursement'] . ' ready.' : ''),
        'accent' => 'var(--ledger-green)',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · <?php echo safeHtml($orgName); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="partials/shell.css">
</head>
<body>
    <?php require __DIR__ . '/partials/shell-head.php'; ?>
    
    <!-- HEADER -->
    <div class="page-header">
        <div>
            <h1>Operational Dashboard</h1>
            <div class="meta-line">Welcome back, <?php echo safeHtml($fullName); ?> · <?php echo date('H:i'); ?> <?php echo date('T'); ?></div>
        </div>
        <?php if ($canCreate && $setupReady): ?>
        <div class="page-header-actions">
            <a href="imports/source_input.php" class="btn btn-primary"><?php echo svgIcon('plus'); ?> New Batch</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- ROLE INFO -->
    <?php if ($roleInfoPanel): ?>
    <div class="info-panel" style="border-left-color: <?php echo $roleInfoPanel['accent']; ?>;">
        <div class="label"><?php echo safeHtml($roleInfoPanel['label']); ?></div>
        <div class="desc"><?php echo safeHtml($roleInfoPanel['desc']); ?></div>
    </div>
    <?php endif; ?>

    <!-- STAT CARDS - 3 across -->
    <div class="stat-grid">
        <div class="stat-card accent-green">
            <div class="stat-label">Total Disbursed (MTD)</div>
            <div class="stat-value"><span class="cur"><?php echo safeHtml($orgCurrency); ?></span> <?php echo number_format($mtdDisbursed, 2); ?></div>
            <?php if ($disbursedDeltaPct !== null): ?>
            <div class="stat-sub <?php echo $disbursedDeltaPct >= 0 ? 'up' : 'down'; ?>"><?php echo $disbursedDeltaPct >= 0 ? '↗' : '↘'; ?> <?php echo abs($disbursedDeltaPct); ?>% vs last month</div>
            <?php else: ?>
            <div class="stat-sub">No prior-month data</div>
            <?php endif; ?>
        </div>

        <div class="stat-card">
            <div class="stat-label">Active Batches</div>
            <div class="stat-value"><?php echo number_format($metrics['active_batches'] ?? 0); ?></div>
            <div class="stat-sub"><?php echo (int)($metrics['executing_batches'] ?? 0); ?> executing now</div>
        </div>

        <div class="stat-card <?php echo ($metrics['pending_approvals'] ?? 0) > 0 ? 'accent-danger' : ''; ?>">
            <div class="stat-label">Pending Approvals</div>
            <div class="stat-value"><?php echo number_format($metrics['pending_approvals'] ?? 0); ?></div>
            <div class="stat-sub"><?php echo $avgClearanceHours !== null ? 'Avg clearance: ' . $avgClearanceHours . ' hrs' : 'No recent approvals'; ?></div>
        </div>
    </div>

    <!-- TWO-COLUMN LAYOUT: RECENT BATCHES + RECENT ACTIVITY -->
    <div class="panel-grid" id="attention">
        <!-- LEFT COLUMN: Needs Your Attention + Recent Batches -->
        <div class="col-7">
            <!-- Needs Your Attention -->
            <div class="panel" style="margin-bottom:var(--space-4);">
                <div class="panel-head">
                    <span class="title"><?php echo svgIcon('warning'); ?> Needs Your Attention</span>
                    <?php if ($criticalActionCount > 0): ?>
                    <span class="critical-pill"><?php echo $criticalActionCount; ?> <span class="critical-pill-label">CRITICAL</span></span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <?php if (empty($actionItems)): ?>
                    <div class="empty-row">All clear — nothing needs your attention.</div>
                    <?php else: foreach ($actionItems as $item): ?>
                    <div class="task-row">
                        <span class="dot <?php echo $item['tone']; ?>"></span>
                        <div class="body">
                            <div class="top-line">
                                <span class="label"><?php echo safeHtml($item['label']); ?></span>
                                <span class="when"><?php echo (int)$item['count']; ?> items</span>
                            </div>
                            <div class="cta">
                                <a href="<?php echo safeHtml($item['href']); ?>" class="btn btn-sm btn-outline"><?php echo safeHtml($item['cta']); ?></a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Recent Batches -->
            <?php $recentBatchesShown = array_slice($recentBatches, 0, 5); ?>
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <span class="card-title">Recent Batches</span>
                    <?php if ($canCreate && $setupReady): ?>
                    <div class="card-actions">
                        <a href="imports/source_input.php" class="btn btn-sm btn-primary">New</a>
                        <a href="batches/index.php?status=all" class="btn btn-sm btn-outline">View All</a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (empty($recentBatchesShown)): ?>
                <div class="empty-state">
                    <p>No batches found. Create your first batch to get started.</p>
                    <?php if ($canCreate && !$setupReady): ?>
                    <p style="font-size:12px;color:var(--ink-300);margin-top:var(--space-2);">Waiting on setup.</p>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Name</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentBatchesShown as $batch): ?>
                        <tr>
                            <td><strong><?php echo safeHtml($batch['batch_reference']); ?></strong></td>
                            <td><?php echo safeHtml($batch['batch_name'] ?? '—'); ?></td>
                            <td><?php echo formatCurrency($batch['total_amount'] ?? 0, $orgCurrency); ?></td>
                            <td><span class="status status-<?php echo getStatusClass($batch['status']); ?>"><?php echo getStatusLabel($batch['status']); ?></span></td>
                            <td><a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-sm btn-outline">Open</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT COLUMN: Recent Activity -->
        <div class="col-5">
            <div class="panel" style="height:100%;">
                <div class="panel-head">
                    <span class="title"><?php echo svgIcon('clock'); ?> Recent Activity</span>
                </div>
                <div class="panel-body">
                    <?php if (empty($recentActivity)): ?>
                    <div class="empty-row">No recorded activity yet.</div>
                    <?php else: ?>
                    <div style="padding:var(--space-2) 0;">
                        <?php foreach ($recentActivity as $ev): ?>
                        <div class="task-row" style="grid-template-columns:1fr;padding:var(--space-3) var(--space-4);">
                            <div class="body">
                                <div class="top-line" style="flex-wrap:wrap;">
                                    <span style="font-weight:600;font-size:13px;"><?php echo safeHtml(getActivityLabel($ev['action'])); ?></span>
                                    <span style="font-family:var(--f-mono);font-size:10.5px;color:var(--ink-300);white-space:nowrap;">
                                        <?php echo date('Y-m-d H:i', strtotime($ev['created_at'])); ?>
                                    </span>
                                </div>
                                <div style="font-size:12px;color:var(--ink-500);margin-top:2px;">
                                    <?php echo $ev['actor_name'] ? safeHtml($ev['actor_name']) : 'System'; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="panel-foot">
                    <a href="audit_log.php">View Full Audit Log <?php echo svgIcon('arrow'); ?></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Trace -->
    <?php if ($canTrace && $traceQuery !== ''): ?>
    <div class="card" id="trace" style="margin-top:var(--space-4);">
        <div class="card-header">
            <span class="card-title">Trace Results for "<?php echo safeHtml($traceQuery); ?>"</span>
        </div>
        <?php if (empty($traceBatches) && empty($traceBeneficiaries)): ?>
        <div class="empty-state"><p>No matches found.</p></div>
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
                    <td><a href="imports/review_batch.php?batch_id=<?php echo $b['id']; ?>" class="btn btn-sm btn-outline">Open</a></td>
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
                <tr><?php foreach ($row as $col => $val): if ($col === 'organization_id') continue; $s = is_array($val) ? json_encode($val) : (string)$val; ?><td><?php echo safeHtml(strlen($s) > 40 ? substr($s, 0, 40) . '…' : $s); ?></td><?php endforeach; ?></tr>
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
