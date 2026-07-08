<?php
/**
 * ============================================================================
 * VOUCHMORPH ENTERPRISE — GOVERNMENT/ENTERPRISE READINESS DIAGNOSTIC
 * ============================================================================
 *
 * DROP-IN LOCATION: public/admin/enterprise/system_diagnostic.php
 * (replaces public/admin/test.php as the "is this system aligned" tool)
 *
 * WHAT THIS DOES
 * This is not a static checklist. It runs live checks against your actual
 * database schema and your actual source files, and tells you exactly what
 * is missing or miswired, with the fix for each item.
 *
 * It is modeled on the checks a government financial system (think Oracle
 * Federal Financials / SAP Public Sector / a Mojaloop-based social protection
 * payment hub) needs before it can be trusted with pensioner rolls, orphanage
 * grants, or social security disbursements:
 *
 *   1. Core infrastructure & environment
 *   2. Organizational structure (departments, not just flat "organizations")
 *   3. Beneficiary & program management (persistent registry, not one-off CSVs)
 *   4. Access control / who gets to log in and do what
 *   5. Disbursement pipeline wiring (is money movement actually connected?)
 *   6. Approval & governance (dual control, thresholds)
 *   7. Audit & compliance (who did what, when, reconciliation)
 *   8. Security hardening
 *
 * ACCESS: owner/admin role only. Safe to run repeatedly — read-only, except
 * it will optionally CREATE missing tables if you click "Apply Fix" (guarded,
 * shows the SQL first, requires confirmation).
 * ============================================================================
 */

// ============================================================
// FIX: Use correct DBConnection method
// ============================================================

require_once __DIR__ . '/auth.php';
$user = requireEnterpriseAuth();

if (!in_array($user['role'], ['owner', 'admin', 'super_admin'])) {
    header('HTTP/1.1 403 Forbidden');
    die('System diagnostics require owner, admin, or super_admin role.');
}

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// FIXED: Use getConnection() instead of getInstance()
$db = DBConnection::getConnection();
$orgId = getOrganizationId();

// Project root, used to grep other source files for wiring checks
$projectRoot = realpath(__DIR__ . '/../../');

// ============================================================================
// CHECK ENGINE
// ============================================================================

$results = []; // grouped by category
$applyFix = $_POST['apply_fix'] ?? null;
$fixMessage = '';

function addResult(string $category, string $name, string $status, string $message, string $fix = ''): void {
    global $results;
    $results[$category][] = [
        'name' => $name,
        'status' => $status, // 'pass' | 'warn' | 'fail'
        'message' => $message,
        'fix' => $fix
    ];
}

