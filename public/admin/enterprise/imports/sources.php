<?php
require_once '../auth.php';
$user = requireEnterpriseAuth();
require_once '../../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getConnection();
$orgId = getOrganizationId();
$batchId = $_GET['batch_id'] ?? 0;

// Get batch info
$stmt = $db->prepare("SELECT * FROM import_batches WHERE id = :id AND organization_id = :org_id");
$stmt->execute([':id' => $batchId, ':org_id' => $orgId]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    die("Batch not found");
}

// Get valid rows count
$stmt = $db->prepare("SELECT COUNT(*) as count, SUM(amount) as total FROM import_rows WHERE batch_id = :batch_id AND validation_status = 'VALID'");
$stmt->execute([':batch_id' => $batchId]);
$validStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get organization source accounts
$stmt = $db->prepare("SELECT * FROM organization_sources WHERE organization_id = :org_id AND status = 'ACTIVE'");
$stmt->execute([':org_id' => $orgId]);
$sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sourceId = $_POST['source_id'] ?? null;
    $executionMode = $_POST['execution_mode'] ?? 'ONE_SOURCE_MANY_DEST';
    
    if ($sourceId) {
        $stmt = $db->prepare("
            UPDATE import_batches 
            SET source_id = :source_id, source_type = 'organization_wallet', 
                execution_mode = :mode, status = 'SOURCES_SELECTED'
            WHERE id = :id
        ");
        $stmt->execute([
            ':source_id' => $sourceId,
            ':mode' => $executionMode,
            ':id' => $batchId
        ]);
        
        header("Location: review.php?batch_id=$batchId");
        exit;
    }
}
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
        .execution-mode-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
            margin-top: 8px;
        }
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
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 12px;
            margin-top: 20px;
        }
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
            <a href="upload.php" class="nav-item active">📁 New Payment</a>
            <a href="../batches/index.php" class="nav-item">📦 Batches</a>
            <a href="../beneficiaries/index.php" class="nav-item">👥 Beneficiaries</a>
            <a href="../templates/index.php" class="nav-item">📋 Templates</a>
            <a href="../reports/index.php" class="nav-item">📄 Reports</a>
            <a href="../settings/index.php" class="nav-item">⚙️ Settings</a>
        </div>
    </div>
    
    <div class="main">
        <div class="top-bar">
            <div class="greeting">
                <h1>Select Source Account</h1>
            </div>
        </div>
        
        <div class="step-indicator">
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Upload</div></div>
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Map</div></div>
            <div class="step completed"><div class="step-number">✓</div><div class="step-label">Validate</div></div>
            <div class="step active"><div class="step-number">4</div><div class="step-label">Source</div></div>
            <div class="step"><div class="step-number">5</div><div class="step-label">Review</div></div>
            <div class="step"><div class="step-number">6</div><div class="step-label">Execute</div></div>
        </div>
        
        <div class="card">
            <div class="card-header">💰 Payment Summary</div>
            <div class="card-body">
                <div class="stats-summary">
                    <div class="stat-item">
                        <div class="label">Total Valid Payments</div>
                        <div class="value"><?php echo number_format($validStats['count'] ?? 0); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Total Amount</div>
                        <div class="value">P<?php echo number_format($validStats['total'] ?? 0, 2); ?></div>
                    </div>
                    <div class="stat-item">
                        <div class="label">Batch Reference</div>
                        <div class="value" style="font-size: 14px;"><?php echo htmlspecialchars($batch['batch_reference']); ?></div>
                    </div>
                </div>
                
                <form method="POST">
                    <h3 style="margin-bottom: 16px;">🏦 Select Source Account</h3>
                    
                    <?php foreach ($sources as $source): ?>
                    <div class="source-option" onclick="selectSource(<?php echo $source['id']; ?>)">
                        <input type="radio" name="source_id" value="<?php echo $source['id']; ?>" id="source_<?php echo $source['id']; ?>" class="radio-input">
                        <label for="source_<?php echo $source['id']; ?>" style="cursor: pointer;">
                            <div class="source-name"><?php echo htmlspecialchars($source['source_name']); ?></div>
                            <div class="source-balance">
                                Available Balance: <strong>P<?php echo number_format($source['balance'], 2); ?></strong>
                                <span style="margin-left: 16px;">Provider: <?php echo $source['provider']; ?></span>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 24px;">
                        <label style="font-weight: 600;">Execution Mode</label>
                        <select name="execution_mode" class="execution-mode-select">
                            <option value="ONE_SOURCE_MANY_DEST">One Source → Many Destinations (Single wallet pays everyone)</option>
                            <option value="MANY_SOURCES_ONE_DEST">Many Sources → One Destination (Collect from multiple wallets)</option>
                            <option value="MANY_TO_MANY">Many Sources → Many Destinations (Matrix payment)</option>
                        </select>
                    </div>
                    
                    <?php if (!empty($sources) && ($validStats['total'] ?? 0) > ($sources[0]['balance'] ?? 0)): ?>
                    <div class="warning-box">
                        ⚠️ <strong>Warning:</strong> Total payment amount (P<?php echo number_format($validStats['total'] ?? 0, 2); ?>) 
                        exceeds selected source balance. Please ensure sufficient funds or add another source account.
                    </div>
                    <?php endif; ?>
                    
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary" onclick="location.href='validate.php?batch_id=<?php echo $batchId; ?>'">← Back</button>
                        <button type="submit" class="btn btn-primary">Continue to Review →</button>
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
}
</script>
</body>
</html>
