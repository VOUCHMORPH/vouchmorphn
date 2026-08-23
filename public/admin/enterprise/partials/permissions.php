<?php
/**
 * partials/permissions.php — NOW A WRAPPER, NOT A SOURCE OF TRUTH.
 * ------------------------------------------------------------
 * Previous version of this file: a hardcoded $ROLE_CAPS array,
 * hand-maintained in PHP, completely disconnected from auth.php's
 * REAL permission system — hasPermission(), which already checks
 * (1) owner bypass, (2) a per-user JSONB override on
 * organization_users.permissions, (3) a DB-backed default set in
 * organization_role_permissions. That array could drift from the
 * real system the moment anyone edited a role's permissions in the
 * database without also remembering to edit this file — a classic
 * "two sources of truth" bug, caught before it caused real damage,
 * but only because it was pointed out, not because it was found here.
 *
 * Every capability flag below now resolves through can(), which
 * calls the real hasPermission($code) from auth.php. auth.php MUST
 * be require_once'd by the including page before this file runs —
 * every page in this codebase already does that for requireEnterpriseAuth().
 *
 * ⚠️ GUESSED PERMISSION CODES — flagged, not hidden: I do not have
 * your organization_role_permissions table's actual seeded rows, so
 * the string passed to each can() call below (e.g. 'view_batches')
 * is my best guess at a plausible permission_code, matching the
 * naming style already used elsewhere in your code. If your real
 * codes differ (e.g. 'batches.view', 'BATCH_VIEW'), every one of
 * these will resolve to false — nobody sees anything — until either
 * the codes here are corrected to match your table, or your table is
 * seeded with these exact strings. Please send me the real
 * permission_code values from organization_role_permissions and I
 * will fix this file in one pass rather than guess twice.
 *
 * DEPARTMENT SCOPING — now uses auth.php's getUserDepartmentScope(),
 * which returns a single ?int (null = org-wide, only for owner and
 * auditor; every other role gets exactly their own department_id).
 * This REPLACES the previous $userDeptScopeIds array-of-many-
 * departments model, which came from a DepartmentService method
 * whose source I've never seen. If DepartmentService::getDepartmentScopeIds()
 * is still authoritative elsewhere in this codebase (e.g. a batch
 * approval page), that page and this dashboard may now disagree
 * about what a department-scoped role can see — reconcile which one
 * is actually correct before treating either as final.
 */

// Lightweight per-request memoization — hasPermission() does a real
// DB round trip every call; without this, rendering one page's worth
// of sidebar + stage gating (a dozen-plus can() calls) would fire a
// dozen-plus near-identical SELECTs against organization_role_permissions.
$__permCache = [];
function can(string $permissionCode): bool {
    global $__permCache;
    if (!array_key_exists($permissionCode, $__permCache)) {
        $__permCache[$permissionCode] = hasPermission($permissionCode);
    }
    return $__permCache[$permissionCode];
}

$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$isSupervisor = ($userRole === 'owner');
$isDepartmentHead = ($userRole === 'department_head');
$isReadOnly = in_array($userRole, ['auditor', 'viewer']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);

$canCreate = can('create_batch');
$canApprove = can('act_approve');
$canDisburse = can('act_disburse');
$canConfirmSource = can('act_confirm_source');
$canTrace = can('trace_payment');
$canManageDepartments = can('manage_departments');
$canSeeSourceAccountsArea = can('manage_sources');
$canManageUsers = can('manage_users');
$canSeeFinancialStats = can('view_stats');
$canViewBatchesTile = can('view_batches');
$canViewActivityTile = can('view_activity');
$canViewReports = can('view_reports');
$canExportFilings = can('export_filings');
$canViewBeneficiariesTile = can('view_beneficiaries');
// Pure role logic layered on top of the real permission check — a
// role only gets the action inbox if it holds at least one of the
// permissions that would put something IN that inbox.
$canViewAttentionTile = $canApprove || $canDisburse || $canConfirmSource || $canCreate || $isSupervisor;

// ============================================================
// DEPARTMENT SCOPE — real helper, not a guessed multi-department
// service call. $userDeptScope is a single ?int: null = org-wide,
// otherwise the one department_id this user is confined to.
// ============================================================
$userDeptScope = getUserDepartmentScope();

/**
 * Appends a single-department filter to a query, or nothing at all
 * for an org-wide scope (null). Replaces the old departmentScopeSql()
 * (which handled an ARRAY of department ids from the never-seen
 * DepartmentService method) — every call site that used the old
 * multi-id version needs updating to this single-id version.
 */
function departmentScopeSqlSingle(?int $deptScope, array &$params, string $paramName = ':dept_scope'): string {
    if ($deptScope === null) return '';
    $params[$paramName] = $deptScope;
    return " AND department_id = {$paramName}";
}
