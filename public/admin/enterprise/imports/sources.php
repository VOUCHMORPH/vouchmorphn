<?php
/**
 * enterprise/imports/sources.php
 * 
 * FIXED: Uses disbursement_batches and source_accounts
 * instead of import_batches and organization_sources
 * 
 * NOTE: This file is now merged with source_input.php functionality.
 * You may want to redirect source_input.php to this file or vice versa.
 */
require_once __DIR__ . '/../auth.php';
$user = requireEnterpriseAuth();
requirePermission('manage_sources');   // added: this endpoint had no permission check
require_once __DIR__ . '/../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

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

// FIXED: Get destinations count from disbursement_destinations
$stmt = $db->prepare("
    SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total 
    FROM disbursement_destinations 
    WHERE batch_id = :batch_id
");
$stmt->execute([':batch_id' => $batchId]);
$destStats = $stmt->fetch(PDO::FETCH_ASSOC);

// FIXED: Use source_accounts
$stmt = $db->prepare("
    SELECT * FROM source_accounts 
    WHERE organization_id = :org_id AND is_active = true
    ORDER BY institution, source_identifier
");
$stmt->execute([':org_id' => $orgId]);
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken($_POST['csrf_token'] ?? null);
    $sourceId = $_POST['source_id'] ?? null;
    
    if ($sourceId) {
        // Get source details
        $stmt = $db->prepare("
            SELECT * FROM source_accounts 
            WHERE id = :id AND organization_id = :org_id
        ");
        $stmt->execute([':id' => $sourceId, ':org_id' => $orgId]);
        $source = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($source) {
            $stmt = $db->prepare("
                UPDATE disbursement_batches 
                SET source_account_id = :source_id,
                    source_institution = :institution,
                    source_asset_type = :asset_type,
                    source_identifier = :identifier,
                    status = 'pending_approval',
                    updated_at = NOW()
                WHERE id = :id AND organization_id = :org_id
            ");
            $stmt->execute([
                ':source_id' => $sourceId,
                ':institution' => $source['institution'],
                ':asset_type' => $source['asset_type'],
                ':identifier' => $source['source_identifier'],
                ':id' => $batchId
            ]);
            
            // Redirect to review
            header("Location: review.php?batch_id=$batchId");
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
$userRole = $user['role'] ?? 'viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Source Account - VouchMorph Enterprise</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
        }
        .app { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px;
            background: #0f172a;
            color: #e2e8f0;
            position: fixed;
            height: 100vh;
        }
        .sidebar-header { padding: 24px; border-bottom: 1px solid #1e293b; }
        .sidebar-header h2 { font-size: 20px; font-weight: 700; }
        .sidebar-header span { color: #fbbf24; }
        .sidebar-nav { padding: 20px 0; }
        .nav-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #cbd5e1;
            text-decoration: none;
        }
        .nav-item:hover { background: #1e293b; color: white; }
        .main {
            flex: 1;
            margin-left: 280px;
            padding: 24px 32px;
        }
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .greeting h1 { font-size: 28px; font-weight: 700; }
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
            max-width: 800px;
        }
        .step {
            flex: 1;
            text-align: center;
        }
        .step-number {
            width: 32px;
            height: 32px;
            background: #e2e8f0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .step.completed .step-number { background: #10b981; color: white; }
        .step.active .step-number { background: #0f172a; color: white; }
        .step-label { font-size: 12px; color: #64748b; }
        .card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 24px;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }
        .card-body { padding: 24px; }
        .stats-summary {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
        }
        .stat-item .label { font-size: 13px; color: #64748b; }
        .stat-item .value { font-size: 24px; font-weight: 700; }
        .source-option {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .source-option:hover { border-color: #0f172a; background: #f8fafc; }
        .source-option.selected { border-color: #10b981; background: #f0fdf4; }
        .source-name { font-weight: 700; font-size: 18px; }
        .source-balance { color: #64748b; font-size: 14px; margin-top: 4px; }
        .source-balance strong { color: #0f172a; }
        .radio-input { margin-right: 16px; transform: scale(1.2); }
        .btn-group { display: flex; gap: 16px; justify-content: flex-end; margin-top: 24px; }
        .btn {
            padding: 12px 28px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            font-size: 14px;
        }
        .btn-primary { background: #0f172a; color: white; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
        .btn-success { background: #10b981; color: white; }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .hooked-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
        }
        .no-sources {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }
        .no-sources .icon { font-size: 48px; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="app">
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>VouchMorph <span>Enterprise</span></h2>
        </div>
        <div class="sidebar-nav">
            <a href="../index.php" class="nav-item">📊 Dashboard</a>
            <a href="source_input.php" class="nav-item active">📁 New Payment</a>
            <a href="../batches/index.php" class="nav-item">📦 Batches</a>
            <a href="../beneficiaries.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../reports.php" class="nav-item">📄 Reports</a>
            <a href="../settings.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Select Source Account</h1>
            </div>
        </div>
        
        <div class="step-indicator">
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Source</div></div>
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Destinations</div></div>
            <div class="step active"><div class="step-number">3</div><div class="step-label">Select Source</div></div>
            <div class="step"><div class="step-number">4</div><div class="step-label">Review</div></div>
            <div class="step"><div class="step-number">5</div><div class="step-label">Execute</div></div>
        </div>
        
        <div class="card">
            <div class="card-header">💰 Payment Summary</div>
            <div class="card-body">
                <div class="stats-summary">
                    <div class="stat-item">
                        <div class="label">Total Destinations</div>
                        <div class="value"><?php echo number_format($destStats['count'] ?? 0); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Total Amount</div>
                        <div class="value">P<?php echo number_format($destStats['total'] ?? 0, 2); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Batch Reference</div>
                        <div class="value" style="font-size: 14px;"><?php echo htmlspecialchars($batch['batch_reference']); ?></div>
                    </div>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <h3 style="margin-bottom: 16px;">🏦 Select Source Account</h3>
                    
                    <?php if (empty($sources)): ?>
                    <div class="no-sources">
                        <div class="icon">📭</div>
                        <p>No source accounts configured.</p>
                        <p style="font-size: 13px; margin-top: 8px;">
                            <a href="add_source.php" style="color: var(--brass);">Add a source account →</a>
                        </p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($sources as $source): ?>
                        <div class="source-option" onclick="selectSource(<?php echo $source['id']; ?>)">
                            <input type="radio" name="source_id" value="<?php echo $source['id']; ?>" id="source_<?php echo $source['id']; ?>" class="radio-input">
                            <label for="source_<?php echo $source['id']; ?>" style="cursor: pointer;">
                                <div class="source-name">
                                    <?php echo htmlspecialchars($source['institution']); ?>
                                    <?php if ($source['is_hooked']): ?>
                                    <span class="hooked-badge">🔗 Hooked</span>
                                    <?php endif; ?>
                                </div>
                                <div class="source-balance">
                                    Account: <?php echo htmlspecialchars($source['source_identifier']); ?>
                                    <span style="margin-left: 16px;">
                                        Balance: <strong>P<?php echo number_format($source['balance'] ?? 0, 2); ?></strong>
                                    </span>
                                    <span style="margin-left: 16px;">
                                        <?php echo htmlspecialchars($source['asset_type']); ?>
                                    </span>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php 
                    $totalAmount = $destStats['total'] ?? 0;
                    $maxBalance = !empty($sources) ? max(array_column($sources, 'balance')) : 0;
                    if ($totalAmount > $maxBalance && !empty($sources)): 
                    ?>
                    <div class="warning-box">
                        ⚠️ <strong>Warning:</strong> Total payment amount (P<?php echo number_format($totalAmount, 2); ?>) 
                        exceeds selected source balance. Please ensure sufficient funds or add another source account.
                    </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary" onclick="location.href='add_destinations.php?batch_id=<?php echo $batchId; ?>'">← Back</button>
                        <a href="add_source.php?batch_id=<?php echo $batchId; ?>" class="btn btn-secondary">➕ Add Source</a>
                        <button type="submit" class="btn btn-primary" <?php echo empty($sources) ? 'disabled' : ''; ?>>
                            Continue to Review →
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function selectSource(sourceId) {
    const radio = document.getElementById('source_' + sourceId);
    if (radio) radio.checked = true;
    // Highlight selected
    document.querySelectorAll('.source-option').forEach(el => el.classList.remove('selected'));
    radio.closest('.source-option').classList.add('selected');
}
</script>
</body>
</html>
