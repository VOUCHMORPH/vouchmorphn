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

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../src/Domain/Services/DepartmentService.php';
use Domain\Services\DepartmentService;

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
        /* ============================================================
           VOUCHMORPH STANDARD STYLE
           Sharp corners · Centralized · Brass/Ink-900 · Appropriate font sizes
           One button-height scale everywhere: --btn-h / --btn-h-sm.
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
            --amber-bg:     #FEF3C7;
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --danger:       #b3261e;
            --danger-bg:    #fbeceb;

            --max-width:    1400px;
            --btn-h:        36px;
            --btn-h-sm:     28px;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        :focus-visible { outline: 2px solid var(--brass); outline-offset: 2px; }

        /* ============================================================
           HEADER
           ============================================================ */
        .header {
            background: var(--ink-900);
            color: #fff;
            border-bottom: 3px solid var(--brass);
        }
        .header-inner {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .logo {
            font-family: var(--f-cond);
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .logo span { color: var(--brass); }
        .org-name {
            font-size: 13px;
            color: var(--ink-300);
            padding-left: 16px;
            border-left: 1px solid rgba(255,255,255,0.1);
        }
        .role-badge {
            padding: 4px 14px;
            background: var(--brass);
            color: var(--ink-900);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-family: var(--f-cond);
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .user-details {
            text-align: right;
        }
        .user-name {
            font-weight: 600;
            color: var(--brass);
            font-size: 14px;
        }
        .user-role {
            font-size: 11px;
            color: var(--ink-300);
            text-transform: uppercase;
            font-family: var(--f-cond);
            letter-spacing: 0.04em;
        }
        .logout-btn {
            height: var(--btn-h-sm);
            display: inline-flex;
            align-items: center;
            padding: 0 16px;
            border: 2px solid var(--brass);
            color: var(--brass);
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            font-family: var(--f-cond);
            transition: all 0.15s;
            letter-spacing: 0.04em;
            box-sizing: border-box;
        }
        .logout-btn:hover {
            background: var(--brass);
            color: var(--ink-900);
        }

        /* ============================================================
           NAVIGATION
           ============================================================ */
        .nav {
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }
        .nav-inner {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 0 32px;
            display: flex;
            gap: 28px;
            flex-wrap: wrap;
            align-items: center;
            overflow-x: auto;
        }
        .nav-item {
            padding: 14px 0;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid transparent;
            transition: all 0.15s;
            white-space: nowrap;
            font-family: var(--f-cond);
        }
        .nav-item:hover { color: var(--ink-900); }
        .nav-item.active {
            color: var(--ink-900);
            border-bottom-color: var(--brass);
        }
        .nav-item .badge {
            background: var(--seal-red);
            color: #fff;
            font-size: 9px;
            padding: 1px 8px;
            margin-left: 4px;
            font-family: var(--f-mono);
        }
        .nav-item .badge-gold {
            background: var(--brass);
            color: #fff;
            font-size: 9px;
            padding: 1px 8px;
            margin-left: 4px;
            font-family: var(--f-mono);
        }

        /* ============================================================
           CONTENT
           ============================================================ */
        .content {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 28px 32px;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 28px;
        }
        .page-header h1 {
            font-family: var(--f-cond);
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .page-header .sub {
            color: var(--ink-500);
            font-size: 14px;
        }
        .page-header .timestamp {
            color: var(--ink-300);
            font-size: 12px;
            font-family: var(--f-mono);
        }

        /* ============================================================
           ACTION QUEUE — the "everything on my face" panel. Every
           role-specific pending item, ranked by urgency, before the
           user has clicked anywhere.
           ============================================================ */
        .action-queue {
            background: var(--ink-900);
            border: 1px solid var(--ink-900);
            border-left: 4px solid var(--seal-red);
            margin-bottom: 24px;
            padding: 18px 22px;
        }
        .action-queue-title {
            font-family: var(--f-cond);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--brass);
            margin-bottom: 12px;
        }
        .action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-top: 1px solid rgba(255,255,255,0.08);
            flex-wrap: wrap;
        }
        .action-row:first-of-type { border-top: none; }
        .action-row-left {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
            font-size: 13.5px;
        }
        .action-row .count-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 22px;
            padding: 0 6px;
            font-family: var(--f-mono);
            font-weight: 700;
            font-size: 12px;
            color: #fff;
        }
        .count-pill.amber { background: var(--amber); }
        .count-pill.green { background: var(--ledger-green); }
        .count-pill.danger { background: var(--seal-red); }
        .action-queue-empty {
            color: rgba(255,255,255,0.6);
            font-size: 13.5px;
        }

        /* ============================================================
           PAYMENT TRACE
           ============================================================ */
        .trace-box {
            display: flex;
            gap: 10px;
            align-items: stretch;
            flex-wrap: wrap;
        }
        .trace-box input[type="text"] {
            height: var(--btn-h);
            padding: 0 14px;
            border: 1.5px solid var(--line);
            font-size: 13.5px;
            font-family: var(--f-body);
            background: var(--paper);
            color: var(--ink-900);
            min-width: 260px;
            flex: 1;
            box-sizing: border-box;
        }
        .trace-box input[type="text"]:focus {
            outline: none;
            border-color: var(--brass);
            background: var(--panel);
        }
        .trace-result-group { margin-top: 16px; }
        .trace-result-group h4 {
            font-family: var(--f-cond);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--ink-500);
            margin-bottom: 8px;
        }
        .trace-timeline {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .trace-step {
            font-family: var(--f-mono);
            font-size: 11px;
            padding: 3px 10px;
            background: var(--paper);
            color: var(--ink-500);
            border: 1px solid var(--line);
        }
        .trace-step.done { background: var(--green-tint); color: var(--ledger-green); border-color: var(--ledger-green); }
        .trace-step.now { background: var(--brass-tint); color: var(--brass); border-color: var(--brass); font-weight: 700; }

        /* ============================================================
           QUICK ACTIONS
           ============================================================ */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }
        .quick-action {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 18px 20px;
            text-decoration: none;
            color: var(--ink-900);
            transition: all 0.15s;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .quick-action:hover {
            border-color: var(--brass);
            background: var(--brass-tint);
            transform: translateY(-2px);
        }
        .quick-action .icon { font-size: 26px; }
        .quick-action .label {
            font-size: 14px;
            font-weight: 600;
            font-family: var(--f-cond);
        }
        .quick-action .desc {
            font-size: 12px;
            color: var(--ink-300);
        }

        /* ============================================================
           METRICS
           ============================================================ */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }
        .metric-card {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 18px 20px;
            transition: border-color 0.15s;
        }
        .metric-card:hover {
            border-color: var(--brass);
        }
        .metric-label {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--ink-300);
            letter-spacing: 0.05em;
            font-weight: 600;
            font-family: var(--f-cond);
        }
        .metric-value {
            font-size: 26px;
            font-weight: 700;
            color: var(--ink-900);
            margin-top: 4px;
            font-family: var(--f-cond);
        }
        .metric-value .currency {
            font-size: 14px;
            color: var(--ink-300);
            font-weight: 400;
        }
        .metric-sub {
            font-size: 11px;
            color: var(--ink-300);
            margin-top: 2px;
        }

        /* ============================================================
           CARDS
           ============================================================ */
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            padding: 20px 24px;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
            flex-wrap: wrap;
            gap: 8px;
        }
        .card-title {
            font-size: 16px;
            font-weight: 700;
            font-family: var(--f-cond);
            letter-spacing: 0.02em;
        }
        .card-badge {
            padding: 2px 12px;
            background: var(--ink-900);
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            font-family: var(--f-cond);
            letter-spacing: 0.04em;
        }
        .card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-responsive { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: var(--paper);
            color: var(--ink-500);
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            border-bottom: 2px solid var(--line);
            font-family: var(--f-cond);
        }
        td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            vertical-align: middle;
            font-size: 13px;
        }
        tr:hover { background: var(--brass-tint); }

        /* ============================================================
           STATUS BADGES
           ============================================================ */
        .status {
            display: inline-block;
            padding: 2px 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-family: var(--f-cond);
        }
        .status-draft { background: var(--paper); color: var(--ink-500); }
        .status-pending { background: var(--amber-bg); color: var(--amber); }
        .status-approved { background: var(--blue-tint); color: #1e40af; }
        .status-completed { background: var(--green-tint); color: var(--ledger-green); }
        .status-rejected { background: var(--danger-bg); color: var(--danger); }

        /* ============================================================
           BUTTONS — one height scale (--btn-h / --btn-h-sm) shared by
           every button and button-like link on the page, regardless
           of color/variant class, so nothing reads as "smaller".
           ============================================================ */
        .btn {
            height: var(--btn-h);
            padding: 0 18px;
            font-size: 12px;
            font-weight: 600;
            font-family: var(--f-cond);
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            box-sizing: border-box;
            line-height: 1;
        }
        .btn:hover { opacity: 0.85; }
        .btn-primary {
            background: var(--ink-900);
            color: #fff;
            border-color: var(--ink-900);
        }
        .btn-primary:hover {
            background: var(--brass);
            border-color: var(--brass);
            color: var(--ink-900);
            opacity: 1;
        }
        .btn-success {
            background: var(--ledger-green);
            color: #fff;
            border-color: var(--ledger-green);
        }
        .btn-success:hover { background: #1a3d2c; opacity: 1; }
        .btn-warning {
            background: var(--amber);
            color: #fff;
            border-color: var(--amber);
        }
        .btn-warning:hover { background: #6e4800; opacity: 1; }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--line);
            color: var(--ink-500);
        }
        .btn-outline:hover {
            border-color: var(--brass);
            color: var(--ink-900);
            background: var(--brass-tint);
            opacity: 1;
        }
        .btn-sm { height: var(--btn-h-sm); padding: 0 14px; font-size: 11px; }
        .btn-disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        /* ============================================================
           EMPTY STATE
           ============================================================ */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--ink-300);
        }
        .empty-state .icon { font-size: 44px; margin-bottom: 12px; }
        .empty-state p { font-size: 15px; }

        /* ============================================================
           ROLE INFO PANELS
           ============================================================ */
        .info-panel {
            padding: 16px 20px;
            margin-bottom: 16px;
            border-left: 3px solid var(--brass);
        }
        .info-panel .label {
            font-weight: 600;
            font-size: 14px;
            font-family: var(--f-cond);
            letter-spacing: 0.02em;
        }
        .info-panel .desc {
            color: var(--ink-500);
            font-size: 13px;
            margin-top: 4px;
        }
        .info-panel .desc .highlight {
            font-weight: 600;
            color: var(--ink-900);
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        .footer {
            background: var(--ink-900);
            color: var(--ink-300);
            text-align: center;
            font-size: 11px;
            border-top: 2px solid var(--brass);
            margin-top: 28px;
            font-family: var(--f-mono);
        }
        .footer-inner {
            max-width: var(--max-width);
            margin: 0 auto;
            padding: 16px 32px;
        }
        .footer .sub {
            color: rgba(255,255,255,0.15);
            font-size: 9px;
            margin-top: 4px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .header-inner { padding: 12px 16px; }
            .nav-inner { padding: 0 16px; gap: 16px; }
            .footer-inner { padding: 12px 16px; }
            .content { padding: 16px; }
            .metrics-grid { grid-template-columns: repeat(2, 1fr); }
            .quick-actions { grid-template-columns: 1fr; }
            .table-responsive { font-size: 12px; }
            th, td { padding: 6px 8px; }
            .page-header h1 { font-size: 20px; }
            .action-row { flex-direction: column; align-items: flex-start; }
        }
        @media (max-width: 480px) {
            .metrics-grid { grid-template-columns: 1fr; }
            .header-left { gap: 10px; }
            .user-info { width: 100%; justify-content: flex-end; }
        }

        /* ============================================================
           DARK MODE SUPPORT
           ============================================================ */
        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #1B2733;
                --panel: #1B2733;
                --ink-900: #ECEFF2;
                --ink-700: #D5DCE0;
                --ink-500: #93A2AC;
                --ink-300: #6B7A85;
                --line: #2C3A45;
            }
            .header { background: #0d1a26; }
            .nav { background: #1B2733; border-color: #2C3A45; }
            .nav-item { color: #93A2AC; }
            .nav-item:hover { color: #ECEFF2; }
            .nav-item.active { color: #ECEFF2; border-bottom-color: var(--brass); }
            .card { background: #1B2733; border-color: #2C3A45; }
            .card-header { border-color: #2C3A45; }
            .card-badge { background: #2C3A45; color: #ECEFF2; }
            th { background: #1B2733; color: #93A2AC; border-color: #2C3A45; }
            td { border-color: #2C3A45; }
            tr:hover { background: #22303A; }
            .metric-card { background: #1B2733; border-color: #2C3A45; }
            .metric-value { color: #ECEFF2; }
            .quick-action { background: #1B2733; border-color: #2C3A45; color: #ECEFF2; }
            .quick-action:hover { background: #22303A; border-color: var(--brass); }
            .btn-primary { background: #2C3A45; color: #ECEFF2; }
            .btn-primary:hover { background: var(--brass); color: var(--ink-900); }
            .btn-outline { border-color: #2C3A45; color: #93A2AC; }
            .btn-outline:hover { border-color: var(--brass); color: #ECEFF2; background: #22303A; }
            .status-draft { background: #2C3A45; color: #93A2AC; }
            .footer { background: #0d1a26; }
            .trace-box input[type="text"] { background: #22303A; color: #ECEFF2; }
            .trace-step { background: #22303A; color: #93A2AC; }
        }
    </style>
</head>
<body>
    <!-- ============================================================ -->
    <!-- HEADER -->
    <!-- ============================================================ -->
    <header class="header">
        <div class="header-inner">
            <div class="header-left">
                <div class="logo">VOUCHMORPH <span>·</span> <?php echo safeHtml($orgName); ?></div>
                <span class="role-badge"><?php echo safeHtml(getRoleLabel($userRole)); ?></span>
            </div>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo safeHtml($fullName); ?></div>
                    <div class="user-role"><?php echo safeHtml(getRoleLabel($userRole)); ?> · <?php echo safeHtml($orgName); ?></div>
                </div>
                <a href="logout.php" class="logout-btn">Sign Out</a>
            </div>
        </div>
    </header>

    <!-- ============================================================ -->
    <!-- NAVIGATION -->
    <!-- ============================================================ -->
    <nav class="nav">
        <div class="nav-inner">
        <a href="index.php" class="nav-item active">📊 Dashboard</a>
        
        <?php if ($canCreate): ?>
        <a href="imports/source_input.php" class="nav-item">💰 New Disbursement</a>
        <?php endif; ?>
        
        <a href="batches/index.php?status=all" class="nav-item">
            📋 Batches
            <?php if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0): ?>
            <span class="badge"><?php echo $metrics['pending_approvals']; ?></span>
            <?php endif; ?>
            <?php if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <span class="badge-gold"><?php echo $metrics['approved_for_disbursement']; ?></span>
            <?php endif; ?>
        </a>
        
        <?php if ($canApprove): ?>
        <a href="batches/index.php?status=pending_approval" class="nav-item">⏳ Pending Approvals
            <?php if (($metrics['pending_approvals'] ?? 0) > 0): ?>
            <span class="badge"><?php echo $metrics['pending_approvals']; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <?php if ($canDisburse): ?>
        <a href="batches/index.php?status=approved" class="nav-item">🚀 Disburse Funds
            <?php if (($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <span class="badge-gold"><?php echo $metrics['approved_for_disbursement']; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        
        <a href="beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
        
        <?php if ($canCreate || $userRole === 'beneficiary_registrar'): ?>
        <a href="imports/add_destinations.php" class="nav-item">📝 Add Destinations</a>
        <?php endif; ?>
        
        <?php if ($canSeeSourceAccountsArea): ?>
        <a href="imports/add_source.php" class="nav-item">💰 Source Accounts
            <?php if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0): ?>
            <span class="badge"><?php echo $metrics['pending_source_confirmations']; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if ($canManageDepartments || $isDepartmentHead): ?>
        <a href="departments/index.php" class="nav-item">🏢 Departments</a>
        <?php endif; ?>

        <?php if ($canTrace): ?>
        <a href="#trace" class="nav-item">🔍 Trace Payment</a>
        <?php endif; ?>
        
        <a href="reports.php" class="nav-item">📈 Reports</a>
        
        <?php if ($canManageUsers): ?>
        <a href="settings/users.php" class="nav-item">👤 Manage Users</a>
        <?php endif; ?>
        
        <a href="settings.php" class="nav-item">⚙️ Settings</a>
        </div>
    </nav>

    <!-- ============================================================ -->
    <!-- CONTENT -->
    <!-- ============================================================ -->
    <main class="content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <div class="sub">Welcome back, <?php echo safeHtml($fullName); ?></div>
            </div>
            <div class="timestamp"><?php echo date('l, F j, Y · H:i'); ?></div>
        </div>

        <!-- ============================================================ -->
        <!-- ACTION QUEUE — front and center, before anything else.       -->
        <!-- Everything this user needs to act on today, in one place.    -->
        <!-- ============================================================ -->
        <?php if (!empty($actionItems)): ?>
        <div class="action-queue">
            <div class="action-queue-title">⚡ Needs Your Attention</div>
            <?php foreach ($actionItems as $item): ?>
            <div class="action-row">
                <div class="action-row-left">
                    <span class="count-pill <?php echo $item['tone']; ?>"><?php echo (int)$item['count']; ?></span>
                    <span><?php echo $item['icon']; ?> <?php echo safeHtml($item['label']); ?></span>
                </div>
                <a href="<?php echo safeHtml($item['href']); ?>" class="btn btn-primary btn-sm"><?php echo safeHtml($item['cta']); ?></a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($canApprove || $canDisburse || $canConfirmSource): ?>
        <div class="action-queue" style="border-left-color: var(--ledger-green);">
            <div class="action-queue-title" style="color:#fff;">✅ All Clear</div>
            <div class="action-queue-empty">Nothing is waiting on you right now.</div>
        </div>
        <?php endif; ?>

        <!-- ============================================================ -->
        <!-- PAYMENT TRACE — find any payment's full lifecycle instantly. -->
        <!-- ============================================================ -->
        <?php if ($canTrace): ?>
        <div class="card" id="trace">
            <div class="card-header">
                <span class="card-title">🔍 Trace a Payment</span>
                <span style="font-size:12px; color:var(--ink-500);">Batch reference, beneficiary phone, or national ID</span>
            </div>
            <form method="get" class="trace-box" action="index.php#trace">
                <input type="text" name="trace" placeholder="e.g. batch reference, phone number, national ID..." value="<?php echo safeHtml($traceQuery); ?>">
                <button type="submit" class="btn btn-primary">Trace</button>
                <?php if ($traceQuery !== ''): ?><a href="index.php#trace" class="btn btn-outline">Clear</a><?php endif; ?>
            </form>

            <?php if ($traceQuery !== ''): ?>
                <?php if (empty($traceBatches) && empty($traceBeneficiaries)): ?>
                <div class="empty-state"><div class="icon">🔍</div><p>No matches for "<?php echo safeHtml($traceQuery); ?>".</p></div>
                <?php endif; ?>

                <?php if (!empty($traceBatches)): ?>
                <div class="trace-result-group">
                    <h4>Matching Batches (<?php echo count($traceBatches); ?>)</h4>
                    <?php foreach ($traceBatches as $b): $st = strtolower($b['status'] ?? ''); ?>
                    <div class="card" style="border-left:3px solid var(--brass); margin-bottom:10px;">
                        <div class="card-header" style="margin-bottom:8px; padding-bottom:8px;">
                            <span class="card-title" style="font-size:14px;"><?php echo safeHtml($b['batch_reference']); ?> — <?php echo safeHtml($b['batch_name'] ?? 'Unnamed'); ?></span>
                            <span class="status status-<?php echo getStatusClass($b['status']); ?>"><?php echo getStatusLabel($b['status']); ?></span>
                        </div>
                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap:8px; font-size:13px;">
                            <div><strong>Source:</strong> <?php echo safeHtml($b['source_institution'] ?? 'N/A'); ?></div>
                            <div><strong>Amount:</strong> <?php echo formatCurrency($b['total_amount'] ?? 0); ?></div>
                            <div><strong>Destinations:</strong> <?php echo number_format($b['total_destinations'] ?? 0); ?></div>
                        </div>
                        <div class="trace-timeline">
                            <span class="trace-step done">Created <?php echo date('Y-m-d H:i', strtotime($b['created_at'] ?? 'now')); ?></span>
                            <?php
                            $stepsOrder = ['draft', 'pending', 'approved', 'completed'];
                            $curIdx = array_search($st === 'pending_approval' ? 'pending' : ($st === 'executed' ? 'completed' : $st), $stepsOrder);
                            foreach (['Draft', 'Pending Approval', 'Approved', 'Disbursed'] as $i => $label):
                                $cls = $curIdx === false ? '' : ($i < $curIdx ? 'done' : ($i === $curIdx ? 'now' : ''));
                            ?>
                            <span class="trace-step <?php echo $cls; ?>"><?php echo safeHtml($label); ?></span>
                            <?php endforeach; ?>
                            <span class="trace-step">Updated <?php echo date('Y-m-d H:i', strtotime($b['updated_at'] ?? $b['created_at'] ?? 'now')); ?></span>
                        </div>
                        <div style="text-align:right; margin-top:10px;">
                            <a href="imports/review_batch.php?batch_id=<?php echo $b['id']; ?>" class="btn btn-outline btn-sm">Open Batch</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($traceBeneficiaries)): ?>
                <div class="trace-result-group">
                    <h4>Matching Beneficiary Records (<?php echo count($traceBeneficiaries); ?>)</h4>
                    <div class="table-responsive">
                        <table>
                            <thead><tr><?php foreach (array_keys($traceBeneficiaries[0]) as $col): if (in_array($col, ['organization_id'])) continue; ?><th><?php echo safeHtml($col); ?></th><?php endforeach; ?></tr></thead>
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
                </div>
                <?php endif; ?>
            <?php else: ?>
            <p style="color:var(--ink-300); font-size:13px;">Enter any reference to see that payment's full path — created, approved, disbursed — with timestamps, in one view.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Quick Actions - Role Specific -->
        <div class="quick-actions">
            <?php if ($canCreate): ?>
            <a href="imports/source_input.php" class="quick-action">
                <span class="icon">💰</span>
                <div>
                    <div class="label">New Disbursement</div>
                    <div class="desc">Create a payment batch</div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($isApprover): ?>
            <a href="batches/index.php?status=pending_approval" class="quick-action" style="border-color: var(--amber);">
                <span class="icon">✅</span>
                <div>
                    <div class="label">Review & Approve</div>
                    <div class="desc"><?php echo ($metrics['pending_approvals'] ?? 0) . ' batches pending'; ?></div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($isSupervisor): ?>
            <a href="batches/index.php?status=approved" class="quick-action" style="border-color: var(--ledger-green);">
                <span class="icon">💸</span>
                <div>
                    <div class="label">Disburse Funds</div>
                    <div class="desc"><?php echo ($metrics['approved_for_disbursement'] ?? 0) . ' batches ready'; ?></div>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if ($canCreate || $userRole === 'beneficiary_registrar'): ?>
            <a href="imports/add_destinations.php" class="quick-action">
                <span class="icon">👤</span>
                <div>
                    <div class="label">Add Beneficiaries</div>
                    <div class="desc">Import or add recipients</div>
                </div>
            </a>
            <?php endif; ?>
            
            <a href="beneficiaries.php" class="quick-action">
                <span class="icon">📋</span>
                <div>
                    <div class="label">View Beneficiaries</div>
                    <div class="desc"><?php echo number_format($metrics['total_beneficiaries'] ?? 0); ?> active records</div>
                </div>
            </a>
            
            <?php if ($canManageUsers): ?>
            <a href="settings/users.php" class="quick-action">
                <span class="icon">👥</span>
                <div>
                    <div class="label">Manage Users</div>
                    <div class="desc"><?php echo number_format($metrics['total_users'] ?? 0); ?> team members</div>
                </div>
            </a>
            <?php endif; ?>
        </div>

        <!-- Metrics - Role Specific -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-label">Total Disbursed</div>
                <div class="metric-value">
                    <?php echo formatCurrency($metrics['total_disbursed'] ?? 0); ?>
                </div>
                <div class="metric-sub">Lifetime disbursements</div>
            </div>
            
            <div class="metric-card">
                <div class="metric-label">Total Batches</div>
                <div class="metric-value"><?php echo number_format($metrics['total_batches'] ?? 0); ?></div>
                <div class="metric-sub">All time</div>
            </div>
            
            <?php if (($metrics['pending_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: var(--amber);">
                <div class="metric-label">Pending Batches</div>
                <div class="metric-value" style="color: var(--amber);"><?php echo number_format($metrics['pending_batches'] ?? 0); ?></div>
                <div class="metric-sub">Waiting for approval</div>
            </div>
            <?php endif; ?>
            
            <?php if (($metrics['approved_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: #1e40af;">
                <div class="metric-label">Approved</div>
                <div class="metric-value" style="color: #1e40af;"><?php echo number_format($metrics['approved_batches'] ?? 0); ?></div>
                <div class="metric-sub">Ready for disbursement</div>
            </div>
            <?php endif; ?>
            
            <?php if (($metrics['executed_batches'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: var(--ledger-green);">
                <div class="metric-label">Completed</div>
                <div class="metric-value" style="color: var(--ledger-green);"><?php echo number_format($metrics['executed_batches'] ?? 0); ?></div>
                <div class="metric-sub">Successfully executed</div>
            </div>
            <?php endif; ?>
            
            <?php if ($canApprove && ($metrics['pending_approvals'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: var(--danger); background: var(--danger-bg);">
                <div class="metric-label">Pending Approvals</div>
                <div class="metric-value" style="color: var(--danger);"><?php echo number_format($metrics['pending_approvals'] ?? 0); ?></div>
                <div class="metric-sub">Needs your review</div>
            </div>
            <?php endif; ?>
            
            <?php if ($canDisburse && ($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
            <div class="metric-card" style="border-color: var(--brass); background: var(--brass-tint);">
                <div class="metric-label">Ready for Disbursement</div>
                <div class="metric-value" style="color: var(--brass);"><?php echo number_format($metrics['approved_for_disbursement'] ?? 0); ?></div>
                <div class="metric-sub">Approved batches</div>
            </div>
            <?php endif; ?>
            
            <div class="metric-card">
                <div class="metric-label">Beneficiaries</div>
                <div class="metric-value"><?php echo number_format($metrics['total_beneficiaries'] ?? 0); ?></div>
                <div class="metric-sub">Active recipients</div>
            </div>
        </div>

        <!-- Recent Batches -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">📋 Recent Batches</span>
                <span class="card-badge"><?php echo count($recentBatches); ?> RECENT</span>
                <div class="card-actions">
                    <a href="batches/index.php?status=all" class="btn btn-outline btn-sm">View All</a>
                    <?php if ($canCreate): ?>
                    <a href="imports/source_input.php" class="btn btn-primary btn-sm">➕ New Batch</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (empty($recentBatches)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <p>No batches found. Create your first disbursement batch to get started.</p>
                <?php if ($canCreate): ?>
                <a href="imports/source_input.php" class="btn btn-primary" style="margin-top:14px;">Create First Batch</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Name</th>
                            <th>Source</th>
                            <th>Amount</th>
                            <th>Destinations</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBatches as $batch): ?>
                        <tr>
                            <td>
                                <strong><?php echo safeHtml($batch['batch_reference']); ?></strong>
                            </td>
                            <td><?php echo safeHtml($batch['batch_name'] ?? '—'); ?></td>
                            <td><?php echo safeHtml($batch['source_institution'] ?? '—'); ?></td>
                            <td><strong><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></strong></td>
                            <td><?php echo number_format($batch['total_destinations'] ?? 0); ?></td>
                            <td>
                                <span class="status status-<?php echo getStatusClass($batch['status']); ?>">
                                    <?php echo getStatusLabel($batch['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d H:i', strtotime($batch['created_at'] ?? 'now')); ?></td>
                            <td>
                                <a href="imports/review_batch.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-outline btn-sm">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Role-specific info panels -->
        <?php if ($isReadOnly): ?>
        <div class="info-panel" style="border-left-color: var(--brass); background: var(--brass-tint);">
            <div class="label">🔍 Read-Only Access</div>
            <div class="desc">
                You have <span class="highlight"><?php echo $userRole === 'auditor' ? 'auditor' : 'read-only'; ?></span> access. 
                You can view and export data but cannot create or modify any records.
                <?php if ($userRole === 'auditor'): ?>
                This is for compliance and audit purposes.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isLoader): ?>
        <div class="info-panel" style="border-left-color: #3b82f6; background: var(--blue-tint);">
            <div class="label">📤 Loader Access</div>
            <div class="desc">
                You can create and upload new disbursement batches. 
                Once created, they will be sent for approval.
                <a href="imports/source_input.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Create New Batch</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isApprover): ?>
        <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
            <div class="label">✅ Approver Access</div>
            <div class="desc">
                You can review and approve pending disbursement batches.
                <?php if (($metrics['pending_approvals'] ?? 0) > 0): ?>
                <span class="highlight"><?php echo $metrics['pending_approvals']; ?> batches awaiting your review.</span>
                <?php endif; ?>
                <a href="batches/index.php?status=pending_approval" class="btn btn-warning btn-sm" style="margin-left:12px;">Review Now</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isSupervisor): ?>
        <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);">
            <div class="label">💸 Owner Disbursement Access</div>
            <div class="desc">
                You can disburse funds for approved batches. This is the only role that can — it is the
                final, non-delegable step in the disbursement chain.
                <?php if (($metrics['approved_for_disbursement'] ?? 0) > 0): ?>
                <span class="highlight"><?php echo $metrics['approved_for_disbursement']; ?> batches ready for disbursement.</span>
                <?php endif; ?>
                <a href="batches/index.php?status=approved" class="btn btn-success btn-sm" style="margin-left:12px;">Disburse Funds</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($userRole === 'beneficiary_registrar'): ?>
        <div class="info-panel" style="border-left-color: var(--ledger-green); background: var(--green-tint);">
            <div class="label">👤 Beneficiary Registrar</div>
            <div class="desc">
                You can add and manage beneficiaries for disbursement batches.
                <a href="imports/add_destinations.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Add Beneficiaries</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canConfirmSource && ($metrics['pending_source_confirmations'] ?? 0) > 0): ?>
        <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
            <div class="label">💰 Source Accounts Awaiting Confirmation</div>
            <div class="desc">
                <span class="highlight"><?php echo $metrics['pending_source_confirmations']; ?> source account(s)</span> proposed by Finance are waiting for an Owner or IT Manager to confirm before they can be used in disbursements.
                <a href="imports/add_source.php" class="btn btn-warning btn-sm" style="margin-left:12px;">Review Now</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($userRole === 'finance_officer'): ?>
        <div class="info-panel" style="border-left-color: var(--brass); background: var(--brass-tint);">
            <div class="label">💰 Finance Officer Access</div>
            <div class="desc">
                You can propose new source accounts for disbursements. An Owner or IT Manager (not you) must confirm each one before it becomes usable.
                <a href="imports/add_source.php" class="btn btn-primary btn-sm" style="margin-left:12px;">Manage Source Accounts</a>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- ============================================================ -->
    <!-- FOOTER -->
    <!-- ============================================================ -->
    <footer class="footer">
        <div class="footer-inner">
            <div>VOUCHMORPH · Enterprise Disbursement Platform · <?php echo date('Y'); ?></div>
            <div class="sub"><?php echo safeHtml($orgName); ?> · Role: <?php echo safeHtml(getRoleLabel($userRole)); ?></div>
        </div>
    </footer>
</body>
</html>
