<?php
/**
 * enterprise/index.php — VouchMorph Enterprise Dashboard
 * CENTER-STAGE rebuild: one hub of press-tiles, one full-screen
 * "stage" per activity (Attention / Batches / Activity / Trace),
 * a header that never moves, a footer that never moves. See
 * partials/shell.css for the full design-system writeup.
 */

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
$basePath = '';

$deptService = new DepartmentService($pdo);

function safeHtml($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function formatCurrency($amount, $currency = 'BWP') { return number_format((float)$amount, 2) . ' ' . $currency; }
function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support', 'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor', 'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor', 'viewer' => 'Viewer',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
function getStatusClass($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'draft', 'pending', 'pending_approval' => 'pending', 'approved' => 'approved',
        'executing' => 'pending', 'completed', 'executed' => 'completed', 'rejected', 'cancelled' => 'rejected',
        default => 'draft'
    };
}
function getStatusLabel($status) {
    $status = strtolower($status);
    return match($status) {
        'draft' => 'Draft', 'pending', 'pending_approval' => 'Pending', 'approved' => 'Approved',
        'executing' => 'Executing', 'completed' => 'Completed', 'executed' => 'Executed',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled', default => ucfirst($status)
    };
}

// ============================================================
// SETUP CHECK
// ============================================================
$setupChecklist = new SetupChecklistService($pdo);
$setupStatus = $setupChecklist->getStatus((int)$orgId);
$setupReady = $setupStatus['ready_for_batches'];

if ($userRole === 'owner' && !$setupReady) {
    renderSetupWizard($orgName, $fullName, $userRole, $setupStatus, $basePath);
    exit;
}

function renderSetupWizard(string $orgName, string $fullName, string $userRole, array $setupStatus, string $basePath): void {
    $steps = $setupStatus['steps'];
    $doneCount = count(array_filter($steps, fn($s) => $s['done']));
    $totalCount = count($steps);
    $pct = $totalCount > 0 ? round(($doneCount / $totalCount) * 100) : 0;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH &middot; Set Up &middot; <?php echo safeHtml($orgName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="partials/shell.css">
</head>
<body>
<?php $backHref = null; require __DIR__ . '/partials/shell-head.php'; ?>
    <div class="stage-view active">
        <div class="stage-head">
            <div>
                <div class="stage-eyebrow">Getting started</div>
                <div class="stage-title">Set up <?php echo safeHtml($orgName); ?></div>
                <div class="stage-meta"><?php echo $doneCount; ?> of <?php echo $totalCount; ?> steps complete &middot; disbursements unlock once every step below is done</div>
            </div>
        </div>
        <div class="stat-card" style="margin-bottom:var(--u4);">
            <div style="display:flex;height:var(--u2);border:var(--border) solid var(--ink);">
                <div style="width:<?php echo $pct; ?>%;background:var(--sky);"></div>
            </div>
        </div>
        <?php foreach ($steps as $i => $step): ?>
        <div class="card" style="<?php echo $step['done'] ? 'background:var(--sky-tint);' : ''; ?>">
            <div style="display:flex;gap:var(--u3);align-items:flex-start;">
                <div style="width:40px;height:40px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-family:var(--f-display);font-weight:700;background:<?php echo $step['done'] ? 'var(--ink)' : 'var(--paper)'; ?>;color:<?php echo $step['done'] ? 'var(--sky)' : 'var(--ink)'; ?>;border:var(--border) solid var(--ink);">
                    <?php echo $step['done'] ? '&#10003;' : ($i + 1); ?>
                </div>
                <div style="flex:1;">
                    <div class="card-title" style="border:none;padding:0;margin:0;display:flex;gap:var(--u2);align-items:center;">
                        <?php echo safeHtml($step['label']); ?>
                        <?php if ($step['done']): ?><span class="status status-completed">Done</span><?php endif; ?>
                    </div>
                    <div style="font-size:12.5px;opacity:0.7;margin:var(--u1) 0 var(--u2);"><?php echo safeHtml($step['description']); ?></div>
                    <?php if ($step['done']): ?>
                        <div class="stat-sub" style="border:none;padding:0;margin-bottom:var(--u2);"><?php echo (int)$step['count']; ?> on record</div>
                        <a href="<?php echo safeHtml($step['action_href']); ?>" class="btn btn-secondary btn-sm">Manage &rsaquo;</a>
                    <?php else: ?>
                        <a href="<?php echo safeHtml($step['action_href']); ?>" class="btn btn-primary btn-sm"><?php echo safeHtml($step['action_label']); ?> &rsaquo;</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="banner">
            <div class="lbl">Heads up</div>
            <div class="desc">A department without a budget set is limited only by the real balance of its source account at the moment funds move.</div>
        </div>
    </div>
<?php $footerNote = "$doneCount of $totalCount setup steps complete"; require __DIR__ . '/partials/shell-foot.php'; ?>
</body>
</html>
    <?php
}

// ============================================================
// ROLE PERMISSIONS
// ------------------------------------------------------------
// This used to be a hardcoded $ROLE_CAPS matrix living in this file
// (and duplicated again in departments/index.php). That was a shadow
// permission system disconnected from the REAL one already built in
// auth.php — hasPermission(), which checks owner bypass, then a
// per-user JSONB override, then the organization_role_permissions
// table. Two sources of truth that could silently disagree is a bad
// place to be for a system that produces audit filings. Fixed: see
// partials/permissions.php, which now wraps the real hasPermission()
// instead of re-deciding access itself.
//
// NOTE 1 — this is still UI visibility, not database-level access
// control. can() returning true means "show this," not "this write
// is safe." Every target page and every mutating query must still
// enforce the same check server-side on its own.
//
// NOTE 2 — segregation of duties: it_manager_enterprise can still
// hold both administrative permissions and act_approve/act_confirm_source
// if your organization_role_permissions table grants it that combination.
// That's now a database configuration question, not something baked
// into this file — which is the correct place for that decision to
// live, but it means fixing it means editing the database, not this code.
// ============================================================
require __DIR__ . '/partials/permissions.php';

// ============================================================
// FETCH DASHBOARD DATA  (unchanged business logic)
// ============================================================
$orgData = [];
try {
    $stmt = $pdo->prepare("SELECT default_currency FROM organizations WHERE id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $orgData = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Org fetch error: " . $e->getMessage()); }
$orgCurrency = $orgData['default_currency'] ?? 'BWP';

$metrics = [];
$recentBatches = [];
try {
    $params = [':org_id' => $orgId];
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM disbursement_batches WHERE organization_id = :org_id GROUP BY status");
    $stmt->execute($params);
    $batchStatus = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $batchStatus[strtolower($row['status'])] = $row['count']; }
    $metrics['pending_batches'] = ($batchStatus['pending'] ?? 0) + ($batchStatus['pending_approval'] ?? 0);
    $metrics['rejected_batches'] = $batchStatus['rejected'] ?? 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM organization_beneficiaries WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $metrics['total_beneficiaries'] = (int)$stmt->fetchColumn();

    if ($canDisburse) {
        $adfParams = [':org_id' => $orgId];
        $adfScopeSql = departmentScopeSqlSingle($userDeptScope, $adfParams, ':dept_adf');
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id AND status = 'approved' $adfScopeSql");
        $stmt->execute($adfParams);
        $metrics['approved_for_disbursement'] = (int)$stmt->fetchColumn();
    }

    $papParams = [':org_id' => $orgId];
    $papScopeSql = departmentScopeSqlSingle($userDeptScope, $papParams, ':dept_pap');
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending', 'pending_approval', 'PENDING', 'PENDING_APPROVAL') $papScopeSql");
    $stmt->execute($papParams);
    $metrics['pending_approvals'] = (int)$stmt->fetchColumn();

    if ($canConfirmSource) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
            $stmt->execute([':org_id' => $orgId]);
            $metrics['pending_source_confirmations'] = (int)$stmt->fetchColumn();
        } catch (PDOException $e) { $metrics['pending_source_confirmations'] = 0; }
    }

    // ============================================================
    // FIX: every branch below now applies departmentScopeSqlSingle()
    // when $userDeptScope is non-null, matching auth.php's real
    // getUserDepartmentScope() rule (only owner/auditor are org-wide).
    // The previous version left 'viewer' and 'finance_officer'
    // completely unscoped — an org-wide read of batch data for two
    // roles auth.php says should be confined to one department each.
    // That's fixed here, not just noted.
    // ============================================================
    $statusFilter = ""; $statusParams = [':org_id' => $orgId];
    if ($isTopRole) {
        // Naturally resolves correctly without special-casing
        // it_manager_enterprise by name: getUserDepartmentScope()
        // only forces org-wide (null) for owner/auditor, but an
        // IT manager with no department_id of their own already
        // comes back null from that function too.
        $statusFilter = "AND 1=1" . departmentScopeSqlSingle($userDeptScope, $statusParams, ':dept_own');
    } elseif ($isReadOnly) {
        $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')" . departmentScopeSqlSingle($userDeptScope, $statusParams, ':dept_ro');
    } elseif ($isApprover) {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')" . departmentScopeSqlSingle($userDeptScope, $statusParams, ':dept_apr');
    } elseif ($userRole === 'finance_officer') {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'completed', 'executed', 'PENDING', 'PENDING_APPROVAL', 'APPROVED', 'COMPLETED', 'EXECUTED')" . departmentScopeSqlSingle($userDeptScope, $statusParams, ':dept_fin');
    } elseif ($isLoader) {
        $statusFilter = "AND (created_by = :user_id OR (department_id = :department_id AND status IN ('pending', 'pending_approval', 'approved', 'draft')))";
        $statusParams[':user_id'] = $userId; $statusParams[':department_id'] = $departmentId;
    } else { $statusFilter = "AND 1=0"; }

    $stmt = $pdo->prepare("
        SELECT id, batch_reference, batch_name, source_institution, total_amount, total_destinations, status, created_at
        FROM disbursement_batches WHERE organization_id = :org_id $statusFilter
        ORDER BY CASE WHEN status IN ('pending', 'pending_approval') THEN 1 WHEN status = 'approved' THEN 2 WHEN status = 'draft' THEN 3 ELSE 4 END, created_at DESC
        LIMIT 30
    ");
    $stmt->execute($statusParams);
    $recentBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[ENTERPRISE DASHBOARD] Metrics error: " . $e->getMessage());
    $metrics = array_fill_keys(['pending_batches', 'rejected_batches', 'total_beneficiaries', 'pending_approvals'], 0);
    $recentBatches = [];
}

