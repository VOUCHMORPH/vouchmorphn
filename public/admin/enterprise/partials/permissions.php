<?php
/**
 * partials/permissions.php — the ONE authorization matrix. Both
 * index.php and departments/index.php used to carry their own copy
 * of this (index.php's was complete; departments/index.php's was a
 * two-line stub that was about to grow into "show everything" just
 * to build its sidebar). That duplication was flagged as a risk
 * from the first version of this matrix and it took exactly one
 * more page for the risk to become a real bug. This file is the fix.
 *
 * Contract — before requiring this file, the including page must
 * set $userRole. This file then defines $ROLE_CAPS, can(), and every
 * PURE role-based boolean flag (no database calls, no $metrics
 * dependency) that any page's nav array or stage gating needs:
 *
 *   canCreate, canApprove, canDisburse, isSupervisor, canConfirmSource,
 *   canTrace, canManageDepartments, isDepartmentHead,
 *   canSeeSourceAccountsArea, canManageUsers, canSeeFinancialStats,
 *   canViewBatchesTile, canViewActivityTile, canViewReports,
 *   canExportFilings, canViewBeneficiariesTile, canViewAttentionTile,
 *   isReadOnly, isApprover, isLoader, isTopRole
 *
 * What stays OUT of this file, on purpose: $userDeptScopeIds and
 * departmentScopeSql() (need a live DepartmentService instance per
 * page), and anything that depends on $metrics/$actionItems (needs a
 * DB round trip a page may not want to pay for just to draw its
 * sidebar). Those remain page-specific.
 *
 * SEGREGATION-OF-DUTIES NOTE, carried over unchanged from the first
 * version of this matrix: it_manager_enterprise holds BOTH
 * administrative capability (users, source accounts) AND financial
 * approval capability (act_approve, act_confirm_source). That is
 * usually a finding in a government compliance review. Still not
 * silently changed here — it's a financial-control policy decision,
 * not a display bug.
 */

$ROLE_CAPS = [
    'owner'                 => ['view_stats','view_attention','view_batches','view_activity','view_reports','export_filings','view_beneficiaries','manage_departments','manage_sources','manage_users','create_batch','act_approve','act_disburse','act_confirm_source'],
    'it_manager_enterprise' => ['view_stats','view_attention','view_batches','view_activity','view_reports','view_beneficiaries','manage_departments','manage_sources','manage_users','create_batch','act_approve','act_confirm_source'],
    'it_officer_enterprise' => ['manage_users','manage_sources'],
    'it_support'            => [],
    'finance_officer'       => ['view_stats','view_batches','view_activity','view_reports','view_beneficiaries','manage_sources','act_confirm_source'],
    'senior_approver'       => ['view_stats','view_attention','view_batches','view_activity','view_reports','act_approve'],
    'approver'              => ['view_stats','view_attention','view_batches','act_approve'],
    'department_head'       => ['view_stats','view_attention','view_batches','view_beneficiaries','create_batch'],
    'program_officer'       => ['view_batches','view_beneficiaries','create_batch'],
    'beneficiary_registrar' => ['view_beneficiaries'],
    'auditor'               => ['view_stats','view_batches','view_activity','view_reports','export_filings','view_beneficiaries'],
    'supervisor'            => ['view_stats','view_batches'],
    'viewer'                => [],
];
$myCaps = $ROLE_CAPS[$userRole] ?? [];
function can(string $cap): bool { global $myCaps; return in_array($cap, $myCaps, true); }

$isTopRole = in_array($userRole, ['owner', 'it_manager_enterprise'], true);
$canCreate = can('create_batch');
$canApprove = can('act_approve');
$canDisburse = can('act_disburse');
$isSupervisor = ($userRole === 'owner');
$canConfirmSource = can('act_confirm_source');
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer']);
$canManageDepartments = can('manage_departments');
$isDepartmentHead = ($userRole === 'department_head');
$canSeeSourceAccountsArea = can('manage_sources');
$canManageUsers = can('manage_users');
$canSeeFinancialStats = can('view_stats');
$canViewBatchesTile = can('view_batches');
$canViewActivityTile = can('view_activity');
$canViewReports = can('view_reports');
$canExportFilings = can('export_filings');
$canViewBeneficiariesTile = can('view_beneficiaries');
$isReadOnly = in_array($userRole, ['auditor', 'viewer']);
$isApprover = in_array($userRole, ['approver', 'senior_approver']);
$isLoader = in_array($userRole, ['program_officer', 'department_head']);
// Pure role logic (no $metrics needed) — a role only gets the action
// inbox if it can act on something in it at all; a pure oversight
// role uses Reports instead of an inbox with nothing to press.
$canViewAttentionTile = $canApprove || $canDisburse || $canConfirmSource || $canCreate || $isSupervisor;
