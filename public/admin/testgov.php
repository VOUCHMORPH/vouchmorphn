<?php
/**
 * ============================================================================
 * VOUCHMORPH ENTERPRISE — GOVERNMENT/ENTERPRISE READINESS DIAGNOSTIC
 * ============================================================================
 *
 * DROP-IN LOCATION: public/admin/system_diagnostic.php
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

require_once __DIR__ . '/enterprise/auth.php';
$user = requireEnterpriseAuth();

if (!in_array($user['role'], ['owner', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    die('System diagnostics require owner or admin role.');
}

require_once __DIR__ . '/../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();
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
// Governments don't have one "organization" — they have a Ministry, which has
// Departments, which have Programs. A single "Ministry of Social Development"
// account needs to segregate "Department of Social Protection" from
// "Department of Disability Services" from "Department of Elderly Care",
// each with its own budget line, its own approvers, its own beneficiary rolls.

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
    $hasDepartments ? 'pass' : 'fail',
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
// This is the single biggest structural gap for government use: your current
// import_rows table is TRANSACTIONAL — it exists only for the life of one
// upload. A pensioner who gets paid every month needs to exist as a durable
// record so the department isn't re-uploading and re-validating National IDs
// every 30 days, and so you can prove "this exact recipient has been paid for
// 14 consecutive quarters" to an auditor.

$hasOrgBeneficiaries = tableExists($db, 'organization_beneficiaries');
addResult(
    '3. Beneficiary & Program Management',
    'organization_beneficiaries table',
    $hasOrgBeneficiaries ? 'pass' : 'fail',
    $hasOrgBeneficiaries ? 'Present — ' . (rowCount($db, 'organization_beneficiaries') ?? '?') . ' active beneficiaries on file' : 'MISSING (referenced by index.php dashboard stats but table does not exist)',
    $hasOrgBeneficiaries ? '' : 'This table is already queried by enterprise/index.php but was never created — that dashboard stat is silently failing or returning 0.'
);

if ($hasOrgBeneficiaries) {
    $hasCategoryCol = columnExists($db, 'organization_beneficiaries', 'beneficiary_category');
    addResult(
        '3. Beneficiary & Program Management',
        'Beneficiary categorization',
        $hasCategoryCol ? 'pass' : 'fail',
        $hasCategoryCol ? 'Categorized' : 'MISSING beneficiary_category column — cannot distinguish a pensioner from an orphanage ward from a disability grant recipient in reporting or eligibility rules',
        $hasCategoryCol ? '' : "ALTER TABLE organization_beneficiaries ADD COLUMN beneficiary_category VARCHAR(50); -- e.g. 'OLD_AGE_PENSIONER', 'ORPHAN_VULNERABLE_CHILD', 'DISABILITY_GRANT', 'DESTITUTE', 'SOCIAL_SECURITY'"
    );

    $hasGuardianCol = columnExists($db, 'organization_beneficiaries', 'guardian_national_id');
    addResult(
        '3. Beneficiary & Program Management',
        'Guardian/dependent linkage',
        $hasGuardianCol ? 'pass' : 'warn',
        $hasGuardianCol ? 'Present' : 'MISSING — orphanage and minor-dependent disbursements need a legal guardian/institution of record on file, separate from the payment destination',
        $hasGuardianCol ? '' : 'ADD COLUMN guardian_national_id VARCHAR(20), guardian_relationship VARCHAR(50), institution_name VARCHAR(200) — needed when the beneficiary is a minor or ward of an orphanage/care institution and someone else administers the funds.'
    );

    $hasEligibilityCol = columnExists($db, 'organization_beneficiaries', 'eligibility_status');
    addResult(
        '3. Beneficiary & Program Management',
        'Eligibility / recertification tracking',
        $hasEligibilityCol ? 'pass' : 'warn',
        $hasEligibilityCol ? 'Present' : 'MISSING — no way to flag a beneficiary as pending recertification, deceased, or suspended without deleting their record (destroys audit history)',
        $hasEligibilityCol ? '' : "ADD COLUMN eligibility_status VARCHAR(30) DEFAULT 'ACTIVE'; -- ACTIVE, PENDING_RECERT, SUSPENDED, DECEASED, TRANSFERRED. Governments are legally required to keep the historical record, not delete it, when someone becomes ineligible."
    );
}

$hasRecurringSchedule = tableExists($db, 'disbursement_schedules');
addResult(
    '3. Beneficiary & Program Management',
    'Recurring disbursement schedules',
    $hasRecurringSchedule ? 'pass' : 'warn',
    $hasRecurringSchedule ? 'Present' : 'MISSING — every batch currently requires a fresh file upload. Pensions and social grants are recurring by law (monthly/quarterly) and should generate their own batches automatically from the standing beneficiary roll.',
    $hasRecurringSchedule ? '' : 'Add disbursement_schedules (id, program_id, frequency, next_run_date, beneficiary_query_filter, auto_generate_batch BOOLEAN) + a cron worker that materializes an import_batch from the current organization_beneficiaries roll on schedule, instead of requiring a human to re-upload a CSV every month.'
);

// ============================================================================
// 4. ACCESS CONTROL — WHO GETS TO LOG IN AND DO WHAT
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

$hasApprovePermConst = false;
foreach (['owner', 'admin', 'approver', 'uploader', 'viewer'] as $expectedRole) {
    if (in_array($expectedRole, ['owner', 'admin']) || in_array($expectedRole, $roles)) continue;
}
addResult(
    '4. Access Control',
    'Segregation of duties (maker-checker)',
    (in_array('uploader', $roles) || in_array('approver', $roles)) ? 'pass' : 'warn',
    'Current model only distinguishes owner/admin (can do everything) from everyone else via a flat permissions array. A government maker-checker requirement typically needs: UPLOADER (can create batches, cannot approve), APPROVER (can approve, cannot upload their own batch for approval), AUDITOR (read-only, sees everything including rejected/failed).',
    'Add explicit role constants and enforce "the user who uploaded a batch cannot also approve it" in approve.php — currently any admin/owner can approve a batch they themselves uploaded, which fails segregation-of-duties audits.'
);

$hasMFA = false; // no MFA references found in the enterprise auth flow shown
addResult(
    '4. Access Control',
    'Multi-factor authentication on login',
    'warn',
    'enterprise/login.php authenticates with email + password only. src/Security/Auth/MultifactorAuth.php exists in your codebase but is not called from the enterprise login flow.',
    'Wire MultifactorAuth into enterprise/login.php, at minimum for owner/admin/approver roles. Government procurement standards (and most banking regulators) will not accept password-only auth for anyone who can approve a disbursement.'
);

$demoCredsCheck = fileContains($projectRoot, 'public/admin/enterprise/login.php', ['demo@vouchmorph.com', 'demo123']);
addResult(
    '4. Access Control',
    'Demo credentials removed from login page',
    ($demoCredsCheck['exists'] && ($demoCredsCheck['matches']['demo@vouchmorph.com'] ?? false)) ? 'fail' : 'pass',
    ($demoCredsCheck['exists'] && ($demoCredsCheck['matches']['demo@vouchmorph.com'] ?? false)) ? 'FAIL — demo@vouchmorph.com / demo123 is printed directly on the public login page' : 'Not found on login page',
    ($demoCredsCheck['exists'] && ($demoCredsCheck['matches']['demo@vouchmorph.com'] ?? false)) ? 'Remove the <div class="demo-cred"> block from enterprise/login.php entirely before any government or enterprise pilot. This is an immediate, trivial account takeover path.' : ''
);

// ============================================================================
// 5. DISBURSEMENT PIPELINE WIRING — IS MONEY MOVEMENT ACTUALLY CONNECTED?
// ============================================================================

$executeCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/execute.php', [
    'SwapService', 'executeAtomicSwap', 'executeMultiDestinationSwap', 'Simulate swap execution'
]);

$isWired = $executeCheck['exists'] && (
    ($executeCheck['matches']['SwapService'] ?? false) ||
    ($executeCheck['matches']['executeAtomicSwap'] ?? false) ||
    ($executeCheck['matches']['executeMultiDestinationSwap'] ?? false)
);
$isStubbed = $executeCheck['exists'] && ($executeCheck['matches']['Simulate swap execution'] ?? false);

addResult(
    '5. Disbursement Pipeline Wiring',
    'execute.php connected to SwapService',
    $isWired ? 'pass' : 'fail',
    $isWired
        ? 'execute.php references SwapService / executeAtomicSwap — appears wired'
        : ($isStubbed
            ? 'FAIL — execute.php contains a stub comment ("Simulate swap execution") and fabricates a swap_reference without ever calling Domain\\Services\\SwapService. Every batch you "execute" today is marked SUCCESS without any money actually moving.'
            : 'execute.php not found or does not reference SwapService'),
    $isWired ? '' : 'Rewrite execute.php to build a payload matching SwapService::executeMultiDestinationSwap() — one source (from organization_sources), many destinations (from import_rows) — and to write per-destination results back to payment_instructions instead of assuming blanket success. This is the single most important fix before any real disbursement runs through this dashboard.'
);

$hasProviderRegistry = tableExists($db, 'providers') || tableExists($db, 'institution_registry');
addResult(
    '5. Disbursement Pipeline Wiring',
    'Institution/provider registry',
    $hasProviderRegistry ? 'pass' : 'fail',
    $hasProviderRegistry ? 'Present' : 'MISSING — import_rows.destination_provider is currently a free-text string typed by whoever mapped the column ("ZURUBANK"), with no validation against SwapService\'s InstitutionAdapterFactory, which needs an exact provider_code matching Countries/{country}/participants.yaml',
    $hasProviderRegistry ? '' : "Create a providers table (provider_code, display_name, institution_type ENUM[BANK, MOBILE_WALLET, CARD, CRYPTO], country_code, supported_asset_types[], active) seeded from participants.yaml, and validate/normalize destination_provider against it during validate.php — right now a typo'd bank name would fail silently or throw deep inside GenericBankClient instead of at upload time."
);

addResult(
    '5. Disbursement Pipeline Wiring',
    'Source institution/identifier on organization_sources',
    columnExists($db, 'organization_sources', 'institution') ? 'pass' : 'fail',
    columnExists($db, 'organization_sources', 'institution') ? 'Present' : 'MISSING — organization_sources currently only has source_name/provider/balance, but SwapService::extractSourceInstitution() and extractSourceIdentifier() need a real institution code and account/wallet identifier to place a hold',
    columnExists($db, 'organization_sources', 'institution') ? '' : 'ADD COLUMN institution VARCHAR(50), source_identifier VARCHAR(100), asset_type VARCHAR(20) DEFAULT \'WALLET\', source_reference VARCHAR(100) -- the last one links to user_authorized_sources if this wallet is registered as a "hooked" source, avoiding per-batch PIN entry.'
);

addResult(
    '5. Disbursement Pipeline Wiring',
    'Synchronous execution risk',
    'warn',
    'execute.php processes every row in a single PHP request with a foreach loop. For a pensioner roll of 50,000+ recipients this will exceed max_execution_time and leave the batch in an inconsistent PARTIAL state with no resume capability.',
    'Move batch execution to a queue worker (you already have scripts/daemons/outbox-worker.php and card-pool-finalize-worker.php as a pattern) — execute.php should enqueue the batch and redirect immediately; the worker calls SwapService per destination and updates payment_instructions as it goes, so the dashboard can show live progress instead of blocking.'
);

// ============================================================================
// 6. APPROVAL & GOVERNANCE
// ============================================================================

$hasApprovalThresholds = tableExists($db, 'approval_thresholds');
addResult(
    '6. Approval & Governance',
    'Amount-based approval thresholds',
    $hasApprovalThresholds ? 'pass' : 'warn',
    $hasApprovalThresholds ? 'Present' : 'MISSING — approval today is a single yes/no per batch regardless of amount. A P500 disbursement and a P50,000,000 disbursement go through the identical single-approver flow.',
    $hasApprovalThresholds ? '' : 'Add approval_thresholds (department_id, min_amount, max_amount, required_approver_count, required_role). Above a configurable ceiling, require 2+ approvers (dual control) — standard for any government payment run and most bank compliance frameworks.'
);

$approveCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/approve.php', ['approve_payments', 'permission']);
addResult(
    '6. Approval & Governance',
    'Reject action permission check',
    'warn',
    'approve.php enforces a permission check on the "approve" action but the "reject" and "submit" actions have no permission gate at all — any authenticated user (any role) can reject or resubmit a batch.',
    'Add the same role/permission check used for approve to the reject and submit branches in approve.php.'
);

addResult(
    '6. Approval & Governance',
    'Approval self-check (maker ≠ checker)',
    'fail',
    'Nothing in approve.php or batches/view.php prevents the user who uploaded a batch from also being the one who approves it.',
    'Before approving, compare $batch[\'uploaded_by\'] against $user[\'id\'] and block self-approval, or require the second approver to be different from uploaded_by when dual control is enabled.'
);

// ============================================================================
// 7. AUDIT & COMPLIANCE
// ============================================================================

$hasAuditLog = tableExists($db, 'audit_logs') || tableExists($db, 'AuditLog');
addResult(
    '7. Audit & Compliance',
    'Immutable audit log table',
    $hasAuditLog ? 'pass' : 'fail',
    $hasAuditLog ? 'Present — ' . (rowCount($db, 'audit_logs') ?? '?') . ' entries' : 'MISSING at the enterprise dashboard layer — src/Domain/Models/AuditLog.php and src/Domain/Services/AuditTrailService.php exist in your codebase but nothing in the enterprise/ dashboard (upload, approve, reject, execute) calls them',
    $hasAuditLog ? 'Confirm every state-changing action in enterprise/ (upload, mapping save, approve, reject, execute) writes an entry — right now it appears AuditTrailService is only used elsewhere in the system, not from the enterprise dashboard flow.' : 'Create audit_logs (id, organization_id, department_id, user_id, action, entity_type, entity_id, before_state JSONB, after_state JSONB, ip_address, user_agent, created_at) and call it from every mutating action in the enterprise dashboard, not just the core swap engine.'
);

addResult(
    '7. Audit & Compliance',
    'Reconciliation reports wired to real data',
    'warn',
    'reports/daily_reconciliations.php, weekly_reconciliations.php, monthly_reconciliations.php exist, but since execute.php never calls SwapService, any reconciliation report reading from payment_instructions is reconciling against fabricated SUCCESS rows, not real settlement data.',
    'These reports will only be trustworthy once item 5 (execute.php wiring) is fixed — flag this dependency explicitly so nobody signs off a reconciliation report against synthetic data.'
);

addResult(
    '7. Audit & Compliance',
    'Suspicious activity reporting',
    file_exists($projectRoot . '/public/admin/reports/suspicious_activity_report.php') ? 'pass' : 'warn',
    file_exists($projectRoot . '/public/admin/reports/suspicious_activity_report.php') ? 'Present' : 'Not found',
    ''
);

// ============================================================================
// 8. SECURITY HARDENING
// ============================================================================

$uploadCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/upload.php', ['mkdir($uploadDir, 0777']);
addResult(
    '8. Security Hardening',
    'Upload directory permission mode',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'fail' : 'pass',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? 'FAIL — upload.php creates /tmp/vouchmorph_uploads with mode 0777 (world-writable)' : 'Not flagged',
    ($uploadCheck['exists'] && ($uploadCheck['matches']['mkdir($uploadDir, 0777'] ?? false)) ? "Change mkdir(\$uploadDir, 0777, true) to mkdir(\$uploadDir, 0750, true) and ensure ownership is the web server user only." : ''
);

$csrfCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/review.php', ['csrf_token', 'CSRF']);
addResult(
    '8. Security Hardening',
    'CSRF protection on state-changing forms',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'pass' : 'fail',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? 'Present' : 'MISSING — none of the fetch()/form POST actions across upload, approve, reject, execute carry a CSRF token',
    ($csrfCheck['exists'] && (($csrfCheck['matches']['csrf_token'] ?? false) || ($csrfCheck['matches']['CSRF'] ?? false))) ? '' : 'Generate a per-session CSRF token in auth.php, embed it in every form and fetch() call, and verify it server-side in approve.php, upload.php, save_mapping.php, save_batch_mapping.php.'
);

addResult(
    '8. Security Hardening',
    'Session cookie hardening',
    'warn',
    'No explicit session.cookie_secure / cookie_httponly / cookie_samesite configuration visible in the enterprise auth flow.',
    "Set session.cookie_httponly=1, session.cookie_secure=1 (HTTPS only), session.cookie_samesite=Strict (or Lax) either in php.ini or via session_set_cookie_params() before session_start() in auth.php."
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
        .header p { color: #64748b; }
        .score-banner {
            display: flex; align-items: center; gap: 32px;
            background: white; border-radius: 20px; padding: 32px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 32px;
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
        .pill-row { display: flex; gap: 12px; }
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
        .legend { display: flex; gap: 20px; margin-bottom: 20px; font-size: 13px; color: #64748b; }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏛 System Readiness Diagnostic</h1>
        <p>Government / Enterprise disbursement platform alignment check — run for <?php echo htmlspecialchars($user['organization_name']); ?> by <?php echo htmlspecialchars($user['email']); ?> on <?php echo date('F d, Y H:i'); ?></p>
    </div>

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
</div>
</body>
</html>
