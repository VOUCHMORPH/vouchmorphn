<?php
/**
 * ============================================================================
 * VOUCHMORPH ENTERPRISE — GOVERNMENT/ENTERPRISE READINESS DIAGNOSTIC
 * ============================================================================
 *
 * DROP-IN LOCATION: public/admin/enterprise/system_diagnostic.php
 * (lives INSIDE enterprise/, alongside auth.php — paths below reflect that)
 *
 * Categories:
 *   1. Core infrastructure & environment
 *   2. Organizational structure (departments, programs, role catalog)
 *   3. Beneficiary & program management (persistent registry)
 *   4. Access control (government role layers, department scoping)
 *   5. Disbursement pipeline wiring (money movement + multi-destination
 *      + identity-based routing)
 *   6. Approval & governance (dual control, maker-checker, thresholds)
 *   7. Audit & compliance
 *   8. Security hardening
 *   9. Platform/organization tier separation
 *
 * ACCESS: owner role only. This tool exposes file paths, extension lists,
 * and infra details — narrower than the audit/reporting roles should see.
 * ============================================================================
 */

require_once __DIR__ . '/auth.php'; // FIXED: same directory, not /enterprise/auth.php
$user = requireEnterpriseAuth();

// FIXED: role catalog no longer includes 'admin'/'super_admin' — only
// 'owner' has org-wide authority. Adjust here if you introduce a distinct
// platform-support role later, but keep this narrow deliberately.
if (($user['role'] ?? '') !== 'owner') {
    header('HTTP/1.1 403 Forbidden');
    die('System diagnostics require the owner role.');
}

// FIXED: three levels up from public/admin/enterprise/ to reach repo root's src/
require_once dirname(__DIR__, 3) . '/src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

// FIXED: standardized on getInstance() (see auth.php's own standardization
// note) — getConnection() and `new DBConnection()->getConnection()` are the
// other two patterns found elsewhere in this codebase; all three should
// converge on one now.
$db = DBConnection::getInstance();
$orgId = getOrganizationId();

// FIXED: three levels up, not two — fileContains() calls below use paths
// like 'public/admin/enterprise/imports/execute.php' relative to the ACTUAL
// repo root, not to public/.
$projectRoot = realpath(dirname(__DIR__, 3));

// ============================================================================
// CHECK ENGINE
// ============================================================================

$results = [];

function addResult(string $category, string $name, string $status, string $message, string $fix = ''): void {
    global $results;
    $results[$category][] = ['name' => $name, 'status' => $status, 'message' => $message, 'fix' => $fix];
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
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = :t AND column_name = :c");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function rowCount(PDO $db, string $table): ?int {
    try {
        return (int)$db->query("SELECT COUNT(*) FROM " . $table)->fetchColumn();
    } catch (Exception $e) {
        return null;
    }
}

function fileContains(string $root, string $relPath, array $needles): array {
    $full = $root . '/' . ltrim($relPath, '/');
    if (!file_exists($full)) return ['exists' => false, 'matches' => []];
    $content = file_get_contents($full);
    $matches = [];
    foreach ($needles as $needle) { $matches[$needle] = (strpos($content, $needle) !== false); }
    return ['exists' => true, 'matches' => $matches];
}

// ============================================================================
// 1. CORE INFRASTRUCTURE & ENVIRONMENT
// ============================================================================

addResult('1. Core Infrastructure', 'Database connectivity', 'pass', 'Connected successfully via ' . $db->getAttribute(PDO::ATTR_DRIVER_NAME));

$phpVersion = PHP_VERSION;
addResult('1. Core Infrastructure', 'PHP version',
    version_compare($phpVersion, '8.1.0', '>=') ? 'pass' : 'warn',
    "Running PHP {$phpVersion}",
    version_compare($phpVersion, '8.1.0', '>=') ? '' : 'Upgrade to PHP 8.1+.'
);

foreach (['pdo_pgsql', 'curl', 'openssl', 'mbstring', 'json'] as $ext) {
    addResult('1. Core Infrastructure', "Extension: {$ext}",
        extension_loaded($ext) ? 'pass' : 'fail',
        extension_loaded($ext) ? 'Loaded' : 'MISSING',
        extension_loaded($ext) ? '' : "Install php-{$ext}."
    );
}