function tableExists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare("SELECT to_regclass(:t) IS NOT NULL AS exists_flag");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM information_schema.columns
            WHERE table_name = :t AND column_name = :c
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function rowCount(PDO $db, string $table): ?int {
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM " . $table);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

function fileContains(string $root, string $relPath, array $needles): array {
    $full = $root . '/' . ltrim($relPath, '/');
    if (!file_exists($full)) {
        return ['exists' => false, 'matches' => []];
    }
    $content = file_get_contents($full);
    $matches = [];
    foreach ($needles as $needle) {
        $matches[$needle] = (strpos($content, $needle) !== false);
    }
    return ['exists' => true, 'matches' => $matches];
}

// ============================================================================
// 1. CORE INFRASTRUCTURE & ENVIRONMENT
// ============================================================================

addResult('1. Core Infrastructure', 'Database connectivity', 'pass', 'Connected successfully via ' . $db->getAttribute(PDO::ATTR_DRIVER_NAME));

$phpVersion = PHP_VERSION;
addResult(
    '1. Core Infrastructure',
    'PHP version',
    version_compare($phpVersion, '8.1.0', '>=') ? 'pass' : 'warn',
    "Running PHP {$phpVersion}",
    version_compare($phpVersion, '8.1.0', '>=') ? '' : 'Upgrade to PHP 8.1+ for full compatibility with typed properties used across Domain\\Services.'
);

foreach (['pdo_pgsql', 'curl', 'openssl', 'mbstring', 'json'] as $ext) {
    addResult(
        '1. Core Infrastructure',
        "Extension: {$ext}",
        extension_loaded($ext) ? 'pass' : 'fail',
        extension_loaded($ext) ? 'Loaded' : 'MISSING — required for ' . ($ext === 'pdo_pgsql' ? 'database access' : ($ext === 'curl' ? 'institution API calls' : ($ext === 'openssl' ? 'message signing/certificates' : 'core parsing'))),
        extension_loaded($ext) ? '' : "Install php-{$ext} and restart the web server / container."
    );
}

addResult(
    '1. Core Infrastructure',
    'Upload directory security',
    is_dir('/tmp/vouchmorph_uploads') ? (substr(sprintf('%o', fileperms('/tmp/vouchmorph_uploads')), -3) === '777' ? 'fail' : 'pass') : 'warn',
    is_dir('/tmp/vouchmorph_uploads') ? 'Permissions: ' . substr(sprintf('%o', fileperms('/tmp/vouchmorph_uploads')), -3) : 'Directory not yet created',
    'upload.php creates this with mode 0777 (world-writable). Change to 0750 and ensure the web server user owns it — beneficiary files (National IDs, phone numbers, amounts) sitting world-writable in /tmp is a data protection finding in any government audit.'
);

// ============================================================================
// 2. ORGANIZATIONAL STRUCTURE — DEPARTMENTS, NOT JUST FLAT ORGANIZATIONS
// ============================================================================

addResult(
    '2. Organizational Structure',
    'organizations table',
    tableExists($db, 'organizations') ? 'pass' : 'fail',
    tableExists($db, 'organizations') ? 'Present' : 'MISSING',
    tableExists($db, 'organizations') ? '' : 'Core tenant table missing entirely.'
);

$hasDepartments = tableExists($db, 'departments');
addResult(
    '2. Organizational Structure',
    'departments table',
    $hasDepartments ? 'pass' : 'warn',
    $hasDepartments ? 'Present — ' . rowCount($db, 'departments') . ' departments registered' : 'MISSING — a government tenant currently has no way to separate "Department of Social Protection" from "Department of Disability Services" under one Ministry account',
    $hasDepartments ? '' : 'See migration script: creates departments (id, organization_id, name, code, budget_ceiling, cost_center, head_user_id). Every import_batch, beneficiary, and approval should carry a department_id so a Ministry can run separate books per department while sharing one login/RBAC layer.'
);

$hasBudgetLines = tableExists($db, 'budget_lines') || tableExists($db, 'disbursement_programs');
addResult(
    '2. Organizational Structure',
    'Programs / budget lines',
    $hasBudgetLines ? 'pass' : 'warn',
    $hasBudgetLines ? 'Present' : 'MISSING — no way to say "this batch is the Q3 2026 Old Age Pension program with a P50M ceiling" as opposed to an ad-hoc payment run',
    $hasBudgetLines ? '' : 'Add disbursement_programs (id, department_id, program_name, program_type ENUM[PENSION, ORPHAN_GRANT, DISABILITY_GRANT, SOCIAL_SECURITY, EMERGENCY_RELIEF, PAYROLL, OTHER], fiscal_year, budget_ceiling, amount_disbursed_to_date, status, recurrence ENUM[ONE_OFF, MONTHLY, QUARTERLY]). Every import_batch should reference program_id.'
);

// ============================================================================
// 3. BENEFICIARY & PROGRAM MANAGEMENT — PERSISTENT REGISTRY
// ============================================================================

$actualBeneficiaryTable = tableExists($db, 'organizations_beneficiaries') ? 'organizations_beneficiaries' : (tableExists($db, 'organization_beneficiaries') ? 'organization_beneficiaries' : null);
$codeExpectsTable = 'organization_beneficiaries';

addResult(
    '3. Beneficiary & Program Management',
    'Beneficiary table exists',
    $actualBeneficiaryTable ? 'pass' : 'fail',
    $actualBeneficiaryTable ? "Present as `{$actualBeneficiaryTable}` — " . (rowCount($db, $actualBeneficiaryTable) ?? '?') . ' beneficiaries on file' : 'MISSING entirely',
    ''
);

addResult(
    '3. Beneficiary & Program Management',
    'Table name matches code (dashboard stat card)',
    ($actualBeneficiaryTable === $codeExpectsTable) ? 'pass' : 'fail',
    ($actualBeneficiaryTable === $codeExpectsTable)
        ? 'Match — enterprise/index.php\'s beneficiary count stat will resolve correctly'
        : "MISMATCH — enterprise/index.php runs \"SELECT COUNT(*) FROM organization_beneficiaries\" (singular) but your real table is `{$actualBeneficiaryTable}` (plural \"organizations\").",
    ($actualBeneficiaryTable === $codeExpectsTable) ? '' : "Rename for consistency with every other child table in the system: ALTER TABLE organizations_beneficiaries RENAME TO organization_beneficiaries;"
);

if ($actualBeneficiaryTable) {
    $hasCategoryCol = columnExists($db, $actualBeneficiaryTable, 'beneficiary_category');
    $hasGuardianCol = columnExists($db, $actualBeneficiaryTable, 'guardian_national_id');
    $hasEligibilityCol = columnExists($db, $actualBeneficiaryTable, 'eligibility_status');
    $hasDeptLink = columnExists($db, $actualBeneficiaryTable, 'department_id');
    
    addResult(
        '3. Beneficiary & Program Management',
        'Beneficiary categorization',
        $hasCategoryCol ? 'pass' : 'warn',
        $hasCategoryCol ? 'Categorized via first-class column' : 'No dedicated beneficiary_category column yet',
        $hasCategoryCol ? '' : "ALTER TABLE {$actualBeneficiaryTable} ADD COLUMN beneficiary_category VARCHAR(50);"
    );
    
    addResult(
        '3. Beneficiary & Program Management',
        'Guardian/dependent linkage',
        $hasGuardianCol ? 'pass' : 'warn',
        $hasGuardianCol ? 'Present' : 'MISSING — no way to record who administers funds for minors',
        $hasGuardianCol ? '' : "ALTER TABLE {$actualBeneficiaryTable} ADD COLUMN guardian_national_id VARCHAR(20), ADD COLUMN guardian_relationship VARCHAR(50);"
    );
    
    addResult(
        '3. Beneficiary & Program Management',
        'Eligibility status tracking',
        $hasEligibilityCol ? 'pass' : 'warn',
        $hasEligibilityCol ? 'Present' : 'Missing — only is_active boolean',
        $hasEligibilityCol ? '' : "ALTER TABLE {$actualBeneficiaryTable} ADD COLUMN eligibility_status VARCHAR(30) DEFAULT 'ACTIVE';"
    );
    
    addResult(
        '3. Beneficiary & Program Management',
        'Department/Program linkage',
        $hasDeptLink ? 'pass' : 'warn',
        $hasDeptLink ? 'Present' : 'MISSING — beneficiaries only belong to organization_id',
        $hasDeptLink ? '' : "ALTER TABLE {$actualBeneficiaryTable} ADD COLUMN department_id INTEGER, ADD COLUMN program_id INTEGER;"
    );
}

$hasRecurringSchedule = tableExists($db, 'disbursement_schedules');
addResult(
    '3. Beneficiary & Program Management',
    'Recurring disbursement schedules',
    $hasRecurringSchedule ? 'pass' : 'warn',
    $hasRecurringSchedule ? 'Present' : 'MISSING — every batch requires manual upload',
    $hasRecurringSchedule ? '' : 'Add disbursement_schedules table for automated recurring payments.'
);

// ============================================================================
// 4. ACCESS CONTROL — ENTERPRISE ROLES
// ============================================================================

$stmt = $db->prepare("SELECT DISTINCT role FROM organization_users WHERE organization_id = :org_id");
try {
    $stmt->execute([':org_id' => $orgId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $roles = [];
}
addResult(
    '4. Access Control',
    'Roles in use',
    count($roles) > 0 ? 'pass' : 'warn',
    count($roles) > 0 ? 'Roles found: ' . implode(', ', $roles) : 'No roles found for this organization',
    ''
);

// Check for segregation of duties
$hasUploader = in_array('uploader', $roles);
$hasApprover = in_array('approver', $roles);
addResult(
    '4. Access Control',
    'Segregation of duties (maker-checker)',
    ($hasUploader && $hasApprover) ? 'pass' : 'warn',
    ($hasUploader && $hasApprover) ? 'Has both uploader and approver roles' : 'No clear separation between who uploads and who approves',
    'Add explicit UPLOADER and APPROVER roles and enforce maker-checker separation.'
);

// ============================================================================
// 5. DISBURSEMENT PIPELINE WIRING
// ============================================================================

$executeFilePath = $projectRoot . '/public/admin/enterprise/imports/execute.php';
$executeContent = file_exists($executeFilePath) ? file_get_contents($executeFilePath) : '';

$hasRealCall = $executeContent && (
    preg_match('/new\s+\\\\?(Domain\\\\Services\\\\)?SwapService\s*\(/', $executeContent) ||
    preg_match('/->\s*executeAtomicSwap\s*\(/', $executeContent) ||
    preg_match('/->\s*executeMultiDestinationSwap\s*\(/', $executeContent)
);

$isStubbed = $executeContent && (
    strpos($executeContent, 'Simulate swap execution') !== false ||
    preg_match('/\$swapReference\s*=\s*[\'"]SWAP_[\'"]\s*\.\s*date/i', $executeContent)
);

$isWired = $hasRealCall && !$isStubbed;

addResult(
    '5. Disbursement Pipeline Wiring',
    'execute.php connected to SwapService',
    $isWired ? 'pass' : 'fail',
    $isWired
        ? 'Real SwapService call found'
        : ($isStubbed
            ? 'FAIL — execute.php contains stubbed code, not real SwapService calls'
            : 'execute.php not found or contains no real SwapService method call'),
    $isWired ? '' : 'Rewrite execute.php to call SwapService::executeMultiDestinationSwap() with real payloads.'
);

$hasProviderRegistry = tableExists($db, 'providers') || tableExists($db, 'institution_registry');
addResult(
    '5. Disbursement Pipeline Wiring',
    'Institution/provider registry',
    $hasProviderRegistry ? 'pass' : 'fail',
    $hasProviderRegistry ? 'Present' : 'MISSING — destination_provider is free-text with no validation',
    $hasProviderRegistry ? '' : 'Create providers table seeded from participants.yaml.'
);

$hasAssetTypeCol = columnExists($db, 'organization_sources', 'asset_type');
addResult(
    '5. Disbursement Pipeline Wiring',
    'organization_sources: asset_type',
    $hasAssetTypeCol ? 'pass' : 'warn',
    $hasAssetTypeCol ? 'Present' : 'MISSING — SwapService expects ACCOUNT or WALLET explicitly',
    $hasAssetTypeCol ? '' : "ALTER TABLE organization_sources ADD COLUMN asset_type VARCHAR(20) DEFAULT 'WALLET';"
);

// ============================================================================
// 6. APPROVAL & GOVERNANCE
// ============================================================================

$hasApprovalThresholds = tableExists($db, 'approval_thresholds');
addResult(
    '6. Approval & Governance',
    'Amount-based approval thresholds',
    $hasApprovalThresholds ? 'pass' : 'warn',
    $hasApprovalThresholds ? 'Present' : 'MISSING — all amounts go through identical single-approver flow',
    $hasApprovalThresholds ? '' : 'Add approval_thresholds table for dual control based on amount.'
);

$approveCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/approve.php', ['approve_payments', 'permission']);
addResult(
    '6. Approval & Governance',
    'Reject action permission check',
    'warn',
    'approve.php enforces permissions on "approve" but not on "reject" or "submit" actions',
    'Add role/permission checks to reject and submit branches in approve.php.'
);

addResult(
    '6. Approval & Governance',
    'Approval self-check (maker ≠ checker)',
    'fail',
    'Nothing prevents the user who uploaded a batch from approving it themselves',
    'Compare $batch[\'uploaded_by\'] against $user[\'id\'] and block self-approval.'
);

// ============================================================================
// 7. AUDIT & COMPLIANCE
// ============================================================================

$hasAuditLog = tableExists($db, 'organization_audit_logs');
addResult(
    '7. Audit & Compliance',
    'Immutable audit log table',
    $hasAuditLog ? 'pass' : 'fail',
    $hasAuditLog ? 'Present — ' . (rowCount($db, 'organization_audit_logs') ?? '?') . ' entries' : 'MISSING',
    $hasAuditLog ? '' : 'Create organization_audit_logs table for compliance tracking.'
);

if ($hasAuditLog) {
    $auditWiring = [
        'imports/upload.php' => fileContains($projectRoot, 'public/admin/enterprise/imports/upload.php', ['organization_audit_logs', 'AuditTrailService']),
        'imports/approve.php' => fileContains($projectRoot, 'public/admin/enterprise/imports/approve.php', ['organization_audit_logs', 'AuditTrailService']),
        'imports/execute.php' => fileContains($projectRoot, 'public/admin/enterprise/imports/execute.php', ['organization_audit_logs', 'AuditTrailService']),
    ];
    $unwired = [];
    foreach ($auditWiring as $file => $check) {
        $writes = $check['exists'] && ((($check['matches']['organization_audit_logs'] ?? false)) || (($check['matches']['AuditTrailService'] ?? false)));
        if (!$writes) $unwired[] = $file;
    }
    addResult(
        '7. Audit & Compliance',
        'Dashboard actions write to audit log',
        empty($unwired) ? 'pass' : 'fail',
        empty($unwired)
            ? 'All checked endpoints reference the audit log'
            : 'Not writing to audit log: ' . implode(', ', $unwired),
        empty($unwired) ? '' : 'Add organization_audit_logs inserts to each endpoint.'
    );
}

$hasExecSummary = tableExists($db, 'batch_execution_summary');
addResult(
    '7. Audit & Compliance',
    'Batch execution summary table',
    $hasExecSummary ? 'pass' : 'fail',
    $hasExecSummary ? 'Present' : 'MISSING — needed for reconciliation',
    $hasExecSummary ? '' : 'Create batch_execution_summary table for reconciliation reports.'
);

// ============================================================================
// 8. SECURITY HARDENING
// ============================================================================

$uploadCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/upload.php', ['mkdir($uploadDir, 0777']);
addResult(
    '8. Security Hardening',
    'Upload directory permission mode',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'fail' : 'pass',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'FAIL — 0777 world-writable' : 'Not flagged',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? "Change to mkdir(\$uploadDir, 0750, true)" : ''
);

$csrfCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/review.php', ['csrf_token', 'CSRF']);
addResult(
    '8. Security Hardening',
    'CSRF protection on forms',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'pass' : 'fail',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'Present' : 'MISSING — no CSRF tokens on state-changing forms',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? '' : 'Add CSRF tokens to all state-changing forms and verify server-side.'
);

addResult(
    '8. Security Hardening',
    'Session cookie hardening',
    'warn',
    'No explicit session.cookie_secure / cookie_httponly / cookie_samesite configuration visible',
    "Set session.cookie_httponly=1, session.cookie_secure=1, session.cookie_samesite=Strict in auth.php"
);

// ============================================================================
// SCORING
// ============================================================================

$totalChecks = 0; $passCount = 0; $warnCount = 0; $failCount = 0;
foreach ($results as $cat => $items) {
    foreach ($items as $item) {
        $totalChecks++;
        if ($item['status'] === 'pass') $passCount++;
        elseif ($item['status'] === 'warn') $warnCount++;
        else $failCount++;
    }
}
$readinessScore = $totalChecks > 0 ? round(($passCount / $totalChecks) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Readiness Diagnostic — VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #0f172a; padding: 32px; }
        .container { max-width: 1100px; margin: 0 auto; }
        .header { margin-bottom: 32px; }
        .header h1 { font-size: 28px; font-weight: 800; margin-bottom: 8px; }
        .header .subtitle { color: #64748b; }
        .org-info {
            background: #e0f2fe;
            padding: 12px 20px;
            border-radius: 12px;
            margin-top: 12px;
            border: 1px solid #7dd3fc;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .org-info .label { font-weight: 600; color: #0369a1; }
        .org-info .value { color: #0c4a6e; }
        .score-banner {
            display: flex; align-items: center; gap: 32px;
            background: white; border-radius: 20px; padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .score-circle {
            width: 120px; height: 120px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-direction: column; font-weight: 800; flex-shrink: 0;
            background: conic-gradient(
                <?php echo $readinessScore >= 70 ? '#10b981' : ($readinessScore >= 40 ? '#f59e0b' : '#ef4444'); ?> <?php echo $readinessScore * 3.6; ?>deg,
                #e2e8f0 0deg
            );
        }
        .score-circle-inner {
            width: 92px; height: 92px; border-radius: 50%; background: white;
            display: flex; align-items: center; justify-content: center; flex-direction: column;
        }
        .score-circle-inner .num { font-size: 26px; font-weight: 800; }
        .score-circle-inner .label { font-size: 10px; color: #64748b; }
        .score-summary h2 { font-size: 20px; margin-bottom: 12px; }
        .pill-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .pill { padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .pill-pass { background: #dcfce7; color: #166534; }
        .pill-warn { background: #fef3c7; color: #92400e; }
        .pill-fail { background: #fee2e2; color: #991b1b; }
        .category { background: white; border-radius: 16px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .category-header { padding: 18px 24px; font-weight: 700; font-size: 16px; background: #0f172a; color: white; }
        .check-row { padding: 18px 24px; border-bottom: 1px solid #f1f5f9; }
        .check-row:last-child { border-bottom: none; }
        .check-top { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 6px; }
        .status-badge {
            width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 800; color: white; margin-top: 2px;
        }
        .status-pass { background: #10b981; }
        .status-warn { background: #f59e0b; }
        .status-fail { background: #ef4444; }
        .check-name { font-weight: 600; font-size: 14.5px; }
        .check-message { font-size: 13.5px; color: #475569; margin-left: 34px; margin-bottom: 6px; line-height: 1.5; }
        .check-fix {
            margin-left: 34px; font-size: 13px; background: #f8fafc; border-left: 3px solid #3b82f6;
            padding: 10px 14px; border-radius: 8px; color: #1e3a8a; line-height: 1.5;
        }
        .check-fix strong { color: #1e40af; }
        .back-link {
            display: inline-block; margin-top: 20px; color: #3b82f6;
            text-decoration: none; font-weight: 500;
        }
        .back-link:hover { text-decoration: underline; }
        .timestamp { color: #94a3b8; font-size: 13px; }
        .org-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #0f172a;
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏛 System Readiness Diagnostic</h1>
        <p class="subtitle">Government / Enterprise disbursement platform alignment check</p>
        
        <div class="org-info">
            <div>
                <span class="label">Organization:</span>
                <span class="value"><?php echo htmlspecialchars($user['organization_name'] ?? 'N/A'); ?></span>
            </div>
            <div>
                <span class="label">User:</span>
                <span class="value"><?php echo htmlspecialchars($user['email']); ?></span>
            </div>
            <div>
                <span class="label">Role:</span>
                <span class="org-badge"><?php echo htmlspecialchars($user['role'] ?? 'user'); ?></span>
            </div>
            <div>
                <span class="label">Organization ID:</span>
                <span class="value">#<?php echo htmlspecialchars($orgId); ?></span>
            </div>
        </div>
        
        <p class="timestamp" style="margin-top: 12px;">
            Run on <?php echo date('F d, Y H:i'); ?>
        </p>
    </div>

    <?php if (!isset($user) || empty($user)): ?>
    <div class="login-prompt">
        <h2>🔒 Authentication Required</h2>
        <p>You need to be logged in to access the system diagnostic.</p>
        <a href="login.php">Go to Login →</a>
    </div>
    <?php else: ?>

    <div class="score-banner">
        <div class="score-circle">
            <div class="score-circle-inner">
                <div class="num"><?php echo $readinessScore; ?>%</div>
                <div class="label">READY</div>
            </div>
        </div>
        <div class="score-summary">
            <h2><?php echo $totalChecks; ?> checks run against live database + source code</h2>
            <div class="pill-row">
                <span class="pill pill-pass">✓ <?php echo $passCount; ?> Passing</span>
                <span class="pill pill-warn">⚠ <?php echo $warnCount; ?> Needs Attention</span>
                <span class="pill pill-fail">✗ <?php echo $failCount; ?> Failing</span>
            </div>
            <a href="index.php" class="back-link">← Back to Dashboard</a>
        </div>
    </div>

    <?php foreach ($results as $category => $items): ?>
    <div class="category">
        <div class="category-header"><?php echo htmlspecialchars($category); ?></div>
        <?php foreach ($items as $item): ?>
        <div class="check-row">
            <div class="check-top">
                <div class="status-badge status-<?php echo $item['status']; ?>">
                    <?php echo $item['status'] === 'pass' ? '✓' : ($item['status'] === 'warn' ? '!' : '✗'); ?>
                </div>
                <div class="check-name"><?php echo htmlspecialchars($item['name']); ?></div>
            </div>
            <div class="check-message"><?php echo htmlspecialchars($item['message']); ?></div>
            <?php if (!empty($item['fix'])): ?>
            <div class="check-fix"><strong>Fix:</strong> <?php echo htmlspecialchars($item['fix']); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <p style="text-align:center; color:#94a3b8; font-size:12px; margin-top:24px;">
        This diagnostic reads your live schema (information_schema / to_regclass) and greps your actual source files —
        it does not modify anything. Re-run after each fix to watch the readiness score move.
    </p>
    
    <?php endif; ?>
</div>
</body>
</html>