$mtdDisbursed = 0.0; $disbursedDeltaPct = null;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount) FILTER (WHERE created_at >= date_trunc('month', CURRENT_DATE)), 0) AS mtd,
               COALESCE(SUM(total_amount) FILTER (WHERE created_at >= date_trunc('month', CURRENT_DATE - INTERVAL '1 month') AND created_at < date_trunc('month', CURRENT_DATE)), 0) AS last_month
        FROM disbursement_batches WHERE organization_id = :org_id AND LOWER(status) IN ('completed', 'executed')
    ");
    $stmt->execute([':org_id' => $orgId]);
    $mtdRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['mtd' => 0, 'last_month' => 0];
    $mtdDisbursed = (float)$mtdRow['mtd'];
    $lastMonthDisbursed = (float)$mtdRow['last_month'];
    if ($lastMonthDisbursed > 0) $disbursedDeltaPct = round((($mtdDisbursed - $lastMonthDisbursed) / $lastMonthDisbursed) * 100, 1);
} catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] MTD metrics error: " . $e->getMessage()); }

$metrics['active_batches'] = 0; $metrics['executing_batches'] = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE LOWER(status) IN ('draft','pending','pending_approval','approved','executing')) AS active,
               COUNT(*) FILTER (WHERE LOWER(status) = 'executing') AS executing
        FROM disbursement_batches WHERE organization_id = :org_id
    ");
    $stmt->execute([':org_id' => $orgId]);
    $activeRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['active' => 0, 'executing' => 0];
    $metrics['active_batches'] = (int)$activeRow['active'];
    $metrics['executing_batches'] = (int)$activeRow['executing'];
} catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Active batch metrics error: " . $e->getMessage()); }