addResult('1. Core Infrastructure', 'Upload directory security',
    is_dir('/tmp/vouchmorph_uploads') ? (substr(sprintf('%o', fileperms('/tmp/vouchmorph_uploads')), -3) === '777' ? 'fail' : 'pass') : 'warn',
    is_dir('/tmp/vouchmorph_uploads') ? 'Permissions: ' . substr(sprintf('%o', fileperms('/tmp/vouchmorph_uploads')), -3) : 'Directory not yet created',
    'Change to 0750, web server user only.'
);

// DBConnection call-pattern consistency across the codebase
$dbPatternFiles = [
    'enterprise auth.php' => 'public/admin/enterprise/auth.php',
    'imports/execute.php' => 'public/admin/enterprise/imports/execute.php',
    'api/v1/swap/execute.php' => 'public/api/v1/swap/execute.php',
];
$patternsFound = [];
foreach ($dbPatternFiles as $label => $relPath) {
    $full = $projectRoot . '/' . $relPath;
    if (!file_exists($full)) continue;
    $content = file_get_contents($full);
    if (preg_match('/DBConnection::getInstance\s*\(/', $content)) $patternsFound[$label] = 'getInstance()';
    elseif (preg_match('/DBConnection::getConnection\s*\(/', $content)) $patternsFound[$label] = 'getConnection()';
    elseif (preg_match('/new\s+DBConnection\s*\(/', $content)) $patternsFound[$label] = 'new DBConnection()';
}
$distinctPatterns = array_unique(array_values($patternsFound));
addResult('1. Core Infrastructure', 'DBConnection call pattern consistency',
    count($distinctPatterns) <= 1 ? 'pass' : 'warn',
    count($distinctPatterns) <= 1
        ? 'Consistent pattern across checked files: ' . (reset($distinctPatterns) ?: 'none found')
        : 'INCONSISTENT — ' . implode('; ', array_map(fn($l, $p) => "{$l} uses {$p}", array_keys($patternsFound), array_values($patternsFound))),
    count($distinctPatterns) <= 1 ? '' : 'Pick one pattern (recommend DBConnection::getInstance()) and update every call site to match — mixing patterns risks separate connections/transaction state within one request.'
);

// ============================================================================
// 2. ORGANIZATIONAL STRUCTURE
// ============================================================================

addResult('2. Organizational Structure', 'organizations table',
    tableExists($db, 'organizations') ? 'pass' : 'fail',
    tableExists($db, 'organizations') ? 'Present' : 'MISSING', ''
);

$hasDepartments = tableExists($db, 'departments');
addResult('2. Organizational Structure', 'departments table',
    $hasDepartments ? 'pass' : 'warn',
    $hasDepartments ? 'Present — ' . rowCount($db, 'departments') . ' departments' : 'MISSING',
    $hasDepartments ? '' : 'Run migration_government_alignment.sql.'
);

$hasBudgetLines = tableExists($db, 'disbursement_programs');
addResult('2. Organizational Structure', 'Programs / budget lines',
    $hasBudgetLines ? 'pass' : 'warn',
    $hasBudgetLines ? 'Present' : 'MISSING',
    $hasBudgetLines ? '' : 'Run migration_government_alignment.sql.'
);

$hasRoleCatalog = tableExists($db, 'organization_role_catalog');
$hasRolePermissions = tableExists($db, 'organization_role_permissions');
addResult('2. Organizational Structure', 'Government role catalog',
    $hasRoleCatalog ? 'pass' : 'fail',
    $hasRoleCatalog ? 'Present — ' . (rowCount($db, 'organization_role_catalog') ?? '?') . ' roles defined' : 'MISSING — no defined role catalog (owner/department_head/program_officer/approver/senior_approver/beneficiary_registrar/auditor/viewer)',
    $hasRoleCatalog ? '' : 'Run migration_government_alignment.sql section B7.'
);
addResult('2. Organizational Structure', 'Role → permission matrix',
    $hasRolePermissions ? 'pass' : 'fail',
    $hasRolePermissions ? 'Present — ' . (rowCount($db, 'organization_role_permissions') ?? '?') . ' role/permission mappings' : 'MISSING — hasPermission() in auth.php will fail closed (deny everything except owner) without this table',
    $hasRolePermissions ? '' : 'Run migration_government_alignment.sql section B7.'
);

