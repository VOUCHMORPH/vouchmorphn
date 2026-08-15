<?php
// enterprise/imports/source_input.php - Select source account
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
require_once __DIR__ . '/../../../../src/Domain/Services/SetupChecklistService.php';
use Core\Database\DBConnection;
use Domain\Services\DepartmentService;
use Domain\Services\SetupChecklistService;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$userId = $user['id'] ?? $user['user_id'] ?? null;
$role = $user['role'] ?? 'viewer';

// Same gate as add_source.php - determines whether the "add/manage
// source accounts" link is shown at all.
$canManageSourceAccounts = in_array($role, ['finance_officer', 'owner', 'it_manager_enterprise']);

// ============================================================
// DEPARTMENT / RATION SETUP
// ============================================================
// A batch always draws against a department's ration, and that
// department is decided HERE, at creation time — never guessed later
// at submit time. Only top roles may pick a department other than
// their own; everyone else is locked to the department on their
// account (never trust a posted department_id from a non-top role).
$deptService = new DepartmentService($db);
$isTopRole = in_array($role, ['owner', 'it_manager_enterprise'], true);
$userDepartmentId = isset($user['department_id']) && $user['department_id'] !== null
    ? (int)$user['department_id']
    : null;

$departmentOptions = [];
if ($isTopRole) {
    try {
        $departmentOptions = $deptService->getDepartmentsFlat($orgId, true);
    } catch (\Throwable $e) {
        error_log("[source_input] Failed to load departments: " . $e->getMessage());
    }
}

// Non-top roles with no department assigned cannot create a batch at all —
// there's nothing to draw ration from. Surface this clearly instead of
// letting them hit a confusing failure later at submit time.
$missingDepartment = (!$isTopRole && $userDepartmentId === null);

$error = '';
$success = '';
$batchId = $_GET['batch_id'] ?? 0;

// Get available source accounts - ONLY confirmed, active ones.
// Sources with status = 'pending_confirmation' are proposed but not
// yet approved by an Owner/IT Manager, and must never be selectable
// for a live disbursement batch.
$sources = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM source_accounts
        WHERE organization_id = :org_id
        AND is_active = true
        AND status = 'active'
        ORDER BY institution, source_identifier
    ");
    $stmt->execute([':org_id' => $orgId]);
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[source_input] Error: " . $e->getMessage());
}

