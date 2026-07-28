<?php
// enterprise/imports/source_input.php - Select source account
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
require_once __DIR__ . '/../../../../src/Domain/Services/DepartmentService.php';
use Core\Database\DBConnection;
use Domain\Services\DepartmentService;

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
$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');

function formatCurrency($amount, $currency = 'BWP') {
    return number_format((float)$amount, 2) . ' ' . $currency;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Source · VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --paper: #EEF1EF;
            --panel: #FFFFFF;
            --ink-900: #0F2138;
            --ink-700: #1D3557;
            --ink-500: #4A5A6E;
            --ink-300: #8A96A3;
            --line: #D3DAD6;
            --brass: #8A6D3B;
            --brass-tint: #F4EFE3;
            --seal-red: #7A2118;
            --ledger-green: #24513A;
            --amber: #8A5A0B;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'IBM Plex Sans', sans-serif;
            background: var(--paper);
            color: var(--ink-900);
            min-height: 100vh;
        }
        .masthead {
            background: var(--ink-900);
            color: white;
            padding: 14px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--brass);
            flex-wrap: wrap;
            gap: 10px;
        }
        .masthead h1 { font-size: 18px; font-weight: 700; }
        .masthead .role-pill {
            font-size: 10px;
            font-weight: 700;
            color: var(--brass);
            border: 1px solid var(--brass);
            padding: 2px 10px;
            text-transform: uppercase;
        }
        .stage { max-width: 900px; margin: 0 auto; padding: 30px 20px; }
        .card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--line);
        }
        .card-title { font-size: 16px; font-weight: 700; text-transform: uppercase; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--ink-500);
            margin-bottom: 4px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid var(--line);
            border-radius: 8px;
            font-size: 13px;
            font-family: inherit;
            background: #fff;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--brass);
        }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 30px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.15s;
            font-family: inherit;
        }
        .btn-primary { background: var(--ink-900); color: white; }
        .btn-primary:hover { background: var(--brass); }
        .btn-success { background: var(--ledger-green); color: white; }
        .btn-success:hover { background: #1a3d2c; }
        .btn-secondary { background: var(--line); color: var(--ink-700); }
        .btn-secondary:hover { background: var(--line-strong); }
        .error {
            background: #fbeceb;
            color: var(--seal-red);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid var(--seal-red);
        }
        .success {
            background: #dcfce7;
            color: #166534;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            border-left: 3px solid #10b981;
        }
        .source-card {
            border: 2px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .source-card:hover { border-color: var(--brass); background: var(--brass-tint); }
        .source-card.selected { border-color: var(--brass); background: var(--brass-tint); }
        .source-card .name { font-weight: 600; }
        .source-card .details { font-size: 12px; color: var(--ink-500); margin-top: 4px; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--ink-500);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .back-link:hover { color: var(--brass); }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 24px;
            padding: 0 20px;
        }
        .step {
            flex: 1;
            text-align: center;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink-300);
            text-transform: uppercase;
        }
        .step.active { color: var(--ink-900); }
        .step.done { color: var(--ledger-green); }
        /* ============================================================
           RATION PREVIEW
           ============================================================ */
        .ration-preview {
            border: 1.5px solid var(--line);
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 16px;
            font-size: 12.5px;
        }
        .ration-preview .row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
        }
        .ration-preview .label { color: var(--ink-500); }
        .ration-preview .value { font-weight: 600; }
        .ration-preview .value.danger { color: var(--seal-red); }
        .ration-preview .value.ok { color: var(--ledger-green); }
        .ration-bar-track { height: 6px; background: var(--paper); border-radius: 3px; margin: 8px 0; overflow: hidden; }
        .ration-bar-fill { height: 100%; }
        .missing-dept-notice {
            background: #fef3c7;
            border-left: 3px solid var(--amber);
            color: var(--amber);
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        @media (max-width: 768px) {
            .masthead { flex-direction: column; text-align: center; }
            .step-indicator { flex-wrap: wrap; gap: 8px; }
            .step { flex: 0 0 45%; }
        }
    </style>
</head>
<body>
    <div class="masthead">
        <h1>VouchMorph · Multi-Destination Disbursement</h1>
        <div>
            <span class="role-pill"><?php echo $roleDisplay; ?></span>
            <a href="../logout.php" style="color: rgba(255,255,255,0.4); text-decoration: none; margin-left: 16px; font-size: 12px;">Logout</a>
        </div>
    </div>

    <div class="stage">
        <a href="../index.php" class="back-link">← Return to Dashboard</a>

        <div class="step-indicator">
            <span class="step active">1. Select Source</span>
            <span class="step">2. Add Destinations</span>
            <span class="step">3. Review</span>
            <span class="step">4. Submit for Approval</span>
            <span class="step">5. Execute</span>
        </div>

        <?php if ($error): ?>
        <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="success">✅ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($missingDepartment): ?>
        <div class="missing-dept-notice">
            ⚠️ <strong>No department assigned.</strong> You need to belong to a department before you can
            create a disbursement batch — it's what your batch draws its budget ration from. Contact an
            Owner or IT Manager to be assigned to one.
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <span class="card-title">💰 Select Source Account</span>
                <?php if ($batch): ?>
                <span style="font-size:12px; color:var(--ink-500);">Batch: <?php echo htmlspecialchars($batch['batch_reference']); ?></span>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="select_source">

                <div class="form-group">
                    <label>Batch Name</label>
                    <input type="text" name="batch_name" 
                           value="<?php echo $batch ? htmlspecialchars($batch['batch_name']) : 'Multi-Destination Disbursement ' . date('Y-m-d'); ?>">
                </div>

                <?php if ($isTopRole): ?>
                <div class="form-group">
                    <label>Department (draws against its ration)</label>
                    <select name="department_id" id="departmentSelect" onchange="window.location.href = '?department_id=' + this.value + '<?php echo $batchId ? '&batch_id=' . (int)$batchId : ''; ?>'">
                        <option value="">Select department&hellip;</option>
                        <?php foreach ($departmentOptions as $dept): ?>
                        <option value="<?php echo (int)$dept['id']; ?>" <?php echo ((int)$dept['id'] === $selectedDepartmentId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept['name']); ?>
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
                <div class="ration-preview">
                    <div class="row"><span class="label">Department Ceiling</span><span class="value"><?php echo formatCurrency($rationPreview['ceiling'], $rationPreview['currency']); ?></span></div>
                    <div class="row"><span class="label">Disbursed YTD</span><span class="value"><?php echo formatCurrency($rationPreview['disbursed_ytd'], $rationPreview['currency']); ?></span></div>
                    <div class="row"><span class="label">Reserved (other pending/approved batches)</span><span class="value"><?php echo formatCurrency($rationPreview['reserved_in_flight'], $rationPreview['currency']); ?></span></div>
                    <div class="ration-bar-track"><div class="ration-bar-fill" style="width:<?php echo $utilPct; ?>%; background:<?php echo $barColor; ?>;"></div></div>
                    <div class="row"><span class="label">Available Now</span><span class="value <?php echo $rationPreview['available'] < 0 ? 'danger' : 'ok'; ?>"><?php echo formatCurrency($rationPreview['available'], $rationPreview['currency']); ?></span></div>
                </div>
                <?php elseif ($selectedDepartmentId !== null): ?>
                <div class="error" style="margin-bottom:16px;">Could not load ration info for this department.</div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Select Source Account</label>
                    <?php if (empty($sources)): ?>
                    <div style="padding:20px; text-align:center; color:var(--ink-300); border:2px dashed var(--line); border-radius:8px;">
                        <p>No confirmed source accounts available.</p>
                        <p style="font-size:12px; margin-top:8px; color:var(--ink-500);">
                            A source account must be proposed by a Finance Officer and confirmed by an Owner or IT Manager before it appears here.
                        </p>
                        <?php if ($canManageSourceAccounts): ?>
                        <p style="font-size:12px; margin-top:8px;">
                            <a href="add_source.php" style="color:var(--brass);">Manage source accounts →</a>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="max-height:400px; overflow-y:auto;">
                        <?php foreach ($sources as $source): ?>
                        <div class="source-card <?php echo ($batch && $batch['source_account_id'] == $source['id']) ? 'selected' : ''; ?>" onclick="selectSource(<?php echo $source['id']; ?>)">
                            <div class="name"><?php echo htmlspecialchars($source['institution']); ?></div>
                            <div class="details">
                                <?php echo htmlspecialchars($source['source_identifier']); ?>
                                (<?php echo htmlspecialchars($source['asset_type']); ?>)
                                <?php if ($source['is_hooked']): ?>
                                <span style="color:var(--ledger-green);">🔗 Hooked</span>
                                <?php endif; ?>
                                <?php if ($source['balance'] > 0): ?>
                                · Balance: <?php echo number_format($source['balance'], 2); ?> <?php echo htmlspecialchars($source['currency']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <input type="hidden" name="source_id" id="sourceId" value="<?php echo $batch ? $batch['source_account_id'] : ''; ?>">

                <div style="display:flex; gap:12px; margin-top:16px; flex-wrap:wrap;">
                    <?php if ($canManageSourceAccounts): ?>
                    <a href="add_source.php" class="btn btn-secondary">➕ Manage Source Accounts</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary" <?php echo (empty($sources) || $missingDepartment) ? 'disabled' : ''; ?>>
                        Continue → Add Destinations
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function selectSource(id) {
            document.getElementById('sourceId').value = id;
            document.querySelectorAll('.source-card').forEach(c => c.classList.remove('selected'));
            document.querySelector(`.source-card[onclick="selectSource(${id})"]`).classList.add('selected');
        }
    </script>
</body>
</html>
