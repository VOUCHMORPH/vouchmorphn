<?php
/**
 * ============================================================================
 * VOUCHMORPH ENTERPRISE — GOVERNMENT/ENTERPRISE READINESS DIAGNOSTIC
 * ============================================================================
 *
 * DROP-IN LOCATION: public/admin/system_diagnostic.php
 * ============================================================================
 */

// Start session and check authentication - use absolute paths
require_once __DIR__ . '/enterprise/auth.php';

// This will redirect to /public/admin/enterprise/login.php if not authenticated
$user = requireEnterpriseAuth();

// Check for admin/owner role
if (!in_array($user['role'] ?? '', ['owner', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    die('System diagnostics require owner or admin role.');
}

// Get database connection
$pdo = getDBConnection();
$orgId = getOrganizationId();

// Project root, used to grep other source files for wiring checks
$projectRoot = realpath(__DIR__ . '/../..');

// ============================================================================
// CHECK ENGINE
// ============================================================================

$results = [];
$applyFix = $_POST['apply_fix'] ?? null;
$fixMessage = '';

function addResult(string $category, string $name, string $status, string $message, string $fix = ''): void {
    global $results;
    $results[$category][] = [
        'name' => $name,
        'status' => $status,
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

addResult('1. Core Infrastructure', 'Database connectivity', 'pass', 'Connected successfully via ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

$phpVersion = PHP_VERSION;
addResult(
    '1. Core Infrastructure',
    'PHP version',
    version_compare($phpVersion, '8.1.0', '>=') ? 'pass' : 'warn',
    "Running PHP {$phpVersion}",
    version_compare($phpVersion, '8.1.0', '>=') ? '' : 'Upgrade to PHP 8.1+ for full compatibility.'
);

foreach (['pdo_pgsql', 'curl', 'openssl', 'mbstring', 'json'] as $ext) {
    addResult(
        '1. Core Infrastructure',
        "Extension: {$ext}",
        extension_loaded($ext) ? 'pass' : 'fail',
        extension_loaded($ext) ? 'Loaded' : 'MISSING',
        extension_loaded($ext) ? '' : "Install php-{$ext} and restart the web server."
    );
}

// ============================================================================
// 2. ORGANIZATIONAL STRUCTURE
// ============================================================================

addResult(
    '2. Organizational Structure',
    'organizations table',
    tableExists($pdo, 'organizations') ? 'pass' : 'fail',
    tableExists($pdo, 'organizations') ? 'Present' : 'MISSING',
    ''
);

$hasDepartments = tableExists($pdo, 'departments');
addResult(
    '2. Organizational Structure',
    'departments table',
    $hasDepartments ? 'pass' : 'fail',
    $hasDepartments ? 'Present — ' . rowCount($pdo, 'departments') . ' departments' : 'MISSING',
    $hasDepartments ? '' : 'Add departments table for government organizational structure.'
);

// ============================================================================
// 3. BENEFICIARY & PROGRAM MANAGEMENT
// ============================================================================

$actualBeneficiaryTable = tableExists($pdo, 'organizations_beneficiaries') ? 'organizations_beneficiaries' : (tableExists($pdo, 'organization_beneficiaries') ? 'organization_beneficiaries' : null);

addResult(
    '3. Beneficiary & Program Management',
    'Beneficiary table exists',
    $actualBeneficiaryTable ? 'pass' : 'fail',
    $actualBeneficiaryTable ? "Present as `{$actualBeneficiaryTable}` — " . (rowCount($pdo, $actualBeneficiaryTable) ?? '?') . ' beneficiaries' : 'MISSING',
    ''
);

// ============================================================================
// 4. ACCESS CONTROL
// ============================================================================

$stmt = $pdo->prepare("SELECT DISTINCT role FROM organization_users WHERE organization_id = :org_id");
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
    count($roles) > 0 ? 'Roles: ' . implode(', ', $roles) : 'No roles found',
    ''
);

// ============================================================================
// 5. DISBURSEMENT PIPELINE WIRING
// ============================================================================

$executeCheck = fileContains($projectRoot, 'public/admin/enterprise/imports/execute.php', [
    'SwapService', 'executeAtomicSwap', 'executeMultiDestinationSwap'
]);

$isWired = $executeCheck['exists'] && (
    ($executeCheck['matches']['SwapService'] ?? false) ||
    ($executeCheck['matches']['executeAtomicSwap'] ?? false) ||
    ($executeCheck['matches']['executeMultiDestinationSwap'] ?? false)
);

addResult(
    '5. Disbursement Pipeline Wiring',
    'execute.php connected to SwapService',
    $isWired ? 'pass' : 'fail',
    $isWired ? 'Wired to SwapService' : 'NOT WIRED — money movement not connected',
    $isWired ? '' : 'Connect execute.php to SwapService::executeMultiDestinationSwap()'
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
    <title>System Readiness Diagnostic</title>
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
        .login-prompt {
            background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px;
            padding: 40px; text-align: center; margin: 40px 0;
        }
        .login-prompt h2 { color: #991b1b; margin-bottom: 12px; }
        .login-prompt a {
            display: inline-block; margin-top: 16px; padding: 12px 32px;
            background: #0f172a; color: white; border-radius: 40px;
            text-decoration: none; font-weight: 600;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🏛 System Readiness Diagnostic</h1>
        <p>Government / Enterprise disbursement platform alignment check</p>
        <p style="font-size: 13px; color: #64748b; margin-top: 4px;">
            Run for <?php echo htmlspecialchars($user['organization_name'] ?? 'N/A'); ?> 
            by <?php echo htmlspecialchars($user['email']); ?> 
            on <?php echo date('F d, Y H:i'); ?>
        </p>
    </div>

    <?php if (!isset($user) || empty($user)): ?>
    <div class="login-prompt">
        <h2>🔒 Authentication Required</h2>
        <p>You need to be logged in to access the system diagnostic.</p>
        <a href="/public/admin/enterprise/login.php">Go to Login →</a>
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
            <a href="/public/admin/enterprise/index.php" class="back-link">← Back to Dashboard</a>
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
        This diagnostic reads your live schema and greps your actual source files — it does not modify anything.
        Re-run after each fix to watch the readiness score move.
    </p>
    
    <?php endif; ?>
</div>
</body>
</html>