// ============================================================================
// 3. BENEFICIARY & PROGRAM MANAGEMENT
// ============================================================================

$actualBeneficiaryTable = tableExists($db, 'organizations_beneficiaries') ? 'organizations_beneficiaries' : (tableExists($db, 'organization_beneficiaries') ? 'organization_beneficiaries' : null);
$codeExpectsTable = 'organization_beneficiaries';

addResult('3. Beneficiary & Program Management', 'Beneficiary table exists',
    $actualBeneficiaryTable ? 'pass' : 'fail',
    $actualBeneficiaryTable ? "Present as `{$actualBeneficiaryTable}` — " . (rowCount($db, $actualBeneficiaryTable) ?? '?') . ' beneficiaries' : 'MISSING', ''
);

addResult('3. Beneficiary & Program Management', 'Table name matches code',
    ($actualBeneficiaryTable === $codeExpectsTable) ? 'pass' : 'fail',
    ($actualBeneficiaryTable === $codeExpectsTable) ? 'Match' : "MISMATCH — real table is `{$actualBeneficiaryTable}`",
    ($actualBeneficiaryTable === $codeExpectsTable) ? '' : "ALTER TABLE organizations_beneficiaries RENAME TO organization_beneficiaries;"
);

if ($actualBeneficiaryTable) {
    foreach ([
        ['beneficiary_category', 'Beneficiary categorization'],
        ['guardian_national_id', 'Guardian/dependent linkage'],
        ['eligibility_status', 'Eligibility status tracking'],
        ['department_id', 'Department/Program linkage'],
    ] as [$col, $label]) {
        $has = columnExists($db, $actualBeneficiaryTable, $col);
        addResult('3. Beneficiary & Program Management', $label,
            $has ? 'pass' : 'warn',
            $has ? 'Present' : "MISSING column: {$col}",
            $has ? '' : "ALTER TABLE {$actualBeneficiaryTable} ADD COLUMN {$col} ..."
        );
    }
}

$hasRecurringSchedule = tableExists($db, 'disbursement_schedules');
addResult('3. Beneficiary & Program Management', 'Recurring disbursement schedules',
    $hasRecurringSchedule ? 'pass' : 'warn',
    $hasRecurringSchedule ? 'Present' : 'MISSING — every batch requires manual upload',
    $hasRecurringSchedule ? '' : 'Add disbursement_schedules table.'
);

// ============================================================================
// 4. ACCESS CONTROL — GOVERNMENT ROLE LAYERS + DEPARTMENT SCOPING
// ============================================================================

$stmt = $db->prepare("SELECT DISTINCT role FROM organization_users WHERE organization_id = :org_id");
try {
    $stmt->execute([':org_id' => $orgId]);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $roles = [];
}
addResult('4. Access Control', 'Roles in use',
    count($roles) > 0 ? 'pass' : 'warn',
    count($roles) > 0 ? 'Roles found: ' . implode(', ', $roles) : 'No roles found', ''
);

$governmentRoles = ['owner', 'department_head', 'program_officer', 'approver', 'senior_approver', 'beneficiary_registrar', 'auditor', 'viewer'];
$unrecognizedRoles = array_diff($roles, $governmentRoles);
addResult('4. Access Control', 'Roles match the government role catalog',
    empty($unrecognizedRoles) ? 'pass' : 'warn',
    empty($unrecognizedRoles) ? 'All in-use roles are recognized' : 'Roles in use but NOT in the catalog (e.g. leftover "admin"): ' . implode(', ', $unrecognizedRoles),
    empty($unrecognizedRoles) ? '' : 'Either migrate these users to a catalog role, or add the role explicitly to organization_role_catalog with a defined permission set.'
);

$hasUploaderRole = in_array('program_officer', $roles);
$hasApproverRole = in_array('approver', $roles) || in_array('senior_approver', $roles);
addResult('4. Access Control', 'Segregation of duties (maker-checker)',
    ($hasUploaderRole && $hasApproverRole) ? 'pass' : 'warn',
    ($hasUploaderRole && $hasApproverRole) ? 'Has both program_officer and approver/senior_approver assigned' : 'No one is assigned a dedicated approver role distinct from uploaders yet',
    ($hasUploaderRole && $hasApproverRole) ? '' : 'Assign at least one program_officer and one approver — right now this may just be the same owner account doing both.'
);