// Restored — present in the original, silently dropped in the first
// center-stage pass.
$avgClearanceHours = null;
if ($canApprove || $canDisburse) {
    try {
        $stmt = $pdo->prepare("
            SELECT AVG(EXTRACT(EPOCH FROM (approved_at - submitted_at)) / 3600.0) AS avg_hours
            FROM disbursement_batches
            WHERE organization_id = :org_id AND approved_at IS NOT NULL AND submitted_at IS NOT NULL
              AND approved_at >= NOW() - INTERVAL '30 days'
        ");
        $stmt->execute([':org_id' => $orgId]);
        $avgHoursRaw = $stmt->fetchColumn();
        $avgClearanceHours = ($avgHoursRaw !== null && $avgHoursRaw !== false) ? round((float)$avgHoursRaw, 1) : null;
    } catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Avg clearance metrics error: " . $e->getMessage()); }
}

$recentActivity = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.action, al.created_at, u.full_name AS actor_name
        FROM organization_audit_logs al LEFT JOIN users u ON al.user_id = u.user_id
        WHERE al.organization_id = :org_id ORDER BY al.created_at DESC LIMIT 30
    ");
    $stmt->execute([':org_id' => $orgId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Recent activity error: " . $e->getMessage()); }

function getActivityLabel(string $action): string {
    $labels = ['APPROVE_BATCH' => 'Batch approved', 'REJECT_BATCH' => 'Batch rejected', 'DEPARTMENT_EDITED' => 'Department updated',
        'DEPARTMENT_ACTIVE' => 'Department reactivated', 'DEPARTMENT_INACTIVE' => 'Department deactivated', 'BUDGET_OVERRUN_RACE_DETECTED' => 'Budget overrun flagged'];
    return $labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
}

$traceQuery = trim($_GET['trace'] ?? '');
$traceBatches = [];
$traceBeneficiaries = [];
if ($canTrace && $traceQuery !== '') {
    $likeQ = '%' . $traceQuery . '%';
    try {
        $stmt = $pdo->prepare("
            SELECT id, batch_reference, batch_name, source_institution, total_amount, status, created_at
            FROM disbursement_batches WHERE organization_id = :org_id AND (batch_reference ILIKE :q OR to_jsonb(disbursement_batches.*)::text ILIKE :q)
            ORDER BY created_at DESC LIMIT 20
        ");
        $stmt->execute([':org_id' => $orgId, ':q' => $likeQ]);
        $traceBatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Trace batch error: " . $e->getMessage()); }
    // Restored — the original searched beneficiaries too, not just
    // batches. Dropped by mistake in the first center-stage pass.
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM organization_beneficiaries
            WHERE organization_id = :org_id AND is_active = true AND to_jsonb(organization_beneficiaries.*)::text ILIKE :q
            ORDER BY id DESC LIMIT 10
        ");
        $stmt->execute([':org_id' => $orgId, ':q' => $likeQ]);
        $traceBeneficiaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { error_log("[ENTERPRISE DASHBOARD] Trace beneficiary error: " . $e->getMessage()); }
}

// ============================================================
// REPORTS & FILINGS — accountability data.
// ------------------------------------------------------------
// Only queried at all when the role can see it. Report rows are
// scoped the same way batch rows are ($userDeptScope) for any
// role that carries a department scope — a Senior Approver's
// filing export covers their own department, not the whole
// organization, exactly like their Batches view already does.
//
// KNOWN GAP, flag this to your backend team: this queries
// organization_audit_logs on the ENTERPRISE database ($pdo) —
// the lightweight admin-action log this dashboard already used.
// It is a different table from the hash-chained financial ledger
// (audit_logs, with entry_hash/prev_hash chaining and an
// audit_log_failures fallback table) that lives on the swap
// engine's own connection. For a filing that needs to survive a
// public inquiry, you almost certainly want BOTH: this table for
// who-clicked-what in the back office, and that one for the
// tamper-evident record of money actually moving. I have not
// wired them together here since I don't know whether they share
// a database connection in your infrastructure — that's a real
// integration task, not something to fake with a UI label.
// ============================================================
$reportType = $_GET['report'] ?? 'register';
$reportFrom = $_GET['report_from'] ?? date('Y-m-01');
$reportTo = $_GET['report_to'] ?? date('Y-m-d');
$reportRegister = [];
$reportAuditTrail = [];
$reportExceptions = [];
$reportDeptSummary = [];
if ($canViewReports) {
    try {
        $rParams = [':org_id' => $orgId, ':from' => $reportFrom, ':to' => $reportTo . ' 23:59:59'];
        $rScope = departmentScopeSqlSingle($userDeptScope, $rParams, ':dept_rep');

        if ($reportType === 'register') {
            $stmt = $pdo->prepare("
                SELECT batch_reference, batch_name, source_institution, total_amount, total_destinations, status, created_at, created_by
                FROM disbursement_batches
                WHERE organization_id = :org_id AND created_at BETWEEN :from AND :to $rScope
                ORDER BY created_at DESC
            ");
            $stmt->execute($rParams);
            $reportRegister = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($reportType === 'audit') {
            $stmt = $pdo->prepare("
                SELECT al.action, al.entity_type, al.entity_id, al.created_at, u.full_name AS actor_name
                FROM organization_audit_logs al LEFT JOIN users u ON al.user_id = u.user_id
                WHERE al.organization_id = :org_id AND al.created_at BETWEEN :from AND :to
                ORDER BY al.created_at DESC LIMIT 2000
            ");
            $stmt->execute([':org_id' => $orgId, ':from' => $reportFrom, ':to' => $reportTo . ' 23:59:59']);
            $reportAuditTrail = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($reportType === 'exceptions') {
            $stmt = $pdo->prepare("
                SELECT batch_reference, batch_name, source_institution, total_amount, status, created_at
                FROM disbursement_batches
                WHERE organization_id = :org_id AND LOWER(status) IN ('rejected','cancelled','failed')
                  AND created_at BETWEEN :from AND :to $rScope
                ORDER BY created_at DESC
            ");
            $stmt->execute($rParams);
            $reportExceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($reportType === 'departments') {
            $stmt = $pdo->prepare("
                SELECT COALESCE(d.name, 'Unassigned') AS department_name, COUNT(*) AS batch_count, COALESCE(SUM(b.total_amount), 0) AS total_amount
                FROM disbursement_batches b LEFT JOIN departments d ON d.id = b.department_id
                WHERE b.organization_id = :org_id AND b.created_at BETWEEN :from AND :to $rScope
                GROUP BY d.name ORDER BY total_amount DESC
            ");
            $stmt->execute($rParams);
            $reportDeptSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("[ENTERPRISE DASHBOARD] Report query error ({$reportType}): " . $e->getMessage());
    }
}

// Action items feeding the Attention stage
// tone restored from the original ($item['tone']) — amber = waiting on
// you, green = good news / ready to act, danger = needs correction.
// Dropped by mistake in the first center-stage pass, which flattened
// every item to the same dot.
$actionItems = [];
if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0) $actionItems[] = ['key' => 'approvals', 'label' => 'Batches awaiting your approval', 'desc' => 'Each of these has cleared upload and is waiting on a decision before it can move to disbursement. Nothing here has been rejected — they simply haven\'t been looked at yet.', 'count' => $metrics['pending_approvals'], 'href' => 'batches/index.php?status=pending_approval', 'cta' => 'Review now', 'tone' => 'amber'];
if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0) $actionItems[] = ['key' => 'disburse', 'label' => 'Approved batches ready to disburse', 'desc' => 'Fully approved and held for the final release step. This is the only action on this list that actually moves money.', 'count' => $metrics['approved_for_disbursement'], 'href' => 'batches/index.php?status=approved', 'cta' => 'Disburse now', 'tone' => 'green'];
if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) $actionItems[] = ['key' => 'sources', 'label' => 'Source accounts awaiting confirmation', 'desc' => 'Newly linked source accounts sit here until someone confirms ownership. Batches can\'t draw from an unconfirmed source.', 'count' => $metrics['pending_source_confirmations'], 'href' => 'imports/add_source.php', 'cta' => 'Confirm now', 'tone' => 'amber'];
if (($metrics['rejected_batches'] ?? 0) > 0 && ($canCreate || $isSupervisor)) $actionItems[] = ['key' => 'rejected', 'label' => 'Rejected batches needing correction', 'desc' => 'Sent back by an approver with a reason attached. These stay off the disbursement path entirely until they\'re corrected and resubmitted.', 'count' => $metrics['rejected_batches'], 'href' => 'batches/index.php?status=rejected', 'cta' => 'Review', 'tone' => 'danger'];
$attentionCount = array_sum(array_column($actionItems, 'count'));
$attentionActive = $attentionCount > 0;
$criticalActionCount = count(array_filter($actionItems, fn($i) => $i['tone'] === 'danger'));
// $canViewAttentionTile now comes from partials/permissions.php —
// no longer redefined here.
$hubHasAnyTile = $canViewAttentionTile || $canViewBatchesTile || $canViewActivityTile || $canTrace
    || $canViewBeneficiariesTile || $canManageDepartments || $isDepartmentHead || $canSeeSourceAccountsArea
    || $canManageUsers || $canViewReports;

// ============================================================
// SIDEBAR NAV — one array, shared with every other page via
// shell-head.php's contract. This is the actual fix for pages
// drifting out of sync with the hub: there is now exactly one
// place that decides what the sidebar contains.
// ============================================================
$navItems = [
    ['key' => 'hub', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => 'index.php', 'show' => true],
    ['key' => 'attention', 'icon' => 'bell', 'label' => 'Attention', 'href' => 'index.php#stage-attention', 'show' => $canViewAttentionTile, 'badge' => $attentionCount > 0 ? $attentionCount : null],
    ['key' => 'batches', 'icon' => 'layers', 'label' => 'Batches', 'href' => 'index.php#stage-batches', 'show' => $canViewBatchesTile],
    ['key' => 'activity', 'icon' => 'history', 'label' => 'Activity', 'href' => 'index.php#stage-activity', 'show' => $canViewActivityTile],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace', 'href' => 'index.php#stage-trace', 'show' => $canTrace],
    ['key' => 'reports', 'icon' => 'file', 'label' => 'Reports', 'href' => 'index.php#stage-reports', 'show' => $canViewReports],
    ['key' => 'departments', 'icon' => 'sitemap', 'label' => 'Departments', 'href' => 'departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'beneficiaries', 'icon' => 'users', 'label' => 'Beneficiaries', 'href' => 'beneficiaries.php', 'show' => $canViewBeneficiariesTile],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => 'imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => ($metrics['pending_source_confirmations'] ?? 0) > 0 ? $metrics['pending_source_confirmations'] : null],
    ['key' => 'team', 'icon' => 'shield', 'label' => 'Team', 'href' => 'settings/users.php', 'show' => $canManageUsers],
];
// index.php's own sub-stages live behind URL hashes on one page, so
// the server can't know which one is "current" the way it can for a
// separate page like departments/index.php — the client-side script
// at the bottom re-highlights the matching sidebar row the moment
// goStage() runs. 'hub' is the honest default for a fresh load.
$currentNavKey = 'hub';
$notificationItems = $actionItems;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VOUCHMORPH &middot; <?php echo safeHtml($orgName); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="partials/shell.css">
</head>
<body>
<?php
// $backHref stays null on the hub itself; each stage below carries
// its own in-page "&larr; Hub" control instead, since — unlike the
// rest of the app — Attention/Batches/Activity/Trace are views
// inside THIS page, not separate page loads. See the JS at the
// bottom for the exact mechanic, copied from the consumer dashboard.
$backHref = null;
require __DIR__ . '/partials/shell-head.php';
?>

    <!-- ============================================================
         HUB — always the entry point. Big press-tiles, one per
         activity. Nothing about any single activity lives here.
         ============================================================ -->
    <div class="stage-view active" id="stage-hub">
        <div class="stage-head">
            <div>
                <div class="stage-eyebrow">Operational dashboard</div>
                <div class="stage-title">Welcome, <?php echo safeHtml(explode(' ', $fullName)[0]); ?></div>
                <div class="stage-meta"><?php echo date('l, j F Y'); ?> &middot; <?php echo date('H:i'); ?> <?php echo date('T'); ?></div>
            </div>
            <?php if ($canCreate && $setupReady): ?>
            <div class="stage-actions"><a href="imports/source_input.php" class="btn btn-primary">+ New batch</a></div>
            <?php endif; ?>
        </div>

        <?php if ($canSeeFinancialStats): ?>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-label">Total disbursed (MTD)</div>
                <div class="stat-value"><span class="cur"><?php echo safeHtml($orgCurrency); ?></span><?php echo number_format($mtdDisbursed, 2); ?></div>
                <div class="stat-sub<?php echo $disbursedDeltaPct !== null ? ' accent' : ''; ?>"><?php echo $disbursedDeltaPct !== null ? ($disbursedDeltaPct >= 0 ? '&#8599; ' : '&#8600; ') . abs($disbursedDeltaPct) . '% vs last month' : 'No prior-month data'; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active batches</div>
                <div class="stat-value"><?php echo number_format($metrics['active_batches'] ?? 0); ?></div>
                <div class="stat-sub"><?php echo (int)($metrics['executing_batches'] ?? 0); ?> executing now</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pending approvals</div>
                <div class="stat-value"><?php echo number_format($metrics['pending_approvals'] ?? 0); ?></div>
                <div class="stat-sub"><?php echo $avgClearanceHours !== null ? 'Avg clearance: ' . $avgClearanceHours . ' hrs' : 'Every one needs a decision'; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$hubHasAnyTile): ?>
        <div class="banner">
            <div class="lbl">Your role has no dashboard sections</div>
            <div class="desc">The <?php echo safeHtml(getRoleLabel($userRole)); ?> role is not assigned any view on this dashboard. If that's wrong, ask an Owner or IT Manager to review your role — not this screen.</div>
        </div>
        <?php endif; ?>

        <div class="tile-grid">
            <?php if ($canViewAttentionTile): ?>
            <button type="button" class="tile" onclick="goStage('attention')">
                <div class="tile-icon"><?php echo svgIcon('bell'); ?></div>
                <?php if ($attentionCount > 0): ?><span class="tile-badge"><?php echo $attentionCount; ?></span><?php endif; ?>
                <div class="tile-label">Needs Attention</div>
                <div class="tile-sub">Approvals, disbursements, and source confirmations waiting on you.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
            <?php if ($canViewBatchesTile): ?>
            <button type="button" class="tile" onclick="goStage('batches')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13"/><path d="M3 10h18"/></svg></div>
                <div class="tile-label">Batches</div>
                <div class="tile-sub">Disbursement batches within your role's scope.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
            <?php if ($canViewActivityTile): ?>
            <button type="button" class="tile" onclick="goStage('activity')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></div>
                <div class="tile-label">Activity</div>
                <div class="tile-sub">The organization's audit trail, most recent first.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
            <?php if ($canTrace): ?>
            <button type="button" class="tile" onclick="goStage('trace')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/></svg></div>
                <div class="tile-label">Trace a payment</div>
                <div class="tile-sub">Search any batch by reference, phone, or ID.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
            <?php if ($canViewReports): ?>
            <button type="button" class="tile" onclick="goStage('reports')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><path d="M6 3h9l5 5v13H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg></div>
                <div class="tile-label">Reports &amp; Filings</div>
                <div class="tile-sub">Accountability exports for oversight, audit, and official filings.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
        </div>

        <?php if ($canViewBeneficiariesTile || $canManageDepartments || $isDepartmentHead || $canSeeSourceAccountsArea || $canManageUsers): ?>
        <div class="tile-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));">
            <?php if ($canViewBeneficiariesTile): ?>
            <a href="beneficiaries.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Beneficiaries</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <?php endif; ?>
            <?php if ($canManageDepartments || $isDepartmentHead): ?>
            <a href="departments/index.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Departments</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <?php endif; ?>
            <?php if ($canSeeSourceAccountsArea): ?>
            <a href="imports/add_source.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Source accounts</div><?php if (($metrics['pending_source_confirmations'] ?? 0) > 0): ?><span class="tile-badge" style="top:var(--u1);right:var(--u1);min-width:20px;height:20px;font-size:10px;"><?php echo (int)$metrics['pending_source_confirmations']; ?></span><?php endif; ?><div class="tile-arrow">Open &rsaquo;</div></a>
            <?php endif; ?>
            <?php if ($canManageUsers): ?>
            <a href="settings/users.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Team</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================
         STAGE — NEEDS ATTENTION (server-side gated, not just hidden)
         List-left / detail-right, per the LeadHive reference: the
         left pane is just a plain selectable list, the right pane
         "pops out" full detail the instant something is selected.
         All the data (label/desc/count/cta/href) already lives in
         data-* attributes rendered server-side — the click handler
         only ever reads the DOM, it never re-fetches anything.
         ============================================================ -->
    <?php if ($canViewAttentionTile): ?>
    <div class="stage-view" id="stage-attention">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage</div><div class="stage-title">Needs Attention</div><div class="stage-meta"><?php echo count($actionItems); ?> item type(s) &middot; <?php echo $attentionCount; ?> total</div></div>
            <div class="stage-actions">
                <?php if ($criticalActionCount > 0): ?><span class="pill-critical"><?php echo $criticalActionCount; ?> Critical</span><?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button>
            </div>
        </div>
        <?php if (empty($actionItems)): ?>
            <div class="empty">All clear — nothing needs your attention right now.</div>
        <?php else: ?>
        <div class="pane-grid">
            <div class="pane-list">
                <div class="pane-list-head">Docket &middot; <?php echo count($actionItems); ?> item type(s)</div>
                <?php foreach ($actionItems as $idx => $item): ?>
                <div class="pane-list-item<?php echo $idx === 0 ? ' selected' : ''; ?>"
                     onclick="selectAttentionItem(this)"
                     data-label="<?php echo safeHtml($item['label']); ?>"
                     data-desc="<?php echo safeHtml($item['desc']); ?>"
                     data-count="<?php echo (int)$item['count']; ?>"
                     data-tone="<?php echo safeHtml($item['tone']); ?>"
                     data-cta="<?php echo safeHtml($item['cta']); ?>"
                     data-href="<?php echo safeHtml($item['href']); ?>">
                    <span class="t"><?php echo safeHtml($item['label']); ?></span>
                    <span class="d"><?php echo (int)$item['count']; ?> item(s) &middot; <?php echo ucfirst(safeHtml($item['tone'])); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <div id="attentionDetail"><!-- filled by JS on load + on click, see bottom script --></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         STAGE — BATCHES (server-side gated)
         ============================================================ -->
    <?php if ($canViewBatchesTile): ?>
    <div class="stage-view" id="stage-batches">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage</div><div class="stage-title">Batches</div><div class="stage-meta"><?php echo count($recentBatches); ?> shown</div></div>
            <div class="stage-actions">
                <?php if ($canCreate && $setupReady): ?><a href="imports/source_input.php" class="btn btn-primary">+ New batch</a><?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button>
            </div>
        </div>
        <div class="field" style="max-width:360px;"><input type="search" id="batchFilterInput" placeholder="Filter by reference, name, source&hellip;" oninput="filterRows('batchRows', this.value)"></div>
        <?php if (empty($recentBatches)): ?>
            <div class="empty">No batches found<?php echo ($canCreate && !$setupReady) ? ' — finish setup (team, department, source account) to create one.' : '.'; ?></div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Reference</th><th>Name</th><th>Source</th><th>Amount</th><th>Dest.</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody id="batchRows">
                <?php foreach ($recentBatches as $batch): ?>
                <tr data-search="<?php echo safeHtml(strtolower(($batch['batch_reference'] ?? '') . ' ' . ($batch['batch_name'] ?? '') . ' ' . ($batch['source_institution'] ?? ''))); ?>">
                    <td><strong><?php echo safeHtml($batch['batch_reference']); ?></strong></td>
                    <td><?php echo safeHtml($batch['batch_name'] ?? '&mdash;'); ?></td>
                    <td><?php echo safeHtml($batch['source_institution'] ?? '&mdash;'); ?></td>
                    <td><?php echo formatCurrency($batch['total_amount'] ?? 0, $orgCurrency); ?></td>
                    <td><?php echo number_format($batch['total_destinations'] ?? 0); ?></td>
                    <td><span class="status status-<?php echo getStatusClass($batch['status']); ?>"><?php echo getStatusLabel($batch['status']); ?></span></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                    <td><a href="imports/review_batch.php?batch_id=<?php echo (int)$batch['id']; ?>" class="btn btn-secondary btn-sm">Open</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div style="text-align:center;margin-top:var(--u3);"><a href="batches/index.php?status=all" class="btn btn-quiet">View the full batch ledger &rsaquo;</a></div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         STAGE — ACTIVITY (server-side gated)
         ============================================================ -->
    <?php if ($canViewActivityTile): ?>
    <div class="stage-view" id="stage-activity">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage</div><div class="stage-title">Activity</div><div class="stage-meta"><?php echo count($recentActivity); ?> entries</div></div>
            <div class="stage-actions"><button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button></div>
        </div>
        <?php if (empty($recentActivity)): ?>
            <div class="empty">No recorded activity yet.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Event</th><th>Actor</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($recentActivity as $ev): ?>
                <tr>
                    <td><?php echo safeHtml(getActivityLabel($ev['action'])); ?></td>
                    <td><?php echo $ev['actor_name'] ? safeHtml($ev['actor_name']) : 'System'; ?></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($ev['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div style="text-align:center;margin-top:var(--u3);"><a href="audit_log.php" class="btn btn-quiet">View the full audit log &rsaquo;</a></div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         STAGE — TRACE A PAYMENT (server-side gated)
         ============================================================ -->
    <?php if ($canTrace): ?>
    <div class="stage-view" id="stage-trace">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage</div><div class="stage-title">Trace a Payment</div><div class="stage-meta">Search across every batch on record</div></div>
            <div class="stage-actions"><button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button></div>
        </div>
        <form method="get" action="index.php#stage-trace" onsubmit="sessionStorage.setItem('vm_stage','trace');">
            <div style="display:flex;gap:var(--u2);max-width:520px;">
                <input type="text" name="trace" placeholder="Batch reference, phone, or ID&hellip;" value="<?php echo safeHtml($traceQuery); ?>" style="flex:1;">
                <button type="submit" class="btn btn-primary">Search</button>
            </div>
        </form>
        <?php if ($traceQuery !== ''): ?>
        <div style="margin-top:var(--u4);">
            <div class="card-title" style="border:none;padding:0;margin-bottom:var(--u2);">Results for &ldquo;<?php echo safeHtml($traceQuery); ?>&rdquo;</div>
            <?php if (empty($traceBatches)): ?>
                <div class="empty">No matches found.</div>
            <?php else: ?>
            <div class="table-wrap">
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
                        <td><a href="imports/review_batch.php?batch_id=<?php echo (int)$b['id']; ?>" class="btn btn-secondary btn-sm">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($traceBeneficiaries)): ?>
        <div style="margin-top:var(--u4);">
            <div class="card-title" style="border:none;padding:0;margin-bottom:var(--u2);">Matching beneficiaries</div>
            <div class="table-wrap">
                <table>
                    <thead><tr><?php foreach (array_keys($traceBeneficiaries[0]) as $col): if ($col === 'organization_id') continue; ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($traceBeneficiaries as $row): ?>
                    <tr><?php foreach ($row as $col => $val): if ($col === 'organization_id') continue; $s = is_array($val) ? json_encode($val) : (string)$val; ?><td><?php echo safeHtml(strlen($s) > 40 ? substr($s, 0, 40) . '&hellip;' : $s); ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         STAGE — REPORTS & FILINGS (server-side gated)
         The accountability surface: every report a role can pull is
         exportable as CSV right now (client-side, no backend needed
         — it reads the table already rendered from real query
         results). "Generate signed filing" calls a backend endpoint
         that does NOT exist yet in this codebase — see the banner.
         ============================================================ -->
    <?php if ($canViewReports): ?>
    <div class="stage-view" id="stage-reports">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage &middot; Accountability</div><div class="stage-title">Reports &amp; Filings</div><div class="stage-meta">Scope: <?php echo $userDeptScope === null ? 'organization-wide' : 'department #' . (int)$userDeptScope . ' only'; ?></div></div>
            <div class="stage-actions"><button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button></div>
        </div>

        <?php if (!$canExportFilings): ?>
        <div class="banner"><div class="lbl">View only</div><div class="desc">Your role can review these reports but cannot generate an official export. Ask an Owner or Auditor to file it.</div></div>
        <?php endif; ?>

        <form method="get" action="index.php#stage-reports" style="margin-bottom:var(--u4);" onsubmit="sessionStorage.setItem('vm_stage','reports');">
            <div style="display:flex;gap:var(--u2);flex-wrap:wrap;align-items:flex-end;">
                <div class="field" style="margin:0;min-width:200px;">
                    <label>Report</label>
                    <select name="report" onchange="this.form.submit()">
                        <option value="register" <?php echo $reportType === 'register' ? 'selected' : ''; ?>>Disbursement register</option>
                        <option value="audit" <?php echo $reportType === 'audit' ? 'selected' : ''; ?>>Audit trail (who did what)</option>
                        <option value="exceptions" <?php echo $reportType === 'exceptions' ? 'selected' : ''; ?>>Rejected / exception batches</option>
                        <option value="departments" <?php echo $reportType === 'departments' ? 'selected' : ''; ?>>Department spend summary</option>
                    </select>
                </div>
                <div class="field" style="margin:0;"><label>From</label><input type="date" name="report_from" value="<?php echo safeHtml($reportFrom); ?>"></div>
                <div class="field" style="margin:0;"><label>To</label><input type="date" name="report_to" value="<?php echo safeHtml($reportTo); ?>"></div>
                <button type="submit" class="btn btn-secondary">Apply</button>
            </div>
        </form>

        <?php if ($reportType === 'register'): ?>
            <div class="card-head" style="border:none;padding:0;"><span class="card-title">Disbursement register &mdash; <?php echo count($reportRegister); ?> batch(es)</span><?php if ($canExportFilings): ?><button type="button" class="btn btn-primary btn-sm" onclick="exportTableCsv('reportTable', 'disbursement-register_<?php echo safeHtml($reportFrom); ?>_to_<?php echo safeHtml($reportTo); ?>.csv')">Export CSV</button><?php endif; ?></div>
            <?php if (empty($reportRegister)): ?><div class="empty">No batches in this range<?php echo $userDeptScope !== null ? ' for your department scope' : ''; ?>.</div><?php else: ?>
            <div class="table-wrap"><table id="reportTable"><thead><tr><th>Reference</th><th>Name</th><th>Source</th><th>Amount</th><th>Destinations</th><th>Status</th><th>Created</th><th>Created by</th></tr></thead><tbody>
                <?php foreach ($reportRegister as $r): ?>
                <tr><td><?php echo safeHtml($r['batch_reference']); ?></td><td><?php echo safeHtml($r['batch_name'] ?? ''); ?></td><td><?php echo safeHtml($r['source_institution'] ?? ''); ?></td><td><?php echo formatCurrency($r['total_amount'] ?? 0, $orgCurrency); ?></td><td><?php echo (int)($r['total_destinations'] ?? 0); ?></td><td><?php echo safeHtml(getStatusLabel($r['status'])); ?></td><td><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td><td><?php echo safeHtml($r['created_by'] ?? ''); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        <?php elseif ($reportType === 'audit'): ?>
            <div class="card-head" style="border:none;padding:0;"><span class="card-title">Audit trail &mdash; <?php echo count($reportAuditTrail); ?> entries</span><?php if ($canExportFilings): ?><button type="button" class="btn btn-primary btn-sm" onclick="exportTableCsv('reportTable', 'audit-trail_<?php echo safeHtml($reportFrom); ?>_to_<?php echo safeHtml($reportTo); ?>.csv')">Export CSV</button><?php endif; ?></div>
            <?php if (empty($reportAuditTrail)): ?><div class="empty">No audit entries in this range.</div><?php else: ?>
            <div class="table-wrap"><table id="reportTable"><thead><tr><th>Action</th><th>Entity</th><th>Actor</th><th>When</th></tr></thead><tbody>
                <?php foreach ($reportAuditTrail as $e): ?>
                <tr><td><?php echo safeHtml(getActivityLabel($e['action'])); ?></td><td><?php echo safeHtml(($e['entity_type'] ?? '') . ' #' . ($e['entity_id'] ?? '')); ?></td><td><?php echo $e['actor_name'] ? safeHtml($e['actor_name']) : 'System'; ?></td><td><?php echo date('Y-m-d H:i:s', strtotime($e['created_at'])); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        <?php elseif ($reportType === 'exceptions'): ?>
            <div class="card-head" style="border:none;padding:0;"><span class="card-title">Rejected / exception batches &mdash; <?php echo count($reportExceptions); ?></span><?php if ($canExportFilings): ?><button type="button" class="btn btn-primary btn-sm" onclick="exportTableCsv('reportTable', 'exceptions_<?php echo safeHtml($reportFrom); ?>_to_<?php echo safeHtml($reportTo); ?>.csv')">Export CSV</button><?php endif; ?></div>
            <?php if (empty($reportExceptions)): ?><div class="empty">No rejected or failed batches in this range<?php echo $userDeptScope !== null ? ' for your department scope' : ''; ?>.</div><?php else: ?>
            <div class="table-wrap"><table id="reportTable"><thead><tr><th>Reference</th><th>Name</th><th>Source</th><th>Amount</th><th>Status</th><th>Created</th></tr></thead><tbody>
                <?php foreach ($reportExceptions as $r): ?>
                <tr><td><?php echo safeHtml($r['batch_reference']); ?></td><td><?php echo safeHtml($r['batch_name'] ?? ''); ?></td><td><?php echo safeHtml($r['source_institution'] ?? ''); ?></td><td><?php echo formatCurrency($r['total_amount'] ?? 0, $orgCurrency); ?></td><td><?php echo safeHtml(getStatusLabel($r['status'])); ?></td><td><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        <?php elseif ($reportType === 'departments'): ?>
            <div class="card-head" style="border:none;padding:0;"><span class="card-title">Department spend summary</span><?php if ($canExportFilings): ?><button type="button" class="btn btn-primary btn-sm" onclick="exportTableCsv('reportTable', 'department-summary_<?php echo safeHtml($reportFrom); ?>_to_<?php echo safeHtml($reportTo); ?>.csv')">Export CSV</button><?php endif; ?></div>
            <?php if (empty($reportDeptSummary)): ?><div class="empty">No batches in this range<?php echo $userDeptScope !== null ? ' for your department scope' : ''; ?>.</div><?php else: ?>
            <div class="table-wrap"><table id="reportTable"><thead><tr><th>Department</th><th>Batches</th><th>Total amount</th></tr></thead><tbody>
                <?php foreach ($reportDeptSummary as $d): ?>
                <tr><td><?php echo safeHtml($d['department_name']); ?></td><td><?php echo (int)$d['batch_count']; ?></td><td><?php echo formatCurrency($d['total_amount'], $orgCurrency); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($canExportFilings): ?>
        <div class="card" style="margin-top:var(--u4);">
            <div class="card-title" style="border:none;padding:0;margin-bottom:var(--u2);">Generate a signed filing</div>
            <div style="font-size:12.5px;opacity:0.75;margin-bottom:var(--u2);">Produces a dated, attributable PDF suitable for an office filing or a public-inquiry submission — separate from the CSV above, which is a working export, not a formal record.</div>
            <button type="button" class="btn btn-secondary" onclick="requestSignedFiling('<?php echo safeHtml($reportType); ?>', '<?php echo safeHtml($reportFrom); ?>', '<?php echo safeHtml($reportTo); ?>')">Generate signed filing (PDF)</button>
            <div id="filingStatusMsg" style="font-size:12px;margin-top:var(--u2);"></div>
        </div>
        <?php endif; ?>

        <div class="banner" style="margin-top:var(--u4);">
            <div class="lbl">Chain-of-custody note</div>
            <div class="desc">This report is drawn from the organization's action log. It does not yet include the financial ledger's own tamper-evident record (hash-chained entries with a documented fallback path for failed writes). Confirm with your backend team whether that ledger needs to be joined into filings before this is relied on for a court or public inquiry.</div>
        </div>
    </div>
    <?php endif; ?>

<?php
$footerNote = $attentionActive ? "$attentionCount item(s) need attention" : 'All clear';
require __DIR__ . '/partials/shell-foot.php';
?>

<script>
// ============================================================
// CENTER-STAGE MECHANIC — identical contract to the consumer
// dashboard's goView(): exactly one .stage-view carries .active
// at any time. Deep-links (#stage-attention, ?trace=&hellip;) and
// the browser's own Back button both resolve to a stage name so
// a bookmark or a refresh always lands on the right screen.
// ============================================================
const STAGE_LABELS = { hub: 'Home', attention: 'Attention', batches: 'Batches', activity: 'Activity', trace: 'Trace a Payment', reports: 'Reports & Filings' };
function goStage(name) {
    document.querySelectorAll('.stage-view').forEach(v => v.classList.remove('active'));
    const el = document.getElementById('stage-' + name);
    (el || document.getElementById('stage-hub')).classList.add('active');
    if (name === 'hub') { history.replaceState(null, '', 'index.php'); }
    else { history.replaceState(null, '', '#stage-' + name); }
    sessionStorage.setItem('vm_stage', name);
    // The sidebar was built server-side with 'hub' marked active
    // (the server can't see the URL fragment) — this is the client
    // half of that contract, keeping the sidebar honest about which
    // in-page stage is actually showing.
    document.querySelectorAll('.side-nav-item').forEach(function (link) {
        const href = link.getAttribute('href') || '';
        const matches = (name === 'hub') ? href === 'index.php' : href.endsWith('#stage-' + name);
        link.classList.toggle('active', matches);
    });
    if (name === 'attention') renderAttentionDetailFromSelected();
    window.scrollTo(0, 0);
}
// ------------------------------------------------------------
// FIX: the sidebar's Attention/Batches/Activity/Trace/Reports links
// are plain <a href="index.php#stage-x">. When you're already ON
// index.php, a browser treats that as same-document navigation —
// it updates the URL bar WITHOUT reloading the page, which means
// this script never re-ran and nothing ever told the page to hide
// the Hub. Result: the Hub stayed visible and whatever stage the
// browser silently "navigated" to could end up rendered underneath
// it — the "I see things twice" bug. Same-document fragment
// navigation DOES fire a real 'hashchange' event even without a
// reload, so listening for that (not just checking the hash once
// at load) is the actual fix — not a click-handler workaround.
// ------------------------------------------------------------
function routeFromLocation() {
    const hash = (location.hash || '').replace('#stage-', '');
    const hasTrace = new URLSearchParams(location.search).get('trace');
    const hasReport = new URLSearchParams(location.search).get('report');
    if (hasTrace && document.getElementById('stage-trace')) { goStage('trace'); return; }
    if (hasReport && document.getElementById('stage-reports')) { goStage('reports'); return; }
    if (hash && document.getElementById('stage-' + hash)) { goStage(hash); return; }
    goStage('hub');
}
routeFromLocation();
window.addEventListener('hashchange', routeFromLocation);
function filterRows(bodyId, query) {
    const q = query.trim().toLowerCase();
    document.querySelectorAll('#' + bodyId + ' tr[data-search]').forEach(row => {
        row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
    });
}

// ------------------------------------------------------------
// NEEDS ATTENTION — list+detail pane. Selecting a row in the left
// list "pops out" the full detail on the right: nothing here
// refetches from the server, every field was already rendered
// into the row's data-* attributes by PHP.
// ------------------------------------------------------------
function renderAttentionDetail(row) {
    const holder = document.getElementById('attentionDetail');
    if (!holder) return;
    if (!row) { holder.innerHTML = '<div class="detail-empty">Select an item on the left to see its full detail here.</div>'; return; }
    const tone = row.dataset.tone;
    const toneLabel = tone === 'danger' ? 'Critical' : tone === 'green' ? 'Ready' : 'Waiting on you';
    holder.innerHTML = `
        <div class="detail-panel">
            <span class="status status-${tone === 'danger' ? 'rejected' : tone === 'green' ? 'approved' : 'pending'}">${toneLabel}</span>
            <div class="detail-title">${row.dataset.label}</div>
            <div class="detail-meta">
                <div><div class="k">Items</div><div class="v">${row.dataset.count}</div></div>
                <div><div class="k">Priority</div><div class="v">${toneLabel}</div></div>
            </div>
            <div class="detail-desc">${row.dataset.desc}</div>
            <div class="detail-actions">
                <a href="${row.dataset.href}" class="btn btn-primary">${row.dataset.cta}</a>
            </div>
        </div>`;
}
function selectAttentionItem(el) {
    document.querySelectorAll('#stage-attention .pane-list-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    renderAttentionDetail(el);
}
function renderAttentionDetailFromSelected() {
    const selected = document.querySelector('#stage-attention .pane-list-item.selected') || document.querySelector('#stage-attention .pane-list-item');
    renderAttentionDetail(selected);
}
document.addEventListener('DOMContentLoaded', renderAttentionDetailFromSelected);

// ------------------------------------------------------------
// Real, working export — reads the table already rendered from
// the server's query results and turns it into a CSV the browser
// downloads directly. No backend round-trip, nothing invented:
// exactly the rows a human can already see on screen.
// ------------------------------------------------------------
function exportTableCsv(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row =>
        Array.from(row.querySelectorAll('th,td')).map(cell => {
            const text = cell.textContent.trim().replace(/"/g, '""');
            return /[",\n]/.test(text) ? `"${text}"` : text;
        }).join(',')
    ).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link); link.click(); link.remove();
}

// ------------------------------------------------------------
// STUB — there is no /api/enterprise/reports/filing.php in this
// codebase. This calls it anyway and reports honestly that it
// isn't wired up yet, rather than pretending a PDF exists. Point
// this at a real document-generation endpoint before relying on
// it for an actual filing.
// ------------------------------------------------------------
async function requestSignedFiling(reportType, from, to) {
    const msg = document.getElementById('filingStatusMsg');
    msg.textContent = 'Requesting signed filing…';
    try {
        const res = await fetch('api/v1/enterprise/reports/filing.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ report: reportType, from, to }),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.pdf_url) { msg.textContent = 'Filing ready.'; window.location = data.pdf_url; }
        else { msg.textContent = data.message || 'Backend did not return a filing.'; }
    } catch (e) {
        msg.textContent = 'This endpoint is not implemented yet (api/v1/enterprise/reports/filing.php). Use CSV export for now, and wire a document-generation backend before this button is relied on for a real filing.';
    }
}
</script>
</body>
</html>
