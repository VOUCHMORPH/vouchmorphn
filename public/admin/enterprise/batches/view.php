<?php
/**
 * batches/view.php - ENTERPRISE DISBURSEMENT BATCH DETAILS
 * FIXED: Uses disbursement_batches and disbursement_destinations
 */
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['id'] ?? 0;

// FIXED: Use disbursement_batches
$stmt = $db->prepare("
    SELECT * FROM disbursement_batches 
    WHERE id = :id AND organization_id = :org_id
");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Get stats for consistency
$stats = ['total_batches' => 0, 'pending' => 0, 'beneficiaries' => 0];
try {
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id");
    $stmt->execute([':org_id' => $orgId]);
    $stats['total_batches'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM disbursement_batches WHERE organization_id = :org_id AND status = 'pending_approval'");
    $stmt->execute([':org_id' => $orgId]);
    $stats['pending'] = $stmt->fetchColumn() ?: 0;

    $stmt = $db->prepare("SELECT COUNT(*) as total FROM organization_beneficiaries WHERE organization_id = :org_id AND is_active = true");
    $stmt->execute([':org_id' => $orgId]);
    $stats['beneficiaries'] = $stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    // Silent fail
}

// FIXED: Get destinations from disbursement_destinations
$stmt = $db->prepare("
    SELECT * FROM disbursement_destinations 
    WHERE batch_id = :batch_id 
    ORDER BY destination_index
");
$stmt->execute([':batch_id' => $batchId]);
$destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get source account
$source = null;
if ($batch['source_account_id']) {
    $stmt = $db->prepare("SELECT * FROM source_accounts WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $batch['source_account_id'], ':org_id' => $orgId]);
    $source = $stmt->fetch(PDO::FETCH_ASSOC);
}

$successCount = count(array_filter($destinations, fn($d) => strtolower($d['status'] ?? '') === 'success'));
$failedCount = count(array_filter($destinations, fn($d) => strtolower($d['status'] ?? '') === 'failed'));
$pendingCount = count(array_filter($destinations, fn($d) => strtolower($d['status'] ?? '') === 'pending'));

$config = [
    'show_actions' => in_array($user['role'] ?? '', ['owner', 'program_officer', 'department_head']),
    'show_beneficiaries' => in_array($user['role'] ?? '', ['owner', 'auditor', 'program_officer', 'beneficiary_registrar', 'department_head', 'viewer']),
    'show_all_batches' => !in_array($user['role'] ?? '', ['beneficiary_registrar']),
    'show_governance' => in_array($user['role'] ?? '', ['owner', 'auditor', 'department_head']),
    'show_settings' => in_array($user['role'] ?? '', ['owner']),
];

$departmentName = '';
$departmentId = $user['department_id'] ?? null;
if ($departmentId) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = :id AND organization_id = :org_id");
    $stmt->execute([':id' => $departmentId, ':org_id' => $orgId]);
    $dept = $stmt->fetch(PDO::FETCH_ASSOC);
    $departmentName = $dept['name'] ?? '';
}

$roleDisplay = strtoupper($user['role'] ?? 'USER');
$orgName = htmlspecialchars($user['organization_name'] ?? 'ORGANIZATIONAL');
$fileRef = 'VM/' . date('Y') . '/' . date('md') . '-' . str_pad((string)($stats['pending'] + 1), 3, '0', STR_PAD_LEFT);

function formatCurrency($amount) {
    return 'BWP ' . number_format($amount, 2);
}
?>
<!-- Rest of the HTML remains the same, but with updated variable names -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VOUCHMORPH · MULTI-ASSET DISBURSEMENT REGISTRY</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Sans+Condensed:wght@500;600;700&family=IBM+Plex+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ... (keep all the styles from your existing view.php) ... */
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
            --ledger-green: #24513A;
            --green-tint:   #E5EEE7;
            --blue-tint:    #E7EEF4;
            --warning-bg:   #FEF3C7;
            --danger-bg:    #FBEceb;

            --f-body: 'IBM Plex Sans', sans-serif;
            --f-cond: 'IBM Plex Sans Condensed', sans-serif;
            --f-mono: 'IBM Plex Mono', monospace;
        }
        /* ... (keep all other styles from your existing view.php) ... */
    </style>
</head>
<body>
    <!-- ... (keep the masthead, watermark, etc.) ... -->
    
    <!-- In the stats grid, use $batch['total_destinations'] instead of $batch['total_rows'] -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo formatCurrency($batch['total_amount'] ?? 0); ?></div>
            <div class="stat-label">Total Amount</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $batch['total_destinations'] ?? 0; ?></div>
            <div class="stat-label">Total Recipients</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $successCount; ?></div>
            <div class="stat-label">✅ Successful</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $failedCount; ?></div>
            <div class="stat-label">❌ Failed</div>
        </div>
    </div>

    <!-- ... (rest of the page with updated references) ... -->
    
    <!-- In the details grid, update status mapping -->
    <?php 
    $statusMap = [
        'draft' => 'draft',
        'pending' => 'pending_approval',
        'pending_approval' => 'pending_approval',
        'approved' => 'approved',
        'completed' => 'completed',
        'executed' => 'completed',
        'rejected' => 'failed'
    ];
    $statusClass = $statusMap[strtolower($batch['status'] ?? 'draft')] ?? 'draft';
    ?>
    <span class="value"><span class="status-badge <?php echo $statusClass; ?>"><?php echo strtoupper(str_replace('_', ' ', $batch['status'] ?? 'DRAFT')); ?></span></span>

    <!-- In the table, use destinations instead of payments -->
    <tbody>
        <?php foreach ($destinations as $dest): ?>
        <tr>
            <td><?php echo $dest['destination_index']; ?></td>
            <td><?php echo htmlspecialchars($dest['beneficiary_name'] ?? 'N/A'); ?></td>
            <td>
                <?php 
                if ($dest['is_identity_recipient'] ?? false) {
                    echo htmlspecialchars($dest['identity_type'] . ': ' . $dest['identity_value']);
                } else {
                    echo htmlspecialchars($dest['identifier']);
                }
                ?>
            </td>
            <td><span class="amt"><?php echo formatCurrency($dest['amount']); ?></span></td>
            <td><?php echo htmlspecialchars($dest['hold_reference'] ?? $dest['transaction_reference'] ?? '-'); ?></td>
            <td><span class="status-badge-sm <?php echo strtolower($dest['status'] ?? 'pending'); ?>"><?php echo $dest['status'] ?? 'PENDING'; ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($destinations)): ?>
        <tr>
            <td colspan="6">
                <div class="empty-state">
                    <span class="mark">§</span>
                    <p>NO DESTINATIONS ADDED YET. ADD DESTINATIONS TO BEGIN.</p>
                </div>
            </td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>