$hasDeptIdCol = columnExists($db, 'organization_users', 'department_id');
addResult('4. Access Control', 'organization_users.department_id (department scoping)',
    $hasDeptIdCol ? 'pass' : 'fail',
    $hasDeptIdCol ? 'Present' : 'MISSING — getUserDepartmentScope() in auth.php cannot function without this column',
    $hasDeptIdCol ? '' : 'Run migration_government_alignment.sql section B7.'
);

if ($hasDeptIdCol && $hasDepartments) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM organization_users
            WHERE organization_id = :org_id
              AND role IN ('department_head','program_officer','approver','senior_approver','beneficiary_registrar','viewer')
              AND department_id IS NULL
        ");
        $stmt->execute([':org_id' => $orgId]);
        $unscopedCount = (int)$stmt->fetchColumn();
        addResult('4. Access Control', 'Department-scoped users actually have a department assigned',
            $unscopedCount === 0 ? 'pass' : 'fail',
            $unscopedCount === 0 ? 'All department-scoped users have department_id set' : "{$unscopedCount} user(s) have a department-scoped role but NO department_id — getUserDepartmentScope() would return null for them, meaning they'd see NOTHING (queries filtering on department_id would match zero rows) rather than being properly scoped",
            $unscopedCount === 0 ? '' : 'Assign a department_id to every user with a department-scoped role.'
        );
    } catch (Exception $e) {
        // table/column existence already covered above; skip on query issues
    }
}

$authContent = file_exists(__DIR__ . '/auth.php') ? file_get_contents(__DIR__ . '/auth.php') : '';
addResult('4. Access Control', 'getUserDepartmentScope() implemented',
    (bool)preg_match('/function\s+getUserDepartmentScope/', $authContent) ? 'pass' : 'fail',
    (bool)preg_match('/function\s+getUserDepartmentScope/', $authContent) ? 'Present' : 'MISSING from auth.php', ''
);
addResult('4. Access Control', 'assertNotSelfApproving() implemented',
    (bool)preg_match('/function\s+assertNotSelfApproving/', $authContent) ? 'pass' : 'fail',
    (bool)preg_match('/function\s+assertNotSelfApproving/', $authContent) ? 'Present' : 'MISSING from auth.php', ''
);

// ============================================================================
// 5. DISBURSEMENT PIPELINE WIRING
// ============================================================================

$executeRelPath = 'public/admin/enterprise/imports/execute.php';
$executeExists = file_exists($projectRoot . '/' . $executeRelPath);
$executeContent = $executeExists ? file_get_contents($projectRoot . '/' . $executeRelPath) : '';

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

addResult('5. Disbursement Pipeline Wiring', 'execute.php connected to SwapService',
    $isWired ? 'pass' : 'fail',
    $isWired ? 'Real SwapService call found, no stub pattern detected' : ($isStubbed ? 'FAIL — stubbed fabricated-reference pattern still present' : 'No real SwapService call found'),
    $isWired ? '' : 'Deploy execute_fixed.php in place of the current stub.'
);

$hasIdentityRouting = $executeContent && preg_match('/->\s*initiateSwapToIdentity\s*\(/', $executeContent);
addResult('5. Disbursement Pipeline Wiring', 'Identity-based routing (send-to-identity)',
    $hasIdentityRouting ? 'pass' : 'warn',
    $hasIdentityRouting ? 'execute.php calls initiateSwapToIdentity() for IDENTITY-routed rows' : 'MISSING — a batch with a beneficiary known only by National ID/phone/email (no wallet/account yet) has no handling path today',
    $hasIdentityRouting ? '' : 'Deploy execute_fixed.php, which splits IDENTITY rows out and processes them via initiateSwapToIdentity() individually.'
);

$hasIdentityTypeCol = columnExists($db, 'import_rows', 'identity_type');
addResult('5. Disbursement Pipeline Wiring', 'import_rows.identity_type column',
    $hasIdentityTypeCol ? 'pass' : 'warn',
    $hasIdentityTypeCol ? 'Present' : 'MISSING — needed to record national_id/phone/email for IDENTITY-routed rows',
    $hasIdentityTypeCol ? '' : 'Run migration_government_alignment.sql.'
);

