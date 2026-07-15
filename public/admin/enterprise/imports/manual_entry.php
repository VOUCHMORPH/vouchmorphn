<?php
/**
 * enterprise/imports/manual_entry.php
 *
 * The Preparer stage's "punch it in manually" path -- the schema already
 * anticipated this (import_batches.payment_mode defaults to 'MANUAL') but
 * there was no screen for it. This creates the batch + rows directly,
 * skipping preview.php/validate.php (those exist to map unknown file
 * headers -- there's nothing to map here, the operator typed the real
 * field names in) and goes straight to sources.php.
 */
require_once '../auth.php';
$user = requireEnterpriseAuth();
$pdo = getDBConnection();
$orgId = getOrganizationId();
$userRole = $user['role'] ?? 'viewer';

if (!in_array($userRole, ['owner', 'department_head', 'program_officer'])) {
    header('HTTP/1.1 403 Forbidden');
    die('Only a Preparer, Department Head, or Owner can create a disbursement batch.');
}

$error = '';
$csrfToken = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);

    $batchName = trim($_POST['batch_name'] ?? '');
    $rowsIn = $_POST['rows'] ?? [];

    // Filter out any completely-blank rows the JS "add another" left behind
    $rows = array_values(array_filter($rowsIn, function ($r) {
        return trim($r['recipient_name'] ?? '') !== '' && trim($r['destination_value'] ?? '') !== '';
    }));

    if (empty($rows)) {
        $error = 'Enter at least one recipient with a name and a destination value.';
    } else {
        $validRows = [];
        $rowErrors = [];
        foreach ($rows as $i => $r) {
            $amount = (float)($r['amount'] ?? 0);
            $destType = strtoupper(trim($r['destination_type'] ?? ''));
            $destValue = trim($r['destination_value'] ?? '');
            $name = trim($r['recipient_name'] ?? '');

            if ($amount <= 0) {
                $rowErrors[] = "Row " . ($i + 1) . ": amount must be greater than zero.";
                continue;
            }
            if (!in_array($destType, ['ACCOUNT', 'WALLET', 'VOUCHER', 'IDENTIFIER', 'PHONE'])) {
                $rowErrors[] = "Row " . ($i + 1) . ": invalid destination type.";
                continue;
            }
            $validRows[] = [
                'recipient_name' => $name,
                'recipient_phone' => trim($r['recipient_phone'] ?? ''),
                'recipient_national_id' => trim($r['recipient_national_id'] ?? ''),
                'amount' => $amount,
                'currency' => trim($r['currency'] ?? '') ?: 'BWP',
                'destination_type' => $destType,
                'destination_provider' => trim($r['destination_provider'] ?? ''),
                'destination_value' => $destValue,
                'identity_type' => $destType === 'IDENTIFIER' ? strtolower(trim($r['identity_type'] ?? 'national_id')) : null,
            ];
        }

        if (!empty($rowErrors)) {
            $error = implode(' ', $rowErrors);
        } else {
            try {
                $pdo->beginTransaction();

                $totalAmount = array_sum(array_column($validRows, 'amount'));
                $batchRef = 'MANUAL_' . date('Ymd_His') . '_' . strtoupper(substr(uniqid(), -6));
                $deptId = getUserDepartmentScope();

                $stmt = $pdo->prepare("
                    INSERT INTO import_batches (
                        organization_id, batch_reference, batch_name, source_format,
                        total_rows, valid_rows, invalid_rows, total_amount, currency,
                        payment_mode, status, execution_mode, uploaded_by, department_id,
                        requires_approval, created_at, updated_at
                    ) VALUES (
                        :org_id, :ref, :name, 'MANUAL',
                        :total_rows, :valid_rows, 0, :total_amount, 'BWP',
                        'MANUAL', 'DRAFT', 'ONE_SOURCE_MANY_DEST', :user_id, :dept_id,
                        true, NOW(), NOW()
                    ) RETURNING id
                ");
                $stmt->execute([
                    ':org_id' => $orgId,
                    ':ref' => $batchRef,
                    ':name' => $batchName ?: ('Manual entry ' . date('d M Y')),
                    ':total_rows' => count($validRows),
                    ':valid_rows' => count($validRows),
                    ':total_amount' => $totalAmount,
                    ':user_id' => $user['id'] ?? $user['user_id'] ?? null,
                    ':dept_id' => $deptId,
                ]);
                $batchId = $stmt->fetchColumn();

                $rowStmt = $pdo->prepare("
                    INSERT INTO import_rows (
                        batch_id, row_number, recipient_name, recipient_phone,
                        recipient_national_id, amount, currency, destination_type,
                        destination_provider, destination_value, identity_type,
                        validation_status, created_at, updated_at
                    ) VALUES (
                        :batch_id, :row_num, :name, :phone,
                        :national_id, :amount, :currency, :dest_type,
                        :dest_provider, :dest_value, :identity_type,
                        'VALID', NOW(), NOW()
                    )
                ");
                foreach ($validRows as $i => $r) {
                    $rowStmt->execute([
                        ':batch_id' => $batchId,
                        ':row_num' => $i + 1,
                        ':name' => $r['recipient_name'],
                        ':phone' => $r['recipient_phone'],
                        ':national_id' => $r['recipient_national_id'],
                        ':amount' => $r['amount'],
                        ':currency' => $r['currency'],
                        ':dest_type' => $r['destination_type'],
                        ':dest_provider' => $r['destination_provider'],
                        ':dest_value' => $r['destination_value'],
                        ':identity_type' => $r['identity_type'],
                    ]);
                }

                try {
                    $auditStmt = $pdo->prepare("
                        INSERT INTO organization_audit_logs (
                            organization_id, user_id, action, entity_type, entity_id,
                            new_values, ip_address, user_agent, created_at
                        ) VALUES (
                            :org_id, :user_id, 'BATCH_CREATED_MANUAL', 'import_batch', :entity_id,
                            :new_values, :ip, :ua, NOW()
                        )
                    ");
                    $auditStmt->execute([
                        ':org_id' => $orgId,
                        ':user_id' => $user['id'] ?? $user['user_id'] ?? null,
                        ':entity_id' => $batchId,
                        ':new_values' => json_encode(['batch_reference' => $batchRef, 'row_count' => count($validRows), 'total_amount' => $totalAmount]),
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    ]);
                } catch (PDOException $e) {
                    error_log("[manual_entry.php] Audit log failed: " . $e->getMessage());
                }

                $pdo->commit();
                header("Location: sources.php?batch_id={$batchId}");
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("[manual_entry.php] " . $e->getMessage());
                $error = 'Could not save this batch. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manual Entry — VouchMorph Enterprise</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Inter',sans-serif; background:#f1f5f9; color:#0f172a; padding:32px; }
.wrap { max-width:1100px; margin:0 auto; }
h1 { font-size:22px; font-weight:700; margin-bottom:6px; }
.sub { color:#64748b; font-size:13.5px; margin-bottom:24px; }
.card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:22px; margin-bottom:20px; }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
label { display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:5px; text-transform:uppercase; letter-spacing:.4px; }
input, select { width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13.5px; }
.entry-row { border:1px solid #e2e8f0; border-radius:10px; padding:16px; margin-bottom:14px; position:relative; background:#fbfaf7; }
.entry-row .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
.entry-row .grid2 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:12px; }
.remove-row { position:absolute; top:10px; right:10px; background:#fee2e2; color:#991b1b; border:none; border-radius:6px; width:26px; height:26px; font-weight:700; cursor:pointer; }
.add-btn { background:#0f172a; color:#fff; border:none; padding:10px 20px; border-radius:30px; font-weight:600; font-size:13px; cursor:pointer; margin-bottom:20px; }
.submit-btn { background:#166534; color:#fff; border:none; padding:12px 28px; border-radius:30px; font-weight:600; font-size:14px; cursor:pointer; }
.error { background:#fef2f2; color:#dc2626; padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:13.5px; }
.row-num { font-size:11px; color:#94a3b8; font-weight:700; text-transform:uppercase; margin-bottom:8px; }
.total-bar { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; background:#f8fafc; border-radius:10px; margin-bottom:16px; font-size:13.5px; }
.total-bar strong { font-family:monospace; font-size:16px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>New Disbursement — Manual Entry</h1>
    <p class="sub">For a handful of recipients. Uploading a file for a large batch? <a href="upload.php">Use file upload instead →</a></p>

    <?php if ($error): ?><div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="POST" id="manualForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

        <div class="card">
            <div class="field-row" style="grid-template-columns:1fr;">
                <div>
                    <label>Batch name (optional)</label>
                    <input type="text" name="batch_name" placeholder="e.g., Supplier payments — July">
                </div>
            </div>
        </div>

        <div id="rowsContainer"></div>

        <button type="button" class="add-btn" onclick="addRow()">+ Add another recipient</button>

        <div class="total-bar">
            <span>Recipients: <strong id="rowCount">0</strong></span>
            <span>Total: <strong id="totalAmount">BWP 0.00</strong></span>
        </div>

        <button type="submit" class="submit-btn">Save and Continue to Source Selection →</button>
    </form>
</div>

<template id="rowTemplate">
    <div class="entry-row" data-row>
        <button type="button" class="remove-row" onclick="this.closest('[data-row]').remove(); updateTotals();">×</button>
        <div class="row-num">Recipient __INDEX__</div>
        <div class="grid">
            <div><label>Recipient Name</label><input type="text" name="rows[__INDEX__][recipient_name]" required></div>
            <div><label>Phone</label><input type="text" name="rows[__INDEX__][recipient_phone]"></div>
            <div><label>National ID (optional)</label><input type="text" name="rows[__INDEX__][recipient_national_id]"></div>
            <div><label>Amount (BWP)</label><input type="number" step="0.01" min="0.01" name="rows[__INDEX__][amount]" class="amount-input" oninput="updateTotals()" required></div>
        </div>
        <div class="grid2">
            <div>
                <label>Destination Type</label>
                <select name="rows[__INDEX__][destination_type]" class="dest-type-select" onchange="toggleIdentityField(this)" required>
                    <option value="ACCOUNT">Bank Account</option>
                    <option value="WALLET">Wallet</option>
                    <option value="VOUCHER">Voucher</option>
                    <option value="PHONE">Cashout (Phone)</option>
                    <option value="IDENTIFIER">Identity Only (no account yet)</option>
                </select>
            </div>
            <div><label>Institution/Provider</label><input type="text" name="rows[__INDEX__][destination_provider]" placeholder="e.g., Saccussalis, Zurubank, Mascom"></div>
            <div><label>Account / Wallet / Phone Number</label><input type="text" name="rows[__INDEX__][destination_value]" required></div>
        </div>
        <div class="grid2 identity-field" style="display:none; margin-top:12px;">
            <div>
                <label>Identity Type</label>
                <select name="rows[__INDEX__][identity_type]">
                    <option value="national_id">National ID</option>
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                </select>
            </div>
        </div>
    </div>
</template>

<script>
let rowIndex = 0;
function addRow() {
    const tpl = document.getElementById('rowTemplate').innerHTML.replaceAll('__INDEX__', rowIndex);
    const div = document.createElement('div');
    div.innerHTML = tpl;
    document.getElementById('rowsContainer').appendChild(div.firstElementChild);
    rowIndex++;
    updateTotals();
}
function toggleIdentityField(select) {
    const wrap = select.closest('[data-row]').querySelector('.identity-field');
    wrap.style.display = select.value === 'IDENTIFIER' ? 'grid' : 'none';
}
function updateTotals() {
    const rows = document.querySelectorAll('[data-row]');
    document.getElementById('rowCount').textContent = rows.length;
    let total = 0;
    document.querySelectorAll('.amount-input').forEach(inp => total += parseFloat(inp.value || 0));
    document.getElementById('totalAmount').textContent = 'BWP ' + total.toLocaleString('en-US', {minimumFractionDigits: 2});
}
addRow(); // start with one row visible
</script>
</body>
</html>