// Get existing batch if provided
$batch = null;
if ($batchId) {
    $stmt = $db->prepare("
        SELECT * FROM disbursement_batches
        WHERE id = :id AND organization_id = :org_id
    ");
    $stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Resolve which department this batch will be (or already is) scoped to,
// for both the ration-preview panel and the eventual INSERT/UPDATE.
$selectedDepartmentId = null;
if ($batch && !empty($batch['department_id'])) {
    $selectedDepartmentId = (int)$batch['department_id'];
} elseif ($isTopRole && !empty($_GET['department_id'])) {
    $selectedDepartmentId = (int)$_GET['department_id'];
} elseif ($userDepartmentId !== null) {
    $selectedDepartmentId = $userDepartmentId;
}

$rationPreview = null;
if ($selectedDepartmentId !== null) {
    try {
        $rationPreview = $deptService->getAvailableRation($selectedDepartmentId);
    } catch (\Throwable $e) {
        error_log("[source_input] Failed to load ration preview: " . $e->getMessage());
    }
}

// Handle source selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';

    if ($action === 'select_source') {
        $sourceId = $_POST['source_id'] ?? null;

        // Resolve the department to attach to this batch. Top roles may
        // pick from the posted dropdown (or default to their own, if
        // they have one); everyone else is hard-locked to their own
        // department regardless of anything in the POST body.
        if ($isTopRole) {
            $postedDeptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
            $departmentIdForBatch = $postedDeptId ?? $userDepartmentId;
        } else {
            $departmentIdForBatch = $userDepartmentId;
        }

        if (!$sourceId) {
            $error = 'Please select a source account.';
        } elseif ($departmentIdForBatch === null) {
            $error = 'You must belong to a department to create a batch, or select one if you are an admin.';
        } else {
            try {
                // Get source details - re-check it's confirmed & active.
                // (Defends against a stale/tampered source_id in the POST
                // body pointing at a pending or deactivated source.)
                $stmt = $db->prepare("
                    SELECT * FROM source_accounts
                    WHERE id = :id AND organization_id = :org_id
                    AND is_active = true AND status = 'active'
                ");
                $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
                $source = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$source) {
                    throw new Exception("Source not found or not yet confirmed for use.");
                }

                // Confirm the department is real, active, and (for non-top
                // roles) actually the one they belong to. Top roles can
                // pick any active department in the org.
                $stmt = $db->prepare("
                    SELECT id FROM departments
                    WHERE id = :id AND organization_id = :org_id AND status = 'active'
                ");
                $stmt->execute([':id' => $departmentIdForBatch, ':org_id' => $orgId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception("Selected department not found or inactive.");
                }
                if (!$isTopRole && $departmentIdForBatch !== $userDepartmentId) {
                    throw new Exception("You can only create batches for your own department.");
                }

                // Create or update batch
                if ($batchId) {
                    $stmt = $db->prepare("
                        UPDATE disbursement_batches
                        SET source_account_id = :source_id,
                            source_institution = :institution,
                            source_asset_type = :asset_type,
                            source_identifier = :identifier,
                            updated_at = NOW()
                        WHERE id = :id AND organization_id = :org_id
                    ");
                    $stmt->execute([
                        ':source_id' => $source['id'],
                        ':institution' => $source['institution'],
                        ':asset_type' => $source['asset_type'],
                        ':identifier' => $source['source_identifier'],
                        ':id' => $batchId
                    ]);
                    $batchId = $batchId;
                } else {
                    $batchRef = 'DISP_' . date('Ymd_His') . '_' . strtoupper(substr(uniqid(), -6));
                    $stmt = $db->prepare("
                        INSERT INTO disbursement_batches (
                            organization_id, department_id, batch_reference, batch_name,
                            source_account_id, source_institution, source_asset_type,
                            source_identifier, currency, status, created_by
                        ) VALUES (
                            :org_id, :department_id, :ref, :name,
                            :source_id, :institution, :asset_type,
                            :identifier, :currency, 'DRAFT', :user_id
                        ) RETURNING id
                    ");
                    $stmt->execute([
                        ':org_id' => $orgId,
                        ':department_id' => $departmentIdForBatch,
                        ':ref' => $batchRef,
                        ':name' => $_POST['batch_name'] ?? 'Multi-Destination Disbursement ' . date('Y-m-d'),
                        ':source_id' => $source['id'],
                        ':institution' => $source['institution'],
                        ':asset_type' => $source['asset_type'],
                        ':identifier' => $source['source_identifier'],
                        ':currency' => $source['currency'] ?? 'BWP',
                        ':user_id' => $userId
                    ]);
                    $batchId = $db->lastInsertId();
                }

                header("Location: add_destinations.php?batch_id=$batchId");
                exit;

            } catch (Exception $e) {
                error_log("[source_input] Error: " . $e->getMessage());
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

$csrfToken = generateCsrfToken();

function safeHtml($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function getRoleLabel($role) {
    $labels = [
        'owner' => 'Owner', 'it_manager_enterprise' => 'IT Manager', 'it_officer_enterprise' => 'IT Officer',
        'it_support' => 'IT Support', 'department_head' => 'Department Head', 'program_officer' => 'Uploader',
        'finance_officer' => 'Finance Officer', 'approver' => 'Approver', 'senior_approver' => 'Senior Approver',
        'supervisor' => 'Supervisor', 'beneficiary_registrar' => 'Beneficiary Registrar', 'auditor' => 'Auditor', 'viewer' => 'Viewer',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}

// ============================================================
// SHARED SHELL SETUP — same contract as index.php/departments/index.php,
// so this page's nav is generated by the exact same code, not a
// hand-copied lookalike.
// ============================================================
$fullName = $user['full_name'] ?? $user['username'] ?? 'User';
$orgName = $user['organization_name'] ?? 'Organization';
$userRole = $role;
$basePath = '../';
$canCreate = in_array($userRole, ['owner', 'it_manager_enterprise', 'program_officer', 'department_head'], true);
$canApprove = in_array($userRole, ['owner', 'approver', 'senior_approver', 'it_manager_enterprise'], true);
$canManageUsers = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise'], true);
$canSeeSourceAccountsArea = in_array($userRole, ['owner', 'it_manager_enterprise', 'finance_officer'], true);
$canTrace = in_array($userRole, ['owner', 'it_manager_enterprise', 'it_officer_enterprise', 'auditor', 'senior_approver', 'approver', 'finance_officer'], true);
$canManageDepartments = $isTopRole;
$isDepartmentHead = ($userRole === 'department_head');

// Unlike add_destinations.php/review_batch.php (which both require an
// existing batch to render at all, proving setup was already completed
// once), this page can be reached with no batch yet — so it's the one
// workflow page where a real, live setup-readiness check matters.
try {
    $setupChecklist = new SetupChecklistService($db);
    $setupStatus = $setupChecklist->getStatus((int)$orgId);
    $setupReady = $setupStatus['ready_for_batches'];
} catch (\Throwable $e) {
    error_log("[source_input] Setup checklist error: " . $e->getMessage());
    $setupReady = true;
}

// Same live badge numbers the dashboard/Departments pages show, so a
// count on "Disbursements" or "Source Accounts" never disagrees
// depending on which page you're on.
$navPendingApprovals = 0;
$navPendingSourceConfirmations = 0;
try {
    if ($canApprove) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM disbursement_batches WHERE organization_id = :org_id AND status IN ('pending','pending_approval','PENDING','PENDING_APPROVAL')");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingApprovals = (int)$stmt->fetchColumn();
    }
    if ($canSeeSourceAccountsArea) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM source_accounts WHERE organization_id = :org_id AND status = 'pending_confirmation' AND deleted_at IS NULL");
        $stmt->execute([':org_id' => $orgId]);
        $navPendingSourceConfirmations = (int)$stmt->fetchColumn();
    }
} catch (PDOException $e) {
    error_log("[source_input] Nav badge query error: " . $e->getMessage());
}

$navItems = [
    ['key' => 'dashboard', 'icon' => 'grid', 'label' => 'Dashboard', 'href' => '../index.php', 'show' => true],
    ['key' => 'disbursements', 'icon' => 'wallet', 'label' => 'Disbursements', 'href' => '../batches/index.php?status=all', 'show' => true, 'badge' => ($navPendingApprovals > 0 && $canApprove) ? $navPendingApprovals : null],
    ['key' => 'beneficiaries', 'icon' => 'people', 'label' => 'Beneficiaries', 'href' => '../beneficiaries.php', 'show' => true],
    ['key' => 'trace', 'icon' => 'search', 'label' => 'Trace Payment', 'href' => '../index.php#trace', 'show' => $canTrace],
    ['key' => 'departments', 'icon' => 'building', 'label' => 'Departments', 'href' => '../departments/index.php', 'show' => $canManageDepartments || $isDepartmentHead],
    ['key' => 'sources', 'icon' => 'bank', 'label' => 'Source Accounts', 'href' => '../imports/add_source.php', 'show' => $canSeeSourceAccountsArea, 'badge' => $navPendingSourceConfirmations > 0 ? $navPendingSourceConfirmations : null],
    ['key' => 'team', 'icon' => 'idcard', 'label' => 'Team', 'href' => '../settings/users.php', 'show' => $canManageUsers],
    ['key' => 'reports', 'icon' => 'chart', 'label' => 'Reports', 'href' => '../reports.php', 'show' => true],
];
$navUtility = [
    ['key' => 'settings', 'icon' => 'gear', 'label' => 'Settings', 'href' => '../settings.php', 'show' => true],
    ['key' => 'logout', 'icon' => 'logout', 'label' => 'Log Out', 'href' => '../logout.php', 'show' => true],
];
$topbarSearchShow = $canTrace;
$topbarSearchAction = '../index.php';
$topbarSearchName = 'trace';
$topbarSearchPlaceholder = 'Search batch reference, phone, national ID…';

$stageTrackerStage = 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Batch · Source Selection · VOUCHMORPH Enterprise</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../partials/shell.css">
    <link rel="stylesheet" href="../partials/stage-tracker.css">
    <style>
        .source-card { border: 1.5px solid var(--line); padding: 14px 16px; margin-bottom: 8px; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
        .source-card:hover { border-color: var(--brass); background: var(--brass-tint); }
        .source-card.selected { border-color: var(--brass); background: var(--brass-tint); }
        .source-card .name { font-weight: 600; font-size: 13.5px; }
        .source-card .details { font-size: 12px; color: var(--ink-500); margin-top: 4px; }
        .ration-panel { margin-top: 4px; margin-bottom: 16px; padding: 14px 16px; border: 1px solid var(--line); }
        .ration-panel .ration-title { font-family: var(--f-cond); font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--ink-500); margin-bottom: 10px; }
        .ration-bar-track { height: 8px; background: var(--paper); border: 1px solid var(--line); margin-bottom: 10px; }
        .ration-bar-fill { height: 100%; }
        .ration-stats { display: flex; gap: 20px; flex-wrap: wrap; font-size: 12.5px; color: var(--ink-500); }
        .ration-stats strong { color: var(--ink-900); }
        .ration-stats .danger strong { color: var(--danger); }
        .ration-stats .ok strong { color: var(--ledger-green); }
    </style>
</head>
<body>
    <?php require __DIR__ . '/../partials/shell-head.php'; ?>
            <div class="page-header">
                <div>
                    <h1>Create Batch</h1>
                    <div class="sub">Step 1 — choose the department this batch draws its budget ration from, and the source account funds will move out of.</div>
                </div>
            </div>

            <?php $stageTrackerStatus = null; require __DIR__ . '/../partials/stage-tracker.php'; ?>

            <?php if ($error): ?>
            <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg);">
                <div class="label" style="color:var(--danger);">⚠️ <?php echo safeHtml($error); ?></div>
            </div>
            <?php endif; ?>

            <?php if ($missingDepartment): ?>
            <div class="info-panel" style="border-left-color: var(--amber); background: var(--amber-bg);">
                <div class="label" style="color:var(--amber);">No department assigned</div>
                <div class="desc">You need to belong to a department before you can create a disbursement batch — it's what your batch draws its budget ration from. Contact an Owner or IT Manager to be assigned to one.</div>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <span class="card-title">Select Source Account &amp; Department</span>
                    <?php if ($batch): ?>
                    <span style="font-size:12px; color:var(--ink-500); font-family:var(--f-mono);">Batch: <?php echo safeHtml($batch['batch_reference']); ?></span>
                    <?php endif; ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo safeHtml($csrfToken); ?>">
                    <input type="hidden" name="action" value="select_source">

                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Batch Name</label>
                        <input type="text" name="batch_name"
                               value="<?php echo $batch ? safeHtml($batch['batch_name']) : 'Multi-Destination Disbursement ' . date('Y-m-d'); ?>">
                    </div>

                    <?php if ($isTopRole): ?>
                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Department (draws against its ration)</label>
                        <select name="department_id" id="departmentSelect" onchange="window.location.href = '?department_id=' + this.value + '<?php echo $batchId ? '&batch_id=' . (int)$batchId : ''; ?>'">
                            <option value="">Select department…</option>
                            <?php foreach ($departmentOptions as $dept): ?>
                            <option value="<?php echo (int)$dept['id']; ?>" <?php echo ((int)$dept['id'] === $selectedDepartmentId) ? 'selected' : ''; ?>>
                                <?php echo safeHtml($dept['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="department_id" value="<?php echo (int)$userDepartmentId; ?>">
                    <?php endif; ?>

                    <?php if ($rationPreview): ?>
                    <?php
                        $utilPct = $rationPreview['ceiling'] > 0
                            ? min(100, round((($rationPreview['disbursed_ytd'] + $rationPreview['reserved_in_flight']) / $rationPreview['ceiling']) * 100, 1))
                            : 0;
                        $barColor = $rationPreview['available'] < 0 ? 'var(--seal-red)' : ($utilPct >= 80 ? 'var(--amber)' : 'var(--ledger-green)');
                    ?>
                    <div class="ration-panel">
                        <div class="ration-title">Department Budget Ration</div>
                        <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                        <div class="ration-stats">
                            <span>Ceiling: <strong><?php echo formatCurrency($rationPreview['ceiling'], $rationPreview['currency']); ?></strong></span>
                            <span>Disbursed YTD: <strong><?php echo formatCurrency($rationPreview['disbursed_ytd'], $rationPreview['currency']); ?></strong></span>
                            <span>Reserved (other pending/approved batches): <strong><?php echo formatCurrency($rationPreview['reserved_in_flight'], $rationPreview['currency']); ?></strong></span>
                            <span class="<?php echo $rationPreview['available'] < 0 ? 'danger' : 'ok'; ?>">Available Now: <strong><?php echo formatCurrency($rationPreview['available'], $rationPreview['currency']); ?></strong></span>
                        </div>
                    </div>
                    <?php elseif ($selectedDepartmentId !== null): ?>
                    <div class="info-panel" style="border-left-color: var(--seal-red); background: var(--danger-bg); margin-bottom:16px;">
                        <div class="label" style="color:var(--danger);">Could not load ration info for this department.</div>
                    </div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label>Select Source Account</label>
                        <?php if (empty($sources)): ?>
                        <div class="empty-state" style="border:1px dashed var(--line);">
                            <p>No confirmed source accounts available.</p>
                            <p style="font-size:12px; margin-top:8px; color:var(--ink-500);">
                                A source account must be proposed by a Finance Officer and confirmed by an Owner or IT Manager before it appears here.
                            </p>
                            <?php if ($canManageSourceAccounts): ?>
                            <p style="font-size:12px; margin-top:8px;">
                                <a href="add_source.php" style="color:var(--brass); font-weight:600;">Manage source accounts →</a>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div style="max-height:400px; overflow-y:auto;">
                            <?php foreach ($sources as $source): ?>
                            <div class="source-card <?php echo ($batch && $batch['source_account_id'] == $source['id']) ? 'selected' : ''; ?>" onclick="selectSource(<?php echo $source['id']; ?>)">
                                <div class="name"><?php echo safeHtml($source['institution']); ?></div>
                                <div class="details">
                                    <?php echo safeHtml($source['source_identifier']); ?>
                                    (<?php echo safeHtml($source['asset_type']); ?>)
                                    <?php if ($source['is_hooked']): ?>
                                    <span style="color:var(--ledger-green);">🔗 Hooked</span>
                                    <?php endif; ?>
                                    <?php if ($source['balance'] > 0): ?>
                                    · Balance: <?php echo number_format($source['balance'], 2); ?> <?php echo safeHtml($source['currency']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="source_id" id="sourceId" value="<?php echo $batch ? $batch['source_account_id'] : ''; ?>">

                    <div class="card-actions">
                        <?php if ($canManageSourceAccounts): ?>
                        <a href="add_source.php" class="btn btn-outline"><?php echo svgIcon('bank'); ?> Manage Source Accounts</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary" <?php echo (empty($sources) || $missingDepartment) ? 'disabled' : ''; ?>>
                            Continue → Add Recipients
                        </button>
                    </div>
                </form>
            </div>

    <script>
        function selectSource(id) {
            document.getElementById('sourceId').value = id;
            document.querySelectorAll('.source-card').forEach(c => c.classList.remove('selected'));
            document.querySelector(`.source-card[onclick="selectSource(${id})"]`).classList.add('selected');
        }
    </script>
<?php
$dbHealthy = DBConnection::isConnected();
$footerStatusLine = 'LEDGER SYNC: ' . ($dbHealthy ? '<span class="ok">OK</span>' : '<span class="bad">DEGRADED</span>');
require __DIR__ . '/../partials/shell-foot.php';
?>
</body>
</html>