$hasProviderRegistry = tableExists($db, 'providers');
addResult('5. Disbursement Pipeline Wiring', 'Institution/provider registry',
    $hasProviderRegistry ? 'pass' : 'fail',
    $hasProviderRegistry ? 'Present' : 'MISSING — destination_provider is free-text',
    $hasProviderRegistry ? '' : 'Create providers table seeded from participants.yaml.'
);

$hasAssetTypeCol = columnExists($db, 'organization_sources', 'asset_type');
addResult('5. Disbursement Pipeline Wiring', 'organization_sources.asset_type',
    $hasAssetTypeCol ? 'pass' : 'warn',
    $hasAssetTypeCol ? 'Present' : 'MISSING',
    $hasAssetTypeCol ? '' : "ALTER TABLE organization_sources ADD COLUMN asset_type VARCHAR(20) DEFAULT 'WALLET';"
);

// ============================================================================
// 6. APPROVAL & GOVERNANCE
// ============================================================================

$hasApprovalThresholds = tableExists($db, 'approval_thresholds');
addResult('6. Approval & Governance', 'Amount-based approval thresholds',
    $hasApprovalThresholds ? 'pass' : 'warn',
    $hasApprovalThresholds ? 'Present' : 'MISSING',
    $hasApprovalThresholds ? '' : 'Run migration_government_alignment.sql.'
);

$hasBatchApprovals = tableExists($db, 'batch_approvals');
addResult('6. Approval & Governance', 'Multi-approver (dual control) tracking',
    $hasBatchApprovals ? 'pass' : 'fail',
    $hasBatchApprovals ? 'Present — ' . (rowCount($db, 'batch_approvals') ?? '?') . ' approval records' : 'MISSING — cannot enforce "2 approvers required above a threshold" without a way to record who has approved so far',
    $hasBatchApprovals ? '' : 'Run migration_government_alignment.sql.'
);

$approveRelPath = 'public/admin/enterprise/imports/approve.php';
$approveContent = file_exists($projectRoot . '/' . $approveRelPath) ? file_get_contents($projectRoot . '/' . $approveRelPath) : '';

$usesSelfApprovalGuard = $approveContent && preg_match('/assertNotSelfApproving\s*\(/', $approveContent);
addResult('6. Approval & Governance', 'Approval self-check (maker ≠ checker) enforced',
    $usesSelfApprovalGuard ? 'pass' : 'fail',
    $usesSelfApprovalGuard ? 'approve.php calls assertNotSelfApproving()' : 'Nothing prevents the uploader from approving their own batch — assertNotSelfApproving() exists in auth.php but approve.php does not call it',
    $usesSelfApprovalGuard ? '' : 'Add assertNotSelfApproving($batch[\'uploaded_by\']) to both the approve AND reject branches in approve.php.'
);

$rejectHasPermCheck = $approveContent && preg_match('/requirePermission\s*\(\s*[\'"]reject_batch[\'"]/', $approveContent);
addResult('6. Approval & Governance', 'Reject action permission check',
    $rejectHasPermCheck ? 'pass' : 'warn',
    $rejectHasPermCheck ? 'Present' : 'approve.php likely still permission-checks "approve" only, not "reject"/"submit"',
    $rejectHasPermCheck ? '' : "Add requirePermission('reject_batch') to the reject branch."
);

// ============================================================================
// 7. AUDIT & COMPLIANCE
// ============================================================================

$hasAuditLog = tableExists($db, 'organization_audit_logs');
addResult('7. Audit & Compliance', 'Immutable audit log table',
    $hasAuditLog ? 'pass' : 'fail',
    $hasAuditLog ? 'Present — ' . (rowCount($db, 'organization_audit_logs') ?? '?') . ' entries' : 'MISSING', ''
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
    addResult('7. Audit & Compliance', 'Dashboard actions write to audit log',
        empty($unwired) ? 'pass' : 'fail',
        empty($unwired) ? 'All checked endpoints reference the audit log' : 'Not writing to audit log: ' . implode(', ', $unwired),
        empty($unwired) ? '' : 'Add organization_audit_logs inserts to each endpoint listed (execute_fixed.php already does this for execute.php).'
    );
}

