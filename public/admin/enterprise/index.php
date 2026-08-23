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
        $key = ":{$prefix}{$i}"; $placeholders[] = $key; $params[$key] = $id;
    }
    return ' AND department_id IN (' . implode(',', $placeholders) . ')';
}

$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head']);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise']);
$canDisburse = ($userRole === 'owner');
$isSupervisor = ($userRole === 'owner');
$canConfirmSource = in_array($userRole, ['owner', 'it_manager_enterprise']);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer']);
$isReadOnly = in_array($userRole, ['auditor', 'viewer']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);

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
        } catch (PDOException $e) { $metrics['pending_source_confirmations'] = 0; }
    }

    $statusFilter = ""; $statusParams = [':org_id' => $orgId];
    if ($isTopRole) {
        $statusFilter = ($userRole === 'it_manager_enterprise' || $userDeptScopeIds === null) ? "AND 1=1" : "AND 1=1" . departmentScopeSql($userDeptScopeIds, $statusParams, 'own');
    } elseif ($isReadOnly) {
        $statusFilter = "AND status IN ('completed', 'executed', 'COMPLETED', 'EXECUTED')";
    } elseif ($isApprover) {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'draft', 'PENDING', 'PENDING_APPROVAL', 'APPROVED')" . departmentScopeSql($userDeptScopeIds, $statusParams, 'apr');
    } elseif ($userRole === 'finance_officer') {
        $statusFilter = "AND status IN ('pending', 'pending_approval', 'approved', 'completed', 'executed', 'PENDING', 'PENDING_APPROVAL', 'APPROVED', 'COMPLETED', 'EXECUTED')";
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
}

// Action items feeding the Attention stage
$actionItems = [];
if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0) $actionItems[] = ['label' => 'Batches awaiting your approval', 'count' => $metrics['pending_approvals'], 'href' => 'batches/index.php?status=pending_approval', 'cta' => 'Review now'];
if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0) $actionItems[] = ['label' => 'Approved batches ready to disburse', 'count' => $metrics['approved_for_disbursement'], 'href' => 'batches/index.php?status=approved', 'cta' => 'Disburse now'];
if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0) $actionItems[] = ['label' => 'Source accounts awaiting confirmation', 'count' => $metrics['pending_source_confirmations'], 'href' => 'imports/add_source.php', 'cta' => 'Confirm now'];
if (($metrics['rejected_batches'] ?? 0) > 0 && ($canCreate || $isSupervisor)) $actionItems[] = ['label' => 'Rejected batches needing correction', 'count' => $metrics['rejected_batches'], 'href' => 'batches/index.php?status=rejected', 'cta' => 'Review'];
$attentionCount = array_sum(array_column($actionItems, 'count'));
$attentionActive = $attentionCount > 0;
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
                <div class="stat-sub">Every one needs a decision</div>
            </div>
        </div>

        <div class="tile-grid">
            <button type="button" class="tile" onclick="goStage('attention')">
                <div class="tile-icon"><?php echo svgIcon('bell'); ?></div>
                <?php if ($attentionCount > 0): ?><span class="tile-badge"><?php echo $attentionCount; ?></span><?php endif; ?>
                <div class="tile-label">Needs Attention</div>
                <div class="tile-sub">Approvals, disbursements, and source confirmations waiting on you.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <button type="button" class="tile" onclick="goStage('batches')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="13"/><path d="M3 10h18"/></svg></div>
                <div class="tile-label">Batches</div>
                <div class="tile-sub">Every disbursement batch — drafts through completed.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <button type="button" class="tile" onclick="goStage('activity')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg></div>
                <div class="tile-label">Activity</div>
                <div class="tile-sub">The organization's audit trail, most recent first.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php if ($canTrace): ?>
            <button type="button" class="tile" onclick="goStage('trace')">
                <div class="tile-icon"><svg class="i" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/></svg></div>
                <div class="tile-label">Trace a payment</div>
                <div class="tile-sub">Search any batch by reference, phone, or ID.</div>
                <div class="tile-arrow">Open &rsaquo;</div>
            </button>
            <?php endif; ?>
        </div>

        <div class="tile-grid" style="grid-template-columns:repeat(4,1fr);">
            <a href="beneficiaries.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Beneficiaries</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <a href="departments/index.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Departments</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <a href="imports/add_source.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Source accounts</div><div class="tile-arrow">Open &rsaquo;</div></a>
            <a href="settings/users.php" class="tile" style="min-height:96px;"><div class="tile-label" style="font-size:14px;">Team</div><div class="tile-arrow">Open &rsaquo;</div></a>
        </div>
    </div>

    <!-- ============================================================
         STAGE — NEEDS ATTENTION
         ============================================================ -->
    <div class="stage-view" id="stage-attention">
        <div class="stage-head">
            <div><div class="stage-eyebrow">Center stage</div><div class="stage-title">Needs Attention</div><div class="stage-meta"><?php echo count($actionItems); ?> item type(s) &middot; <?php echo $attentionCount; ?> total</div></div>
            <div class="stage-actions"><button type="button" class="btn btn-secondary" onclick="goStage('hub')">&larr; Hub</button></div>
        </div>
        <?php if (empty($actionItems)): ?>
            <div class="empty">All clear — nothing needs your attention right now.</div>
        <?php else: foreach ($actionItems as $item): ?>
            <div class="card">
                <div class="row" style="border:none;">
                    <span class="row-dot"></span>
                    <div class="row-body">
                        <div class="row-title"><?php echo safeHtml($item['label']); ?></div>
                        <div class="row-sub"><?php echo (int)$item['count']; ?> item(s)</div>
                    </div>
                    <a href="<?php echo safeHtml($item['href']); ?>" class="btn btn-primary btn-sm"><?php echo safeHtml($item['cta']); ?></a>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ============================================================
         STAGE — BATCHES
         ============================================================ -->
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

    <!-- ============================================================
         STAGE — ACTIVITY
         ============================================================ -->
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

    <!-- ============================================================
         STAGE — TRACE A PAYMENT
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
        <?php endif; ?>
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
const STAGE_LABELS = { hub: 'Home', attention: 'Attention', batches: 'Batches', activity: 'Activity', trace: 'Trace a Payment' };
function goStage(name) {
    document.querySelectorAll('.stage-view').forEach(v => v.classList.remove('active'));
    const el = document.getElementById('stage-' + name);
    (el || document.getElementById('stage-hub')).classList.add('active');
    const backBtn = document.querySelector('.hdr-back');
    if (name === 'hub') { history.replaceState(null, '', 'index.php'); }
    else { history.replaceState(null, '', '#stage-' + name); }
    sessionStorage.setItem('vm_stage', name);
    window.scrollTo(0, 0);
}
(function initStage() {
    const hash = (location.hash || '').replace('#stage-', '');
    const hasTrace = new URLSearchParams(location.search).get('trace');
    if (hasTrace && document.getElementById('stage-trace')) { goStage('trace'); return; }
    if (hash && document.getElementById('stage-' + hash)) { goStage(hash); return; }
    const remembered = sessionStorage.getItem('vm_stage');
    if (remembered === 'attention' && !hasTrace) { /* only restore lightweight stages, never a stale search */ }
})();
function filterRows(bodyId, query) {
    const q = query.trim().toLowerCase();
    document.querySelectorAll('#' + bodyId + ' tr[data-search]').forEach(row => {
        row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
    });
}
</script>
</body>
</html>