$hasExecSummary = tableExists($db, 'batch_execution_summary');
addResult('7. Audit & Compliance', 'Batch execution summary table',
    $hasExecSummary ? 'pass' : 'fail',
    $hasExecSummary ? 'Present' : 'MISSING', ''
);

// ============================================================================
// 8. SECURITY HARDENING
// ============================================================================

$uploadCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/upload.php', ['mkdir($uploadDir, 0777']);
addResult('8. Security Hardening', 'Upload directory permission mode',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'fail' : 'pass',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'FAIL — 0777 world-writable' : 'Not flagged',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? "Change to mkdir(\$uploadDir, 0750, true)" : ''
);

$csrfCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/review.php', ['csrf_token', 'CSRF']);
addResult('8. Security Hardening', 'CSRF protection on forms',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'pass' : 'fail',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'Present' : 'MISSING',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? '' : 'Add CSRF tokens to all state-changing forms.'
);

$loginCheck = fileContains($projectRoot, 'public/admin/enterprise/login.php', ['demo-cred', 'value="password123"', 'value="test@vouchmorph.com"']);
$hasDemoCreds = $loginCheck['exists'] && (($loginCheck['matches']['demo-cred'] ?? false) || ($loginCheck['matches']['value="password123"'] ?? false));
addResult('8. Security Hardening', 'Demo credentials removed from login page',
    $hasDemoCreds ? 'fail' : 'pass',
    $hasDemoCreds ? 'FAIL — demo credentials still displayed/pre-filled on the public login page' : 'Not found',
    $hasDemoCreds ? 'Remove the demo-cred block and pre-filled value="" attributes from login.php before any real pilot.' : ''
);

$hasCookieHardening = $authContent && (
    preg_match('/session\.cookie_httponly/', $authContent) &&
    preg_match('/session\.cookie_secure/', $authContent)
);
$hardeningBeforeSessionStart = false;
if ($hasCookieHardening) {
    $httponlyPos = strpos($authContent, 'session.cookie_httponly');
    $sessionStartPos = strpos($authContent, 'session_start(');
    $hardeningBeforeSessionStart = ($httponlyPos !== false && $sessionStartPos !== false && $httponlyPos < $sessionStartPos);
}
addResult('8. Security Hardening', 'Session cookie hardening',
    $hardeningBeforeSessionStart ? 'pass' : ($hasCookieHardening ? 'fail' : 'warn'),
    $hardeningBeforeSessionStart
        ? 'cookie_httponly/cookie_secure set before session_start() in auth.php'
        : ($hasCookieHardening
            ? 'FAIL — cookie hardening ini_set() calls found but positioned AFTER session_start() — PHP ignores session.cookie_* changes once a session is already active, so these currently have no effect'
            : 'No explicit session.cookie_secure / cookie_httponly / cookie_samesite configuration visible'),
    $hardeningBeforeSessionStart ? '' : "Set ini_set('session.cookie_httponly','1'), ini_set('session.cookie_secure','1'), ini_set('session.cookie_samesite','Lax') in auth.php, BEFORE the session_start() call, not in login.php after auth.php has already been required."
);

// ============================================================================
// 9. PLATFORM / ORGANIZATION TIER SEPARATION
// ============================================================================
// VouchMorph's own staff (src/Application/Admin/Auth/AdminAuth.php) must
// never share session state, tables, or login pages with organization users
// (public/admin/enterprise/auth.php). Checking that separation holds.

$hasAdminAuth = file_exists($projectRoot . '/src/Application/Admin/Auth/AdminAuth.php');
addResult('9. Platform/Organization Tier Separation', 'Platform admin auth exists separately',
    $hasAdminAuth ? 'pass' : 'warn',
    $hasAdminAuth ? 'Present at src/Application/Admin/Auth/AdminAuth.php' : 'Not found at expected path', ''
);

if ($hasAdminAuth) {
    $adminAuthContent = file_get_contents($projectRoot . '/src/Application/Admin/Auth/AdminAuth.php');
    $usesSameSessionKey = preg_match('/\$_SESSION\[[\'"]enterprise_user[\'"]\]/', $adminAuthContent);
    addResult('9. Platform/Organization Tier Separation', 'Platform admin uses a DISTINCT session key',
        !$usesSameSessionKey ? 'pass' : 'fail',
        !$usesSameSessionKey ? 'No overlap with $_SESSION[\'enterprise_user\'] detected' : 'FAIL — AdminAuth.php references $_SESSION[\'enterprise_user\'] — platform staff and organization users must never share a session key',
        !$usesSameSessionKey ? '' : 'Use a distinct session key, e.g. $_SESSION[\'platform_admin\'], backed by its own table (not organization_users).'
    );
}

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
        .org-info { background: #e0f2fe; padding: 12px 20px; border-radius: 12px; margin-top: 12px; border: 1px solid #7dd3fc; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .org-info .label { font-weight: 600; color: #0369a1; }
        .org-info .value { color: #0c4a6e; }
        .score-banner { display: flex; align-items: center; gap: 32px; background: white; border-radius: 20px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 32px; flex-wrap: wrap; }
        .score-circle { width: 120px; height: 120px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-direction: column; font-weight: 800; flex-shrink: 0;
            background: conic-gradient(<?php echo $readinessScore >= 70 ? '#10b981' : ($readinessScore >= 40 ? '#f59e0b' : '#ef4444'); ?> <?php echo $readinessScore * 3.6; ?>deg, #e2e8f0 0deg); }
        .score-circle-inner { width: 92px; height: 92px; border-radius: 50%; background: white; display: flex; align-items: center; justify-content: center; flex-direction: column; }
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
        .status-badge { width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; color: white; margin-top: 2px; }
        .status-pass { background: #10b981; }
        .status-warn { background: #f59e0b; }
        .status-fail { background: #ef4444; }
        .check-name { font-weight: 600; font-size: 14.5px; }
        .check-message { font-size: 13.5px; color: #475569; margin-left: 34px; margin-bottom: 6px; line-height: 1.5; }
        .check-fix { margin-left: 34px; font-size: 13px; background: #f8fafc; border-left: 3px solid #3b82f6; padding: 10px 14px; border-radius: 8px; color: #1e3a8a; line-height: 1.5; }
        .check-fix strong { color: #1e40af; }
        .back-link { display: inline-block; margin-top: 20px; color: #3b82f6; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
        .timestamp { color: #94a3b8; font-size: 13px; }
        .org-badge { display: inline-block; padding: 4px 12px; background: #0f172a; color: white; border-radius: 20px; font-size: 12px; font-weight: 600; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏛 System Readiness Diagnostic</h1>
        <p class="subtitle">Government / Enterprise disbursement platform alignment check</p>
        <div class="org-info">
            <div><span class="label">Organization:</span> <span class="value"><?php echo htmlspecialchars($user['organization_name'] ?? 'N/A'); ?></span></div>
            <div><span class="label">User:</span> <span class="value"><?php echo htmlspecialchars($user['email']); ?></span></div>
            <div><span class="label">Role:</span> <span class="org-badge"><?php echo htmlspecialchars($user['role'] ?? 'user'); ?></span></div>
            <div><span class="label">Organization ID:</span> <span class="value">#<?php echo htmlspecialchars((string)$orgId); ?></span></div>
        </div>
        <p class="timestamp" style="margin-top: 12px;">Run on <?php echo date('F d, Y H:i'); ?></p>
    </div>

    <div class="score-banner">
        <div class="score-circle"><div class="score-circle-inner"><div class="num"><?php echo $readinessScore; ?>%</div><div class="label">READY</div></div></div>
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
                <div class="status-badge status-<?php echo $item['status']; ?>"><?php echo $item['status'] === 'pass' ? '✓' : ($item['status'] === 'warn' ? '!' : '✗'); ?></div>
                <div class="check-name"><?php echo htmlspecialchars($item['name']); ?></div>
            </div>
            <div class="check-message"><?php echo htmlspecialchars($item['message']); ?></div>
            <?php if (!empty($item['fix'])): ?><div class="check-fix"><strong>Fix:</strong> <?php echo htmlspecialchars($item['fix']); ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <p style="text-align:center; color:#94a3b8; font-size:12px; margin-top:24px;">
        This diagnostic reads your live schema and greps your actual source files — it does not modify anything.
        Re-run after each fix to watch the readiness score move.
    </p>
</div>
</body>
</html>
